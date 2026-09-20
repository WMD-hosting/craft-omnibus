<?php

namespace wmd\craftproductpricehistory;

use Craft;
use yii\base\Event;
use craft\base\Plugin;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\commerce\elements\Variant;
use craft\commerce\elements\Product;
use craft\db\Query;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\base\Model;
use wmd\craftproductpricehistory\models\Settings;
use wmd\craftproductpricehistory\services\AnchorService;
use wmd\craftproductpricehistory\services\PriceListService;
use wmd\craftproductpricehistory\services\ProductPriceHistoryService;
use wmd\craftproductpricehistory\utilities\ProductPriceUtility;
use wmd\craftproductpricehistory\variables\ProductPriceHistoryVariable;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;

class ProductHistory extends Plugin
{
    /**
     * @var ProductHistory
     */
    public static ProductHistory $plugin;

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'productPriceHistoryService' => ['class' => ProductPriceHistoryService::class],
                'anchor' => ['class' => AnchorService::class],
                'priceList' => ['class' => PriceListService::class],
            ],
        ];
    }

    public function getAnchor(): AnchorService
    {
        return $this->get('anchor');
    }

    public function getPriceList(): PriceListService
    {
        return $this->get('priceList');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('omnibus/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
            
            // Register the utility
            Event::on(
                Utilities::class,
                Utilities::EVENT_REGISTER_UTILITIES,
                function(RegisterComponentTypesEvent $event) {
                    $event->types[] = ProductPriceUtility::class;
                }
            );
        });

        // Public price-list index (NN 101/2026 čl. VII): /cjenik
        Event::on(
            \craft\web\UrlManager::class,
            \craft\web\UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(\craft\events\RegisterUrlRulesEvent $event) {
                $route = trim($this->getSettings()->priceListRoute, '/');
                if ($route !== '') {
                    $event->rules[$route] = 'omnibus/price-list/index';
                }
            }
        );

        // Register variable
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('productPriceHistory', ProductPriceHistoryVariable::class);
        });

        // Handle product price history before save (updates)
        Event::on(
            Variant::class,
            Variant::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) {
                $variant = $event->sender;

                // Ignore first save & drafts
                if ($variant->firstSave || $variant->getIsDraft()) {
                    return;
                }

                // check if product was added to product_price_history
                $productPriceHistory = ProductPriceHistoryRecord::find()
                    ->where(['productId' => $variant->productId, 'variantId' => $variant->id])
                    ->one();

                // $oldVariant = Commerce::getInstance()->getVariants()->getVariantById($variant->id);
                // $oldPrice = $oldVariant->basePrice;
                $oldPrice = (new Query())
                    ->from('{{%commerce_purchasables_stores}}')
                    ->select(['basePrice'])
                    ->where(['purchasableId' => $variant->id])
                    ->orderBy(['storeId' => SORT_ASC]) // pick the first store arbitrarily if multiple exist
                    ->scalar();
                    
                // add to product_price_history if it doesn't exist
                if (!$productPriceHistory && $oldPrice !== null) {
                    ProductHistory::$plugin
                        ->productPriceHistoryService
                        ->addProductHistoryPrice($variant->productId, $variant->id, $oldPrice);
                }

                $newPrice = $variant->basePrice;

                if ($oldPrice === null || abs((float)$oldPrice - (float)$newPrice) < 0.001) {
                    return; // nothing changed
                }
        
                ProductHistory::$plugin
                    ->productPriceHistoryService
                    ->addProductHistoryPrice(
                        $variant->productId,
                        $variant->id,
                        $newPrice,
                    );
            }
        );

        // Handle product price history after save (first-time creation)
        Event::on(
            Product::class,
            Product::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                if ($event->sender->firstSave) {
                    $product = $event->sender;

                    // Iterate through product variants
                    foreach ($product->variants as $variant) {
                        // Ensure the variant has a valid ID before proceeding
                        if ($variant->id !== null) {
                            ProductHistory::$plugin
                                ->productPriceHistoryService
                                ->addProductHistoryPrice($product->id, $variant->id, $variant->basePrice);
                        }
                    }
                }
            }
        );
    }

    private function attachEventHandlers(): void
    {
        // Register other event handlers if needed
    }
}
