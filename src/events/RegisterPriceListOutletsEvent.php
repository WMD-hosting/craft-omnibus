<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\events;

use wmd\craftproductpricehistory\models\PriceListOutlet;
use yii\base\Event;

/**
 * Lets a site list its outlets. `outlets` starts with the web shop from the
 * plugin settings; append one PriceListOutlet per physical store, or remove
 * the web shop if the site does not sell online.
 *
 * @since 1.2.0
 */
class RegisterPriceListOutletsEvent extends Event
{
    /** @var PriceListOutlet[] */
    public array $outlets = [];
}
