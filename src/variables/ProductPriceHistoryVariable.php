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
        $id = $subject instanceof Variant ? $subject->id : $subject;
        return $anchor->getVariantAnchor((int)$id);
    }

    /** Label to print next to the price; the setting, or the translated default ("Anchor price", hr "Sidrena cijena"). */
    public function anchorLabel(): string
    {
        return ProductHistory::$plugin->getSettings()->anchorLabel
            ?: Craft::t('craft-product-price-history', 'Anchor price');
    }

    /** The reference day as configured. */
    public function anchorDate(): DateTime
    {
        return ProductHistory::$plugin->getAnchor()->anchorDay();
    }

    /**
     * Lowest recorded price in the window before a reduction (default 30 days
     * before now).
     */
    public function lowestBefore(Variant|int $subject, ?DateTime $saleStart = null, ?int $days = null): ?float
    {
        $id = $subject instanceof Variant ? $subject->id : $subject;
        return ProductHistory::$plugin->getAnchor()->getVariantLowestBefore((int)$id, $saleStart, $days);
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
