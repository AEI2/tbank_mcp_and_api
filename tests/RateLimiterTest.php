<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Client;
use Tbank\Invest\Config;
use Tbank\Invest\Exception\RateLimitException;
use Tbank\Invest\FakeClock;
use Tbank\Invest\QuotaTable;
use Tbank\Invest\SlidingWindowLimiter;

final class RateLimiterTest extends TestCase
{
    public function testGlobalOneRequestPerSecondQueues(): void
    {
        $clock = new FakeClock(1000.0);
        $config = new Config(token: 't.testtokenvalue', rateLimitRps: 1, rateLimitRpm: 1000, rateLimitMaxWait: 5);
        $limiter = SlidingWindowLimiter::memory($config, $clock);
        $limiter->acquire('UsersService', 'GetAccounts');
        $limiter->acquire('UsersService', 'GetInfo');
        self::assertEqualsWithDelta(1.0, $clock->slept, 0.001);
        self::assertEqualsWithDelta(1001.0, $clock->now, 0.001);
    }

    public function testPostOrderUsesFifteenPerSecond(): void
    {
        $clock = new FakeClock();
        $config = new Config(token: 't.testtokenvalue', rateLimitRps: 50, rateLimitRpm: 1000, rateLimitMaxWait: 5);
        $windows = QuotaTable::windows('OrdersService', 'PostOrder', $config);
        $methodSecond = null;
        foreach ($windows as $window) {
            if ($window['key'] === 'm:OrdersService/PostOrder:1s') {
                $methodSecond = $window;
            }
        }
        self::assertNotNull($methodSecond);
        self::assertSame(15, $methodSecond['max']);

        $limiter = SlidingWindowLimiter::memory($config, $clock);
        for ($i = 0; $i < 15; $i++) {
            $limiter->acquire('OrdersService', 'PostOrder');
        }
        self::assertSame(0.0, $clock->slept);
        $limiter->acquire('OrdersService', 'PostOrder');
        self::assertEqualsWithDelta(1.0, $clock->slept, 0.001);
    }

    public function testThrowsWhenQueueWaitExceedsMax(): void
    {
        $clock = new FakeClock();
        $config = new Config(token: 't.testtokenvalue', rateLimitRps: 1, rateLimitRpm: 1000, rateLimitMaxWait: 0.01);
        $limiter = SlidingWindowLimiter::memory($config, $clock);
        $limiter->acquire('UsersService', 'GetAccounts');
        $this->expectException(RateLimitException::class);
        $limiter->acquire('UsersService', 'GetAccounts');
    }

    public function testClientRetriesRateLimitThenSucceeds(): void
    {
        $clock = new FakeClock();
        $transport = (new FakeTransport())
            ->queue(429, ['code' => 8, 'message' => 'rate limit'], ['x-ratelimit-reset' => '2'])
            ->queue(200, ['accounts' => [['id' => 'a1']]]);
        $config = new Config(token: 't.testtokenvalue', rateLimitRetries: 3);
        $client = new Client($config, $transport, null, $clock);
        $data = $client->call('UsersService', 'GetAccounts', []);
        self::assertSame('a1', $data['accounts'][0]['id']);
        self::assertCount(2, $transport->requests);
        self::assertEqualsWithDelta(2.0, $clock->slept, 0.001);
    }

    public function testShareListHasTightMinuteQuota(): void
    {
        $config = new Config(token: 't.testtokenvalue');
        $windows = QuotaTable::windows('InstrumentsService', 'Shares', $config);
        $found = false;
        foreach ($windows as $window) {
            if ($window['key'] === 'm:InstrumentsService/Shares:1m') {
                self::assertSame(15, $window['max']);
                $found = true;
            }
        }
        self::assertTrue($found);
    }
}
