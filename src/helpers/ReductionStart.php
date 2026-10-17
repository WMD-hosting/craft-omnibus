<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\helpers;

use DateTime;

/**
 * When the reduction a variant is on started, so the "lowest price in the N
 * days before" window (Zakon o zaštiti potrošača čl. 19, Omnibus) closes there.
 *
 * The history records base prices only. A Commerce promotional price or
 * catalog pricing rule never writes a row, so the latest row is the start of
 * a reduction only when it lowered the price; a selling price below it is a
 * promotion that started later.
 *
 * @since 1.3.1
 */
final class ReductionStart
{
    private const EPSILON = 0.005;

    /**
     * @param list<array{price: float, date: DateTime}> $rows The latest history rows, newest first (two are enough).
     * @param float|null $salePrice The variant's current selling price; null when unknown.
     * @param DateTime|null $promotionStart When the promotion behind the selling price started; null when unknown.
     */
    public static function resolve(array $rows, ?float $salePrice, ?DateTime $promotionStart, DateTime $now): DateTime
    {
        $latest = $rows[0] ?? null;
        if ($latest === null) {
            return $now;
        }

        // Sold below the recorded price: a promotion that started after the
        // latest row, which is the price in force before it.
        if ($salePrice !== null && $salePrice < $latest['price'] - self::EPSILON) {
            return $promotionStart ?? $now;
        }

        // A reduction recorded in the history: the row that lowered the price.
        $previous = $rows[1] ?? null;
        if ($previous !== null && $latest['price'] < $previous['price'] - self::EPSILON) {
            return $latest['date'];
        }

        // No reduction in force (a first row, a raise, an unchanged price):
        // the window before now.
        return $now;
    }

    /**
     * The start of the selling price: the earliest `dateFrom` of the active
     * catalog prices that give it. Null when none gives it (a price set some
     * other way) or one of them has no start date.
     *
     * @param list<array{price: float, dateFrom: DateTime|null}> $catalogPrices
     */
    public static function promotionStart(array $catalogPrices, float $salePrice): ?DateTime
    {
        $start = null;
        foreach ($catalogPrices as $row) {
            if (abs($row['price'] - $salePrice) >= self::EPSILON) {
                continue;
            }
            if ($row['dateFrom'] === null) {
                return null;
            }
            if ($start === null || $row['dateFrom'] < $start) {
                $start = $row['dateFrom'];
            }
        }

        return $start;
    }
}
