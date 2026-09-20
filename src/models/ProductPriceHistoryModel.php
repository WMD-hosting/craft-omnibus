<?php

namespace wmd\craftproductpricehistory\models;

use craft\base\Model;
use DateTime;

class ProductPriceHistoryModel extends Model
{
    /**
     * @var int|null
     */
    public ?int $id = null;

    /**
     * @var int|null
     */
    public ?int $productId = null;

    /**
     * @var int|null
     */
    public ?int $variantId = null;

    /**
     * @var float
     */
    public float $price = 0;

    /**
     *
     * @var DateTime|null
     */
    public ?DateTime $dateChanged = null;


    /**
     * Define what is returned when model is converted to string
     */
    public function __toString(): string
    {
        return (string)$this->price;
    }
}