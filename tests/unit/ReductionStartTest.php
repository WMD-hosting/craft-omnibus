<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use DateTime;
use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\helpers\ReductionStart;

final class ReductionStartTest extends TestCase
{
    private DateTime $now;

    protected function setUp(): void
    {
        $this->now = new DateTime('2026-10-17 10:00:00');
    }

    public function testBackfillRowOlderThanTheWindowWithAPromotionalPrice(): void
    {
        // The 1979 case: 999.00 backfilled on 10.9., selling at 839 since an unknown day.
        $rows = [['price' => 999.0, 'date' => new DateTime('2026-09-10 12:00:00')]];

        self::assertEquals($this->now, ReductionStart::resolve($rows, 839.0, null, $this->now));
    }

    public function testAPromotionWithAKnownStartClosesTheWindowThere(): void
    {
        $rows = [['price' => 999.0, 'date' => new DateTime('2026-09-10 12:00:00')]];
        $start = new DateTime('2026-10-01 00:00:00');

        self::assertEquals($start, ReductionStart::resolve($rows, 839.0, $start, $this->now));
    }

    public function testAReductionRecordedInTheHistoryStartsAtItsRow(): void
    {
        $reduced = new DateTime('2026-10-10 08:00:00');
        $rows = [
            ['price' => 79.0, 'date' => $reduced],
            ['price' => 99.0, 'date' => new DateTime('2026-09-10 12:00:00')],
        ];

        self::assertEquals($reduced, ReductionStart::resolve($rows, 79.0, null, $this->now));
        // The selling price unknown: the history alone still shows the reduction.
        self::assertEquals($reduced, ReductionStart::resolve($rows, null, null, $this->now));
    }

    public function testAPriceRaisedThenPromotedStartsAtThePromotion(): void
    {
        // 900 -> 1000 on 5.10., then a promotion at 950: the raise is not the
        // reduction, so the window before now still sees the 900.
        $rows = [
            ['price' => 1000.0, 'date' => new DateTime('2026-10-05 09:00:00')],
            ['price' => 900.0, 'date' => new DateTime('2026-09-10 12:00:00')],
        ];

        self::assertEquals($this->now, ReductionStart::resolve($rows, 950.0, null, $this->now));
    }

    public function testAPromotionOnTopOfARecordedReductionStartsAtThePromotion(): void
    {
        $start = new DateTime('2026-10-15 00:00:00');
        $rows = [
            ['price' => 79.0, 'date' => new DateTime('2026-10-10 08:00:00')],
            ['price' => 99.0, 'date' => new DateTime('2026-09-10 12:00:00')],
        ];

        self::assertEquals($start, ReductionStart::resolve($rows, 69.0, $start, $this->now));
    }

    public function testNoReductionInForceIsTheWindowBeforeNow(): void
    {
        $raised = [
            ['price' => 1000.0, 'date' => new DateTime('2026-10-05 09:00:00')],
            ['price' => 900.0, 'date' => new DateTime('2026-09-10 12:00:00')],
        ];
        $single = [['price' => 999.0, 'date' => new DateTime('2026-09-10 12:00:00')]];

        self::assertEquals($this->now, ReductionStart::resolve($raised, 1000.0, null, $this->now));
        self::assertEquals($this->now, ReductionStart::resolve($single, 999.0, null, $this->now));
        self::assertEquals($this->now, ReductionStart::resolve($single, 998.999, null, $this->now), 'rounding is not a reduction');
        self::assertEquals($this->now, ReductionStart::resolve([], 10.0, null, $this->now));
    }

    public function testPromotionStartIsTheEarliestDateOfTheRowsGivingTheSalePrice(): void
    {
        $prices = [
            ['price' => 839.0, 'dateFrom' => new DateTime('2026-10-10')],
            ['price' => 839.0, 'dateFrom' => new DateTime('2026-10-01')],
            ['price' => 799.0, 'dateFrom' => new DateTime('2026-09-01')],
        ];

        self::assertEquals(new DateTime('2026-10-01'), ReductionStart::promotionStart($prices, 839.0));
    }

    public function testPromotionStartIsUnknownWithoutADatedRow(): void
    {
        // Commerce's row for a typed promotional price has no dateFrom.
        self::assertNull(ReductionStart::promotionStart([['price' => 839.0, 'dateFrom' => null]], 839.0));
        self::assertNull(ReductionStart::promotionStart([
            ['price' => 839.0, 'dateFrom' => new DateTime('2026-10-01')],
            ['price' => 839.0, 'dateFrom' => null],
        ], 839.0));
        self::assertNull(ReductionStart::promotionStart([['price' => 799.0, 'dateFrom' => new DateTime('2026-10-01')]], 839.0));
        self::assertNull(ReductionStart::promotionStart([], 839.0));
    }
}
