<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\MoneyHelper;
use DateTime;
use DateTimeZone;
use Money\Money;
use wmd\craftproductpricehistory\events\DefineAnchorDateEvent;
use wmd\craftproductpricehistory\helpers\AnchorDateRules;
use wmd\craftproductpricehistory\helpers\ReductionStart;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;

/**
 * The two legally defined reference prices, read from the history table:
 *
 * - the anchor price ("dodatna / sidrena cijena", NN 101/2026): the price
 *   applicable on the variant's reference day (`anchorDate`, or the day
 *   `anchorDateRules` or EVENT_DEFINE_ANCHOR_DATE give it), or on the first
 *   day of listing for products that came later;
 * - the lowest price in the N days before a reduction started (Zakon o
 *   zaštiti potrošača čl. 19, Omnibus).
 *
 * @since 1.1.0
 */
class AnchorService extends Component
{
    /**
     * @event DefineAnchorDateEvent The reference day of one variant.
     * @since 1.3.0
     */
    public const EVENT_DEFINE_ANCHOR_DATE = 'defineAnchorDate';

    public const SOURCE_ANCHOR_DATE = 'anchorDate';
    public const SOURCE_FIRST_LISTING = 'firstListing';

    /** @since 1.3.0 The price comes from the `anchorOverrideField`. */
    public const SOURCE_OVERRIDE = 'override';

    /** @var array<string, array<int, true>> A rule's element ids => element ids related to them. */
    private array $_ruleRelations = [];

    // =========================================================================
    // Anchor price
    // =========================================================================

