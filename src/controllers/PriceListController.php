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
        $dir = Craft::getAlias($settings->priceListPath);
        $files = is_dir($dir) ? (glob("$dir/*.{csv,xml}", GLOB_BRACE) ?: []) : [];
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        $baseUrl = rtrim(str_replace(Craft::getAlias('@webroot'), Craft::getAlias('@web'), $dir), '/');
        $rows = array_map(static fn(string $file) => [
            'name' => basename($file),
            'url' => $baseUrl . '/' . basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i', filemtime($file)),
        ], $files);

        $html = Craft::$app->getView()->renderTemplate('craft-product-price-history/_price-list', [
            'files' => $rows,
            'anchorDate' => $settings->anchorDate,
            'retentionDays' => $settings->retentionDays,
        ], Craft::$app->getView()::TEMPLATE_MODE_CP);

        $this->response->getHeaders()->set('Content-Type', 'text/html; charset=UTF-8')->set('X-Robots-Tag', 'index, follow');

        return $this->asRaw($html);
    }
}
