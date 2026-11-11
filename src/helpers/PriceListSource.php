<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\helpers;

use wmd\craftproductpricehistory\services\PriceListService;

/**
 * Turns a spreadsheet the shop keeps by hand (a table of cells: first sheet
 * of an Excel file, or a CSV) into price-list rows, for shops whose prices
 * are not in Commerce.
 *
 * - The header row is found by its titles, in any order and with or without
 *   diacritics: "Šifra", "Naziv proizvoda", "Maloprodajna cijena", "Dodatna
 *   (sidrena) cijena", or the price-list column names themselves. Titles in
 *   brackets are tried with and without the bracketed part.
 * - `sifra`, `naziv` and `maloprodajna_cijena` are required; every other
 *   column may be missing and is filled: `posebni_oblik_prodaje` NE,
 *   `dostupnost` dostupno, `cijena_za_jedinicu_mjere` from the price and the
 *   unit ("5 l" gives the price per litre and `neto_kolicina` "5 l").
 * - A row with a single filled cell and no price is a heading (a category
 *   title) and is skipped. A row missing the code, name or price, with a value that is not a price, or repeating a
 *   code, is left out and reported.
 *
 * @since 1.4.0
 */
final class PriceListSource
{
    /** Header titles per column, normalized (lower case, no diacritics, single spaces). */
    public const ALIASES = [
        'naziv' => ['naziv', 'naziv proizvoda', 'naziv artikla', 'proizvod', 'artikl', 'opis'],
        'sifra' => ['sifra', 'sifra proizvoda', 'sifra artikla', 'kod', 'kod proizvoda', 'sku'],
        'marka' => ['marka', 'brand', 'proizvodac'],
        'neto_kolicina' => ['neto kolicina', 'kolicina', 'pakiranje'],
        'jedinica_mjere' => ['jedinica mjere', 'jm', 'mjera', 'mj'],
        'cijena_za_jedinicu_mjere' => ['cijena za jedinicu mjere', 'cijena za jm', 'jedinicna cijena'],
        'maloprodajna_cijena' => ['maloprodajna cijena', 'mpc', 'cijena', 'prodajna cijena', 'cijena s pdv om'],
        'posebni_oblik_prodaje' => ['posebni oblik prodaje', 'akcija', 'snizenje'],
        'naziv_posebnog_oblika_prodaje' => ['naziv posebnog oblika prodaje', 'naziv akcije'],
        'sidrena_cijena' => ['sidrena cijena', 'dodatna cijena', 'dodana cijena', 'dodatna sidrena cijena', 'dodana sidrena cijena'],
        'barkod' => ['barkod', 'bar kod', 'barcode', 'ean', 'ean kod'],
        'dostupnost' => ['dostupnost', 'dostupno'],
    ];

    public const REQUIRED = ['sifra', 'naziv', 'maloprodajna_cijena'];

    /** Units with a quantity, to the unit the price per unit is given in. */
    private const UNIT_FACTORS = [
        'ml' => ['l', 0.001],
        'cl' => ['l', 0.01],
        'dl' => ['l', 0.1],
        'l' => ['l', 1.0],
        'g' => ['kg', 0.001],
        'dag' => ['kg', 0.01],
        'kg' => ['kg', 1.0],
        'mm' => ['m', 0.001],
        'cm' => ['m', 0.01],
        'm' => ['m', 1.0],
        'm2' => ['m²', 1.0],
        'm3' => ['m³', 1.0],
        'kom' => ['kom', 1.0],
    ];

    /** Header rows are looked for in the first this many rows. */
    private const HEADER_SEARCH_ROWS = 20;

