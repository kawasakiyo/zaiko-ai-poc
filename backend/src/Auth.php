<?php

namespace App;

use PDO;
use Predis\Client as Redis;

/**
 * ID/PASS 認証。パスワードは bcrypt(password_hash)で保存し、
 * ログイン成功時に Redis にセッショントークンを発行する(TTL付き)。
 */
class Auth
{
    public function __construct(
        private PDO $pdo,
        private Redis $redis,
        private int $ttlSec
    ) {
    }

    /**
     * ユーザーを新規作成する。既存なら例外。成功時は新規 Id を返す。
     */
    public function register(string $username, string $password): int
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new \RuntimeException('ユーザー名とパスワードは必須です。');
        }

        $check = $this->pdo->prepare('SELECT Id FROM app.Users WHERE Username = :u');
        $check->execute([':u' => $username]);
        if ($check->fetch()) {
            throw new \RuntimeException('そのユーザー名は既に使われています。');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $this->pdo->prepare('INSERT INTO app.Users (Username, PasswordHash) VALUES (:u, :h)');
        $ins->execute([':u' => $username, ':h' => $hash]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 認証してトークンを発行する。失敗時は null。
     *
     * @return array{token:string, username:string}|null
     */
    public function login(string $username, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT Id, Username, PasswordHash FROM app.Users WHERE Username = :u');
        $stmt->execute([':u' => trim($username)]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['PasswordHash'])) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->redis->setex(
            'session:' . $token,
            $this->ttlSec,
            json_encode(['userId' => (int) $row['Id'], 'username' => $row['Username']])
        );

        return ['token' => $token, 'username' => $row['Username']];
    }

    /**
     * トークンを検証し、セッション情報を返す。無効なら null。
     *
     * @return array{userId:int, username:string}|null
     */
    public function validate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $raw = $this->redis->get('session:' . $token);
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function logout(string $token): void
    {
        if ($token !== '') {
            $this->redis->del(['session:' . $token]);
        }
    }
}
