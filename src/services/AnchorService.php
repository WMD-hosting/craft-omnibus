<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Variant;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeZone;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;

/**
 * The two legally defined reference prices, read from the history table:
 *
 * - the anchor price ("dodatna / sidrena cijena", NN 101/2026): the price
 *   applicable on the reference day, or on the first day of listing for
 *   products that came later;
 * - the lowest price in the N days before a reduction started (Zakon o
 *   zaštiti potrošača čl. 19, Omnibus).
 *
 * @since 1.1.0
 */
class AnchorService extends Component
{
    public const SOURCE_ANCHOR_DATE = 'anchorDate';
    public const SOURCE_FIRST_LISTING = 'firstListing';

    // =========================================================================
    // Anchor price
    // =========================================================================

    /**
     * Returns the anchor price for a variant, or null when nothing is recorded.
     *
     * @return array{price: float, date: DateTime, source: string}|null
     */
    public function getVariantAnchor(int $variantId): ?array
    {
        $settings = ProductHistory::$plugin->getSettings();
        $endOfDay = $this->anchorDayEnd();

        $row = (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->select(['price', 'dateChanged'])
            ->where(['variantId' => $variantId])
            ->andWhere(['<=', 'dateChanged', $endOfDay->format('Y-m-d H:i:s')])
            ->orderBy(['dateChanged' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        if ($row) {
            return [
                'price' => (float)$row['price'],
                'date' => $this->anchorDay(),
                'source' => self::SOURCE_ANCHOR_DATE,
            ];
        }

        if (!$settings->anchorFromFirstListing) {
            return null;
        }

        // Listed after the reference day: the first recorded price, with its date.
        $first = (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->select(['price', 'dateChanged'])
            ->where(['variantId' => $variantId])
            ->orderBy(['dateChanged' => SORT_ASC, 'id' => SORT_ASC])
            ->one();

        if (!$first) {
            return null;
        }

        return [
            'price' => (float)$first['price'],
            'date' => DateTimeHelper::toDateTime($first['dateChanged']) ?: new DateTime($first['dateChanged']),
            'source' => self::SOURCE_FIRST_LISTING,
        ];
    }

    /**
     * Anchor for a product: the anchor of its default variant.
     *
     * @return array{price: float, date: DateTime, source: string}|null
     */
    public function getProductAnchor(int $productId): ?array
    {
        $defaultVariantId = (new Query())
            ->from('{{%commerce_products}}')
            ->select(['defaultVariantId'])
            ->where(['id' => $productId])
            ->scalar();

        return $defaultVariantId ? $this->getVariantAnchor((int)$defaultVariantId) : null;
    }

    // =========================================================================
    // Lowest price before a reduction
    // =========================================================================

    /**
     * Lowest recorded price in the window before `$saleStart` (default: now),
     * falling back to the price in force at the window start.
     */
    public function getVariantLowestBefore(int $variantId, ?DateTime $saleStart = null, ?int $days = null): ?float
    {
        $days ??= ProductHistory::$plugin->getSettings()->lowestPriceDays;

        // The reduction "starts" when the current price was recorded, so by
        // default the window closes at the latest history row, exclusive:
        // the reduced price itself never counts as its own reference.
        if ($saleStart === null) {
            $latest = (new Query())
                ->from(ProductPriceHistoryRecord::tableName())
                ->select(['dateChanged'])
                ->where(['variantId' => $variantId])
                ->orderBy(['dateChanged' => SORT_DESC, 'id' => SORT_DESC])
                ->scalar();
            $saleStart = $latest ? new DateTime($latest) : new DateTime();
        }
        $end = clone $saleStart;
        $start = (clone $end)->modify("-{$days} days");

        $lowest = (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->where(['variantId' => $variantId])
            ->andWhere(['>=', 'dateChanged', $start->format('Y-m-d H:i:s')])
            ->andWhere(['<', 'dateChanged', $end->format('Y-m-d H:i:s')])
            ->min('price');

        // The price in force when the window opened also counts.
        $before = (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->select(['price'])
            ->where(['variantId' => $variantId])
            ->andWhere(['<', 'dateChanged', $start->format('Y-m-d H:i:s')])
            ->orderBy(['dateChanged' => SORT_DESC, 'id' => SORT_DESC])
            ->scalar();

        $candidates = array_filter([$lowest, $before], static fn($v) => $v !== null && $v !== false);

        return $candidates ? (float)min($candidates) : null;
    }

    // =========================================================================
    // Backfill
    // =========================================================================

    /**
     * Records the current base price for every variant that has no history
     * yet. With `$asOf` the rows are dated then, which is how a store that
     * installs the plugin after the reference day asserts "these prices were
     * already in force on that day".
     *
     * @return int rows written
     */
    public function backfillMissing(?DateTime $asOf = null, ?int $storeId = null): int
    {
        $written = 0;
        $recorded = (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->select(['variantId'])
            ->distinct()
            ->column();
        $recorded = array_flip(array_map('intval', $recorded));

        $query = (new Query())
            ->from(['v' => '{{%commerce_variants}}'])
            ->innerJoin(['ps' => '{{%commerce_purchasables_stores}}'], '[[ps.purchasableId]] = [[v.id]]')
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[v.id]]')
            ->select(['v.id', 'v.primaryOwnerId', 'ps.basePrice'])
            ->where(['e.dateDeleted' => null, 'e.revisionId' => null, 'e.draftId' => null]);
        if ($storeId) {
            $query->andWhere(['ps.storeId' => $storeId]);
        }

        foreach ($query->each() as $row) {
            $variantId = (int)$row['id'];
            if (isset($recorded[$variantId]) || $row['basePrice'] === null) {
                continue;
            }
            $record = new ProductPriceHistoryRecord();
            $record->productId = (int)$row['primaryOwnerId'];
            $record->variantId = $variantId;
            $record->price = (float)$row['basePrice'];
            if ($asOf) {
                $record->dateChanged = $asOf->format('Y-m-d H:i:s');
            }
            if ($record->save(false)) {
                $recorded[$variantId] = true;
                $written++;
            }
        }

        return $written;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function anchorDay(): DateTime
    {
        $settings = ProductHistory::$plugin->getSettings();
        return new DateTime($settings->anchorDate, new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function anchorDayEnd(): DateTime
    {
        $end = $this->anchorDay()->setTime(23, 59, 59);
        // Rows are stored in UTC.
        return $end->setTimezone(new DateTimeZone('UTC'));
    }
}
