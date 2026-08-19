<?php

declare(strict_types=1);

namespace Tbank\Invest\Exception;

final class ConfigException extends TInvestException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 500, null, null, 'config_error');
    }
}
