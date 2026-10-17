<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use wmd\craftproductpricehistory\helpers\AnchorDateRules;
use wmd\craftproductpricehistory\helpers\PriceImport;
use wmd\craftproductpricehistory\ProductHistory;
use yii\console\ExitCode;

/**
 * Anchor-price helpers: backfill history for variants that have none, import
 * the prices of a past reference day, and print the anchor a variant resolves to.
 *
 * @since 1.1.0
 */
class AnchorController extends Controller
{
    public $defaultAction = 'backfill';

    /**
     * @var string|null Date the backfilled or imported rows are stamped with (Y-m-d).
     * Use the anchor day when the current prices were already in force then.
     */
    public ?string $asOf = null;

    /** @var int|null Commerce store id. */
    public ?int $store = null;

    /** @var bool Report what `import` would write without writing it. */
    public bool $dryRun = false;

    /** @var string|null CSV delimiter of the `import` file; detected when empty. */
    public ?string $delimiter = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = [...parent::options($actionID), 'asOf'];

        return match ($actionID) {
            'backfill' => [...$options, 'store'],
            'import' => [...$options, 'dryRun', 'delimiter'],
            default => parent::options($actionID),
        };
    }

    /**
     * Records the current base price for every variant without history.
     */
    public function actionBackfill(): int
    {
        $asOf = $this->asOf ? new DateTime($this->asOf . ' 12:00:00') : null;
        $written = ProductHistory::$plugin->getAnchor()->backfillMissing($asOf, $this->store);
        $this->stdout("Backfilled {$written} variant(s)" . ($asOf ? " as of {$asOf->format('Y-m-d')}" : '') . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Imports the prices in force on a past reference day from a `sku;price`
     * CSV, e.g. `craft omnibus/anchor/import prices-2025-05-02.csv --as-of=2025-05-02`.
     * No install has history from 2.5.2025, the reference day of food,
     * drink, cosmetics, cleaning, toiletries and household goods.
     */
    public function actionImport(string $file): int
    {
        if ($this->asOf === null || !AnchorDateRules::isDate($this->asOf)) {
            $this->stderr("--as-of=<Y-m-d> is required: the day these prices were in force.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $asOf = new DateTime($this->asOf);
        if ($asOf > new DateTime('today')) {
            $this->stderr("--as-of {$this->asOf} is in the future.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        if (!is_file($file) || !is_readable($file)) {
            $this->stderr("Cannot read $file\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $parsed = PriceImport::parse((string)file_get_contents($file), $this->delimiter ?: null);
        foreach ($parsed['errors'] as $error) {
            $this->stderr("$error\n", Console::FG_YELLOW);
        }
        if ($parsed['prices'] === []) {
            $this->stderr("No prices to import.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $result = ProductHistory::$plugin->getAnchor()->importPrices($parsed['prices'], $asOf, $this->dryRun);
        foreach ($result['unknown'] as $sku) {
            $this->stderr("Unknown SKU: $sku\n", Console::FG_YELLOW);
        }
        foreach (array_unique($result['unrecorded']) as $sku) {
            $this->stderr("Skipped $sku: no price recorded after {$asOf->format('Y-m-d')}. Run omnibus/anchor/backfill first, or the imported price would stand for every day since.\n", Console::FG_YELLOW);
        }
        $this->stdout(sprintf(
            "%s%d written, %d updated, %d unchanged as of %s; %d unknown SKU(s), %d without later history, %d bad line(s)\n",
            $this->dryRun ? '[dry run] ' : '',
            $result['written'],
            $result['updated'],
            $result['unchanged'],
            $asOf->format('Y-m-d'),
            count($result['unknown']),
            count(array_unique($result['unrecorded'])),
            count($parsed['errors']),
        ), Console::FG_GREEN);

        return $result['unknown'] || $result['unrecorded'] || $parsed['errors'] ? ExitCode::DATAERR : ExitCode::OK;
    }

    /**
     * Prints the anchor price and the lowest price before now for one variant.
     */
    public function actionShow(int $variantId): int
    {
        $anchor = ProductHistory::$plugin->getAnchor();
        $row = $anchor->getVariantAnchor($variantId);
        $lowest = $anchor->getVariantLowestBefore($variantId);

        $this->stdout($row
            ? sprintf("anchor: %.2f (%s, %s)\n", $row['price'], $row['date']->format('Y-m-d'), $row['source'])
            : "anchor: none recorded\n");
        $this->stdout($lowest !== null ? sprintf("lowest before now: %.2f\n", $lowest) : "lowest before now: none\n");

        return ExitCode::OK;
    }
}
