<?php

namespace App;

use PDO;

/**
 * 分析リクエストの履歴（実行ユーザー・プロンプト・モデル・生成SQL・AIの説明）を
 * アプリDB(AppDb)の app.QueryHistory に保存／取得する。履歴は全ユーザー共有で表示する。
 */
class History
{
    public function __construct(private PDO $pdo)
    {
    }

    public function add(int $userId, string $prompt, string $model, string $sql, string $explanation): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.QueryHistory (UserId, Prompt, Model, GeneratedSql, Explanation) '
            . 'VALUES (:userId, :prompt, :model, :sql, :explanation)'
        );
        $stmt->execute([
            ':userId' => $userId,
            ':prompt' => $prompt,
            ':model' => $model,
            ':sql' => $sql,
            ':explanation' => $explanation,
        ]);
    }

    /**
     * 新しい順に最大 $limit 件を返す（実行ユーザー名・AIの説明を含む・全ユーザー共有）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query(
            'SELECT TOP (' . $limit . ') h.Id, u.Username, h.Prompt, h.Model, h.GeneratedSql, h.Explanation, h.CreatedAt '
            . 'FROM app.QueryHistory h '
            . 'LEFT JOIN app.Users u ON u.Id = h.UserId '
            . 'ORDER BY h.Id DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * 履歴ID から保存済みの生成SQLを取得する。無ければ null。
     */
    public function sqlById(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT GeneratedSql FROM app.QueryHistory WHERE Id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? (string) $row['GeneratedSql'] : null;
    }
}
