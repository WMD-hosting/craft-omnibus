<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\controllers;

use Craft;
use craft\web\Controller;
use wmd\craftproductpricehistory\ProductHistory;
use yii\web\Response;

/**
 * Public index of the published price-list files (NN 101/2026 čl. VII asks
 * for them to be reachable by automated collectors, so nothing is gated).
 *
 * @since 1.1.0
 */
class PriceListController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        $settings = ProductHistory::$plugin->getSettings();
        $priceList = ProductHistory::$plugin->getPriceList();
        $dir = Craft::getAlias($settings->priceListPath);
        $baseUrl = rtrim(str_replace(Craft::getAlias('@webroot'), Craft::getAlias('@web'), $dir), '/');

        $html = $priceList->renderIndex($priceList->publishedFiles($dir, $baseUrl));

        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8')->set('X-Robots-Tag', 'index, follow');

        return $this->asRaw($html);
    }
}
