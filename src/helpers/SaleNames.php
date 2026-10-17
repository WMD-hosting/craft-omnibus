<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\helpers;

/**
 * Which catalog pricing rule is behind a reduced price, for the price list's
 * `naziv_posebnog_oblika_prodaje` (NN 101/2026-1213 t. III: the special form
 * of sale "i nazivom tog posebnog oblika prodaje").
 *
 * @since 1.3.0
 */
final class SaleNames
{
    /**
     * The name of the rule whose price is the sale price: a promotional rule
     * before a standard one, then the first by name. Null when no rule gives
     * that price (a promotional price typed on the variant).
     *
     * @param list<array{price: float, promotional: bool, name: string}> $candidates The variant's active catalog prices.
     */
    public static function pick(array $candidates, float $salePrice): ?string
    {
        $matching = array_values(array_filter(
            $candidates,
            static fn(array $c) => abs($c['price'] - $salePrice) < 0.005 && trim($c['name']) !== '',
        ));
        if ($matching === []) {
            return null;
        }

        usort($matching, static fn(array $a, array $b) => [$b['promotional'], $a['name']] <=> [$a['promotional'], $b['name']]);

        return trim($matching[0]['name']);
    }
}
