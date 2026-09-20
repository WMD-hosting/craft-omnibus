<?php

namespace wmd\craftproductpricehistory\services;

use Craft;
use craft\base\Component;
use DateTime;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use wmd\craftproductpricehistory\models\ProductPriceHistoryModel;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;


class ProductPriceHistoryService extends Component
{
    /**
     * @param int $productId
     * @param int $variantId
     * @param float $price
     * @return bool
     */
    public function addProductHistoryPrice(int $productId, int $variantId, float $price): bool
    {
        // Validate price
        if ($price < 0) {
            Craft::warning("Invalid price {$price} for variant {$variantId}. Price cannot be negative.", __METHOD__);
            return false;
        }

        // Check for duplicate recent entry (within last minute with same price)
        $recentEntry = ProductPriceHistoryRecord::find()
            ->where(['variantId' => $variantId])
            ->andWhere(['>=', 'dateChanged', (new DateTime())->modify('-1 minute')->format('Y-m-d H:i:s')])
            ->orderBy(['dateChanged' => SORT_DESC])
            ->one();

        if ($recentEntry && abs((float)$recentEntry->price - $price) < 0.001) {
            // Duplicate entry, skip
            return false;
        }

        $productPriceHistoryRecord = new ProductPriceHistoryRecord();
        $productPriceHistoryRecord->setAttribute('productId', $productId);
        $productPriceHistoryRecord->setAttribute('variantId', $variantId);
        $productPriceHistoryRecord->setAttribute('price', $price);

        // Save record in DB
        return $productPriceHistoryRecord->save();
    }

    /**
     * @param int $productId
     * @param $daysAgo
     * @param bool $findMostRecentIfEmpty
     * @return float|null
     */
    public function getProductLowestPrice(int $productId, $daysAgo = null, bool $findMostRecentIfEmpty = false): float|null
    {
        return $this->getLowestPrice('productId', $productId, $daysAgo, $findMostRecentIfEmpty);
    }

    /**
     * @param int $variantId
     * @param $daysAgo
     * @param bool $findMostRecentIfEmpty
     * @return float
     */
    public function getVariantLowestPrice(int $variantId, $daysAgo = null, bool $findMostRecentIfEmpty = false): float|null
    {
        return $this->getLowestPrice('variantId', $variantId, $daysAgo, $findMostRecentIfEmpty);
    }

    /**
     * @param string $field
     * @param int $value
     * @param $daysAgo
     * @param bool $findMostRecentIfEmpty
     * @return float|null
     */
    private function getLowestPrice(string $field, int $value, $daysAgo = null, bool $findMostRecentIfEmpty = false): float|null
    {
        $productPriceHistoryModel = new ProductPriceHistoryModel();

        $query = ProductPriceHistoryRecord::find()
            ->where([$field => $value])
            ->orderBy(['price' => SORT_ASC]);

        // Add condition for fromDate if provided
        if ($daysAgo !== null) {
            $dateAgo = (new DateTime())->modify("-$daysAgo days")->format('Y-m-d H:i:s');
            $query->andWhere(['>=', 'dateChanged', $dateAgo]);
        }

        $productPriceHistoryRecord = $query->one();

        // If no record is found after fromDate, get the most recent record
        if ($productPriceHistoryRecord === null && $findMostRecentIfEmpty) {
            $productPriceHistoryRecord = ProductPriceHistoryRecord::find()
                ->where([$field => $value])
                ->orderBy(['dateChanged' => SORT_DESC]) // Order by most recent date
                ->one();
        }

        if ($productPriceHistoryRecord) {
            // Populate model from record
            $productPriceHistoryModel->setAttributes($productPriceHistoryRecord->getAttributes(), false);
        } else {
            return null;
        }

        return $productPriceHistoryModel->price;
    }

    public function isVariantAdded(int $variantId): bool
    {
        return ProductPriceHistoryRecord::find()->where(['variantId' => $variantId])->exists();
    }

    /**
     * Get product price history data grouped by product and variant with pagination
     *
     * @param int $page
     * @param int $limit
     * @param int $historyLimit Maximum number of price history records per variant
     * @return array
     */
    public function getProductPriceHistory(int $page = 1, int $limit = 20, int $historyLimit = 50): array
    {
        $offset = ($page - 1) * $limit;

        // Get unique product IDs with pagination
        $productIds = ProductPriceHistoryRecord::find()
            ->select(['productId'])
            ->distinct()
            ->orderBy(['productId' => SORT_ASC])
            ->offset($offset)
            ->limit($limit)
            ->column();

        if (empty($productIds)) {
            return [
                'products' => [],
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => 0,
                    'totalProducts' => 0,
                    'limit' => $limit
                ]
            ];
        }

        // Eager load all products at once
        $productsById = Product::find()
            ->id($productIds)
            ->indexBy('id')
            ->all();

        // Get all variant IDs for these products
        $variantIdsByProduct = [];
        $allVariantIds = [];

