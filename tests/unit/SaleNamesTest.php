<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\helpers\SaleNames;

final class SaleNamesTest extends TestCase
{
    public function testPicksTheRuleWhosePriceIsTheSalePrice(): void
    {
        $candidates = [
            ['price' => 90.0, 'promotional' => true, 'name' => 'Jesenska akcija'],
            ['price' => 80.0, 'promotional' => true, 'name' => 'Black Friday'],
        ];

        self::assertSame('Black Friday', SaleNames::pick($candidates, 80.0));
        self::assertSame('Jesenska akcija', SaleNames::pick($candidates, 89.999));
    }

    public function testPromotionalRuleBeatsAStandardOneAtTheSamePrice(): void
    {
        $candidates = [
            ['price' => 50.0, 'promotional' => false, 'name' => 'A cjenik'],
            ['price' => 50.0, 'promotional' => true, 'name' => 'Z sniženje'],
            ['price' => 50.0, 'promotional' => true, 'name' => 'M sniženje'],
        ];

        self::assertSame('M sniženje', SaleNames::pick($candidates, 50.0));
    }

    public function testNoRuleAtThatPriceIsNull(): void
    {
        self::assertNull(SaleNames::pick([['price' => 70.0, 'promotional' => true, 'name' => 'Akcija']], 65.0));
        self::assertNull(SaleNames::pick([['price' => 65.0, 'promotional' => true, 'name' => '  ']], 65.0));
        self::assertNull(SaleNames::pick([], 65.0));
    }
}
