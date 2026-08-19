<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Client;
use Tbank\Invest\Config;
use Tbank\Invest\Exception\TradingDisabledException;
use Tbank\Invest\Service;

final class ServiceClientTest extends TestCase
{
    private function service(FakeTransport $transport, bool $allowTrading = false): Service
    {
        $config = new Config(token: 't.testtokenvalue', environment: 'sandbox', allowTrading: $allowTrading);
        $client = new Client($config, $transport);

        return new Service($client, $config);
    }

    public function testGetAccountsAndCatalog(): void
    {
        $transport = (new FakeTransport())->queue(200, ['accounts' => [['id' => 'acc-1', 'status' => 'ACCOUNT_STATUS_OPEN']]]);
        $service = $this->service($transport);
        $data = $service->getAccounts();
        self::assertSame('acc-1', $data['accounts'][0]['id']);
        self::assertStringContainsString('UsersService/GetAccounts', $transport->lastUrl());
        self::assertGreaterThan(0, $service->catalog()['count']);
        self::assertSame('sandbox', $service->serverInfo()['environment']);
    }

    public function testFindInstrumentLimit(): void
    {
        $transport = (new FakeTransport())->queue(200, [
            'instruments' => [
                ['ticker' => 'SBER', 'uid' => 'u1'],
                ['ticker' => 'SBERP', 'uid' => 'u2'],
            ],
        ]);
        $data = $this->service($transport)->findInstrument('SBER', limit: 1);
        self::assertSame(1, $data['count']);
        self::assertSame('SBER', $data['instruments'][0]['ticker']);
    }

    public function testCandlesResolveFigiWithoutSearch(): void
    {
        $transport = (new FakeTransport())->queue(200, ['candles' => []]);
        $this->service($transport)->getCandles('BBG004730N88', interval: '1h');
        self::assertSame('BBG004730N88', $transport->lastBody()['instrumentId']);
        self::assertSame('CANDLE_INTERVAL_HOUR', $transport->lastBody()['interval']);
    }

    public function testPostOrderBlockedWhenTradingDisabled(): void
    {
        $this->expectException(TradingDisabledException::class);
        $this->service(new FakeTransport())->postOrder('BBG004730N88', 1, 'buy', 'market', 'acc-1');
    }

    public function testPostOrderWhenEnabled(): void
    {
        $transport = (new FakeTransport())->queue(200, ['orderId' => 'oid']);
        $data = $this->service($transport, true)->postOrder('BBG004730N88', 1, 'buy', 'limit', 'acc-1', 250.5);
        self::assertSame('oid', $data['orderId']);
        $body = $transport->lastBody();
        self::assertSame('ORDER_DIRECTION_BUY', $body['direction']);
        self::assertSame('ORDER_TYPE_LIMIT', $body['orderType']);
        self::assertSame('250', $body['price']['units']);
        self::assertStringContainsString('OrdersService/PostOrder', $transport->lastUrl());
    }

    public function testSandboxPayInMoneyValue(): void
    {
        $transport = (new FakeTransport())->queue(200, ['balance' => ['units' => '1000', 'nano' => 0, 'currency' => 'rub']]);
        $data = $this->service($transport, true)->sandboxPayIn('sbx', 1000.5, 'RUB');
        self::assertSame(1000.0, $data['balance']['value']);
        $amount = $transport->lastBody()['amount'];
        self::assertSame('rub', $amount['currency']);
        self::assertSame('1000', $amount['units']);
        self::assertSame(500000000, $amount['nano']);
    }

    public function testUnknownMethod(): void
    {
        $this->expectExceptionMessage('Unknown T-Invest method');
        $this->service(new FakeTransport())->call('NopeService', 'Nope');
    }

    public function testHttpErrorMapped(): void
    {
        $transport = (new FakeTransport())->queue(401, ['message' => 'Unauthenticated', 'code' => 16], ['x-tracking-id' => 'tr-1']);
        try {
            $this->service($transport)->getUserInfo();
            self::fail('expected exception');
        } catch (\Tbank\Invest\Exception\TInvestException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('tr-1', $e->trackingId);
            self::assertSame('Unauthenticated', $e->getMessage());
        }
    }
}
