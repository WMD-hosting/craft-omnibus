<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use InvalidArgumentException;
use wmd\craftproductpricehistory\models\Settings;
use wmd\craftproductpricehistory\ProductHistory;
use yii\console\ExitCode;

/**
 * Publishes the NN 101/2026 price lists, one per outlet. Schedule `publish`
 * daily before 08:00. `import` loads the spreadsheet of a shop whose prices
 * are not in Commerce.
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

    /** @var bool `import`: read and report, keep the current file. */
    public bool $dryRun = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        if ($actionID === 'import') {
            return [...parent::options($actionID), 'dryRun'];
        }
        return [...parent::options($actionID), 'outlet', 'store'];
    }

    /**
     * Makes a spreadsheet (xlsx, xls, ods) or CSV the price-list source, as
     * the upload in Utilities does. Takes effect with the next publish.
     *
     * @since 1.4.0
     */
    public function actionImport(string $file): int
    {
        if (ProductHistory::$plugin->getSettings()->priceListSource !== Settings::SOURCE_FILE) {
            $this->stdout("Note: the price list is read from Commerce; set priceListSource to \"file\" for this file to be published.\n", Console::FG_YELLOW);
        }

        try {
            $result = ProductHistory::$plugin->getPriceListSource()->import($file, basename($file), $this->dryRun);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        foreach ($result['columns'] as $column => $title) {
            $this->stdout(str_pad($column, 32) . " ← $title\n", Console::FG_GREY);
        }
        foreach ($result['errors'] as $error) {
            $this->stderr("$error\n", Console::FG_YELLOW);
        }
        if ($result['fatal'] !== null) {
            $this->stderr($result['fatal'] . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("{$result['rows']} rows" . ($result['errors'] ? ', ' . count($result['errors']) . ' left out' : '') . ($result['saved'] ? ", saved\n" : ", dry run, nothing saved\n"), Console::FG_GREEN);

        return ExitCode::OK;
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

        // A skipped outlet has no file today, which the decision does not allow: fail the cron run.
        foreach ($result['skipped'] as $skipped) {
            $this->stderr("skipped outlet $skipped\n", Console::FG_RED);
        }

        return $result['skipped'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Lists the outlets that get a file: the web shop and the stores a site registers.
     */
    public function actionOutlets(): int
    {
        $priceList = ProductHistory::$plugin->getPriceList();
        foreach ($priceList->outlets() as $outlet) {
            $location = $outlet->inventoryLocationId ? "location #{$outlet->inventoryLocationId}" : 'web stock';
            $this->stdout(str_pad($outlet->code, 20) . ' ' . $outlet->displayName() . ", $location\n");
            $this->stdout('  ' . $outlet->prefix() . "_N_YYYYMMDD_HHMMSS.csv\n", Console::FG_GREY);
        }
        foreach ($priceList->skippedOutlets as $skipped) {
            $this->stderr("skipped $skipped\n", Console::FG_RED);
        }

        return $priceList->skippedOutlets ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
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
        try {
            $rows = $priceList->rows($outlet);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout(ProductHistory::$plugin->getPriceList()->csv(array_slice($rows, 0, 20)));
        $this->stdout(count($rows) . " rows total\n", Console::FG_GREY);

        return ExitCode::OK;
    }
}
