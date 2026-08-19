<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Config;
use Tbank\Invest\DotEnv;
use Tbank\Invest\Exception\ConfigException;

final class ConfigTest extends TestCase
{
    public function testSandboxAndProductionUrls(): void
    {
        $sandbox = new Config(token: 't.abcde12345', environment: 'sandbox');
        self::assertSame(Config::SANDBOX_REST, $sandbox->restBaseUrl());
        self::assertSame('t.a…2345', $sandbox->maskedToken());

        $prod = new Config(token: 't.abcde12345', environment: 'production');
        self::assertSame(Config::PROD_REST, $prod->restBaseUrl());
    }

    public function testFromEnvAliases(): void
    {
        putenv('INVEST_TOKEN=t.fromalias');
        $_ENV['INVEST_TOKEN'] = 't.fromalias';
        putenv('TBANK_INVEST_ENV=prod');
        $_ENV['TBANK_INVEST_ENV'] = 'prod';
        $config = Config::fromEnv();
        self::assertSame('t.fromalias', $config->token);
        self::assertSame('production', $config->environment);
        putenv('INVEST_TOKEN');
        unset($_ENV['INVEST_TOKEN']);
        putenv('TBANK_INVEST_ENV');
        unset($_ENV['TBANK_INVEST_ENV']);
    }

    public function testRequireToken(): void
    {
        $this->expectException(ConfigException::class);
        (new Config())->requireToken();
    }

    public function testDotEnvParser(): void
    {
        $file = sys_get_temp_dir() . '/tbank-dotenv-' . uniqid() . '.env';
        file_put_contents($file, "FOO_TEST_KEY=bar\n# comment\nexport BAZ_TEST_KEY=\"qux\"\n");
        $prevFoo = getenv('FOO_TEST_KEY');
        $prevBaz = getenv('BAZ_TEST_KEY');
        putenv('FOO_TEST_KEY');
        putenv('BAZ_TEST_KEY');
        unset($_ENV['FOO_TEST_KEY'], $_ENV['BAZ_TEST_KEY']);
        DotEnv::load($file);
        self::assertSame('bar', getenv('FOO_TEST_KEY'));
        self::assertSame('qux', getenv('BAZ_TEST_KEY'));
        putenv('FOO_TEST_KEY' . ($prevFoo !== false ? '=' . $prevFoo : ''));
        putenv('BAZ_TEST_KEY' . ($prevBaz !== false ? '=' . $prevBaz : ''));
        unlink($file);
    }
}
