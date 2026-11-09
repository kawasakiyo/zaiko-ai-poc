<?php

namespace App;

use PDO;

/**
 * SQL Server への接続。読み取り専用ユーザー(appreader)で接続する。
 * 履歴テーブル dbo.QueryHistory への INSERT/SELECT のみ別途許可されている。
 */
class Db
{
    private PDO $pdo;

    public function __construct(array $env)
    {
        $host = $env['DB_HOST'] ?? 'db';
        $port = $env['DB_PORT'] ?? '1433';
        $name = $env['DB_NAME'] ?? 'AdventureWorks';
        $user = $env['DB_USER'] ?? 'appreader';
        $pass = $env['DB_PASSWORD'] ?? '';

        // ODBC Driver 18 は既定で暗号化必須。開発用に自己署名証明書を信頼する。
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

    /**
     * 検証済みSELECTを実行し、列名と行（最大 $maxRows 行）を返す。
     *
     * @return array{columns: string[], rows: array<int, array<string, mixed>>}
     */
    public function runSelect(string $sql, int $maxRows): array
    {
        $stmt = $this->pdo->query($sql);

        $rows = [];
        $columns = [];
        while (count($rows) < $maxRows && ($row = $stmt->fetch()) !== false) {
            if ($columns === []) {
                $columns = array_keys($row);
            }
            // NULL や数値をそのままJSON化できる形に整える。
            $rows[] = $row;
        }

        // 行が0件でも列名を取得する。
        if ($columns === []) {
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'] ?? ('col' . $i);
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
