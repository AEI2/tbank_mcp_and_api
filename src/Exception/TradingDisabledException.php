<?php

declare(strict_types=1);

namespace Tbank\Invest\Exception;

final class TradingDisabledException extends TInvestException
{
    public function __construct(
        public readonly string $service,
        public readonly string $method,
    ) {
        parent::__construct(
            "Trading is disabled. Enable TBANK_ALLOW_TRADING=true to call {$service}/{$method}.",
            403,
            null,
            null,
            'trading_disabled',
        );
    }
}
