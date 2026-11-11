<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use wmd\craftproductpricehistory\helpers\PriceListSource;

final class PriceListSourceTest extends TestCase
{
    private const HEADER = ['Šifra', 'Naziv proizvoda', 'Marka', 'Jedinica mjere', 'Maloprodajna cijena', 'Dodana (sidrena) cijena', 'Barkod (nije primjenjivo)'];

    public function testReadsAShopSheet(): void
    {
        $result = PriceListSource::parse([
            self::HEADER,
            ['', '', '', '', '', '', ''],
            ['0101005', 'Harvia Dela D36', 'Harvia', 'kom.', 504.53125000000006, 504.53125000000006, ''],
            ['HARVIA REZERVNI DIJELOVI', '', '', '', '', '', ''],
            ['4411030311', 'Klor 5l', 'Astralpool', '5l', 40.0, 38.5, 8412345678905.0],
        ]);

        self::assertNull($result['fatal']);
        self::assertSame([], $result['errors']);
        self::assertSame('Dodana (sidrena) cijena', $result['columns']['sidrena_cijena']);
        self::assertSame('Barkod (nije primjenjivo)', $result['columns']['barkod']);
        self::assertCount(2, $result['rows']);

        self::assertSame([
            'naziv' => 'Harvia Dela D36',
            'sifra' => '0101005',
            'marka' => 'Harvia',
            'neto_kolicina' => '',
            'jedinica_mjere' => 'kom',
            'cijena_za_jedinicu_mjere' => '504.53',
            'maloprodajna_cijena' => '504.53',
            'posebni_oblik_prodaje' => 'NE',
            'naziv_posebnog_oblika_prodaje' => '',
            'sidrena_cijena' => '504.53',
            'barkod' => '',
            'dostupnost' => 'dostupno',
            'sidreni_datum' => '',
        ], $result['rows'][0]);

        $canister = $result['rows'][1];
        self::assertSame('5 l', $canister['neto_kolicina']);
        self::assertSame('l', $canister['jedinica_mjere']);
        self::assertSame('8.00', $canister['cijena_za_jedinicu_mjere']);
        self::assertSame('38.50', $canister['sidrena_cijena']);
        self::assertSame('8412345678905', $canister['barkod']);
    }

    public function testFindsTheHeaderBelowATitleAndInAnyOrder(): void
    {
        $result = PriceListSource::parse([
            ['Cjenik Spiritus koncept d.o.o.'],
            [],
            ['MPC', 'Naziv', 'Sifra', 'Dostupnost', 'Posebni oblik prodaje', 'Naziv posebnog oblika prodaje'],
            ['12,50', 'Četka', 'A1', 'nedostupno', 'DA', 'Jesenska akcija'],
        ]);

        self::assertNull($result['fatal']);
        $row = $result['rows'][0];
        self::assertSame('A1', $row['sifra']);
        self::assertSame('12.50', $row['maloprodajna_cijena']);
        self::assertSame('nedostupno', $row['dostupnost']);
        self::assertSame('DA', $row['posebni_oblik_prodaje']);
        self::assertSame('Jesenska akcija', $row['naziv_posebnog_oblika_prodaje']);
    }

    public function testOwnColumnNamesAreRecognized(): void
    {
        $result = PriceListSource::parse([
            ['naziv', 'sifra', 'neto_kolicina', 'jedinica_mjere', 'cijena_za_jedinicu_mjere', 'maloprodajna_cijena'],
            ['Sol', 'S1', '25 kg', 'kg', '0.40', '10.00'],
        ]);

        self::assertNull($result['fatal']);
        self::assertSame('25 kg', $result['rows'][0]['neto_kolicina']);
        self::assertSame('kg', $result['rows'][0]['jedinica_mjere']);
        self::assertSame('0.40', $result['rows'][0]['cijena_za_jedinicu_mjere']);
    }

