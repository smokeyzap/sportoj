<?php
declare(strict_types=1);

namespace App\Support;

/**
 * File based sliding-window limiter under storage/ratelimit/ (no schema change).
 * Fails open (and logs) when the directory is not writable so a storage fault cannot lock every user out.
 */
final class RateLimiter
{
    public function __construct(private string $dir, private Logger $logger)
    {
    }

    /** Count a hit; returns false when the limit is exceeded (the hit is not recorded then). */
    public function attempt(string $key, int $max, int $windowSeconds): bool
    {
        return $this->withBucket($key, $windowSeconds, static function (array $hits, int $now) use ($max): array {
            if (count($hits) >= $max) {
                return [$hits, false];
            }
            $hits[] = $now;
            return [$hits, true];
        });
    }

    /** True when the key already has $max or more hits inside the window; records nothing. */
    public function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        return !$this->withBucket($key, $windowSeconds, static fn (array $hits, int $now): array => [$hits, count($hits) < $max]);
    }

    public function hit(string $key, int $windowSeconds): void
    {
        $this->withBucket($key, $windowSeconds, static function (array $hits, int $now): array {
            $hits[] = $now;
            return [$hits, true];
        });
    }

    public function clear(string $key): void
    {
        @unlink($this->file($key));
    }

    /** Seconds until the oldest hit leaves the window. */
    public function retryAfter(string $key, int $windowSeconds): int
    {
        $hits = $this->read($this->file($key));
        return $hits === [] ? 1 : max(1, min($hits) + $windowSeconds - time());
    }

    /** Remove bucket files that can no longer contain a live hit. */
    public function purge(int $olderThanSeconds = 86400): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - $olderThanSeconds && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    /** @return list<int> */
    private function read(string $file): array
    {
        $raw = @file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        return is_array($data) ? array_values(array_map('intval', $data)) : [];
    }

    /**
     * @param callable(list<int>,int):array{0:list<int>,1:bool} $fn
     */
    private function withBucket(string $key, int $window, callable $fn): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            $this->logger->warning('ratelimit dir not writable', ['dir' => $this->dir]);
            return true;
        }
        $fh = @fopen($this->file($key), 'c+');
        if ($fh === false) {
            $this->logger->warning('ratelimit file not writable', ['dir' => $this->dir]);
            return true;
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $hits = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $now = time();
            $hits = array_values(array_filter(is_array($hits) ? $hits : [], static fn ($t) => is_int($t) && $t > $now - $window));
            [$hits, $ok] = $fn($hits, $now);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($hits));
            fflush($fh);
            return $ok;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
