<?php
/**
 * Store-scoped history (1.3.4) against a real Craft install with a second
 * Commerce store: price history is one table with no store column and the
 * legal "lowest price in 30 days" reads it, so only the primary (retail) store
 * may write to it. Another store's price (a trade store whose variants sit at
 * 0.00, or at a wholesale price) must not become a row, and neither may a
 * propagating save. Before 1.3.4 a single product save that propagated to the
 * second store wrote one 0.00 row per variant.
 *
 * Usage, from a Craft install with Omnibus and a Commerce install that has
 * two stores:
 *   php tests/console/store-scoped-history.php <craft-root> [--src=<plugin src dir>] [--variant=<id>]
 * The variant (default: the first live one that has a price in the primary
 * store and a row in another store) is saved in turn from the other store,
 * from the primary store and as a propagating save. Everything runs in one
 * database transaction that is rolled back. Exit code 0 = all PASS.
 */

$args = array_slice($argv, 1);
$src = null;
$variantId = null;
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
    fwrite(STDERR, "usage: php tests/console/store-scoped-history.php <craft-root> [--src=<dir>] [--variant=<id>]\n");
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

use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\events\ModelEvent;
use wmd\craftproductpricehistory\ProductHistory;

$fail = 0;
$check = function(bool $ok, string $what) use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $what\n";
    $fail += $ok ? 0 : 1;
};
$money = fn($v) => number_format((float)$v, 2, '.', '');

if ($src !== null) {
    $file = (new ReflectionClass(ProductHistory::class))->getFileName();
    $check(str_starts_with($file, $src), "plugin classes load from --src ($file)");
}

$stores = Commerce::getInstance()->getStores();
$primary = $stores->getPrimaryStore();
$other = null;
foreach ($stores->getAllStores() as $store) {
    if ($store->id !== $primary->id) {
        $other = $store;
        break;
    }
}
if (!$other) {
    fwrite(STDERR, "this install has one Commerce store; the test needs a second\n");
    exit(2);
}
$primarySiteId = $primary->getSites()->first()->id;
$otherSiteId = $other->getSites()->first()->id;

$priceIn = fn(int $id, int $storeId) => (new Query())->from('{{%commerce_purchasables_stores}}')
    ->where(['purchasableId' => $id, 'storeId' => $storeId])->select('basePrice')->scalar();
$rows = fn(int $id) => (new Query())->from('{{%product_price_history}}')
    ->where(['variantId' => $id])->select(['price'])->orderBy(['id' => SORT_ASC])->column();
$show = fn(array $prices) => json_encode(array_map($money, $prices));
$load = fn(int $id, int $siteId) => Variant::find()->id($id)->siteId($siteId)->status(null)->one();
$elements = Craft::$app->getElements();

if (!$variantId) {
    $variantId = (new Query())
        ->select(['v.id'])
        ->from(['v' => '{{%commerce_variants}}'])
        ->innerJoin(['p' => '{{%commerce_purchasables_stores}}'], ['and', '[[p.purchasableId]] = [[v.id]]', ['p.storeId' => $primary->id]])
        ->innerJoin(['o' => '{{%commerce_purchasables_stores}}'], ['and', '[[o.purchasableId]] = [[v.id]]', ['o.storeId' => $other->id]])
        ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[v.id]]')
        ->where(['e.dateDeleted' => null, 'e.revisionId' => null, 'e.draftId' => null, 'e.enabled' => true])
        ->andWhere(['>', 'p.basePrice', 0])
        ->orderBy(['v.id' => SORT_ASC])
        ->scalar();
}
if (!$variantId || !$priceIn($variantId, $primary->id) || $priceIn($variantId, $other->id) === false) {
    fwrite(STDERR, "no variant with a price in both stores found (pass --variant=<id>)\n");
    exit(2);
}

