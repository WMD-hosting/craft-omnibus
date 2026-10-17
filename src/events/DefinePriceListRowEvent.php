<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\events;

use wmd\craftproductpricehistory\models\PriceListOutlet;
use yii\base\Event;

/**
 * One price-list row for one outlet, before it is written. A store that sells
 * at its own price sets `maloprodajna_cijena` (and `posebni_oblik_prodaje`)
 * here; set `row` to null to leave the product out of that outlet's file.
 *
 * @since 1.2.0
 */
class DefinePriceListRowEvent extends Event
{
    public PriceListOutlet $outlet;

    public int $variantId;

    /**
     * @var array<string, string>|null Columns of PriceListService::COLUMNS, plus
     * PriceListService::ROW_ANCHOR_DATE (the day of `sidrena_cijena`, since 1.3.0).
     * Change values in place; a row rebuilt from COLUMNS alone loses that day.
     */
    public ?array $row = null;
}
