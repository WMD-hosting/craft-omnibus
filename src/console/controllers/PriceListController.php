<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use wmd\craftproductpricehistory\ProductHistory;
use yii\console\ExitCode;

/**
 * Publishes the NN 101/2026 price list. Schedule `publish` daily before 08:00.
 *
 * @since 1.1.0
 */
class PriceListController extends Controller
{
    public $defaultAction = 'publish';

    /** @var int|null Commerce store id; defaults to the primary store. */
    public ?int $store = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [...parent::options($actionID), 'store'];
    }

    /**
     * Writes today's CSV and XML price list, prunes old files, rewrites the index.
     */
    public function actionPublish(): int
    {
        if (!ProductHistory::$plugin->getSettings()->priceListEnabled) {
            $this->stdout("Price list publication is disabled in the plugin settings.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $result = ProductHistory::$plugin->getPriceList()->publish($this->store);

        $this->stdout("Published {$result['rows']} rows\n", Console::FG_GREEN);
        $this->stdout("  CSV   {$result['csv']}\n");
        $this->stdout("  XML   {$result['xml']}\n");
        $this->stdout("  index {$result['index']}\n");
        if ($result['pruned']) {
            $this->stdout("  pruned {$result['pruned']} file(s) past retention\n");
        }

        return ExitCode::OK;
    }

    /**
     * Prints the rows without writing anything.
     */
    public function actionPreview(): int
    {
        $rows = ProductHistory::$plugin->getPriceList()->rows($this->store);
        $this->stdout(ProductHistory::$plugin->getPriceList()->csv(array_slice($rows, 0, 20)));
        $this->stdout(count($rows) . " rows total\n", Console::FG_GREY);

        return ExitCode::OK;
    }
}
