<?php

namespace wmd\craftproductpricehistory\utilities;

use Craft;
use craft\base\Utility;
use wmd\craftproductpricehistory\ProductHistory;

/**
 * Product Price History utility.
 */
class ProductPriceUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Product Price History';
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'product-price-history';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $service = ProductHistory::$plugin->productPriceHistoryService;
        
        // Get current page from request
        $page = (int) Craft::$app->getRequest()->getQueryParam('page', 1);
        if ($page < 1) {
            $page = 1;
        }
        
        $data = $service->getProductPriceHistory($page);
        
        return Craft::$app->getView()->renderTemplate('omnibus/_utility.twig', [
            'data' => $data,
        ]);
    }
} 