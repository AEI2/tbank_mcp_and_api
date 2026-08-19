<?php

declare(strict_types=1);

namespace Tbank\Invest;

interface Clock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int) max(1, round($seconds * 1_000_000)));
    }
}

final class FakeClock implements Clock
{
    public float $slept = 0.0;

    public function __construct(public float $now = 1_000.0)
    {
    }

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $seconds = max(0.0, $seconds);
        $this->slept += $seconds;
        $this->now += $seconds;
    }
}
