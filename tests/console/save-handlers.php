<?php
/**
 * Reproduction: saving a product whose type has versioning must not crash the
 * Omnibus Variant::EVENT_BEFORE_SAVE handler (revision variants have id === null),
 * and a real price change must still be recorded for the canonical variant only.
 *
 * Usage, from any Craft install that has Omnibus and a product type with versioning:
 *   php tests/console/save-handlers.php <craft-root> <productId>
 * Everything runs inside a transaction that is rolled back. Exit code 0 = all PASS.
 * Step 4 also checks that a variant without a store row never records a 0.00 price.
 */

[$_, $root, $productId] = $argv + [null, null, null];
chdir($root);
require $root . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Product;
use craft\db\Query;

$product = Product::find()->id((int)$productId)->status(null)->one();
if (!$product) {
    fwrite(STDERR, "product $productId not found\n");
    exit(2);
}
$type = $product->getType();
$variant = $product->getVariants()[0];
printf("product #%d \"%s\", type %s, versioning=%s, variant #%d basePrice=%s\n",
    $product->id, $product->title, $type->handle, var_export($type->enableVersioning, true),
    $variant->id, $variant->basePrice);

$history = fn() => (new Query())->from('{{%product_price_history}}')
    ->where(['productId' => $product->id])->select(['variantId', 'price'])->all();
$before = count($history());

$fail = 0;
$tx = Craft::$app->getDb()->beginTransaction();
try {
    // 1. Plain resave (creates a revision when versioning is on)
    try {
        $ok = Craft::$app->getElements()->saveElement($product);
        printf("[%s] plain save -> %s\n", $ok ? 'PASS' : 'FAIL', $ok ? 'saved' : json_encode($product->getErrors()));
        $fail += $ok ? 0 : 1;
    } catch (Throwable $e) {
        printf("[FAIL] plain save threw %s: %s\n", get_class($e), $e->getMessage());
        $fail++;
    }

    // 2. Variant price change (CP variant card / importer): one new history row,
    //    for the canonical variant id
    $product = Product::find()->id((int)$productId)->status(null)->one();
    $variant = $product->getVariants()[0];
    $newPrice = (float)$variant->basePrice + 1.23;
    $variant->basePrice = $newPrice;
    $mid = count($history());
    try {
        Craft::$app->getElements()->saveElement($variant);
        $rows = array_slice($history(), $mid);
        $good = count($rows) === 1
            && (int)$rows[0]['variantId'] === (int)$variant->id
            && abs((float)$rows[0]['price'] - $newPrice) < 0.001;
        printf("[%s] variant price change -> new rows: %s\n", $good ? 'PASS' : 'FAIL', json_encode($rows));
        $fail += $good ? 0 : 1;
    } catch (Throwable $e) {
        printf("[FAIL] variant save threw %s: %s\n", get_class($e), $e->getMessage());
        $fail++;
    }

    // 3. Product save after that: the variant changed since its last revision, so
    //    the product revision duplicates it (id === null). Must save, add no rows.
    sleep(2); // revisions are skipped when dateUpdated == last revision dateCreated (1 s resolution)
    $product = Product::find()->id((int)$productId)->status(null)->one();
    $revsBefore = Product::find()->revisionOf($product)->status(null)->count();
    $mid = count($history());
    try {
        $ok = Craft::$app->getElements()->saveElement($product);
        $revs = Product::find()->revisionOf($product)->status(null)->count() - $revsBefore;
        $rows = array_slice($history(), $mid);
        $good = $ok && $rows === [];
        printf("[%s] product save after variant change -> saved=%s, new revisions=%d, new rows: %s\n",
            $good ? 'PASS' : 'FAIL', var_export($ok, true), $revs, json_encode($rows));
        $fail += $good ? 0 : 1;
    } catch (Throwable $e) {
        printf("[FAIL] product save threw %s: %s\n", get_class($e), $e->getMessage());
        $fail++;
    }

    // 4. No commerce_purchasables_stores row and no history yet: the old price is
    //    unknown, so nothing may be recorded as a 0.00 price.
    $variant = Product::find()->id((int)$productId)->status(null)->one()->getVariants()[0];
    Craft::$app->getDb()->createCommand()->delete('{{%commerce_purchasables_stores}}', ['purchasableId' => $variant->id])->execute();
    Craft::$app->getDb()->createCommand()->delete('{{%product_price_history}}', ['variantId' => $variant->id])->execute();
    $variant->basePrice = (float)$variant->basePrice + 1;
    $mid = count($history());
    try {
        Craft::$app->getElements()->saveElement($variant);
        $rows = array_slice($history(), $mid);
        $zero = array_filter($rows, fn($r) => (float)$r['price'] < 0.001);
        printf("[%s] variant without store row -> new rows: %s\n", $zero ? 'FAIL' : 'PASS', json_encode($rows));
        $fail += $zero ? 1 : 0;
    } catch (Throwable $e) {
        printf("[FAIL] store-less variant save threw %s: %s\n", get_class($e), $e->getMessage());
        $fail++;
    }
} finally {
    $tx->rollBack();
}
printf("rolled back; history rows %d -> %d\n", $before, count($history()));
exit($fail ? 1 : 0);
