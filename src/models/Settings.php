<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\models;

use Craft;
use craft\base\Model;
use wmd\craftproductpricehistory\helpers\AnchorDateRules;

/**
 * Plugin settings: the anchor ("sidrena cijena") reference date and the
 * price-list publication required by NN 101/2026 (Odluka o objavi cjenika
 * proizvoda i usluga, in force 1 October 2026).
 *
 * @since 1.1.0
 */
class Settings extends Model
{
    // =========================================================================
    // Anchor price ("dodatna / sidrena cijena", NN 101/2026)
    // =========================================================================

    /**
     * @var string The reference day. The anchor is the price applicable on
     * this day, i.e. the last recorded price at or before its end.
     */
    public string $anchorDate = '2026-09-10';

    /**
     * @var array Products that take another reference day, first match wins:
     * `[['date' => '2025-05-02', 'productTypes' => ['food'], 'relatedTo' => [12, 34]], ...]`.
     * `productTypes` are product type handles; `relatedTo` are ids of
     * categories or entries the product or variant relates to (descendants
     * included). NN 101/2026-1212 t. IV keeps 2.5.2025 for food, drink,
     * cosmetics, cleaning, toiletries and household goods; the draft
     * Pravilnik o isticanju bazne cijene (čl. 3(1)) extends it to every trader.
     * PHP code can decide instead through AnchorService::EVENT_DEFINE_ANCHOR_DATE.
     * @since 1.3.0
     */
    public array $anchorDateRules = [];

    private bool $_loggedRuleErrors = false;

    /**
     * @var string Handle of a number or money field on the variant (or its
     * product) that, when filled, is the anchor price itself. For prices the
     * history cannot compute, such as the draft Pravilnik's čl. 3(3) case: a
     * product on sale on 2.5.2025 takes the lowest price of the 30 days
     * before that sale started.
     * @since 1.3.0
     */
    public string $anchorOverrideField = '';

    /**
     * @var string Label shown next to the selling price. The decision names
     * both "dodatna cijena" and "sidrena cijena".
     */
    public string $anchorLabel = '';

    /**
     * @var bool For products first listed after the reference day, use the
     * price on the first day of listing (with its date) as the anchor.
     */
    public bool $anchorFromFirstListing = true;

    /**
     * @var int Days for the "lowest price before a reduction" rule
     * (Zakon o zaštiti potrošača, čl. 19; Omnibus).
     */
    public int $lowestPriceDays = 30;

    // =========================================================================
    // Price list ("cjenik", NN 101/2026 čl. III–VII)
    // =========================================================================

    /** @var bool Generate the daily price-list files. */
    public bool $priceListEnabled = true;

    /** @var string Oblik prodajnog objekta, part of the file name (čl. VI). */
    public string $storeType = 'internetska-trgovina';

    /** @var string Adresa prodajnog objekta, part of the file name. */
    public string $storeAddress = '';

    /** @var string Oznaka prodajnog objekta, part of the file name. */
    public string $storeCode = 'webshop';

    /** @var string Public directory the files are written to (alias allowed). */
    public string $priceListPath = '@webroot/cjenik';

    /** @var string Site URI of the public index page listing the published files. */
    public string $priceListRoute = 'cjenik';

    /** @var int Days each published file stays available (čl. VII: 30). */
    public int $retentionDays = 30;

    /** @var string CSV delimiter. */
    public string $csvDelimiter = ';';

    /** @var string Field handle on the product holding the brand (čl. III "marka"). Category/Entries or text. */
    public string $brandField = 'productsBrandCategories';

    /** @var string Field handle on the variant or product holding the barcode ("barkod robe"). */
    public string $barcodeField = '';

    /**
     * @var string Field handle holding the net quantity ("neto količina",
     * draft Pravilnik o načinu objave cjenika čl. 3(2)). A row handler such
     * as wmd/craft-units may fill the column instead.
     * @since 1.3.0
     */
    public string $netQuantityField = '';

    /**
     * @var string `naziv_posebnog_oblika_prodaje` for a reduced price no
     * catalog pricing rule explains (a promotional price entered on the
     * variant). Empty uses the translated default ("Sniženje").
     * @since 1.3.0
     */
    public string $saleName = '';

    /** @var string Field handle holding the unit of measure ("jedinica mjere"). */
    public string $unitField = '';

    /** @var string Field handle holding the price per unit of measure. */
    public string $unitPriceField = '';

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['anchorDate', 'storeType', 'storeCode', 'priceListPath', 'priceListRoute', 'csvDelimiter'], 'required'],
            [['priceListRoute'], 'match', 'pattern' => '/^[a-z0-9\/-]+$/i'],
            [['anchorDate'], 'date', 'format' => 'php:Y-m-d'],
            [['lowestPriceDays', 'retentionDays'], 'integer', 'min' => 1],
            [['csvDelimiter'], 'string', 'length' => 1],
            [['storeAddress', 'brandField', 'barcodeField', 'unitField', 'unitPriceField', 'netQuantityField', 'anchorOverrideField', 'saleName'], 'string'],
            [['anchorDateRules'], 'validateAnchorDateRules'],
        ];
    }

    /**
     * The anchor date rules in one shape, whether they came from the CP or a
     * config file. Rules without a valid day are left out (and logged): a
     * config file is never validated, and a wrong day must not reach the
     * published prices.
     *
     * @return list<array{date: string, productTypes: list<string>, relatedTo: list<int>}>
     * @since 1.3.0
     */
    public function getAnchorDateRules(): array
    {
        $rules = AnchorDateRules::normalize($this->anchorDateRules);
        $errors = AnchorDateRules::errors($rules);
        if ($errors === []) {
            return $rules;
        }

        if (!$this->_loggedRuleErrors) {
            $this->_loggedRuleErrors = true;
            Craft::warning('Ignored anchorDateRules: ' . implode(' ', $errors), __METHOD__);
        }

        return array_values(array_filter($rules, static fn(array $rule) => AnchorDateRules::errors([$rule]) === []));
    }

    /**
     * @since 1.3.0
     */
    public function validateAnchorDateRules(string $attribute): void
    {
        foreach (AnchorDateRules::errors(AnchorDateRules::normalize($this->anchorDateRules)) as $error) {
            $this->addError($attribute, $error);
        }
    }
}
