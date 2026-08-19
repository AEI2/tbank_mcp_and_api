<?php

declare(strict_types=1);

namespace Tbank\Invest\Tests;

use PHPUnit\Framework\TestCase;
use Tbank\Invest\Money;
use Tbank\Invest\Normalize;

final class MoneyTest extends TestCase
{
    public function testFromNumberAndBack(): void
    {
        $q = Money::fromNumber(123.45);
        self::assertSame('123', $q['units']);
        self::assertSame(450000000, $q['nano']);
        self::assertEqualsWithDelta(123.45, Money::toFloat($q), 1e-9);
    }

    public function testNegativeFractional(): void
    {
        $q = Money::fromNumber(-0.5);
        self::assertSame('0', $q['units']);
        self::assertSame(-500000000, $q['nano']);
        self::assertEqualsWithDelta(-0.5, Money::toFloat($q), 1e-9);
    }

    public function testNormalizeAddsValue(): void
    {
        $data = Normalize::values([
            'price' => ['units' => '10', 'nano' => 250000000, 'currency' => 'rub'],
        ]);
        self::assertSame(10.25, $data['price']['value']);
        self::assertSame('rub', $data['price']['currency']);
    }

    public function testDropNulls(): void
    {
        self::assertSame(['a' => 1], Normalize::dropNulls(['a' => 1, 'b' => null]));
    }
}
