<?php

declare(strict_types=1);

namespace Tbank\Invest\Mcp;

use Tbank\Invest\Exception\TInvestException;
use Tbank\Invest\Service;
use Tbank\Invest\Version;

final class Protocol
{
    private const array SUPPORTED_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

    public function __construct(
        private readonly Toolset $toolset,
        private readonly Service $service,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        $jsonrpc = $message['jsonrpc'] ?? '2.0';
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($method !== '' && !array_key_exists('id', $message)) {
            if ($method === 'notifications/initialized' || str_starts_with($method, 'notifications/')) {
                return null;
            }
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->toolset->definitions()],
                'tools/call' => $this->callTool($params),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => throw new TInvestException("Unknown MCP method '{$method}'", 404, null, null, 'unknown_mcp_method'),
            };
        } catch (TInvestException $e) {
            if ($method === 'tools/call') {
                return [
                    'jsonrpc' => $jsonrpc,
                    'id' => $id,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => json_encode($e->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                        'isError' => true,
                    ],
                ];
            }

            return [
                'jsonrpc' => $jsonrpc,
                'id' => $id,
                'error' => [
                    'code' => $e->statusCode === 404 ? -32601 : -32000,
                    'message' => $e->getMessage(),
                    'data' => $e->toArray(),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'jsonrpc' => $jsonrpc,
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage(),
                ],
            ];
        }

        return [
            'jsonrpc' => $jsonrpc,
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '2025-03-26');
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : '2025-03-26';

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => Version::NAME,
                'version' => Version::NUMBER,
            ],
            'instructions' => 'T-Bank Invest MCP. Use tbank_call for any T-Invest method, or high-level tools like tbank_portfolio, tbank_candles, tbank_search_instruments. Trading tools require TBANK_ALLOW_TRADING=true. Environment: ' . $this->service->config->environment . '.',
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            throw new TInvestException('Tool name is required', 400, null, null, 'invalid_tool');
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $data = $this->toolset->call($name, $arguments);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $data,
            'isError' => false,
        ];
    }
}
