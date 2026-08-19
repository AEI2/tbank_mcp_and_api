<?php

declare(strict_types=1);

namespace Tbank\Invest\Exception;

final class RateLimitException extends TInvestException
{
    public function __construct(string $message, float $retryAfter = 0.0)
    {
        parent::__construct($message, 429, ['retry_after' => $retryAfter], null, 'rate_limited');
    }
}
