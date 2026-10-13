<?php

namespace wmd\craftproductpricehistory\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $uid
 * @property int $productId
 * @property int $variantId
 * @property float|string $price Decimal column: written as a float, read back as a string
 * @property string $dateChanged
 */
class ProductPriceHistoryRecord extends ActiveRecord
{
    /**
    * @inheritdoc
    */
    public static function tableName(): string
    {
        return '{{%product_price_history}}';
    }
}
