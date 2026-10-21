<?php

namespace wmd\craftproductpricehistory\migrations;

use craft\db\Migration;

/**
 * Index for the per-variant lookups (lowest price in a window, the duplicate check on
 * every save). Only productId was indexed, so they scanned the whole table.
 */
class m261021_000000_variant_date_index extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createIndexIfMissing('{{%product_price_history}}', ['variantId', 'dateChanged']);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropIndexIfExists('{{%product_price_history}}', ['variantId', 'dateChanged']);

        return true;
    }
}