    /**
     * @param iterable<array<int|string, mixed>> $table Rows of cells, as a spreadsheet reader returns them.
     * @param (callable(string, array<string, string|int>): string)|null $t Translates a message with
     *   `{placeholders}`; null fills the placeholders into the English text.
     * @return array{rows: list<array<string, string>>, errors: list<string>, fatal: string|null, columns: array<string, string>}
     *   `columns` maps each recognized price-list column to the title it came from.
     */
    public static function parse(iterable $table, ?callable $t = null): array
    {
        $t ??= static fn(string $message, array $params) => strtr($message, array_combine(
            array_map(static fn($key) => '{' . $key . '}', array_keys($params)),
            array_map('strval', array_values($params)),
        ) ?: []);
        $result = ['rows' => [], 'errors' => [], 'fatal' => null, 'columns' => []];

        $map = null;
        $seen = [];
        $line = 0;
        foreach ($table as $cells) {
            $line++;
            $cells = array_values((array)$cells);

            if ($map === null) {
                if ($line > self::HEADER_SEARCH_ROWS) {
                    break;
                }
                $found = self::headerMap($cells);
                if (count($found) >= 2) {
                    $map = $found;
                    foreach ($found as $column => $index) {
                        $result['columns'][$column] = trim(self::text($cells[$index] ?? ''));
                    }
                    $missing = array_diff(self::REQUIRED, array_keys($found));
                    if ($missing !== []) {
                        $result['fatal'] = $t('Missing columns: {columns}.', ['columns' => implode(', ', $missing)]);
                        return $result;
                    }
                }
                continue;
            }

            $value = static fn(string $column) => isset($map[$column]) ? ($cells[$map[$column]] ?? '') : '';
            $sku = self::code($value('sifra'));
            $name = trim(self::text($value('naziv')));
            $priceCell = $value('maloprodajna_cijena');

            $filled = 0;
            foreach ($map as $index) {
                if (trim(self::text($cells[$index] ?? '')) !== '') {
                    $filled++;
                }
            }
            if ($filled === 0 || ($filled === 1 && trim(self::text($priceCell)) === '')) {
                // Blank line, or a heading such as a category title in one cell.
                continue;
            }

            $price = self::price($priceCell);
            $error = match (true) {
                $sku === '' => $t('no code (šifra)', []),
                $name === '' => $t('no name', []),
                $price === null => $t('"{value}" is not a price', ['value' => trim(self::text($priceCell))]),
                isset($seen[$sku]) => $t('code {code} is already in row {row}', ['code' => $sku, 'row' => (string)$seen[$sku]]),
                default => null,
            };

            $row = null;
            if ($error === null) {
                [$row, $error] = self::row($sku, $name, $price, $value, $t);
            }
            if ($error !== null) {
                $result['errors'][] = $t('Row {row}: {error}.', ['row' => (string)$line, 'error' => $error]);
                continue;
            }

            $seen[$sku] = $line;
            $result['rows'][] = $row;
        }

        if ($map === null) {
            $result['fatal'] = $t('No header row: the first rows must hold the column titles (Šifra, Naziv, Maloprodajna cijena…).', []);
        } elseif ($result['rows'] === []) {
            $result['fatal'] = $t('No products: every row was empty or had an error.', []);
        }

        return $result;
    }

    /**
     * Price per unit of measure, the unit and the net quantity from what the
     * sheet writes as the unit: "5l" is 5 l at the price per litre, "400 ml"
     * the price per litre too, "kom." or "kpl" the price itself.
     *
     * @return array{neto_kolicina: string, jedinica_mjere: string, cijena_za_jedinicu_mjere: string}
     */
    public static function unit(string $raw, float $price): array
    {
        $unit = trim(str_replace("\u{00A0}", ' ', $raw));
        $unit = rtrim($unit, '. ');
        $key = str_replace('²', '2', str_replace('³', '3', mb_strtolower($unit)));

        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*([a-z]+[23]?)$/', $key, $m) === 1 && isset(self::UNIT_FACTORS[$m[2]])) {
            [$base, $factor] = self::UNIT_FACTORS[$m[2]];
            $quantity = (float)str_replace(',', '.', $m[1]) * $factor;
            if ($quantity > 0) {
                return [
                    'neto_kolicina' => $m[1] . ' ' . $m[2],
                    'jedinica_mjere' => $base,
                    'cijena_za_jedinicu_mjere' => self::money($price / $quantity),
                ];
            }
        }

        // One spelling for square and cubic metres.
        $unit = match ($key) {
            'm2' => 'm²',
            'm3' => 'm³',
            default => $unit,
        };

