<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\services\PriceListService;

final class PriceListCsvTest extends TestCase
{
    private const ROW = [
        'naziv' => 'Sauna cap, 1.5 m',
        'sifra' => '0307185',
        'neto_kolicina' => '0.4 l',
        'jedinica_mjere' => 'l',
        'cijena_za_jedinicu_mjere' => '28.75',
        'maloprodajna_cijena' => '11.50',
        'sidrena_cijena' => '28.05',
    ];

    public function testPointIsTheDefault(): void
    {
        $line = $this->dataLine((new PriceListService())->csv([self::ROW]));

        self::assertSame('28.75', $line[5]);
        self::assertSame('11.50', $line[6]);
        self::assertSame('28.05', $line[9]);
    }

    public function testCommaChangesOnlyThePriceColumns(): void
    {
        $line = $this->dataLine((new PriceListService())->csv([self::ROW], ';', ','));

        self::assertSame('28,75', $line[5]);
        self::assertSame('11,50', $line[6]);
        self::assertSame('28,05', $line[9]);
        self::assertSame('Sauna cap, 1.5 m', $line[0]);
        self::assertSame('0.4 l', $line[3]);
    }

    public function testEmptyAnchorStaysEmpty(): void
    {
        $line = $this->dataLine((new PriceListService())->csv([['sidrena_cijena' => ''] + self::ROW], ';', ','));

        self::assertSame('', $line[9]);
    }

    /** @return list<string> */
    private function dataLine(string $csv): array
    {
        $lines = array_values(array_filter(explode("\n", substr($csv, 3))));
        return str_getcsv($lines[1], ';', '"', '');
    }
}
