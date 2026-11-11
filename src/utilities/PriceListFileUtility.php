<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\utilities;

use Craft;
use craft\base\Utility;
use wmd\craftproductpricehistory\helpers\PriceListSource;
use wmd\craftproductpricehistory\ProductHistory;

/**
 * Upload of the price-list spreadsheet, for a shop whose prices are not in
 * Commerce (setting `priceListSource` = `file`). Who may upload is the
 * utility's own permission, `utility:omnibus-price-list-file`.
 *
 * @since 1.4.0
 */
class PriceListFileUtility extends Utility
{
    /** Session key of the last upload's result, shown once. */
    public const FLASH_KEY = 'omnibus.priceListImport';

    public static function displayName(): string
    {
        return Craft::t('omnibus', 'Price list file');
    }

    public static function id(): string
    {
        return 'omnibus-price-list-file';
    }

    public static function icon(): ?string
    {
        return 'file-spreadsheet';
    }

    public static function contentHtml(): string
    {
        $plugin = ProductHistory::$plugin;
        $settings = $plugin->getSettings();
        $source = $plugin->getPriceListSource();
        $session = Craft::$app->getSession();

        $preview = [];
        $info = $source->info();
        if ($info !== null) {
            $preview = array_slice($source->rows(), 0, 10);
        }

        return Craft::$app->getView()->renderTemplate('omnibus/_price-list-file', [
            'info' => $info,
            'preview' => $preview,
            'lastImport' => $session->remove(self::FLASH_KEY),
            'columns' => array_keys(PriceListSource::ALIASES),
            'required' => PriceListSource::REQUIRED,
            'indexUrl' => '/' . trim($settings->priceListRoute, '/'),
            'publishEnabled' => $settings->priceListEnabled,
        ]);
    }
}
