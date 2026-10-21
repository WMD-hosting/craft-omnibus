<?php
/**
 * First-save history (1.3.2) against a real Craft install: a product created
 * with its variants in one save (how every importer works) records one row per
 * variant at its base price, under the product. Commerce 5 saves the variants
 * after Product::EVENT_AFTER_SAVE, where 1.3.1 looked for them, so a fresh
 * import wrote no history at all. Also: a resave adds nothing, a variant added
 * in a product draft gets its row under the live product once the draft is
 * applied, and the (variantId, dateChanged) index is there for the lookups.
 *
 * Usage, from a Craft install with Omnibus and Commerce:
 *   php tests/console/first-save.php <craft-root> [--src=<plugin src dir>] [--type=<product type handle>]
 * --type defaults to the first product type. The saves run in one database
 * transaction that is rolled back; the index check runs the migration outside
 * it (DDL commits) and leaves the index in place. Exit code 0 = all PASS.
 */

$args = array_slice($argv, 1);
$src = null;
$typeHandle = null;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--src=')) {
        $src = realpath(substr($arg, 6)) ?: null;
        unset($args[$i]);
    } elseif (str_starts_with($arg, '--type=')) {
        $typeHandle = substr($arg, 7);
        unset($args[$i]);
    }
}
$root = array_values($args)[0] ?? null;
if (!$root) {
    fwrite(STDERR, "usage: php tests/console/first-save.php <craft-root> [--src=<dir>] [--type=<handle>]\n");
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

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use wmd\craftproductpricehistory\migrations\m261021_000000_variant_date_index;
use wmd\craftproductpricehistory\ProductHistory;

$fail = 0;
$check = function(bool $ok, string $what) use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $what\n";
    $fail += $ok ? 0 : 1;
};

if ($src !== null) {
    $file = (new ReflectionClass(ProductHistory::class))->getFileName();
    $check(str_starts_with($file, $src), "plugin classes load from --src ($file)");
}

$types = Commerce::getInstance()->getProductTypes();
$type = $typeHandle !== null ? $types->getProductTypeByHandle($typeHandle) : ($types->getAllProductTypes()[0] ?? null);
if (!$type) {
    fwrite(STDERR, "product type " . ($typeHandle ?? '(any)') . " not found\n");
    exit(2);
}

$rows = fn(array $where) => (new Query())->from('{{%product_price_history}}')
    ->where($where)->select(['productId', 'variantId', 'price'])->orderBy(['id' => SORT_ASC])->all();
$show = fn(array $r) => json_encode(array_map(fn($x) => "p{$x['productId']}/v{$x['variantId']}={$x['price']}", $r));
$elements = Craft::$app->getElements();
$tag = 'omnibus-first-save-' . StringHelper::randomString(6);
$newVariant = fn(string $sku, float $price) => new Variant(['sku' => $sku, 'basePrice' => $price]);

$tx = Craft::$app->getDb()->beginTransaction();
try {
    // 1. One save creates the product and its variants, as an importer does
    $product = new Product([
        'typeId' => $type->id,
        'title' => $tag,
        'slug' => $tag,
    ]);
    $product->setVariants([
        $newVariant("$tag-a", 12.34),
        $newVariant("$tag-b", 56.78),
    ]);
    // Commerce saves nested variants only when the attribute is dirty (CP and importers set it)
    $product->setDirtyAttributes(['variants']);
    $ok = $elements->saveElement($product);
    $check($ok, "product with 2 variants saved in one save" . ($ok ? " (#$product->id, type $type->handle)" : ': ' . json_encode($product->getErrors())));
    if (!$ok) {
        throw new RuntimeException('setup failed');
    }
    $variants = Variant::find()->ownerId($product->id)->status(null)->orderBy(['sku' => SORT_ASC])->all();
    $got = $rows(['productId' => $product->id]);
    $want = array_map(fn(Variant $v) => ['productId' => (string)$product->id, 'variantId' => (string)$v->id, 'price' => number_format((float)$v->basePrice, 2, '.', '')], $variants);
    $check(
        count($variants) === 2 && array_map(fn($r) => array_map('strval', $r), $got) === $want,
        "one row per new variant at its base price, under the product: " . $show($got)
    );

    // 2. A plain resave of the new product records nothing more
    $before = count($rows(['productId' => $product->id]));
    $product = Product::find()->id($product->id)->status(null)->one();
    $elements->saveElement($product);
    $after = $rows(['productId' => $product->id]);
    $check(count($after) === $before, "resave adds no rows ($before -> " . count($after) . ")");

    // 3. A variant added in a draft of the live product, as the CP does it: the variant
    //    is saved with the draft as owner, then the draft is saved. Applying the draft
    //    duplicates the variant onto the live product (a new id, its own first save);
    //    it must have one row under the live product, and none may stay under the draft.
    $draft = Craft::$app->getDrafts()->createDraft($product);
    $draftId = $draft->id;
    $added = $newVariant("$tag-c", 9.99);
    $added->title = $tag;
    $added->ownerId = $added->primaryOwnerId = $draftId;
    $ok = $elements->saveElement($added);
    $draft = Product::find()->id($draftId)->drafts(null)->status(null)->one();
    $draft->setDirtyAttributes(['variants']);
    $ok = $ok && $elements->saveElement($draft);
    $check($ok, "draft #$draftId with a new variant saved" . ($ok ? '' : ': ' . json_encode($added->getErrors() + $draft->getErrors())));
    $canonical = Craft::$app->getDrafts()->applyDraft($draft);
    $live = Variant::find()->ownerId($canonical->id)->sku("$tag-c")->status(null)->one();
    $liveRows = $live ? $rows(['variantId' => $live->id]) : [];
    $check(
        $live && count($liveRows) === 1
            && (int)$liveRows[0]['productId'] === (int)$canonical->id
            && abs((float)$liveRows[0]['price'] - 9.99) < 0.001,
        "applied draft: live variant " . ($live ? "#$live->id" : '(missing)') . " has one row under product #$canonical->id: " . $show($liveRows)
    );
    $check($rows(['productId' => $draftId]) === [], "no rows left under the applied draft #$draftId");
} catch (Throwable $e) {
    $check(false, 'threw ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    $tx->rollBack();
}

// 4. The migration adds the (variantId, dateChanged) index and is idempotent
$table = Craft::$app->getDb()->getSchema()->getRawTableName('{{%product_price_history}}');
$hasIndex = function() use ($table) {
    Craft::$app->getDb()->getSchema()->refresh();
    foreach (Craft::$app->getDb()->getSchema()->findIndexes($table) as $index) {
        if ($index['columns'] === ['variantId', 'dateChanged']) {
            return true;
        }
    }
    return false;
};
$migration = new m261021_000000_variant_date_index();
ob_start();
$migration->safeUp();
$migration->safeUp();
ob_end_clean();
$check($hasIndex(), "index on (variantId, dateChanged) after the migration (run twice)");
$plan = Craft::$app->getDb()->createCommand(
    "EXPLAIN SELECT * FROM `$table` WHERE variantId = 1 AND dateChanged >= NOW() - INTERVAL 30 DAY ORDER BY price"
)->queryOne();
$check(($plan['key'] ?? null) !== null && ($plan['key'] ?? null) !== 'PRIMARY', "lowest-in-window lookup uses an index (" . ($plan['key'] ?? 'none') . ")");

exit($fail ? 1 : 0);
