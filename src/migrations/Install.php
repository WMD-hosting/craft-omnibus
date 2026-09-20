<?php

namespace wmd\craftproductpricehistory\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%product_price_history}}')) {
            $this->createTable('{{%product_price_history}}', [
                'id' => $this->primaryKey(),
                'uid' => $this->uid(),
                'productId' => $this->integer()->notNull(),
                'variantId' => $this->integer()->notNull(),
                'price' => $this->decimal(10, 2)->notNull(),
                'dateChanged' => $this->dateTime()->defaultExpression('CURRENT_TIMESTAMP')->notNull(),
            ]);

            $this->addForeignKey(null, '{{%product_price_history}}', 'productId', '{{%commerce_products}}', 'id', 'CASCADE');

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%product_price_history}}');

        return true;
    }
}