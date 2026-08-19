<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Exception\ConfigException;

final class Config
{
    public const string PROD_REST = 'https://invest-public-api.tbank.ru/rest';
    public const string SANDBOX_REST = 'https://sandbox-invest-public-api.tbank.ru/rest';
    public const string PROTO_PREFIX = 'tinkoff.public.invest.api.contract.v1';

    public function __construct(
        public readonly string $token = '',
        public readonly string $environment = 'sandbox',
        public readonly bool $allowTrading = false,
        public readonly string $appName = 'AEI2.tbank_mcp_and_api',
        public readonly string $apiHost = '0.0.0.0',
        public readonly int $apiPort = 8080,
        public readonly ?string $defaultAccountId = null,
        public readonly float $requestTimeout = 30.0,
        public readonly ?string $restBaseUrlOverride = null,
        public readonly string $mcpAllowedOrigins = 'localhost,127.0.0.1,[::1]',
        public readonly bool $mcpRequireSession = false,
        public readonly int $mcpSessionTtl = 3600,
        public readonly bool $rateLimitEnabled = false,
        public readonly int $rateLimitRps = 50,
        public readonly int $rateLimitRpm = 1000,
        public readonly int $rateLimitMinIntervalMs = 0,
        public readonly float $rateLimitMaxWait = 30.0,
        public readonly int $rateLimitRetries = 3,
    ) {
    }

    public static function fromEnv(): self
    {
        $token = self::env(['TBANK_INVEST_TOKEN', 'TINKOFF_INVEST_TOKEN', 'INVEST_TOKEN', 'TINKOFF_API_TOKEN'], '');
        $environment = self::normalizeEnv(self::env(['TBANK_INVEST_ENV', 'TINKOFF_INVEST_ENV', 'INVEST_ENV'], 'sandbox'));
        $account = self::env(['TBANK_DEFAULT_ACCOUNT_ID', 'TINKOFF_ACCOUNT_ID'], '');
        $override = self::env(['TBANK_REST_BASE_URL', 'TINKOFF_REST_BASE_URL'], '');

        return new self(
            token: trim($token),
            environment: $environment,
            allowTrading: self::boolEnv(['TBANK_ALLOW_TRADING', 'TINKOFF_ALLOW_TRADING'], false),
            appName: self::env(['TBANK_APP_NAME', 'TINKOFF_APP_NAME'], 'AEI2.tbank_mcp_and_api'),
            apiHost: self::env(['TBANK_API_HOST'], '0.0.0.0'),
            apiPort: (int) self::env(['TBANK_API_PORT'], '8080'),
            defaultAccountId: $account !== '' ? $account : null,
            requestTimeout: (float) self::env(['TBANK_REQUEST_TIMEOUT'], '30'),
            restBaseUrlOverride: $override !== '' ? rtrim($override, '/') : null,
            mcpAllowedOrigins: self::env(['TBANK_MCP_ALLOWED_ORIGINS'], 'localhost,127.0.0.1,[::1]'),
            mcpRequireSession: self::boolEnv(['TBANK_MCP_REQUIRE_SESSION'], false),
            mcpSessionTtl: (int) self::env(['TBANK_MCP_SESSION_TTL'], '3600'),
            rateLimitEnabled: self::boolEnv(['TBANK_RATE_LIMIT'], true),
            rateLimitRps: (int) self::env(['TBANK_RATE_RPS'], '50'),
            rateLimitRpm: (int) self::env(['TBANK_RATE_RPM'], '1000'),
            rateLimitMinIntervalMs: (int) self::env(['TBANK_RATE_MIN_INTERVAL_MS'], '0'),
            rateLimitMaxWait: (float) self::env(['TBANK_RATE_MAX_WAIT'], '30'),
            rateLimitRetries: (int) self::env(['TBANK_RATE_RETRIES'], '3'),
        );
    }

    public function restBaseUrl(): string
    {
        if ($this->restBaseUrlOverride) {
            return $this->restBaseUrlOverride;
        }

        return $this->environment === 'sandbox' ? self::SANDBOX_REST : self::PROD_REST;
    }

    public function requireToken(): string
    {
        if ($this->token === '') {
            throw new ConfigException(
                'Missing T-Invest token. Set TBANK_INVEST_TOKEN (or INVEST_TOKEN / TINKOFF_API_TOKEN).',
            );
        }

        return $this->token;
    }

    public function maskedToken(): string
    {
        if ($this->token === '') {
            return '';
        }
        if (strlen($this->token) <= 8) {
            return '***';
        }

        return substr($this->token, 0, 3) . '…' . substr($this->token, -4);
    }

    /** @param list<string> $keys */
    private static function env(array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /** @param list<string> $keys */
    private static function boolEnv(array $keys, bool $default): bool
    {
        $raw = strtolower(self::env($keys, $default ? 'true' : 'false'));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeEnv(string $value): string
    {
        $lowered = strtolower(trim($value));
        if (in_array($lowered, ['prod', 'production', 'live'], true)) {
            return 'production';
        }
        if (in_array($lowered, ['sandbox', 'sand', 'demo', 'test'], true)) {
            return 'sandbox';
        }

        return $lowered !== '' ? $lowered : 'sandbox';
    }
}
