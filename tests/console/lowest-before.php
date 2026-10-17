<?php
/**
 * The lowest price before a reduction (1.3.1) against a real Craft install:
 * a variant on a Commerce promotional price whose only history row is the
 * regular price (a backfill older than the window) gets that price, not null;
 * a raise before the promotion does not hide the older price; a dated catalog
 * price closes the window at its start; a reduction recorded in the history
 * still closes it at its own row.
 *
 * Usage, from a Craft install with Omnibus and Commerce:
 *   php tests/console/lowest-before.php <craft-root> [--src=<plugin src dir>] [--variant=<id>]
 * The variant (default 1979, constructor-cms) must sell below its latest
 * history row, through a promotional price or a pricing rule, and that row
 * must be older than the window. Everything runs in one database transaction
 * that is rolled back. Exit code 0 = all PASS.
 */

$args = array_slice($argv, 1);
$src = null;
$variantId = 1979;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--src=')) {
        $src = realpath(substr($arg, 6)) ?: null;
        unset($args[$i]);
    } elseif (str_starts_with($arg, '--variant=')) {
        $variantId = (int)substr($arg, 10);
        unset($args[$i]);
    }
}
$root = array_values($args)[0] ?? null;
if (!$root) {
    fwrite(STDERR, "usage: php tests/console/lowest-before.php <craft-root> [--src=<dir>] [--variant=<id>]\n");
    exit(2);
}
chdir($root);
require $root . '/bootstrap.php';
if ($src !== null) {
    spl_autoload_register(function(string $class) use ($src) {
        $prefix = 'wmd\\craftproductpricehistory\\';
        if (str_starts_with($class, $prefix) && is_file($file = $src . '/' . strtr(substr($class, strlen($prefix)), '\\', '/') . '.php')) {
            require $file;
        }
    }, true, true);
}
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Variant;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;
use wmd\craftproductpricehistory\services\AnchorService;
use wmd\craftproductpricehistory\variables\ProductPriceHistoryVariable;

$fail = 0;
$check = function(bool $ok, string $what) use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $what\n";
    $fail += $ok ? 0 : 1;
};
$same = fn(?float $a, ?float $b) => $a !== null && $b !== null && abs($a - $b) < 0.001;
$show = fn(?float $v) => $v === null ? 'null' : number_format($v, 2, '.', '');

if ($src !== null) {
    $file = (new ReflectionClass(AnchorService::class))->getFileName();
    $check(str_starts_with($file, $src), "plugin classes load from --src ($file)");
}

$anchor = ProductHistory::$plugin->getAnchor();
$days = ProductHistory::$plugin->getSettings()->lowestPriceDays;
$utc = new DateTimeZone('UTC');
$history = ProductPriceHistoryRecord::tableName();

/** @var Variant|null $variant */
$variant = Variant::find()->id($variantId)->status(null)->one();
$rows = (new Query())->from($history)->where(['variantId' => $variantId])->orderBy(['dateChanged' => SORT_DESC, 'id' => SORT_DESC])->all();
$latest = $rows[0] ?? null;
if (!$variant || !$latest || (float)$variant->getSalePrice() >= (float)$latest['price'] - 0.005
    || new DateTime($latest['dateChanged'], $utc) > new DateTime("-$days days", $utc)) {
    fwrite(STDERR, "variant $variantId must sell below its latest history row, older than $days days\n");
    exit(2);
}
$regular = (float)$latest['price'];
$salePrice = (float)$variant->getSalePrice();
echo "variant $variantId: latest row {$show($regular)} @ {$latest['dateChanged']} UTC, selling at {$show($salePrice)}\n";

$db = Craft::$app->getDb();
$transaction = $db->beginTransaction();
$insert = function(float $price, DateTime $at) use ($db, $history, $variant) {
    // The table has a uid but no dateCreated/dateUpdated.
    $db->createCommand()->insert($history, [
        'productId' => $variant->getPrimaryOwnerId(),
        'variantId' => $variant->id,
        'price' => $price,
        'dateChanged' => (clone $at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'uid' => StringHelper::UUID(),
    ], false)->execute();
};

try {
    // ------------------------------------------------- backfill + promotion
    $lowest = $anchor->getVariantLowestBefore($variant);
    $check($same($lowest, $regular), "a promotion over a backfill row older than $days days: lowest = the regular price ({$show($lowest)})");
    $check($same($anchor->getVariantLowestBefore($variantId), $lowest), 'the id gives the same price');
    $check($same((new ProductPriceHistoryVariable())->lowestBefore($variant), $lowest), 'craft.productPriceHistory.lowestBefore(variant) too');
    if (count($rows) === 1) {
        $explicit = new DateTime($latest['dateChanged'], $utc);
        $check($anchor->getVariantLowestBefore($variant, $explicit) === null, 'an explicit saleStart is still honoured: nothing recorded before the only row');
    }

    // ------------------------------------------------ raised, then promoted
    $raisedPrice = round(max($regular, $salePrice) + 100, 2);
    $insert($raisedPrice, new DateTime('-5 days', $utc));
    $lowest = $anchor->getVariantLowestBefore($variant);
    $check($same($lowest, $regular), "raised to {$show($raisedPrice)} 5 days ago, then promoted: lowest stays {$show($regular)} ({$show($lowest)})");

    // ------------------------------------------ a dated promotion closes it
    // A base price change after the promotion started, still above the selling price.
    $mid = round(($regular + $salePrice) / 2, 2);
    $insert($mid, new DateTime('-3 days', $utc));
    $lowest = $anchor->getVariantLowestBefore($variant);
    $check($same($lowest, $mid), "without a promotion start the window ends now and sees {$show($mid)} from 3 days ago ({$show($lowest)})");
    $promoRows = (new Query())->from(CommerceTable::CATALOG_PRICING)
        ->where(['purchasableId' => $variant->id, 'storeId' => $variant->getStoreId(), 'userId' => null])
        ->andWhere(['between', 'price', $salePrice - 0.005, $salePrice + 0.005])
        ->column();
    $db->createCommand()->update(CommerceTable::CATALOG_PRICING, ['dateFrom' => Db::prepareDateForDb(new DateTime('-10 days', $utc))], ['id' => $promoRows], [], false)->execute();
    $lowest = $anchor->getVariantLowestBefore($variant);
    $check($promoRows !== [] && $same($lowest, $regular), "a catalog price dated 10 days ago closes the window there: {$show($mid)} and {$show($raisedPrice)} come after it ({$show($lowest)})");
    $db->createCommand()->update(CommerceTable::CATALOG_PRICING, ['dateFrom' => null], ['id' => $promoRows], [], false)->execute();

    // --------------------------------------------- a recorded reduction
    // Below the selling price, so Commerce does not look like a promotion:
    // the row lowered the price, and the window closes at it.
    $reduced = round($salePrice - 10, 2);
    $insert($reduced, new DateTime('-1 day', $utc));
    $lowest = $anchor->getVariantLowestBefore($variant);
    $check($same($lowest, $mid), "a reduction to {$show($reduced)} recorded yesterday: the window closes at its row, the reduced price is not its own reference ({$show($lowest)})");
} finally {
    $transaction->rollBack();
}

$check((new Query())->from($history)->where(['variantId' => $variantId])->count() == count($rows), 'rolled back: history unchanged');

echo ($fail ? "$fail FAIL" : 'all PASS') . "\n";
exit($fail ? 1 : 0);