        return ['neto_kolicina' => '', 'jedinica_mjere' => $unit, 'cijena_za_jedinicu_mjere' => self::money($price)];
    }

    /**
     * CSV in UTF-8 (with or without BOM) or Windows-1250, as Excel saves it
     * on a Croatian Windows; delimiter `;`, tab or `,`.
     *
     * @return list<list<string>>
     */
    public static function readCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (!mb_check_encoding($contents, 'UTF-8')) {
            // mbstring has no Windows-1250; iconv does.
            $converted = iconv('CP1250', 'UTF-8//IGNORE', $contents);
            $contents = $converted !== false ? $converted : mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-2');
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = ',';
        $best = 0;
        foreach ([';', "\t", ','] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $best) {
                [$delimiter, $best] = [$candidate, $count];
            }
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);
        $table = [];
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $table[] = array_map(static fn($cell) => (string)$cell, $cells);
        }
        fclose($handle);

        return $table;
    }

    /** A header title in the form the aliases are written in. */
    public static function normalize(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $title = strtr($title, ['č' => 'c', 'ć' => 'c', 'đ' => 'd', 'dž' => 'dz', 'š' => 's', 'ž' => 'z']);
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? '';

        return trim($title);
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * @param list<mixed> $cells
     * @return array<string, int> column => cell index
     */
    private static function headerMap(array $cells): array
    {
        $lookup = [];
        foreach (self::ALIASES as $column => $aliases) {
            $lookup[self::normalize(str_replace('_', ' ', $column))] = $column;
            foreach ($aliases as $alias) {
                $lookup[$alias] ??= $column;
            }
        }

        $map = [];
        foreach ($cells as $index => $cell) {
            $title = self::text($cell);
            if (trim($title) === '') {
                continue;
            }
            // "Dodatna (sidrena) cijena", "Barkod (nije primjenjivo)": with and without the brackets.
            $candidates = [self::normalize($title), self::normalize(preg_replace('/\([^)]*\)/', ' ', $title) ?? $title)];
            foreach ($candidates as $candidate) {
                $column = $lookup[$candidate] ?? null;
                if ($column !== null && !isset($map[$column])) {
                    $map[$column] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param callable(string): mixed $value
     * @param callable(string, array<string, string|int>): string $t
     * @return array{0: array<string, string>|null, 1: string|null}
     */
    private static function row(string $sku, string $name, float $price, callable $value, callable $t): array
    {
        $anchorCell = $value('sidrena_cijena');
        $anchor = '';
        if (trim(self::text($anchorCell)) !== '') {
            $parsed = self::price($anchorCell);
            if ($parsed === null) {
                return [null, $t('"{value}" is not an anchor price', ['value' => trim(self::text($anchorCell))])];
            }
            $anchor = self::money($parsed);
        }

        $special = self::yesNo(self::text($value('posebni_oblik_prodaje')), false, ['da', 'yes', 'y', '1', 'x', 'true', 'akcija', 'snizenje'], ['ne', 'no', 'n', '0', 'false']);
        if ($special === null) {
            return [null, $t('"{value}" is not DA or NE (posebni oblik prodaje)', ['value' => trim(self::text($value('posebni_oblik_prodaje')))])];
        }
        $available = self::yesNo(self::text($value('dostupnost')), true, ['dostupno', 'da', 'yes', '1', 'na stanju', 'raspolozivo'], ['nedostupno', 'ne', 'no', '0', 'nije dostupno', 'rasprodano']);
        if ($available === null) {
            return [null, $t('"{value}" is not dostupno or nedostupno', ['value' => trim(self::text($value('dostupnost')))])];
        }

        $unit = self::unit(self::text($value('jedinica_mjere')), $price);
        $net = trim(self::text($value('neto_kolicina')));
        $unitPriceCell = $value('cijena_za_jedinicu_mjere');
        if (trim(self::text($unitPriceCell)) !== '') {
            $unitPrice = self::price($unitPriceCell);
            if ($unitPrice === null) {
                return [null, $t('"{value}" is not a price per unit', ['value' => trim(self::text($unitPriceCell))])];
            }
            $unit['cijena_za_jedinicu_mjere'] = self::money($unitPrice);
        }

        return [[
            'naziv' => $name,
            'sifra' => $sku,
            'marka' => trim(self::text($value('marka'))),
            'neto_kolicina' => $net !== '' ? $net : $unit['neto_kolicina'],
            'jedinica_mjere' => $unit['jedinica_mjere'],
            'cijena_za_jedinicu_mjere' => $unit['cijena_za_jedinicu_mjere'],
            'maloprodajna_cijena' => self::money($price),
            'posebni_oblik_prodaje' => $special ? 'DA' : 'NE',
            'naziv_posebnog_oblika_prodaje' => $special ? trim(self::text($value('naziv_posebnog_oblika_prodaje'))) : '',
            'sidrena_cijena' => $anchor,
            'barkod' => self::code($value('barkod')),
            'dostupnost' => $available ? 'dostupno' : 'nedostupno',
            PriceListService::ROW_ANCHOR_DATE => '',
        ], null];
    }

    /**
     * True, false, or null when the value is neither; empty is `$default`.
     *
     * @param list<string> $yes
     * @param list<string> $no
     */
    private static function yesNo(string $raw, bool $default, array $yes, array $no): ?bool
    {
        $value = self::normalize($raw);
        if ($value === '') {
            return $default;
        }
        if (in_array($value, $yes, true)) {
            return true;
        }
        return in_array($value, $no, true) ? false : null;
    }

    /** A price from a number cell (rounded to cents) or text in either notation. */
    private static function price(mixed $cell): ?float
    {
        if (is_int($cell) || is_float($cell)) {
            return $cell >= 0 ? round((float)$cell, 2) : null;
        }
        $price = PriceImport::price(self::text($cell));

        return $price === null ? null : round($price, 2);
    }

    /** A code or barcode: a number cell has no decimals or exponent ("4006381333931"). */
    private static function code(mixed $cell): string
    {
        if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
            return sprintf('%.0f', $cell);
        }

        return trim(self::text($cell));
    }

    private static function text(mixed $cell): string
    {
        if ($cell === null || is_bool($cell)) {
            return '';
        }
        if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
            return sprintf('%.0f', $cell);
        }

        return is_scalar($cell) || $cell instanceof \Stringable ? (string)$cell : '';
    }

    private static function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
