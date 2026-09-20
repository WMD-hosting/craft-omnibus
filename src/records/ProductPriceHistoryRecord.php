<?php

namespace wmd\craftproductpricehistory\records;

use craft\db\ActiveRecord;

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