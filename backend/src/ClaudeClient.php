<?php

namespace App;

use Anthropic\Client;

/**
 * 公式 Anthropic PHP SDK を使った Claude Messages API クライアント。
 *
 * structured outputs(json_schema)で {sql, explanation} を確実に受け取る。
 */
class ClaudeClient
{
    private Client $client;

    public function __construct(string $apiKey)
    {
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY が未設定です。');
        }
        $this->client = new Client(apiKey: $apiKey);
    }

    /**
     * 自然言語の指示とスキーマから SQL を生成する。
     *
     * @return array{sql:string, explanation:string}
     */
    public function generateSql(string $prompt, string $model, string $schemaContext): array
    {
        $system = $schemaContext . "\n\n"
            . "あなたは在庫データ分析アシスタントです。ユーザーの指示を、上記スキーマに対する"
            . "単一の読み取り専用 SELECT クエリ(T-SQL)に変換してください。"
            . "破壊的な操作や複数文は決して生成しないでください。"
            . "explanation には生成したクエリが何を返すかを日本語で簡潔に説明してください。";

        $message = $this->client->messages->create(
            model: $model,
            maxTokens: 2048,
            // SQL生成は軽量タスク。thinkingは無効化して遅延と
            // structured output のパース阻害を避ける（全対象モデルで有効）。
            thinking: ['type' => 'disabled'],
            system: $system,
            outputConfig: [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'sql' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['sql', 'explanation'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
        );

        if ($message->stopReason === 'refusal') {
            $detail = $message->stopDetails->explanation ?? '';
            throw new \RuntimeException('リクエストが安全上の理由で拒否されました。' . $detail);
        }

        // structured outputs のため、最初の text ブロックが有効なJSON。
        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        if ($text === null) {
            throw new \RuntimeException('Claude応答にテキストブロックがありません。');
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || !isset($parsed['sql'], $parsed['explanation'])) {
            throw new \RuntimeException('Claude応答のJSON解析に失敗しました: ' . $text);
        }

        return [
            'sql' => (string) $parsed['sql'],
            'explanation' => (string) $parsed['explanation'],
        ];
    }
}
