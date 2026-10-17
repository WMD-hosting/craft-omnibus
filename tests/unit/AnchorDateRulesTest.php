<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\helpers\AnchorDateRules;

final class AnchorDateRulesTest extends TestCase
{
    public function testNormalizesCpRowsAndConfigArraysAlike(): void
    {
        $fromCp = ['row0' => ['date' => ' 2025-05-02 ', 'productTypes' => 'food, drinks;cosmetics', 'relatedTo' => '12, 34 12'], 'row1' => ['date' => '', 'productTypes' => '', 'relatedTo' => '']];
        $fromConfig = [['date' => '2025-05-02', 'productTypes' => ['food', 'drinks', 'cosmetics'], 'relatedTo' => [12, '34']]];
        $expected = [['date' => '2025-05-02', 'productTypes' => ['food', 'drinks', 'cosmetics'], 'relatedTo' => [12, 34]]];

        self::assertSame($expected, AnchorDateRules::normalize($fromCp));
        self::assertSame($expected, AnchorDateRules::normalize($fromConfig));
        self::assertSame([], AnchorDateRules::normalize(''));
    }

    public function testErrorsNameTheBrokenRule(): void
    {
        $rules = AnchorDateRules::normalize([
            ['date' => '2025-05-02', 'productTypes' => 'food'],
            ['date' => '2.5.2025', 'productTypes' => 'drinks'],
            ['date' => '2025-02-30', 'relatedTo' => '5'],
            ['date' => '2025-05-02'],
        ]);

        self::assertSame([
            'Rule 2: "2.5.2025" is not a Y-m-d date.',
            'Rule 3: "2025-02-30" is not a Y-m-d date.',
            'Rule 4 names no product type and no related element.',
        ], AnchorDateRules::errors($rules));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $rules = AnchorDateRules::normalize([
            ['date' => '2025-05-02', 'productTypes' => 'food'],
            ['date' => '2025-06-01', 'relatedTo' => '7'],
            ['date' => '2025-07-01', 'productTypes' => 'tools', 'relatedTo' => '7'],
        ]);
        $asked = [];
        $related = function(int $rule) use (&$asked) {
            $asked[] = $rule;
            return [100 => true, 201 => true];
        };

        self::assertSame('2025-05-02', AnchorDateRules::match($rules, 'food', [100, 101], $related));
        self::assertSame([], $asked, 'a product type match needs no relation lookup');

        self::assertSame('2025-06-01', AnchorDateRules::match($rules, 'tools', [200, 201], $related));
        self::assertSame('2025-07-01', AnchorDateRules::match($rules, 'tools', [300], $related));
        self::assertNull(AnchorDateRules::match($rules, 'garden', [300], $related));
        self::assertNull(AnchorDateRules::match($rules, null, [300], $related));
    }
}