        $variantResults = ProductPriceHistoryRecord::find()
            ->select(['productId', 'variantId'])
            ->where(['productId' => $productIds])
            ->distinct()
            ->all();

        foreach ($variantResults as $result) {
            $variantIdsByProduct[$result->productId][] = $result->variantId;
            $allVariantIds[] = $result->variantId;
        }

        // Eager load all variants at once (Commerce has no getAllVariants; use Variant::find())
        $variantsById = [];
        if (!empty($allVariantIds)) {
            $loadedVariants = Variant::find()
                ->id($allVariantIds)
                ->all();

            foreach ($loadedVariants as $variant) {
                $variantsById[$variant->id] = $variant;
            }
        }

        // Batch load price history for all variants
        $priceHistoryByVariant = [];
        $allPriceHistory = ProductPriceHistoryRecord::find()
            ->where(['variantId' => $allVariantIds])
            ->orderBy(['variantId' => SORT_ASC, 'dateChanged' => SORT_DESC])
            ->limit($historyLimit * count($allVariantIds))
            ->all();

        foreach ($allPriceHistory as $record) {
            if (!isset($priceHistoryByVariant[$record->variantId])) {
                $priceHistoryByVariant[$record->variantId] = [];
            }
            if (count($priceHistoryByVariant[$record->variantId]) < $historyLimit) {
                $priceHistoryByVariant[$record->variantId][] = $record;
            }
        }

        // Build final structure
        $products = [];

        foreach ($productIds as $productId) {
            if (!isset($productsById[$productId])) {
                continue;
            }

            $product = $productsById[$productId];
            $variants = [];

            if (isset($variantIdsByProduct[$productId])) {
                foreach ($variantIdsByProduct[$productId] as $variantId) {
                    if (!isset($variantsById[$variantId])) {
                        continue;
                    }

                    $priceHistory = $priceHistoryByVariant[$variantId] ?? [];
                    // Reverse to show oldest first
                    $priceHistory = array_reverse($priceHistory);

                    $variants[] = [
                        'variant' => $variantsById[$variantId],
                        'priceHistory' => $priceHistory
                    ];
                }
            }

            if (!empty($variants)) {
                $products[] = [
                    'product' => $product,
                    'variants' => $variants
                ];
            }
        }

