<?php

namespace wmd\craftproductpricehistory\controllers;

use Craft;
use craft\web\Controller;
use wmd\craftproductpricehistory\ProductHistory;

/**
 * Product Price History Controller
 */
class ProductPriceHistoryController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Search products and variants by title and description
     */
    public function actionSearch()
    {
        $this->requirePostRequest();
        
        try {
            $request = Craft::$app->getRequest();
            $searchTerm = $request->getBodyParam('searchTerm', '');
            $page = (int) $request->getBodyParam('page', 1);
            
            if ($page < 1) {
                $page = 1;
            }
            
            // Log the request for debugging
            Craft::info("Search request received: term='{$searchTerm}', page={$page}", __METHOD__);
            
            $service = ProductHistory::$plugin->productPriceHistoryService;
            $data = $service->searchProductPriceHistory($searchTerm, $page);
            
            // Log the response for debugging
            Craft::info("Search response: " . count($data['products']) . " products found", __METHOD__);
            
            return $this->asJson([
                'success' => true,
                'data' => $data,
                'searchTerm' => $searchTerm
            ]);
        } catch (\Exception $e) {
            Craft::error('Search error: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
} 