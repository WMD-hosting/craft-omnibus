<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\events;

use craft\commerce\elements\Variant;
use yii\base\Event;

/**
 * The reference day of one variant's anchor price. `date` arrives as the
 * plugin's answer (the first matching `anchorDateRules` entry, else
 * `anchorDate`); a handler sets another Y-m-d day, for example 2025-05-02 for
 * food, drink, cosmetics, cleaning, toiletries and household goods
 * (NN 101/2026-1212 t. IV; draft Pravilnik o isticanju bazne cijene, čl. 3(1)).
 *
 * @since 1.3.0
 */
class DefineAnchorDateEvent extends Event
{
    public Variant $variant;

    /** @var string Y-m-d */
    public string $date = '';
}
