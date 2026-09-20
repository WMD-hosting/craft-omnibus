<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use wmd\craftproductpricehistory\ProductHistory;
use yii\console\ExitCode;

/**
 * Anchor-price helpers: backfill history for variants that have none, and
 * print the anchor a variant resolves to.
 *
 * @since 1.1.0
 */
class AnchorController extends Controller
{
    public $defaultAction = 'backfill';

    /**
     * @var string|null Date the backfilled rows are stamped with (Y-m-d).
     * Use the anchor day when the current prices were already in force then.
     */
    public ?string $asOf = null;

    /** @var int|null Commerce store id. */
    public ?int $store = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return [...parent::options($actionID), 'asOf', 'store'];
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
