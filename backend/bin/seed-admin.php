<?php

/**
 * 初期管理者ユーザーを AppDb に冪等シードする CLI スクリプト。
 * バックエンドコンテナ起動時に docker-entrypoint.sh から実行される。
 * AppDb/app.Users がまだ準備できていない場合はリトライする。
 * 失敗してもコンテナ起動は止めない（exit 0）。
 */

require __DIR__ . '/../vendor/autoload.php';

use App\AppDb;

$env = getenv();
$user = trim((string) ($env['APP_ADMIN_USER'] ?? ''));
$pass = (string) ($env['APP_ADMIN_PASSWORD'] ?? '');

if ($user === '' || $pass === '') {
    fwrite(STDERR, "[seed-admin] APP_ADMIN_USER / APP_ADMIN_PASSWORD 未設定。シードをスキップします。\n");
    exit(0);
}

// 初回は AdventureWorks の復元に時間がかかる（特に Apple Silicon のエミュレーション時）ため、
// AppDb/app.Users が用意できるまで長めにリトライする（最大 100 回 × 3 秒 = 5 分）。
for ($i = 1; $i <= 100; $i++) {
    try {
        $pdo = (new AppDb($env))->pdo();

        $check = $pdo->prepare('SELECT Id FROM app.Users WHERE Username = :u');
        $check->execute([':u' => $user]);
        if ($check->fetch()) {
            fwrite(STDOUT, "[seed-admin] 管理者 '{$user}' は既に存在します。\n");
            exit(0);
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $ins = $pdo->prepare('INSERT INTO app.Users (Username, PasswordHash) VALUES (:u, :h)');
        $ins->execute([':u' => $user, ':h' => $hash]);

        fwrite(STDOUT, "[seed-admin] 管理者 '{$user}' を作成しました。\n");
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[seed-admin] AppDb 準備待ち ({$i}/60): " . $e->getMessage() . "\n");
        sleep(3);
    }
}

fwrite(STDERR, "[seed-admin] AppDb に接続できずシードを断念しました（起動は継続）。\n");
exit(0);
