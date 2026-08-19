<?php

declare(strict_types=1);

namespace Tbank\Invest\Mcp;

use Tbank\Invest\Config;
use Tbank\Invest\Http\Request;
use Tbank\Invest\Http\Response;

final class StreamableHttp
{
    public function __construct(
        private readonly Protocol $protocol,
        private readonly SessionStore $sessions,
        private readonly Config $config,
    ) {
    }

    public function handle(Request $request): Response
    {
        $corsDenied = $this->rejectOrigin($request);
        if ($corsDenied !== null) {
            return $this->withCors($request, $corsDenied);
        }

        $response = match ($request->method) {
            'OPTIONS' => $this->options(),
            'POST' => $this->post($request),
            'GET' => $this->get($request),
            'DELETE' => $this->delete($request),
            default => Response::json([
                'error' => 'Method not allowed',
                'status_code' => 405,
                'code' => 'method_not_allowed',
            ], 405, ['Allow' => 'GET, POST, DELETE, OPTIONS']),
        };

        return $this->withCors($request, $response);
    }

    private function options(): Response
    {
        return Response::empty(204, [
            'Allow' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    private function post(Request $request): Response
    {
        if ($request->rawBody !== '' && $request->body === null) {
            return Response::json($this->rpcError(-32700, 'Parse error'), 400);
        }
        if (!is_array($request->body)) {
            return Response::json($this->rpcError(-32600, 'Invalid Request'), 400);
        }

        $batch = $this->isBatch($request->body);
        $messages = $batch ? $request->body : [$request->body];
        if ($messages === []) {
            return Response::json($this->rpcError(-32600, 'Invalid Request'), 400);
        }

        $requests = [];
        $hasNotificationOrResponse = false;
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                return Response::json($this->rpcError(-32600, 'Invalid Request'), 400);
            }
            if ($this->isRpcRequest($message)) {
                $requests[] = [$index, $message];
            } else {
                $hasNotificationOrResponse = true;
                $this->protocol->handle($message);
            }
        }

        $session = $this->resolveSession($request, $requests);
        if ($session['error'] !== null) {
            return $session['error'];
        }

        if ($requests === []) {
            return $hasNotificationOrResponse
                ? Response::empty(202, $session['headers'])
                : Response::json($this->rpcError(-32600, 'Invalid Request'), 400, $session['headers']);
        }

        $responses = [];
        foreach ($requests as [$index, $message]) {
            $handled = $this->protocol->handle($message);
            if ($handled !== null) {
                $responses[$index] = $handled;
            }
        }
        $payloads = array_values($responses);
        if ($payloads === []) {
            return Response::empty(202, $session['headers']);
        }

        if ($request->wantsSse()) {
            $body = '';
            foreach ($payloads as $i => $payload) {
                $body .= Sse::message($payload, $session['id'] . '-' . ($i + 1));
            }

            return Response::sse($body, 200, $session['headers']);
        }

        $body = ($batch || count($payloads) > 1) ? $payloads : $payloads[0];

        return Response::json($body, 200, $session['headers'] + [
            'Cache-Control' => 'no-cache, no-transform',
        ]);
    }

    private function get(Request $request): Response
    {
        if ($request->acceptHeader() !== '' && !$request->wantsSse()) {
            return Response::json([
                'error' => 'GET /mcp requires Accept: text/event-stream',
                'status_code' => 406,
                'code' => 'not_acceptable',
            ], 406);
        }

        $session = $this->resolveSession($request, []);
        if ($session['error'] !== null) {
            return $session['error'];
        }

        $body = Sse::retry(15000) . Sse::comment('tbank-mcp streamable-http connected');

        return Response::sse($body, 200, $session['headers']);
    }

    private function delete(Request $request): Response
    {
        $id = $request->mcpSessionId();
        if ($id === null) {
            return Response::json([
                'error' => 'Mcp-Session-Id is required to terminate a session',
                'status_code' => 400,
                'code' => 'session_required',
            ], 400);
        }
        if ($this->sessions->get($id) === null) {
            return Response::json([
                'error' => 'Unknown MCP session',
                'status_code' => 404,
                'code' => 'session_not_found',
            ], 404);
        }
        $this->sessions->delete($id);

        return Response::empty(204);
    }

    /**
     * @param list<array{0: int, 1: array<string, mixed>}> $requests
     * @return array{id: string, headers: array<string, string>, error: ?Response}
     */
    private function resolveSession(Request $request, array $requests): array
    {
        $empty = ['id' => '0', 'headers' => [], 'error' => null];
        $incoming = $request->mcpSessionId();
        $isInitialize = false;
        foreach ($requests as [, $message]) {
            if (($message['method'] ?? null) === 'initialize') {
                $isInitialize = true;
                break;
            }
        }

        if ($incoming !== null) {
            if ($this->sessions->get($incoming) === null) {
                return [
                    'id' => $incoming,
                    'headers' => [],
                    'error' => Response::json([
                        'error' => 'Unknown MCP session',
                        'status_code' => 404,
                        'code' => 'session_not_found',
                    ], 404),
                ];
            }

            return ['id' => $incoming, 'headers' => ['Mcp-Session-Id' => $incoming], 'error' => null];
        }

        if ($isInitialize) {
            $id = $this->sessions->create([
                'created' => time(),
                'protocolVersion' => $this->requestedProtocolVersion($requests),
            ]);

            return ['id' => $id, 'headers' => ['Mcp-Session-Id' => $id], 'error' => null];
        }

        if ($this->config->mcpRequireSession) {
            return [
                'id' => '0',
                'headers' => [],
                'error' => Response::json([
                    'error' => 'Mcp-Session-Id is required',
                    'status_code' => 400,
                    'code' => 'session_required',
                ], 400),
            ];
        }

        return $empty;
    }

    /**
     * @param list<array{0: int, 1: array<string, mixed>}> $requests
     */
    private function requestedProtocolVersion(array $requests): string
    {
        foreach ($requests as [, $message]) {
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];
            if (isset($params['protocolVersion'])) {
                return (string) $params['protocolVersion'];
            }
        }

        return '2025-03-26';
    }

    private function rejectOrigin(Request $request): ?Response
    {
        $origin = $request->header('Origin');
        if ($origin === null || $origin === '') {
            return null;
        }
        if ($this->originAllowed($origin)) {
            return null;
        }

        return Response::json([
            'error' => 'Origin not allowed',
            'status_code' => 403,
            'code' => 'forbidden_origin',
        ], 403);
    }

    private function originAllowed(string $origin): bool
    {
        $allow = trim($this->config->mcpAllowedOrigins);
        if ($allow === '*' || strcasecmp($allow, 'any') === 0) {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $candidates = is_string($host) && $host !== '' ? [$origin, $host] : [$origin];
        foreach (explode(',', $allow) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (strcasecmp($candidate, $item) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function withCors(Request $request, Response $response): Response
    {
        $origin = $request->header('Origin');
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID, Authorization',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
        ];
        $allow = trim($this->config->mcpAllowedOrigins);
        if ($allow === '*' || strcasecmp($allow, 'any') === 0) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } elseif ($origin && $this->originAllowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin';
        }

        return $response->withHeaders($headers);
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function isBatch(array $payload): bool
    {
        return array_is_list($payload) && $payload !== [] && is_array($payload[0] ?? null);
    }

    /** @param array<string, mixed> $message */
    private function isRpcRequest(array $message): bool
    {
        return isset($message['method']) && array_key_exists('id', $message);
    }

    /** @return array<string, mixed> */
    private function rpcError(int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
