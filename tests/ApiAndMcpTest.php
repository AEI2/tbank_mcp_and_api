<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Client;
use Tbank\Invest\Config;
use Tbank\Invest\Http\Kernel;
use Tbank\Invest\Http\Request;
use Tbank\Invest\Mcp\Protocol;
use Tbank\Invest\Mcp\Toolset;
use Tbank\Invest\Service;

final class ApiAndMcpTest extends TestCase
{
    private function kernel(FakeTransport $transport, bool $allowTrading = false): Kernel
    {
        $config = new Config(token: 't.testtokenvalue', environment: 'sandbox', allowTrading: $allowTrading);
        $service = new Service(new Client($config, $transport), $config);

        return Kernel::create($service);
    }

    public function testHealthAndCatalogRoutes(): void
    {
        $kernel = $this->kernel(new FakeTransport());
        $health = $kernel->handle(new Request('GET', '/health'));
        self::assertSame(200, $health->status);
        self::assertSame('ok', $health->body['status']);

        $catalog = $kernel->handle(new Request('GET', '/v1/catalog', ['group' => 'Users']));
        self::assertGreaterThan(0, $catalog->body['count']);
        self::assertSame('UsersService', $catalog->body['methods'][0]['service']);
    }

    public function testProxyAndAccounts(): void
    {
        $transport = (new FakeTransport())->queue(200, ['accounts' => [['id' => 'a1']]]);
        $resp = $this->kernel($transport)->handle(new Request(
            'POST',
            '/v1/tinvest/UsersService/GetAccounts',
            [],
            ['body' => []],
        ));
        self::assertSame(200, $resp->status);
        self::assertSame('a1', $resp->body['accounts'][0]['id']);
    }

    public function testTradingBlockedViaHttp(): void
    {
        $resp = $this->kernel(new FakeTransport())->handle(new Request(
            'POST',
            '/v1/accounts/acc-1/orders',
            [],
            ['instrument' => 'BBG004730N88', 'quantity' => 1, 'direction' => 'buy', 'order_type' => 'market'],
        ));
        self::assertSame(403, $resp->status);
        self::assertSame('trading_disabled', $resp->body['code']);
    }

    public function testMcpInitializeAndTools(): void
    {
        $config = new Config(token: 't.testtokenvalue');
        $service = new Service(new Client($config, new FakeTransport()), $config);
        $protocol = new Protocol(new Toolset($service), $service);

        $init = $protocol->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test']],
        ]);
        self::assertSame('2025-03-26', $init['result']['protocolVersion']);
        self::assertSame('tbank-mcp-and-api', $init['result']['serverInfo']['name']);

        $list = $protocol->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
        $names = array_column($list['result']['tools'], 'name');
        self::assertContains('tbank_call', $names);
        self::assertContains('tbank_portfolio', $names);
        self::assertContains('tbank_candles', $names);
        self::assertGreaterThan(30, count($names));
    }

    public function testMcpToolCall(): void
    {
        $transport = (new FakeTransport())->queue(200, ['accounts' => [['id' => 'mcp-1']]]);
        $config = new Config(token: 't.testtokenvalue');
        $service = new Service(new Client($config, $transport), $config);
        $protocol = new Protocol(new Toolset($service), $service);

        $result = $protocol->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'tbank_accounts', 'arguments' => []],
        ]);
        $payload = json_decode($result['result']['content'][0]['text'], true);
        self::assertSame('mcp-1', $payload['accounts'][0]['id']);
        self::assertFalse($result['result']['isError']);
    }

    public function testMcpHttpEndpoint(): void
    {
        $transport = (new FakeTransport())->queue(200, ['info' => true]);
        $resp = $this->kernel($transport)->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'tbank_user', 'arguments' => []]],
        ));
        self::assertSame(200, $resp->status);
        self::assertArrayHasKey('result', $resp->body);
    }

    public function testNotFound(): void
    {
        $resp = $this->kernel(new FakeTransport())->handle(new Request('GET', '/nope'));
        self::assertSame(404, $resp->status);
    }
}
