<?php

declare(strict_types=1);

namespace Tbank\Invest;

/**
 * Official unary REST quotas: https://developer.tbank.ru/invest/intro/intro/limits
 *
 * T-Bank also recommends staying under 50 requests/second from one IP
 * and 1000/minute overall. Some methods have an explicit per-second cap
 * (PostOrder = 15/s). Others are derived as ceil(perMinute / 60).
 */
final class QuotaTable
{
    /** @var array<string, array{rpm: int, rps?: int}> */
    private const array SERVICES = [
        'InstrumentsService' => ['rpm' => 200],
        'UsersService' => ['rpm' => 100],
        'OperationsService' => ['rpm' => 200],
        'MarketDataService' => ['rpm' => 600],
        'StopOrdersService' => ['rpm' => 50],
        'SandboxService' => ['rpm' => 200],
        'OrdersService' => ['rpm' => 100],
        'SignalService' => ['rpm' => 100],
    ];

    /** @var array<string, array{rpm: int, rps?: int}> */
    private const array METHODS = [
        'InstrumentsService/Bonds' => ['rpm' => 15],
        'InstrumentsService/Shares' => ['rpm' => 15],
        'InstrumentsService/OptionsBy' => ['rpm' => 15],
        'InstrumentsService/Futures' => ['rpm' => 15],
        'InstrumentsService/Etfs' => ['rpm' => 15],
        'InstrumentsService/GetAssets' => ['rpm' => 15],
        'OperationsService/GetBrokerReport' => ['rpm' => 5],
        'MarketDataService/GetHistory' => ['rpm' => 30],
        'OrdersService/GetOrders' => ['rpm' => 200],
        'OrdersService/PostOrder' => ['rpm' => 900, 'rps' => 15],
        'OrdersService/PostOrderAsync' => ['rpm' => 600],
        'OrdersService/CancelOrder' => ['rpm' => 300],
        'OrdersService/ReplaceOrder' => ['rpm' => 300],
        'StopOrdersService/GetStopOrders' => ['rpm' => 60],
        'SandboxService/PostSandboxOrder' => ['rpm' => 900, 'rps' => 15],
        'SandboxService/PostSandboxOrderAsync' => ['rpm' => 600],
        'SandboxService/GetSandboxOrders' => ['rpm' => 200],
    ];

    /**
     * @return list<array{key: string, max: int, window: float}>
     */
    public static function windows(string $service, string $method, Config $config): array
    {
        $buckets = [];
        if ($config->rateLimitRps > 0) {
            $buckets[] = ['key' => 'global:1s', 'max' => $config->rateLimitRps, 'window' => 1.0];
        }
        if ($config->rateLimitRpm > 0) {
            $buckets[] = ['key' => 'global:1m', 'max' => $config->rateLimitRpm, 'window' => 60.0];
        }
        if ($config->rateLimitMinIntervalMs > 0) {
            $buckets[] = [
                'key' => 'global:gap',
                'max' => 1,
                'window' => $config->rateLimitMinIntervalMs / 1000.0,
            ];
        }

        $methodKey = $service . '/' . $method;
        $spec = self::METHODS[$methodKey] ?? self::SERVICES[$service] ?? ['rpm' => 100];
        $prefix = isset(self::METHODS[$methodKey]) ? "m:{$methodKey}" : "svc:{$service}";
        $buckets[] = ['key' => "{$prefix}:1s", 'max' => self::rps($spec), 'window' => 1.0];
        $buckets[] = ['key' => "{$prefix}:1m", 'max' => $spec['rpm'], 'window' => 60.0];

        return $buckets;
    }

    /** @param array{rpm: int, rps?: int} $spec */
    private static function rps(array $spec): int
    {
        if (isset($spec['rps'])) {
            return max(1, $spec['rps']);
        }

        return max(1, (int) ceil($spec['rpm'] / 60));
    }
}
