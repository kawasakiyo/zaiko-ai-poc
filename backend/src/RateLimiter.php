<?php

namespace App;

use Predis\Client as Redis;

/**
 * IP単位の固定ウィンドウ・レートリミッタ（Redis）。
 * 1ウィンドウ($windowSec秒)あたり同一IPから $max 回まで許可する。
 */
class RateLimiter
{
    private Redis $redis;
    private int $max;
    private int $windowSec;

    public function __construct(Redis $redis, int $max, int $windowSec)
    {
        $this->redis = $redis;
        $this->max = $max;
        $this->windowSec = $windowSec;
    }

    /**
     * 消費を試みる。許可されれば [allowed=true]、超過なら retryAfter 秒を返す。
     *
     * @return array{allowed: bool, remaining: int, retryAfter: int}
     */
    public function hit(string $ip): array
    {
        $key = 'rl:' . $ip;

        // INCR してから、初回(=1)のときだけ有効期限を設定する固定ウィンドウ。
        $count = (int) $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, $this->windowSec);
        }

        if ($count > $this->max) {
            $ttl = (int) $this->redis->ttl($key);
            if ($ttl < 0) {
                $ttl = $this->windowSec;
            }
            return ['allowed' => false, 'remaining' => 0, 'retryAfter' => $ttl];
        }

        return [
            'allowed' => true,
            'remaining' => max(0, $this->max - $count),
            'retryAfter' => 0,
        ];
    }
}
