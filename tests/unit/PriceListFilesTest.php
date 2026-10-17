<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\helpers\PriceListFiles;

final class PriceListFilesTest extends TestCase
{
    public function testPrefixIsAsciiKebabPerPart(): void
    {
        self::assertSame(
            'prodavaonica_subiceva-ul-12-djakovo_zagreb-ilica',
            PriceListFiles::prefix('Prodavaonica', 'Šubićeva ul. 12, Đakovo', 'zagrebIlica'),
        );
    }

    public function testPrefixFallsBackForEmptyParts(): void
    {
        self::assertSame('trgovina_bez-adrese_objekt', PriceListFiles::prefix('', '', ''));
    }

    public function testArchiveNumberCountsPerOutletAndDay(): void
    {
        $stamp = new DateTimeImmutable('2026-10-17 07:30:05');
        $existing = [
            '/web/cjenik/internetska-trgovina_bez-adrese_webshop_1_20261017_070000.csv',
            '/web/cjenik/internetska-trgovina_bez-adrese_webshop_2_20261017_071500.csv',
            // Another outlet the same day does not count.
            '/web/cjenik/prodavaonica_ilica-42-zagreb_zagreb-ilica_1_20261017_070000.csv',
            // The same outlet on another day does not count.
            '/web/cjenik/internetska-trgovina_bez-adrese_webshop_7_20261016_070000.csv',
        ];

        self::assertSame(
            'internetska-trgovina_bez-adrese_webshop_3_20261017_073005',
            PriceListFiles::base('internetska-trgovina_bez-adrese_webshop', $stamp, $existing),
        );
        self::assertSame(
            'prodavaonica_ilica-42-zagreb_zagreb-ilica_2_20261017_073005',
            PriceListFiles::base('prodavaonica_ilica-42-zagreb_zagreb-ilica', $stamp, $existing),
        );
        self::assertSame(
            'prodavaonica_marmontova-5-split_split_1_20261017_073005',
            PriceListFiles::base('prodavaonica_marmontova-5-split_split', $stamp, $existing),
        );
    }

    public function testParseSplitsANameAndRejectsOtherFiles(): void
    {
        self::assertSame([
            'prefix' => 'prodavaonica_ilica-42-zagreb_zagreb-ilica',
            'number' => 2,
            'day' => '20261017',
            'date' => '2026-10-17',
            'time' => '07:30',
            'ext' => 'xml',
        ], PriceListFiles::parse('prodavaonica_ilica-42-zagreb_zagreb-ilica_2_20261017_073005.xml'));

        self::assertNull(PriceListFiles::parse('index.html'));
        self::assertNull(PriceListFiles::parse('notes_20261017_073005.csv'));
    }

    public function testParseAcceptsNamesWithDiacriticsFromBeforeAsciiPrefixes(): void
    {
        self::assertSame('prodavaonica_šubićeva-12_djakovo', PriceListFiles::parse('prodavaonica_šubićeva-12_djakovo_1_20261001_070000.csv')['prefix'] ?? null);
    }

    public function testGroupByDayThenOutletOrder(): void
    {
        $file = static fn(string $name) => ['name' => $name, 'url' => "/cjenik/$name", 'size' => 100];
        $files = [
            $file('prodavaonica_marmontova-5-split_split-marmontova_1_20261016_070000.csv'),
            $file('prodavaonica_ilica-42-zagreb_zagreb-ilica_1_20261017_070000.csv'),
            $file('prodavaonica_ilica-42-zagreb_zagreb-ilica_1_20261017_070000.xml'),
            $file('internetska-trgovina_bez-adrese_webshop_1_20261017_070000.csv'),
            $file('internetska-trgovina_bez-adrese_webshop_2_20261017_090000.csv'),
            // A store that closed but whose files are still within retention.
            $file('prodavaonica_stara-1-osijek_osijek_1_20261017_070000.csv'),
            $file('index.html'),
        ];
        $outlets = [
            'internetska-trgovina_bez-adrese_webshop' => ['name' => 'Internetska trgovina', 'address' => ''],
            'prodavaonica_ilica-42-zagreb_zagreb-ilica' => ['name' => 'Trgovina Zagreb, Ilica', 'address' => 'Ilica 42, Zagreb'],
            'prodavaonica_marmontova-5-split_split-marmontova' => ['name' => 'Trgovina Split', 'address' => 'Marmontova 5, Split'],
        ];

        $groups = PriceListFiles::group($files, $outlets);

        self::assertSame(['2026-10-17', '2026-10-16'], array_column($groups, 'date'));
        self::assertSame(
            ['Internetska trgovina', 'Trgovina Zagreb, Ilica', 'osijek'],
            array_column($groups[0]['outlets'], 'name'),
        );
        self::assertSame('Ilica 42, Zagreb', $groups[0]['outlets'][1]['address']);
        // Latest run first.
        self::assertSame([2, 1], array_column($groups[0]['outlets'][0]['files'], 'number'));
        // CSV before XML within a run.
        self::assertSame(['csv', 'xml'], array_column($groups[0]['outlets'][1]['files'], 'ext'));
        self::assertSame('/cjenik/prodavaonica_ilica-42-zagreb_zagreb-ilica_1_20261017_070000.csv', $groups[0]['outlets'][1]['files'][0]['url']);
        self::assertSame(['Trgovina Split'], array_column($groups[1]['outlets'], 'name'));
    }

    public function testGroupOfNoFilesIsEmpty(): void
    {
        self::assertSame([], PriceListFiles::group([], []));
    }
}
