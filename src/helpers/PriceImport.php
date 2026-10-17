<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\helpers;

/**
 * Reads the `sku;price` file of `omnibus/anchor/import`: a spreadsheet or ERP
 * export of the prices in force on a past reference day.
 *
 * - delimiter `;`, `,` or tab, detected from the first line unless given;
 * - an optional header row (a first line whose price is not a number);
 * - prices in either notation: `1234.56`, `1234,56`, `1.234,56`, `1,234.56`,
 *   with or without a currency sign. A lone dot with three digits after it
 *   (`1.234`) could be either, so the line is refused rather than guessed.
 *
 * @since 1.3.0
 */
final class PriceImport
{
    /**
     * @return array{prices: array<string, float>, errors: list<string>}
     */
    public static function parse(string $contents, ?string $delimiter = null): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $delimiter ??= self::detectDelimiter($lines);

        $prices = [];
        $errors = [];
        $first = true;
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $isFirst = $first;
            $first = false;
            $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            $sku = $cells[0];
            $raw = (string)($cells[1] ?? '');
            $price = self::price($raw);
            $n = $i + 1;

            // 3,50 unquoted in a comma file splits into "3" and "50".
            if ($delimiter === ',' && preg_match('/^\d+$/', $cells[2] ?? '') === 1) {
                $errors[] = "Line $n: the price looks split at a decimal comma; quote it or use ; as the delimiter.";
                continue;
            }
            if ($price === null) {
                // A header has no number in the price column.
                if ($isFirst && preg_match('/\d/', $raw) !== 1) {
                    continue;
                }
                $errors[] = "Line $n: \"$raw\" is not a price.";
                continue;
            }
            if ($sku === '') {
                $errors[] = "Line $n: no SKU.";
                continue;
            }
            if (isset($prices[$sku]) && abs($prices[$sku] - $price) >= 0.00005) {
                $errors[] = "Line $n: SKU \"$sku\" appears twice with different prices.";
                continue;
            }
            $prices[$sku] = $price;
        }

        return ['prices' => $prices, 'errors' => $errors];
    }

    /** A price in either notation, or null when it is not one (or is ambiguous). */
    public static function price(string $raw): ?float
    {
        $value = preg_replace('/[\s\x{00A0}€]|EUR|HRK|kn/iu', '', $raw) ?? '';
        if ($value === '' || preg_match('/^\d[\d.,]*$/', $value) !== 1) {
            return null;
        }

        $dot = strrpos($value, '.');
        $comma = strrpos($value, ',');
        if ($dot !== false && $comma !== false) {
            // The later separator is the decimal one; the other groups thousands.
            [$decimal, $group] = $dot > $comma ? ['.', ','] : [',', '.'];
            $value = str_replace($group, '', $value);
            $value = str_replace($decimal, '.', $value);
        } elseif ($comma !== false || $dot !== false) {
            $sep = $comma !== false ? ',' : '.';
            $parts = explode($sep, $value);
            if (count($parts) > 2) {
                // 1.234.567 or 1,234,567: groups only.
                $value = implode('', $parts);
            } elseif (strlen($parts[1]) === 3) {
                // 1.234 / 1,234: thousands or three decimals, no way to tell.
                return null;
            } else {
                $value = $parts[0] . '.' . $parts[1];
            }
        }

        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * @param list<string> $lines
     */
    private static function detectDelimiter(array $lines): string
    {
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            foreach (["\t", ';'] as $candidate) {
                if (str_contains($line, $candidate)) {
                    return $candidate;
                }
            }
            return ',';
        }

        return ';';
    }
}