        return [
            'products' => $products,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $this->getTotalPagesCount($limit),
                'totalProducts' => $this->getTotalProductsCount(),
                'limit' => $limit
            ]
        ];
    }

    /**
     * Get total count of products with price history
     * 
     * @return int
     */
    public function getTotalProductsCount(): int
    {
        return ProductPriceHistoryRecord::find()
            ->select(['productId'])
            ->distinct()
            ->count();
    }

    /**
     * Get total pages count for pagination
     * 
     * @param int $limit
     * @return int
     */
    public function getTotalPagesCount(int $limit = 20): int
    {
        $totalProducts = $this->getTotalProductsCount();
        return (int) ceil($totalProducts / $limit);
    }

    /**
     * Search product price history by title and description
     *
     * @param string $searchTerm
     * @param int $page
     * @param int $limit
     * @param int $historyLimit Maximum number of price history records per variant
     * @return array
     */
    public function searchProductPriceHistory(string $searchTerm, int $page = 1, int $limit = 20, int $historyLimit = 50): array
    {
        try {
            $offset = ($page - 1) * $limit;

            // Log search parameters
            Craft::info("Searching for: '{$searchTerm}', page: {$page}, limit: {$limit}", __METHOD__);

            // Search in products by title
            $productQuery = Product::find()
                ->search($searchTerm);

            // Get product IDs that match the search
            $matchingProductIds = $productQuery->ids();
            Craft::info("Found " . count($matchingProductIds) . " matching product IDs", __METHOD__);

            // Also search in variants by title
            $variantQuery = \craft\commerce\elements\Variant::find()
                ->search($searchTerm);

            $matchingVariantIds = $variantQuery->ids();
            Craft::info("Found " . count($matchingVariantIds) . " matching variant IDs", __METHOD__);

            // Get product IDs from matching variants
            $variantProductIds = [];
            if (!empty($matchingVariantIds)) {
                $variantProductIds = (new \craft\db\Query())
                    ->select(['ownerId'])
                    ->from('{{%elements_owners}}')
                    ->where(['elementId' => $matchingVariantIds])
                    ->column();
            }

            // Combine all matching product IDs
            $allMatchingProductIds = array_unique(array_merge($matchingProductIds, $variantProductIds));
            Craft::info("Total unique product IDs to check: " . count($allMatchingProductIds), __METHOD__);

            if (empty($allMatchingProductIds)) {
                return [
                    'products' => [],
                    'pagination' => [
                        'currentPage' => $page,
                        'totalPages' => 0,
                        'totalProducts' => 0,
                        'limit' => $limit
                    ]
                ];
            }

            // Filter to only products that have price history
            $productsWithHistory = ProductPriceHistoryRecord::find()
                ->select(['productId'])
                ->distinct()
                ->where(['productId' => $allMatchingProductIds])
                ->orderBy(['productId' => SORT_ASC])
                ->offset($offset)
                ->limit($limit)
                ->column();

            if (empty($productsWithHistory)) {
                return [
                    'products' => [],
                    'pagination' => [
                        'currentPage' => $page,
                        'totalPages' => 0,
                        'totalProducts' => 0,
                        'limit' => $limit
                    ]
                ];
            }

            // Eager load all products at once
            $productsById = Product::find()
                ->id($productsWithHistory)
                ->indexBy('id')
                ->all();

            // Get all variant IDs for these products
            $variantIdsByProduct = [];
            $allVariantIds = [];

            $variantResults = ProductPriceHistoryRecord::find()
                ->select(['productId', 'variantId'])
                ->where(['productId' => $productsWithHistory])
                ->distinct()
                ->all();

            foreach ($variantResults as $result) {
                $variantIdsByProduct[$result->productId][] = $result->variantId;
                $allVariantIds[] = $result->variantId;
            }

            // Eager load all variants at once (Commerce has no getAllVariants; use Variant::find())
            $variantsById = [];
            if (!empty($allVariantIds)) {
                $loadedVariants = Variant::find()
                    ->id($allVariantIds)
                    ->all();

                foreach ($loadedVariants as $variant) {
                    $variantsById[$variant->id] = $variant;
                }
            }

            // Batch load price history for all variants
            $priceHistoryByVariant = [];
            $allPriceHistory = ProductPriceHistoryRecord::find()
                ->where(['variantId' => $allVariantIds])
                ->orderBy(['variantId' => SORT_ASC, 'dateChanged' => SORT_DESC])
                ->limit($historyLimit * count($allVariantIds))
                ->all();

            foreach ($allPriceHistory as $record) {
                if (!isset($priceHistoryByVariant[$record->variantId])) {
                    $priceHistoryByVariant[$record->variantId] = [];
                }
                if (count($priceHistoryByVariant[$record->variantId]) < $historyLimit) {
                    $priceHistoryByVariant[$record->variantId][] = $record;
                }
            }

            // Build final structure
            $products = [];

            foreach ($productsWithHistory as $productId) {
                if (!isset($productsById[$productId])) {
                    continue;
                }

                $product = $productsById[$productId];
                $variants = [];

                if (isset($variantIdsByProduct[$productId])) {
                    foreach ($variantIdsByProduct[$productId] as $variantId) {
                        if (!isset($variantsById[$variantId])) {
                            continue;
                        }

                        $priceHistory = $priceHistoryByVariant[$variantId] ?? [];
                        // Reverse to show oldest first
                        $priceHistory = array_reverse($priceHistory);

                        $variants[] = [
                            'variant' => $variantsById[$variantId],
                            'priceHistory' => $priceHistory
                        ];
                    }
                }

                if (!empty($variants)) {
                    $products[] = [
                        'product' => $product,
                        'variants' => $variants
                    ];
                }
            }

            Craft::info("Returning " . count($products) . " products with variants", __METHOD__);

            return [
                'products' => $products,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $this->getSearchTotalPagesCount($searchTerm, $limit),
                    'totalProducts' => $this->getSearchTotalProductsCount($searchTerm),
                    'limit' => $limit
                ]
            ];
        } catch (\Exception $e) {
            Craft::error('Search service error: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }

    /**
     * Get total count of products with price history that match search
     * 
     * @param string $searchTerm
     * @return int
     */
    public function getSearchTotalProductsCount(string $searchTerm): int
    {
        // Search in products by title
        $productQuery = Product::find()
            ->search($searchTerm);
        
        $matchingProductIds = $productQuery->ids();
        
        // Also search in variants by title
        $variantQuery = \craft\commerce\elements\Variant::find()
            ->search($searchTerm);
        
        $matchingVariantIds = $variantQuery->ids();
        
        // Get product IDs from matching variants
        $variantProductIds = [];
        if (!empty($matchingVariantIds)) {
            $variantProductIds = (new \craft\db\Query())
                ->select(['ownerId'])
                ->from('{{%elements_owners}}')
                ->where(['elementId' => $matchingVariantIds])
                ->column();
        }
        
        // Combine all matching product IDs
        $allMatchingProductIds = array_unique(array_merge($matchingProductIds, $variantProductIds));
        
        // Count only products that have price history
        return ProductPriceHistoryRecord::find()
            ->select(['productId'])
            ->distinct()
            ->where(['productId' => $allMatchingProductIds])
            ->count();
    }

    /**
     * Get total pages count for search pagination
     * 
     * @param string $searchTerm
     * @param int $limit
     * @return int
     */
    public function getSearchTotalPagesCount(string $searchTerm, int $limit = 20): int
    {
        $totalProducts = $this->getSearchTotalProductsCount($searchTerm);
        return (int) ceil($totalProducts / $limit);
    }
}
