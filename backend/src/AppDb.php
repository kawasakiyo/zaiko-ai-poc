<?php

namespace App;

use PDO;

/**
 * アプリ用データベース(AppDb)への接続。app_user で接続する。
 * ユーザー(app.Users)と履歴(app.QueryHistory)を扱う。
 * ※この接続は分析データ(AdventureWorks)には触れない。
 */
class AppDb
{
    private PDO $pdo;

    public function __construct(array $env)
    {
        $host = $env['APP_DB_HOST'] ?? 'db';
        $port = $env['APP_DB_PORT'] ?? '1433';
        $name = $env['APP_DB_NAME'] ?? 'AppDb';
        $user = $env['APP_DB_USER'] ?? 'app_user';
        $pass = $env['APP_DB_PASSWORD'] ?? '';

        $dsn = sprintf(
            'sqlsrv:Server=%s,%s;Database=%s;Encrypt=yes;TrustServerCertificate=yes',
            $host,
            $port,
            $name
        );

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
