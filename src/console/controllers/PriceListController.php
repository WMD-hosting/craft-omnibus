<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use wmd\craftproductpricehistory\ProductHistory;
use yii\console\ExitCode;

/**
 * Publishes the NN 101/2026 price lists, one per outlet. Schedule `publish`
 * daily before 08:00.
 *
 * @since 1.1.0
 */
class PriceListController extends Controller
{
    public $defaultAction = 'publish';

    /** @var string|null Publish or preview only the outlet with this code. */
    public ?string $outlet = null;

    /**
     * @var int|null Ignored; prices are always the current Commerce store's.
     * @deprecated in 1.2.0. Kept so existing cron lines keep working.
     */
    public ?int $store = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [...parent::options($actionID), 'outlet', 'store'];
    }

    /**
     * Writes today's CSV and XML for every outlet, prunes old files, rewrites the index.
     */
    public function actionPublish(): int
    {
        if (!ProductHistory::$plugin->getSettings()->priceListEnabled) {
            $this->stdout("Price list publication is disabled in the plugin settings.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        try {
            $result = ProductHistory::$plugin->getPriceList()->publish($this->outlet);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        foreach ($result['files'] as $file) {
            $this->stdout("{$file['name']} ({$file['code']}): {$file['rows']} rows\n", Console::FG_GREEN);
            $this->stdout("  CSV   {$file['csv']}\n");
            $this->stdout("  XML   {$file['xml']}\n");
        }
        $this->stdout("index {$result['index']}\n");
        if ($result['pruned']) {
            $this->stdout("pruned {$result['pruned']} file(s) past retention\n");
        }

        return ExitCode::OK;
    }

    /**
     * Lists the outlets that get a file: the web shop and the stores a site registers.
     */
    public function actionOutlets(): int
    {
        foreach (ProductHistory::$plugin->getPriceList()->outlets() as $outlet) {
            $location = $outlet->inventoryLocationId ? "location #{$outlet->inventoryLocationId}" : 'web stock';
            $this->stdout(str_pad($outlet->code, 20) . ' ' . $outlet->displayName() . ", $location\n");
            $this->stdout('  ' . $outlet->prefix() . "_N_YYYYMMDD_HHMMSS.csv\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Prints the rows without writing anything.
     */
    public function actionPreview(): int
    {
        $priceList = ProductHistory::$plugin->getPriceList();
        $outlet = $this->outlet !== null ? $priceList->getOutletByCode($this->outlet) : null;
        if ($this->outlet !== null && $outlet === null) {
            $this->stderr("No price-list outlet has the code \"{$this->outlet}\".\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $rows = $priceList->rows($outlet);
        $this->stdout(ProductHistory::$plugin->getPriceList()->csv(array_slice($rows, 0, 20)));
        $this->stdout(count($rows) . " rows total\n", Console::FG_GREY);

        return ExitCode::OK;
    }
}
