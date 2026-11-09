<?php

namespace App;

/**
 * AIが生成したSQLを実行前に検証する安全ガード。
 * 単一の SELECT / WITH(CTE) 文のみ許可し、破壊的操作・DB切替・クロスDB参照を遮断する。
 * （DB接続側も analysis_reader(AppDb無権限)で実行するため多層防御になっている。）
 */
class SqlGuard
{
    /** 単語境界で現れたら拒否するキーワード。 */
    private const FORBIDDEN = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'EXEC', 'EXECUTE', 'MERGE', 'GRANT', 'REVOKE', 'INTO', 'BACKUP',
        'RESTORE', 'SHUTDOWN', 'RECONFIGURE', 'DBCC',
        // DB切替・クロスDB・リンクサーバ経由のアクセスを禁止（情報漏洩対策）。
        'USE', 'OPENROWSET', 'OPENQUERY', 'OPENDATASOURCE', 'OPENXML',
    ];

    /** アプリDB名（この名前への参照を拒否する）。 */
    private string $appDbName;

    public function __construct(string $appDbName = 'AppDb')
    {
        $this->appDbName = $appDbName;
    }

    /**
     * 検証し、問題なければ正規化済みSQLを返す。違反時は例外。
     */
    public function validate(string $sql): string
    {
        $stripped = $this->stripComments($sql);
        $trimmed = trim($stripped);
        $trimmed = rtrim($trimmed, "; \t\n\r\0\x0B");

        if ($trimmed === '') {
            throw new \RuntimeException('空のSQLは実行できません。');
        }

        // セミコロンで分割して、非空の文が1つであること（複数文を禁止）。
        $statements = array_values(array_filter(
            array_map('trim', explode(';', $trimmed)),
            static fn ($s) => $s !== ''
        ));
        if (count($statements) !== 1) {
            throw new \RuntimeException('複数のSQL文は実行できません（単一のSELECT文のみ許可）。');
        }

        // 先頭キーワードが SELECT または WITH のみ許可。
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $trimmed)) {
            throw new \RuntimeException('SELECT 文以外は実行できません。');
        }

        // 禁止キーワードチェック（単語境界）。
        foreach (self::FORBIDDEN as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $trimmed)) {
                throw new \RuntimeException('禁止されたキーワードが含まれています: ' . $kw);
            }
        }

        // アプリDB名への参照を拒否（例: AppDb.app.Users）。
        if (preg_match('/\b' . preg_quote($this->appDbName, '/') . '\b/i', $trimmed)) {
            throw new \RuntimeException('アプリケーションDBへの参照は許可されていません。');
        }

        // 3部名（db.schema.object）= データベースをまたぐ参照を拒否。
        if (preg_match('/\b[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*/', $trimmed)) {
            throw new \RuntimeException('データベースをまたぐ参照は許可されていません。');
        }

        return $trimmed;
    }

    /**
     * -- 行コメントと /* *\/ ブロックコメントを除去する。
     */
    private function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $sql = preg_replace('/--[^\r\n]*/', ' ', $sql);
        return $sql;
    }
}
