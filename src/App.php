<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Http\Kernel;
use Tbank\Invest\Mcp\Protocol;
use Tbank\Invest\Mcp\StdioServer;
use Tbank\Invest\Mcp\Toolset;

final class App
{
    public static function boot(?string $envFile = null): void
    {
        if ($envFile) {
            DotEnv::load($envFile);
        } else {
            DotEnv::loadNearest();
        }
    }

    public static function service(): Service
    {
        self::boot();

        return Service::fromEnv();
    }

    public static function kernel(): Kernel
    {
        return Kernel::create(self::service());
    }

    public static function runApi(): never
    {
        $config = self::service()->config;
        $router = dirname(__DIR__) . '/public/index.php';
        $host = $config->apiHost;
        $port = $config->apiPort;
        $public = dirname(__DIR__) . '/public';
        fwrite(STDERR, "T-Bank Invest API http://{$host}:{$port}  env={$config->environment}\n");
        fwrite(STDERR, "Streamable HTTP MCP  http://{$host}:{$port}/mcp\n");
        passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($host . ':' . $port) . ' -t ' . escapeshellarg($public) . ' ' . escapeshellarg($router), $code);
        exit($code);
    }

    public static function runMcp(): never
    {
        $service = self::service();
        $toolset = new Toolset($service);
        $protocol = new Protocol($toolset, $service);
        (new StdioServer($protocol))->run();
        exit(0);
    }
}
