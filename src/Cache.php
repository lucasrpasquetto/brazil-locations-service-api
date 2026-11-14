<?php

declare(strict_types=1);

namespace App;

/**
 * Response cache with graceful degradation.
 *
 * Drivers, in order of preference:
 *   redis -> shared cache, survives deploys, needs ext-redis
 *   file  -> no dependencies, per-server, fine for a static dataset
 *   none  -> straight to the database
 *
 * If anything fails, we fall back to computing the answer. The API never
 * goes down because the cache is unavailable.
 */
final class Cache
{
    private string $driver;
    private int $defaultTtl;
    private ?\Redis $redis = null;
    private string $dir;

    public function __construct(?string $driver = null)
    {
        $this->driver = $driver ?? (getenv('CACHE_DRIVER') ?: 'none');
        $this->defaultTtl = (int) (getenv('CACHE_TTL') ?: 86400);
        $this->dir = dirname(__DIR__) . '/storage/cache';

        if ($this->driver === 'redis' && ! class_exists('\Redis')) {
            $this->driver = 'file';
        }

        if ($this->driver === 'redis') {
            $this->connectRedis();
        }

        if ($this->driver === 'file' && ! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    private function connectRedis(): void
    {
        try {
            $redis = new \Redis();
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            $pass = getenv('REDIS_PASS') ?: null;

            if (! $redis->connect($host, $port, 1.0)) {
                $this->driver = 'file';

                return;
            }

            if ($pass !== null && $pass !== '') {
                $redis->auth($pass);
            }

            $redis->select((int) (getenv('REDIS_DB') ?: 0));
            $this->redis = $redis;
        } catch (\Throwable $e) {
            $this->driver = 'file';
        }
    }

    public function get(string $key): mixed
    {
        $key = $this->key($key);

        if ($this->driver === 'redis' && $this->redis !== null) {
            $value = $this->redis->get($key);

            return $value === false ? null : json_decode((string) $value, true);
        }

        if ($this->driver === 'file') {
            $file = $this->dir . '/' . sha1($key) . '.json';
            if (! is_file($file)) {
                return null;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                return null;
            }
            $payload = json_decode($raw, true);
            if (! is_array($payload) || ($payload['exp'] ?? 0) < time()) {
                @unlink($file);

                return null;
            }

            return $payload['data'];
        }

        return null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $key = $this->key($key);
        $ttl = $ttl ?? $this->defaultTtl;

        if ($this->driver === 'redis' && $this->redis !== null) {
            $this->redis->setex($key, $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($this->driver === 'file') {
            $file = $this->dir . '/' . sha1($key) . '.json';
            @file_put_contents($file, json_encode(['exp' => time() + $ttl, 'data' => $value], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    private function key(string $key): string
    {
        $version = getenv('CACHE_VERSION') ?: 'v1';

        return 'locations:' . $version . ':' . $key;
    }
}
