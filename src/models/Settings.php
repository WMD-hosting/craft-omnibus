<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\models;

use craft\base\Model;

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
     * @var string Label shown next to the selling price. The decision names
     * both "dodatna cijena" and "sidrena cijena".
     */
    public string $anchorLabel = 'Sidrena cijena';

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
            [['anchorDate', 'anchorLabel', 'storeType', 'storeCode', 'priceListPath', 'priceListRoute', 'csvDelimiter'], 'required'],
            [['priceListRoute'], 'match', 'pattern' => '/^[a-z0-9\/-]+$/i'],
            [['anchorDate'], 'date', 'format' => 'php:Y-m-d'],
            [['lowestPriceDays', 'retentionDays'], 'integer', 'min' => 1],
            [['csvDelimiter'], 'string', 'length' => 1],
            [['storeAddress', 'brandField', 'barcodeField', 'unitField', 'unitPriceField'], 'string'],
        ];
    }
}
