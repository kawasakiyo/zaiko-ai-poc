<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\AppDb;
use App\Auth;
use App\ClaudeClient;
use App\Db;
use App\History;
use App\RateLimiter;
use App\SqlGuard;
use Predis\Client as Redis;

/** JSONレスポンスを返して終了する。 */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 選択可能なモデルの whitelist（キー=API識別子）。 */
function allowed_models(): array
{
    return [
        'claude-haiku-4-5' => 'Haiku 4.5（安価・高速）',
        'claude-sonnet-5' => 'Sonnet 5（バランス）',
        'claude-opus-4-8' => 'Opus 4.8（最高精度）',
    ];
}

/** 送信元IPを取得（プロキシの X-Forwarded-For を優先）。 */
function client_ip(): string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $parts = explode(',', $fwd);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** リクエストJSONボディを配列で取得。 */
function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Authorization: Bearer トークンを取得。 */
function bearer_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (preg_match('/Bearer\s+(.+)/i', (string) $h, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** Redis クライアントを生成。 */
function build_redis(array $env): Redis
{
    return new Redis([
        'scheme' => 'tcp',
        'host' => $env['REDIS_HOST'] ?: 'redis',
        'port' => (int) ($env['REDIS_PORT'] ?: 6379),
    ]);
}

$env = getenv();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    $redis = build_redis($env);
    $appDb = new AppDb($env);
    $auth = new Auth($appDb->pdo(), $redis, (int) ($env['SESSION_TTL_SEC'] ?: 28800));

    // ===== 公開ルート（認証不要） =====

    // ---- POST /api/login ----
    if ($method === 'POST' && $path === '/api/login') {
        $body = json_body();
        $result = $auth->login((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''));
        if ($result === null) {
            json_response(['error' => 'ユーザー名またはパスワードが違います。'], 401);
        }
        json_response(['token' => $result['token'], 'username' => $result['username']]);
    }

    // ---- POST /api/logout ----
    if ($method === 'POST' && $path === '/api/logout') {
        $auth->logout(bearer_token());
        json_response(['ok' => true]);
    }

    // ===== ここから認証必須 =====
    $user = $auth->validate(bearer_token());
    if ($user === null) {
        json_response(['error' => '認証が必要です。ログインしてください。'], 401);
    }

    // ---- GET /api/me ----
    if ($method === 'GET' && $path === '/api/me') {
        json_response(['username' => $user['username']]);
    }

    // ---- GET /api/models ----
    if ($method === 'GET' && $path === '/api/models') {
        $models = [];
        foreach (allowed_models() as $id => $label) {
            $models[] = ['id' => $id, 'label' => $label];
        }
        json_response([
            'models' => $models,
            'default' => $env['DEFAULT_MODEL'] ?: 'claude-sonnet-5',
        ]);
    }

    // ---- POST /api/analyze ----
    if ($method === 'POST' && $path === '/api/analyze') {
        $body = json_body();
        $prompt = trim((string) ($body['prompt'] ?? ''));
        $model = (string) ($body['model'] ?? '');

        if ($prompt === '') {
            json_response(['error' => '指示（prompt）が空です。'], 400);
        }

        // モデルを whitelist 検証。不正値は既定モデルにフォールバック。
        $models = allowed_models();
        if (!isset($models[$model])) {
            $model = $env['DEFAULT_MODEL'] ?: 'claude-sonnet-5';
            if (!isset($models[$model])) {
                $model = 'claude-sonnet-5';
            }
        }

        // レートリミット（Redis・IP単位）。
        $limiter = new RateLimiter(
            $redis,
            (int) ($env['RATE_LIMIT_MAX'] ?: 20),
            (int) ($env['RATE_LIMIT_WINDOW_SEC'] ?: 3600)
        );
        $rl = $limiter->hit(client_ip());
        if (!$rl['allowed']) {
            header('Retry-After: ' . $rl['retryAfter']);
            json_response([
                'error' => 'レート制限を超えました。しばらく待って再試行してください。',
                'retryAfter' => $rl['retryAfter'],
            ], 429);
        }

        // SQL生成（Claude）。渡すスキーマは在庫系のみ（アプリ表の存在すら伝えない）。
        $schema = file_get_contents(__DIR__ . '/../schema/adventureworks_inventory.md') ?: '';
        $claude = new ClaudeClient((string) ($env['ANTHROPIC_API_KEY'] ?: ''));
        $generated = $claude->generateSql($prompt, $model, $schema);

        // 安全ガード（SELECT単文・クロスDB/アプリDB参照の遮断）。
        $guard = new SqlGuard((string) ($env['APP_DB_NAME'] ?: 'AppDb'));
        $safeSql = $guard->validate($generated['sql']);

        // 実行は分析用接続（analysis_reader＝AppDb無権限）で。
        $analysisDb = new Db($env);
        $result = $analysisDb->runSelect($safeSql, (int) ($env['MAX_ROWS'] ?: 500));

        // 履歴はアプリDBへ、実行ユーザーID・AIの説明と共に記録。
        (new History($appDb->pdo()))->add(
            (int) $user['userId'],
            $prompt,
            $model,
            $generated['sql'],
            $generated['explanation']
        );

        json_response([
            'sql' => $generated['sql'],
            'explanation' => $generated['explanation'],
            'columns' => $result['columns'],
            'rows' => $result['rows'],
            'rateRemaining' => $rl['remaining'],
        ]);
    }

    // ---- GET /api/history ----
    if ($method === 'GET' && $path === '/api/history') {
        $items = (new History($appDb->pdo()))->recent(100);
        json_response(['items' => $items]);
    }

    // ---- POST /api/rerun ----
    // 履歴に保存済みの生成SQLを再実行する（Claudeは呼ばない＝課金なし・レート制限対象外）。
    if ($method === 'POST' && $path === '/api/rerun') {
        $body = json_body();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            json_response(['error' => '履歴IDが不正です。'], 400);
        }

        $sql = (new History($appDb->pdo()))->sqlById($id);
        if ($sql === null) {
            json_response(['error' => '対象の履歴が見つかりません。'], 404);
        }

        // 保存済みSQLでも再検証し、分析用接続（analysis_reader）で実行する。
        $guard = new SqlGuard((string) ($env['APP_DB_NAME'] ?: 'AppDb'));
        $safeSql = $guard->validate($sql);
        $analysisDb = new Db($env);
        $result = $analysisDb->runSelect($safeSql, (int) ($env['MAX_ROWS'] ?: 500));

        json_response([
            'sql' => $sql,
            'columns' => $result['columns'],
            'rows' => $result['rows'],
        ]);
    }

    // ---- POST /api/export (CSV) ----
    if ($method === 'POST' && $path === '/api/export') {
        $body = json_body();
        $columns = $body['columns'] ?? [];
        $rows = $body['rows'] ?? [];
        if (!is_array($columns) || !is_array($rows)) {
            json_response(['error' => 'columns と rows が必要です。'], 400);
        }

        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="result.csv"');

        $out = fopen('php://output', 'w');
        // ExcelでUTF-8を正しく開くためBOMを付与。
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $v = $row[$col] ?? '';
                $line[] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    json_response(['error' => 'Not Found', 'path' => $path], 404);
} catch (\Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