    /**
     * Returns the anchor price for a variant, or null when nothing is recorded.
     * Pass the Variant when you have it: with date rules, a date handler or an
     * override field the id alone costs a variant query.
     *
     * @return array{price: float, date: DateTime, source: string}|null
     */
    public function getVariantAnchor(int|Variant $variant): ?array
    {
        $settings = ProductHistory::$plugin->getSettings();
        $variantId = $variant instanceof Variant ? (int)$variant->id : $variant;
        $element = $this->needsVariant() ? $this->variant($variant) : null;
        $day = $this->anchorDayFor($element ?? $variantId);

        $override = $element ? $this->overridePrice($element) : null;
        if ($override !== null) {
            return [
                'price' => $override,
                'date' => $day,
                'source' => self::SOURCE_OVERRIDE,
            ];
        }

        $row = (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->select(['price', 'dateChanged'])
            ->where(['variantId' => $variantId])
            ->andWhere(['<=', 'dateChanged', $this->dayEnd($day)->format('Y-m-d H:i:s')])
            ->orderBy(['dateChanged' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        if ($row) {
            return [
                'price' => (float)$row['price'],
                'date' => $day,
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
     * The reference day of a variant (Y-m-d): the first matching
     * `anchorDateRules` entry, else `anchorDate`, then EVENT_DEFINE_ANCHOR_DATE.
     *
     * @since 1.3.0
     */
    public function anchorDateFor(int|Variant $variant): string
    {
        $settings = ProductHistory::$plugin->getSettings();
        $rules = $settings->getAnchorDateRules();
        $hasHandlers = $this->hasEventHandlers(self::EVENT_DEFINE_ANCHOR_DATE);
        if (!$rules && !$hasHandlers) {
            return $settings->anchorDate;
        }

        $variant = $this->variant($variant);
        if ($variant === null) {
            return $settings->anchorDate;
        }

        $date = $rules ? $this->ruleDate($rules, $variant) : null;
        $date ??= $settings->anchorDate;

        if ($hasHandlers) {
            $event = new DefineAnchorDateEvent(['variant' => $variant, 'date' => $date]);
            $this->trigger(self::EVENT_DEFINE_ANCHOR_DATE, $event);
            if (AnchorDateRules::isDate($event->date)) {
                $date = $event->date;
            } else {
                Craft::warning("Ignored anchor date \"{$event->date}\" for variant {$variant->id}: not Y-m-d.", __METHOD__);
            }
        }

        return $date;
    }

    /**
     * The reference day of a variant, at midnight in the site's time zone.
     *
     * @since 1.3.0
     */
    public function anchorDayFor(int|Variant $variant): DateTime
    {
        return $this->day($this->anchorDateFor($variant));
    }

    /**
     * Every reference day the settings can give, the default first. Dates a
     * EVENT_DEFINE_ANCHOR_DATE handler picks are not known in advance.
     *
     * @return list<string>
     * @since 1.3.0
     */
    public function anchorDates(): array
    {
        $settings = ProductHistory::$plugin->getSettings();

        return array_values(array_unique([$settings->anchorDate, ...array_column($settings->getAnchorDateRules(), 'date')]));
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
     * Lowest recorded price in the window before `$saleStart`, falling back to
     * the price in force at the window start. Without `$saleStart` the window
     * closes where the reduction started: the history row that lowered the
     * price, or for a promotional / catalog price the start of its pricing
     * rule (else now), so the latest recorded price still counts. Pass the
     * Variant when you have it: the id alone costs a variant query.
     */
    public function getVariantLowestBefore(int|Variant $variant, ?DateTime $saleStart = null, ?int $days = null): ?float
    {
        $days ??= ProductHistory::$plugin->getSettings()->lowestPriceDays;
        $variantId = $variant instanceof Variant ? (int)$variant->id : $variant;
        $utc = new DateTimeZone('UTC');

        // The reduced price itself never counts as its own reference: the
        // window closes at the reduction's start, exclusive.
        $saleStart ??= $this->reductionStart($variant, $utc);
        $end = (clone $saleStart)->setTimezone($utc);
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
    // Historic import
    // =========================================================================

    /**
     * Writes one history row per variant with the given SKU, dated `$asOf`
     * (noon, site time zone), so the anchor of a reference day before the
     * plugin was installed (2.5.2025) has a price to find. Running it again
     * with the same day updates those rows instead of adding more.
     *
     * A variant with no history after that day is left out (`unrecorded`):
     * its imported row would stand for every day since, the default reference
     * day and the lowest-price window included. Record the prices since first
     * (anchor/backfill), then import.
     *
     * @param array<string, float> $prices SKU => price
     * @return array{written: int, updated: int, unchanged: int, unknown: list<string>, unrecorded: list<string>}
     * @since 1.3.0
     */
    public function importPrices(array $prices, DateTime $asOf, bool $dryRun = false): array
    {
        $stamp = $this->day($asOf->format('Y-m-d'))->setTime(12, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $result = ['written' => 0, 'updated' => 0, 'unchanged' => 0, 'unknown' => [], 'unrecorded' => []];

        // Live variants by SKU, case-insensitive like the database. Commerce keeps
        // SKUs unique, but a list costs nothing if an install does not.
        $variants = [];
        foreach (array_chunk(array_keys($prices), 500) as $skus) {
            $rows = (new Query())
                ->select(['p.sku', 'v.id', 'v.primaryOwnerId'])
                ->from(['v' => '{{%commerce_variants}}'])
                ->innerJoin(['p' => '{{%commerce_purchasables}}'], '[[p.id]] = [[v.id]]')
                ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[v.id]]')
                ->where(['p.sku' => array_map('strval', $skus), 'e.dateDeleted' => null, 'e.revisionId' => null, 'e.draftId' => null])
                ->all();
            foreach ($rows as $row) {
                $variants[mb_strtolower((string)$row['sku'])][] = ['id' => (int)$row['id'], 'productId' => (int)$row['primaryOwnerId']];
            }
        }
        $recordedAfter = array_flip(array_map('intval', (new Query())
            ->select(['variantId'])
            ->distinct()
            ->from(ProductPriceHistoryRecord::tableName())
            ->where(['>', 'dateChanged', $stamp])
            ->column()));

        $transaction = $dryRun ? null : Craft::$app->getDb()->beginTransaction();
        try {
            foreach ($prices as $sku => $price) {
                $sku = (string)$sku;
                $key = mb_strtolower($sku);
                if (!isset($variants[$key])) {
                    $result['unknown'][] = $sku;
                    continue;
                }
                // The column holds cents.
                $price = round($price, 2);
                foreach ($variants[$key] as $variant) {
                    if (!isset($recordedAfter[$variant['id']])) {
                        $result['unrecorded'][] = $sku;
                        continue;
                    }
                    /** @var ProductPriceHistoryRecord|null $record */
                    $record = ProductPriceHistoryRecord::find()
                        ->where(['variantId' => $variant['id'], 'dateChanged' => $stamp])
                        ->one();
                    if ($record && abs((float)$record->price - $price) < 0.00005) {
                        $result['unchanged']++;
                        continue;
                    }
                    $result[$record ? 'updated' : 'written']++;
                    if ($dryRun) {
                        continue;
                    }
                    $record ??= new ProductPriceHistoryRecord();
                    $record->productId = $variant['productId'];
                    $record->variantId = $variant['id'];
                    $record->price = $price;
                    $record->dateChanged = $stamp;
                    $record->save(false);
                }
            }
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        return $result;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** The default reference day (`anchorDate`); per variant use anchorDayFor(). */
    public function anchorDay(): DateTime
    {
        return $this->day(ProductHistory::$plugin->getSettings()->anchorDate);
    }

    private function day(string $date): DateTime
    {
        return new DateTime($date, new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function dayEnd(DateTime $day): DateTime
    {
        $end = (clone $day)->setTime(23, 59, 59);
        // Rows are stored in UTC.
        return $end->setTimezone(new DateTimeZone('UTC'));
    }

    /** Whether resolving an anchor needs the variant element, not just its id. */
    private function needsVariant(): bool
    {
        $settings = ProductHistory::$plugin->getSettings();

        return $settings->anchorOverrideField !== ''
            || $settings->getAnchorDateRules() !== []
            || $this->hasEventHandlers(self::EVENT_DEFINE_ANCHOR_DATE);
    }

    private function variant(int|Variant $variant): ?Variant
    {
        if ($variant instanceof Variant) {
            return $variant;
        }

        /** @var Variant|null */
        return Variant::find()->id($variant)->status(null)->one();
    }

    /**
     * When the reduction the variant is on started (see ReductionStart), in UTC.
     */
    private function reductionStart(int|Variant $variant, DateTimeZone $utc): DateTime
    {
        $variantId = $variant instanceof Variant ? (int)$variant->id : $variant;
        $rows = array_map(static fn(array $row) => [
            'price' => (float)$row['price'],
            'date' => new DateTime($row['dateChanged'], $utc),
        ], (new Query())
            ->from(ProductPriceHistoryRecord::tableName())
            ->select(['price', 'dateChanged'])
            ->where(['variantId' => $variantId])
            ->orderBy(['dateChanged' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(2)
            ->all());
        $now = new DateTime('now', $utc);
        if ($rows === []) {
            return $now;
        }

        $element = $this->variant($variant);
        $salePrice = $element?->getSalePrice();
        $promotionStart = null;
        if ($element !== null && $salePrice !== null && $salePrice < $rows[0]['price']) {
            $promotionStart = ReductionStart::promotionStart($this->catalogPrices($element, $utc), $salePrice);
        }

        return ReductionStart::resolve($rows, $salePrice, $promotionStart, $now);
    }

    /**
     * The variant's active catalog prices in its store, for guests: rows of
     * rules with a customer condition (catalogpricingrules_users) are left
     * out, as on the price list.
     *
     * @return list<array{price: float, dateFrom: DateTime|null}>
     */
    private function catalogPrices(Variant $variant, DateTimeZone $utc): array
    {
        $now = Db::prepareDateForDb(new DateTime());
        $rows = (new Query())
            ->select(['price', 'dateFrom'])
            ->from(CommerceTable::CATALOG_PRICING)
            ->where(['purchasableId' => $variant->id, 'storeId' => $variant->getStoreId(), 'userId' => null])
            ->andWhere(['or', ['catalogPricingRuleId' => null], ['not in', 'catalogPricingRuleId', (new Query())
                ->select(['catalogPricingRuleId'])
                ->from(CommerceTable::CATALOG_PRICING_RULES_USERS)
                ->where(['not', ['catalogPricingRuleId' => null]]), ]])
            ->andWhere(['or', ['dateFrom' => null], ['<=', 'dateFrom', $now]])
            ->andWhere(['or', ['dateTo' => null], ['>=', 'dateTo', $now]])
            ->all();

        return array_map(static fn(array $row) => [
            'price' => (float)$row['price'],
            'dateFrom' => $row['dateFrom'] !== null ? new DateTime($row['dateFrom'], $utc) : null,
        ], $rows);
    }

    /**
     * @param list<array{date: string, productTypes: list<string>, relatedTo: list<int>}> $rules
     */
    private function ruleDate(array $rules, Variant $variant): ?string
    {
        $product = $variant->getOwner();
        $typeHandle = null;
        if ($product instanceof Product && $product->typeId) {
            $typeHandle = Commerce::getInstance()->getProductTypes()->getProductTypeById($product->typeId)?->handle;
        }
        $ids = array_filter([(int)$variant->id, (int)$variant->getPrimaryOwnerId()]);

        return AnchorDateRules::match($rules, $typeHandle, $ids, fn(int $i) => $this->ruleRelations($rules[$i]['relatedTo']));
    }

    /**
     * Element ids (products, variants) related to a rule's elements or to
     * their descendants, read once per set of elements and request.
     *
     * @param list<int> $targets
     * @return array<int, true>
     */
    private function ruleRelations(array $targets): array
    {
        $key = implode(',', $targets);
        if (isset($this->_ruleRelations[$key])) {
            return $this->_ruleRelations[$key];
        }

        // "Hrana" also covers "Hrana > Tjestenina": add the descendants in their structures.
        $ids = $targets;
        $nodes = (new Query())
            ->select(['structureId', 'lft', 'rgt'])
            ->from(Table::STRUCTUREELEMENTS)
            ->where(['elementId' => $targets])
            ->all();
        foreach ($nodes as $node) {
            $ids = [...$ids, ...array_map('intval', (new Query())
                ->select(['elementId'])
                ->from(Table::STRUCTUREELEMENTS)
                ->where(['structureId' => $node['structureId']])
                ->andWhere(['>', 'lft', $node['lft']])
                ->andWhere(['<', 'rgt', $node['rgt']])
                ->column())];
        }

        $sources = (new Query())
            ->select(['sourceId'])
            ->distinct()
            ->from(Table::RELATIONS)
            ->where(['targetId' => array_values(array_unique($ids))])
            ->column();

        return $this->_ruleRelations[$key] = array_fill_keys(array_map('intval', $sources), true);
    }

    /** The `anchorOverrideField` value of the variant, else its product; null when empty. */
    private function overridePrice(Variant $variant): ?float
    {
        $handle = ProductHistory::$plugin->getSettings()->anchorOverrideField;
        if ($handle === '') {
            return null;
        }

        foreach ([$variant, $variant->getOwner()] as $element) {
            if (!$element instanceof ElementInterface || !$element->getFieldLayout()?->getFieldByHandle($handle)) {
                continue;
            }
            $value = $element->getFieldValue($handle);
            $price = $value instanceof Money ? (float)MoneyHelper::toDecimal($value) : (is_numeric($value) ? (float)$value : null);
            // A field's default 0 is not a price.
            if ($price !== null && $price > 0) {
                return $price;
            }
        }

        return null;
    }
}
