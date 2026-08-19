<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Client;
use Tbank\Invest\Config;
use Tbank\Invest\Http\Kernel;
use Tbank\Invest\Http\Request;
use Tbank\Invest\Mcp\MemorySessionStore;
use Tbank\Invest\Service;

final class StreamableHttpTest extends TestCase
{
    private function kernel(?FakeTransport $transport = null, bool $requireSession = false): Kernel
    {
        $config = new Config(
            token: 't.testtokenvalue',
            environment: 'sandbox',
            mcpRequireSession: $requireSession,
        );
        $service = new Service(new Client($config, $transport ?? new FakeTransport()), $config);

        return Kernel::create($service, new MemorySessionStore());
    }

    public function testInitializeAssignsSessionAndCanStream(): void
    {
        $resp = $this->kernel()->handle(new Request(
            'POST',
            '/mcp',
            [],
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-03-26',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test'],
                ],
            ],
            [],
            ['Accept' => 'application/json, text/event-stream'],
        ));

        self::assertSame(200, $resp->status);
        self::assertSame('text/event-stream', $resp->headers['Content-Type']);
        self::assertArrayHasKey('Mcp-Session-Id', $resp->headers);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $resp->headers['Mcp-Session-Id']);
        self::assertStringContainsString('event: message', (string) $resp->body);
        self::assertStringContainsString('"protocolVersion":"2025-03-26"', (string) $resp->body);
        self::assertSame('Mcp-Session-Id', $resp->headers['Access-Control-Expose-Headers']);
    }

    public function testNotificationReturns202(): void
    {
        $resp = $this->kernel()->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []],
        ));
        self::assertSame(202, $resp->status);
        self::assertNull($resp->body);
    }

    public function testJsonFallbackWithoutAccept(): void
    {
        $transport = (new FakeTransport())->queue(200, ['ok' => true]);
        $resp = $this->kernel($transport)->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'tbank_user', 'arguments' => []]],
        ));
        self::assertSame(200, $resp->status);
        self::assertIsArray($resp->body);
        self::assertArrayHasKey('result', $resp->body);
    }

    public function testGetOpensSseStream(): void
    {
        $resp = $this->kernel()->handle(new Request('GET', '/mcp', [], null, [], [
            'Accept' => 'text/event-stream',
        ]));
        self::assertSame(200, $resp->status);
        self::assertSame('text/event-stream', $resp->headers['Content-Type']);
        self::assertStringContainsString('streamable-http connected', (string) $resp->body);
    }

    public function testGetRejectsJsonOnlyAccept(): void
    {
        $resp = $this->kernel()->handle(new Request('GET', '/mcp', [], null, [], [
            'Accept' => 'application/json',
        ]));
        self::assertSame(406, $resp->status);
    }

    public function testDeleteSession(): void
    {
        $kernel = $this->kernel();
        $init = $kernel->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']],
            [],
            ['Accept' => 'application/json'],
        ));
        $session = $init->headers['Mcp-Session-Id'];
        self::assertSame('application/json; charset=utf-8', $init->headers['Content-Type']);
        self::assertSame('2025-03-26', $init->body['result']['protocolVersion']);

        $deleted = $kernel->handle(new Request('DELETE', '/mcp', [], null, [], [
            'Mcp-Session-Id' => $session,
        ]));
        self::assertSame(204, $deleted->status);

        $again = $kernel->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
            [],
            ['Mcp-Session-Id' => $session],
        ));
        self::assertSame(404, $again->status);
        self::assertSame('session_not_found', $again->body['code']);
    }

    public function testUnknownSession(): void
    {
        $resp = $this->kernel()->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            [],
            ['Mcp-Session-Id' => str_repeat('ab', 16)],
        ));
        self::assertSame(404, $resp->status);
    }

    public function testOriginRejected(): void
    {
        $resp = $this->kernel()->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            [],
            ['Origin' => 'https://evil.example'],
        ));
        self::assertSame(403, $resp->status);
        self::assertSame('forbidden_origin', $resp->body['code']);
    }

    public function testLocalOriginAllowed(): void
    {
        $resp = $this->kernel()->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            [],
            ['Origin' => 'http://localhost:1234', 'Accept' => 'application/json'],
        ));
        self::assertSame(200, $resp->status);
        self::assertSame('http://localhost:1234', $resp->headers['Access-Control-Allow-Origin']);
    }

    public function testBatchAndOptions(): void
    {
        $resp = $this->kernel()->handle(new Request(
            'POST',
            '/mcp',
            [],
            [
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]],
            ],
            [],
            ['Accept' => 'application/json'],
        ));
        self::assertSame(200, $resp->status);
        self::assertIsArray($resp->body);
        self::assertTrue(array_is_list($resp->body));
        self::assertSame(1, $resp->body[0]['id']);

        $options = $this->kernel()->handle(new Request('OPTIONS', '/mcp'));
        self::assertSame(204, $options->status);
        self::assertStringContainsString('POST', $options->headers['Allow']);
    }

    public function testParseError(): void
    {
        $resp = $this->kernel()->handle(new Request('POST', '/mcp', [], null, [], [], '{not-json'));
        self::assertSame(400, $resp->status);
        self::assertSame(-32700, $resp->body['error']['code']);
    }

    public function testRequireSession(): void
    {
        $resp = $this->kernel(requireSession: true)->handle(new Request(
            'POST',
            '/mcp',
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ));
        self::assertSame(400, $resp->status);
        self::assertSame('session_required', $resp->body['code']);
    }
}
