<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Exception\RateLimitException;

interface RateLimiter
{
    public function acquire(string $service, string $method): void;
}

final class NullRateLimiter implements RateLimiter
{
    public function acquire(string $service, string $method): void
    {
    }
}

final class SlidingWindowLimiter implements RateLimiter
{
    /** @var array<string, list<float>> */
    private array $windows = [];

    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock = new SystemClock(),
        private readonly ?string $path = null,
    ) {
    }

    public static function memory(Config $config, Clock $clock = new SystemClock()): self
    {
        return new self($config, $clock, null);
    }

    public static function file(Config $config, Clock $clock = new SystemClock(), ?string $path = null): self
    {
        $file = $path ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tbank-rate-limit.json');

        return new self($config, $clock, $file);
    }

    public function acquire(string $service, string $method): void
    {
        $this->lock();
        try {
            $this->load();
            $deadline = $this->clock->now() + $this->config->rateLimitMaxWait;
            while (true) {
                $now = $this->clock->now();
                $wait = 0.0;
                $buckets = QuotaTable::windows($service, $method, $this->config);
                foreach ($buckets as $bucket) {
                    $wait = max($wait, $this->waitFor($bucket['key'], $bucket['max'], $bucket['window'], $now));
                }
                if ($wait <= 0.0005) {
                    foreach ($buckets as $bucket) {
                        $this->windows[$bucket['key']][] = $now;
                    }
                    $this->save();

                    return;
                }
                if ($now + $wait > $deadline) {
                    throw new RateLimitException(
                        "T-Invest rate queue waited longer than {$this->config->rateLimitMaxWait}s for {$service}/{$method}.",
                        $wait,
                    );
                }
                $this->clock->sleep($wait);
            }
        } finally {
            $this->unlock();
        }
    }

    private function waitFor(string $key, int $max, float $window, float $now): float
    {
        $times = $this->windows[$key] ?? [];
        $times = array_values(array_filter($times, static fn (float $t) => ($now - $t) < $window));
        $this->windows[$key] = $times;
        if (count($times) < $max) {
            return 0.0;
        }

        return max(0.0, $window - ($now - $times[0]));
    }

    private function load(): void
    {
        if ($this->path === null || !is_file($this->path)) {
            return;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded['windows'] ?? null)) {
            $this->windows = $decoded['windows'];
        }
    }

    private function save(): void
    {
        if ($this->path === null) {
            return;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(
            $this->path,
            json_encode(['windows' => $this->windows], JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    private function lock(): void
    {
        if ($this->path === null) {
            return;
        }
        $lockPath = $this->path . '.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RateLimitException('Cannot open T-Invest rate-limit lock file');
        }
        flock($handle, LOCK_EX);
        $this->lock = $handle;
    }

    private function unlock(): void
    {
        if ($this->lock === null) {
            return;
        }
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }
}
