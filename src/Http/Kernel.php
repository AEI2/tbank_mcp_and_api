<?php

declare(strict_types=1);

namespace Tbank\Invest\Http;

use Tbank\Invest\Config;
use Tbank\Invest\Exception\TInvestException;
use Tbank\Invest\Mcp\FileSessionStore;
use Tbank\Invest\Mcp\Protocol;
use Tbank\Invest\Mcp\SessionStore;
use Tbank\Invest\Mcp\StreamableHttp;
use Tbank\Invest\Mcp\Toolset;
use Tbank\Invest\Service;

final class Kernel
{
    public function __construct(
        public readonly Service $service,
        public readonly Config $config,
        private readonly Router $router,
        private readonly Protocol $protocol,
    ) {
    }

    public static function create(Service $service, ?SessionStore $sessions = null): self
    {
        $router = new Router();
        $toolset = new Toolset($service);
        $protocol = new Protocol($toolset, $service);
        $sessions ??= new FileSessionStore(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tbank-mcp-sessions',
            $service->config->mcpSessionTtl,
        );
        $streamable = new StreamableHttp($protocol, $sessions, $service->config);
        Routes::register($router, $service, $toolset, $streamable);

        return new self($service, $service->config, $router, $protocol);
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (TInvestException $e) {
            return Response::json($e->toArray(), $e->statusCode);
        } catch (\Throwable $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'status_code' => 500,
                'code' => 'internal_error',
            ], 500);
        }
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }
}
