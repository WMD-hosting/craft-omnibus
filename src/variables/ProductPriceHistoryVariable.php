<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\variables;

use Craft;

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use DateTime;
use wmd\craftproductpricehistory\ProductHistory;

/**
 * `craft.productPriceHistory` in Twig.
 */
class ProductPriceHistoryVariable
{
    // =========================================================================
    // Anchor price ("sidrena cijena")
    // =========================================================================

    /**
     * Anchor for a variant, a product, or an id.
     *
     * @return array{price: float, date: DateTime, source: string}|null
     */
    public function anchor(Variant|Product|int $subject): ?array
    {
        $anchor = ProductHistory::$plugin->getAnchor();
        if ($subject instanceof Product) {
            return $anchor->getProductAnchor($subject->id);
        }
        return $anchor->getVariantAnchor($subject);
    }

    /** Label to print next to the price; the setting, or the translated default ("Anchor price", hr "Sidrena cijena"). */
    public function anchorLabel(): string
    {
        return ProductHistory::$plugin->getSettings()->anchorLabel
            ?: Craft::t('omnibus', 'Anchor price');
    }

    /** The reference day of a variant, or the default one (`anchorDate`) without. */
    public function anchorDate(Variant|int|null $subject = null): DateTime
    {
        $anchor = ProductHistory::$plugin->getAnchor();

        return $subject === null ? $anchor->anchorDay() : $anchor->anchorDayFor($subject);
    }

    /**
     * Lowest recorded price in the window (default 30 days) before the
     * variant's reduction started.
     */
    public function lowestBefore(Variant|int $subject, ?DateTime $saleStart = null, ?int $days = null): ?float
    {
        return ProductHistory::$plugin->getAnchor()->getVariantLowestBefore($subject, $saleStart, $days);
    }

    // =========================================================================
    // Existing API
    // =========================================================================

    public function getProductLowestPrice(int $productId, $daysAgo = null, bool $findMostRecentIfEmpty = false): float|null
    {
        return ProductHistory::$plugin
            ->productPriceHistoryService
            ->getProductLowestPrice($productId, $daysAgo, $findMostRecentIfEmpty);
    }

    public function getVariantLowestPrice(int $variantId, $daysAgo = null, bool $findMostRecentIfEmpty = false): float|null
    {
        return ProductHistory::$plugin
            ->productPriceHistoryService
            ->getVariantLowestPrice($variantId, $daysAgo, $findMostRecentIfEmpty);
    }
}