    public function testBadRowsAreLeftOutAndReported(): void
    {
        $result = PriceListSource::parse([
            ['Šifra', 'Naziv', 'Maloprodajna cijena', 'Dostupnost'],
            ['A1', 'Prvi', '10', ''],
            ['A1', 'Duplikat', '11', ''],
            ['', 'Bez šifre', '5', ''],
            ['A2', 'Bez cijene', 'na upit', ''],
            ['A3', 'Krivo', '7', 'možda'],
            ['A4', 'Drugi', '1.234,00', ''],
        ]);

        self::assertNull($result['fatal']);
        self::assertSame(['A1', 'A4'], array_column($result['rows'], 'sifra'));
        self::assertSame('1234.00', $result['rows'][1]['maloprodajna_cijena']);
        self::assertSame([
            'Row 3: code A1 is already in row 2.',
            'Row 4: no code (šifra).',
            'Row 5: "na upit" is not a price.',
            'Row 6: "možda" is not dostupno or nedostupno.',
        ], $result['errors']);
    }

    public function testReadsAWindows1250Csv(): void
    {
        $csv = (string)iconv('UTF-8', 'CP1250', "Šifra;Naziv;Maloprodajna cijena\r\nA1;Četka \"mala\";12,50\r\n");
        $table = PriceListSource::readCsv($csv);

        self::assertSame([['Šifra', 'Naziv', 'Maloprodajna cijena'], ['A1', 'Četka "mala"', '12,50']], $table);
        self::assertSame('12.50', PriceListSource::parse($table)['rows'][0]['maloprodajna_cijena']);
    }

    public function testReadsAUtf8CsvWithBomAndCommas(): void
    {
        $table = PriceListSource::readCsv("\xEF\xBB\xBFšifra,naziv,mpc\nA1,Žica,\"3,20\"\n");

        self::assertSame([['šifra', 'naziv', 'mpc'], ['A1', 'Žica', '3,20']], $table);
    }

    public function testMissingRequiredColumnIsFatal(): void
    {
        $result = PriceListSource::parse([
            ['Šifra', 'Naziv', 'Marka'],
            ['A1', 'Prvi', 'X'],
        ]);

        self::assertSame('Missing columns: maloprodajna_cijena.', $result['fatal']);
        self::assertSame([], $result['rows']);
    }

    public function testNoHeaderIsFatal(): void
    {
        $result = PriceListSource::parse([['a', 'b'], ['1', '2']]);

        self::assertNotNull($result['fatal']);
    }

    /**
     * @return array<string, array{string, float, string, string, string}>
     */
    public static function units(): array
    {
        return [
            'piece with dot' => ['kom.', 10.0, '', 'kom', '10.00'],
            'set' => ['kpl', 99.99, '', 'kpl', '99.99'],
            'per litre already' => ['l', 3.0, '', 'l', '3.00'],
            'square metre' => ['m²', 20.0, '', 'm²', '20.00'],
            'square metre ascii' => ['m2', 20.0, '', 'm²', '20.00'],
            'trailing space' => ['m ', 2.5, '', 'm', '2.50'],
            'five litres' => ['5l', 40.0, '5 l', 'l', '8.00'],
            'millilitres' => ['400 ml', 6.0, '400 ml', 'l', '15.00'],
            'decimal comma' => ['0,5 kg', 3.0, '0,5 kg', 'kg', '6.00'],
            'grams' => ['250g', 5.0, '250 g', 'kg', '20.00'],
            'roll' => ['rola', 12.0, '', 'rola', '12.00'],
        ];
    }

    #[DataProvider('units')]
    public function testUnit(string $raw, float $price, string $net, string $unit, string $unitPrice): void
    {
        self::assertSame(
            ['neto_kolicina' => $net, 'jedinica_mjere' => $unit, 'cijena_za_jedinicu_mjere' => $unitPrice],
            PriceListSource::unit($raw, $price),
        );
    }
}
