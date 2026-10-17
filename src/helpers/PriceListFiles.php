<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\helpers;

use craft\helpers\StringHelper;
use DateTimeInterface;

/**
 * File names of the published price lists (NN 101/2026 čl. VI) and the
 * grouping of the public index. Needs no Craft application, so it is unit
 * tested on its own.
 *
 * Name: `{oblik}_{adresa}_{oznaka}_{broj-pohrane}_{YYYYMMDD}_{HHMMSS}.csv|xml`.
 * The three outlet parts are ASCII and kebab-cased, so they never contain an
 * underscore and a name splits back into its parts. The parser also takes
 * names from before 1.2.0, whose parts could keep diacritics.
 *
 * @since 1.2.0
 */
final class PriceListFiles
{
    private const PATTERN = '#^(?<prefix>[^_/]+_[^_/]+_[^_/]+)_(?<number>\d+)_(?<day>\d{8})_(?<time>\d{6})\.(?<ext>csv|xml)$#';

    /**
     * The outlet part of the name: oblik, adresa, oznaka.
     */
    public static function prefix(string $type, string $address, string $code): string
    {
        return implode('_', [
            self::slug($type) ?: 'trgovina',
            self::slug($address) ?: 'bez-adrese',
            self::slug($code) ?: 'objekt',
        ]);
    }

    /**
     * The name without extension. `broj pohrane` is the outlet's run count for
     * the day, so a second run the same day never overwrites the first.
     *
     * @param string[] $existing File names already in the directory.
     */
    public static function base(string $prefix, DateTimeInterface $stamp, array $existing): string
    {
        $day = $stamp->format('Ymd');
        $number = 0;
        foreach ($existing as $name) {
            $parsed = self::parse(basename($name));
            if ($parsed && $parsed['prefix'] === $prefix && $parsed['day'] === $day) {
                $number = max($number, $parsed['number']);
            }
        }

        return sprintf('%s_%d_%s_%s', $prefix, $number + 1, $day, $stamp->format('His'));
    }

    /**
     * Splits a published name, or null for anything else in the directory.
     *
     * @return array{prefix: string, number: int, day: string, date: string, time: string, ext: string}|null
     */
    public static function parse(string $name): ?array
    {
        if (!preg_match(self::PATTERN, $name, $m)) {
            return null;
        }

        return [
            'prefix' => $m['prefix'],
            'number' => (int)$m['number'],
            'day' => $m['day'],
            'date' => substr($m['day'], 0, 4) . '-' . substr($m['day'], 4, 2) . '-' . substr($m['day'], 6, 2),
            'time' => substr($m['time'], 0, 2) . ':' . substr($m['time'], 2, 2),
            'ext' => $m['ext'],
        ];
    }

    /**
     * Groups files by publication day (newest first), then by outlet in the
     * order given. Files of an outlet that is no longer registered (a closed
     * store, still within the retention period) follow, named by their code.
     *
     * @param list<array{name: string, url: string, size: int}> $files
     * @param array<string, array{name: string, address: string}> $outlets prefix => outlet, in display order
     * @return list<array{date: string, outlets: list<array{prefix: string, name: string, address: string, files: list<array<string, mixed>>}>}>
     */
    public static function group(array $files, array $outlets): array
    {
        $days = [];
        foreach ($files as $file) {
            $parsed = self::parse($file['name']);
            if ($parsed === null) {
                continue;
            }
            $days[$parsed['date']][$parsed['prefix']][] = $file + $parsed;
        }
        krsort($days);

        $order = array_flip(array_keys($outlets));
        $groups = [];
        foreach ($days as $date => $byPrefix) {
            uksort($byPrefix, static fn($a, $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
            $rows = [];
            foreach ($byPrefix as $prefix => $outletFiles) {
                // Latest run first; CSV before XML within a run.
                usort($outletFiles, static fn($a, $b) => [$b['number'], $a['ext']] <=> [$a['number'], $b['ext']]);
                $rows[] = [
                    'prefix' => (string)$prefix,
                    'name' => $outlets[$prefix]['name'] ?? explode('_', (string)$prefix)[2],
                    'address' => $outlets[$prefix]['address'] ?? '',
                    'files' => $outletFiles,
                ];
            }
            $groups[] = ['date' => (string)$date, 'outlets' => $rows];
        }

        return $groups;
    }

    private static function slug(string $value): string
    {
        return StringHelper::toKebabCase(StringHelper::toAscii($value, 'hr'));
    }
}