$tx = Craft::$app->getDb()->beginTransaction();
try {
    // Fixture: a known history, a retail price that differs from the other store's
    $retail = (float)$priceIn($variantId, $primary->id);
    $productId = (int)(new Query())->from('{{%commerce_variants}}')->where(['id' => $variantId])->select('primaryOwnerId')->scalar();
    Craft::$app->getDb()->createCommand()->delete('{{%product_price_history}}', ['variantId' => $variantId])->execute();
    Craft::$app->getDb()->createCommand()->insert('{{%product_price_history}}', [
        'productId' => $productId,
        'variantId' => $variantId,
        'price' => $retail,
        'dateChanged' => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
    ])->execute();
    $baseline = $rows($variantId);
    $check(
        $primary->primary && !$other->primary && $other->id !== $primary->id,
        "variant #$variantId: retail store '{$primary->handle}' (site $primarySiteId) at " . $money($retail)
            . ", other store '{$other->handle}' (site $otherSiteId) at " . $money($priceIn($variantId, $other->id))
    );

    // 1. A save from the other store (a trade price) writes nothing
    $trade = $load($variantId, $otherSiteId);
    $tradePrice = round($retail * 0.8, 2) ?: 1.0;
    $trade->basePrice = $tradePrice;
    $ok = $elements->saveElement($trade, true, false);
    $check($ok, "variant saved from store '{$other->handle}' at " . $money($tradePrice) . ($ok ? '' : ': ' . json_encode($trade->getErrors())));
    $check(
        abs((float)$priceIn($variantId, $other->id) - $tradePrice) < 0.001,
        "the other store's price was stored (" . $money($priceIn($variantId, $other->id)) . ")"
    );
    $got = $rows($variantId);
    $check($got === $baseline, "other-store save writes no history row: " . $show($got));

    // 2. The same at 0.00, the shape a trade store has before it is priced
    $trade = $load($variantId, $otherSiteId);
    $trade->basePrice = 0;
    $elements->saveElement($trade, true, false);
    $got = $rows($variantId);
    $check($got === $baseline, "other-store save to 0.00 writes no history row: " . $show($got));

    // 3. A retail save writes one row at the new price, and its propagation
    //    to the other store (which the save does by default) adds none
    $changed = round($retail + 1.11, 2);
    $primaryVariant = $load($variantId, $primarySiteId);
    $primaryVariant->basePrice = $changed;
    $ok = $elements->saveElement($primaryVariant);
    $check($ok, "variant saved from the primary store at " . $money($changed) . ($ok ? '' : ': ' . json_encode($primaryVariant->getErrors())));
    $got = $rows($variantId);
    $check(
        count($got) === count($baseline) + 1 && abs((float)end($got) - $changed) < 0.001,
        "primary-store save writes one row at the new price: " . $show($got)
    );
    $check(!in_array(0.0, array_map('floatval', $got), true), "no 0.00 row after the save propagated to the other store");
    $afterRetail = $rows($variantId);

    // 4. A propagating save writes nothing, even for the primary store
    $propagating = $load($variantId, $primarySiteId);
    $propagating->propagating = true;
    $propagating->basePrice = round($changed + 1.11, 2);
    $propagating->trigger(Variant::EVENT_BEFORE_SAVE, new ModelEvent(['isNew' => false]));
    $got = $rows($variantId);
    $check($got === $afterRetail, "propagating save writes no history row: " . $show($got));

    // 5. First save: the other store's writes nothing, the primary store's one row
    $first = $load($variantId, $otherSiteId);
    $first->firstSave = true;
    $first->basePrice = round($changed + 2.22, 2);
    $first->trigger(Variant::EVENT_AFTER_SAVE, new ModelEvent(['isNew' => true]));
    $got = $rows($variantId);
    $check($got === $afterRetail, "first save in the other store writes no history row: " . $show($got));

    $first = $load($variantId, $primarySiteId);
    $first->firstSave = true;
    $first->basePrice = round($changed + 2.22, 2);
    $first->trigger(Variant::EVENT_AFTER_SAVE, new ModelEvent(['isNew' => true]));
    $got = $rows($variantId);
    $check(
        count($got) === count($afterRetail) + 1 && abs((float)end($got) - $changed - 2.22) < 0.001,
        "first save in the primary store writes one row: " . $show($got)
    );

    // 6. Backfill (for variants with no history) starts from the primary store's price
    Craft::$app->getDb()->createCommand()->update('{{%commerce_purchasables_stores}}', ['basePrice' => 0], ['purchasableId' => $variantId, 'storeId' => $other->id])->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%product_price_history}}', ['variantId' => $variantId])->execute();
    $current = (float)$priceIn($variantId, $primary->id);
    ProductHistory::$plugin->getAnchor()->backfillMissing();
    $got = $rows($variantId);
    $check(
        count($got) === 1 && abs((float)$got[0] - $current) < 0.001,
        "backfill records the primary store's price (" . $money($current) . ") only: " . $show($got)
    );
} catch (Throwable $e) {
    $check(false, 'threw ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    $tx->rollBack();
}

exit($fail ? 1 : 0);
