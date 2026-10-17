<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\helpers\PriceImport;

final class PriceImportTest extends TestCase
{
    /**
     * @return array<string, array{string, float|null}>
     */
    public static function prices(): array
    {
        return [
            'plain' => ['12', 12.0],
            'dot decimal' => ['12.99', 12.99],
            'comma decimal' => ['12,99', 12.99],
            'one decimal' => ['0,5', 0.5],
            'hr thousands' => ['1.234,56', 1234.56],
            'en thousands' => ['1,234.56', 1234.56],
            'groups only' => ['1.234.567', 1234567.0],
            'currency and nbsp' => ["1\u{00A0}099,00 €", 1099.0],
            'EUR suffix' => ['5,00 EUR', 5.0],
            'ambiguous dot' => ['1.234', null],
            'ambiguous comma' => ['1,234', null],
            'negative' => ['-3,00', null],
            'text' => ['cijena', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('prices')]
    public function testPrice(string $raw, ?float $expected): void
    {
        self::assertSame($expected, PriceImport::price($raw));
    }

    public function testParseSkipsBomHeaderAndBlankLines(): void
    {
        $csv = "\xEF\xBB\xBFsku;cijena\r\nA-1;12,99\r\n\r\nB-2;1.099,00\nA-1;12.99\n";

        self::assertSame(
            ['prices' => ['A-1' => 12.99, 'B-2' => 1099.0], 'errors' => []],
            PriceImport::parse($csv),
        );
    }

    public function testParseReportsBadLinesAndConflictingDuplicates(): void
    {
        $result = PriceImport::parse("A-1;10,00\nB-2;\n;5,00\nA-1;11,00\nC-3;1.234\n");

        self::assertSame(['A-1' => 10.0], $result['prices']);
        self::assertSame([
            'Line 2: "" is not a price.',
            'Line 3: no SKU.',
            'Line 4: SKU "A-1" appears twice with different prices.',
            'Line 5: "1.234" is not a price.',
        ], $result['errors']);
    }

    public function testDetectsTabAndCommaDelimiters(): void
    {
        self::assertSame(['A-1' => 3.5], PriceImport::parse("A-1\t3,50\n")['prices']);
        self::assertSame(['A-1' => 3.5], PriceImport::parse("sku,price\nA-1,3.50\n")['prices']);
        self::assertSame(['A-1' => 3.5], PriceImport::parse("A-1,\"3,50\"\n")['prices']);
    }

    public function testUnquotedDecimalCommaInACommaFileIsRefused(): void
    {
        $result = PriceImport::parse("A-1,3,50\nB-2,4.00,Opis\n");

        self::assertSame(['B-2' => 4.0], $result['prices']);
        self::assertSame(['Line 1: the price looks split at a decimal comma; quote it or use ; as the delimiter.'], $result['errors']);
    }

    public function testOnlyTheFirstLineCanBeAHeader(): void
    {
        $result = PriceImport::parse("sku;cijena\nA-1;n/a\nB-2;2,00\n");

        self::assertSame(['B-2' => 2.0], $result['prices']);
        self::assertSame(['Line 2: "n/a" is not a price.'], $result['errors']);
    }

    public function testExplicitDelimiterWins(): void
    {
        self::assertSame(['A;1' => 2.0], PriceImport::parse("A;1|2,00\n", '|')['prices']);
    }
}
