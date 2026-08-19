<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Catalog;
use Tbank\Invest\InstrumentId;
use Tbank\Invest\InstrumentIdKind;

final class CatalogAndIdsTest extends TestCase
{
    public function testCatalogContainsCoreMethods(): void
    {
        $keys = array_map(static fn ($spec) => $spec->key(), Catalog::methods());
        self::assertContains('UsersService/GetAccounts', $keys);
        self::assertContains('MarketDataService/GetCandles', $keys);
        self::assertContains('OrdersService/PostOrder', $keys);
        self::assertTrue(Catalog::resolve('OrdersService', 'PostOrder')->mutating);
        self::assertGreaterThan(80, count(Catalog::methods()));
    }

    public function testResolveInterval(): void
    {
        self::assertSame('CANDLE_INTERVAL_HOUR', Catalog::resolveInterval('1h'));
        self::assertSame('CANDLE_INTERVAL_DAY', Catalog::resolveInterval('day'));
        self::assertSame('CANDLE_INTERVAL_5_MIN', Catalog::resolveInterval('CANDLE_INTERVAL_5_MIN'));
    }

    public function testClassifyUidFigiTicker(): void
    {
        $uid = InstrumentId::classify('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        self::assertSame(InstrumentIdKind::UID, $uid->kind);

        $figi = InstrumentId::classify('BBG004730N88');
        self::assertSame(InstrumentIdKind::FIGI, $figi->kind);

        $ticker = InstrumentId::classify('SBER', 'TQBR');
        self::assertSame(InstrumentIdKind::TICKER, $ticker->kind);
        self::assertSame(['idType' => InstrumentIdKind::TICKER, 'id' => 'SBER', 'classCode' => 'TQBR'], InstrumentId::byRequest($ticker));

        $query = InstrumentId::classify('Сбербанк');
        self::assertSame(InstrumentIdKind::QUERY, $query->kind);
    }
}
