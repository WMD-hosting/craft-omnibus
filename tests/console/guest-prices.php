<?php
/**
 * Guest prices (1.3.3) against a real Craft install: with a customer signed
 * in who has a catalog price of their own (a pricing rule with a customer
 * condition), the price list row and the lowest price before a reduction are
 * still the guest's. Craft's web queue runner runs jobs inside a visitor's
 * request, so a price list built there used to carry that visitor's price.
 *
 * Usage, from a Craft install with Omnibus and Commerce:
 *   php tests/console/guest-prices.php <craft-root> [--src=<plugin src dir>] [--variant=<id>] [--user=<id>]
 * The variant (default: the first enabled one with a base price, no
 * promotional price and no pricing rule) gets a 10 % rule for the user
 * (default: the first user). Everything runs in one database transaction
 * that is rolled back. Exit code 0 = all PASS.
 */

$args = array_slice($argv, 1);
$src = null;
$variantId = null;
$userId = null;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--src=')) {
        $src = realpath(substr($arg, 6)) ?: null;
        unset($args[$i]);
    } elseif (str_starts_with($arg, '--variant=')) {
        $variantId = (int)substr($arg, 10);
        unset($args[$i]);
    } elseif (str_starts_with($arg, '--user=')) {
        $userId = (int)substr($arg, 7);
        unset($args[$i]);
    }
}
$root = array_values($args)[0] ?? null;
if (!$root) {
    fwrite(STDERR, "usage: php tests/console/guest-prices.php <craft-root> [--src=<dir>] [--variant=<id>] [--user=<id>]\n");
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
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;
use wmd\craftproductpricehistory\services\AnchorService;

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

$plugin = ProductHistory::$plugin;
$anchor = $plugin->getAnchor();
$storeId = Commerce::getInstance()->getStores()->getCurrentStore()->id;
$utc = new DateTimeZone('UTC');
$history = ProductPriceHistoryRecord::tableName();

$variantId ??= (int)(new Query())
    ->select(['ps.purchasableId'])
    ->from(['ps' => CommerceTable::PURCHASABLES_STORES])
    ->innerJoin(['v' => CommerceTable::VARIANTS], '[[v.id]] = [[ps.purchasableId]]')
    ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[v.id]]')
    ->innerJoin(['pe' => Table::ELEMENTS], '[[pe.id]] = [[v.primaryOwnerId]]')
    ->where(['ps.storeId' => $storeId, 'ps.basePromotionalPrice' => null, 'e.enabled' => true, 'pe.enabled' => true])
    ->andWhere(['e.draftId' => null, 'e.revisionId' => null, 'e.dateDeleted' => null, 'pe.dateDeleted' => null])
    ->andWhere(['>', 'ps.basePrice', 0])
    ->andWhere(['not in', 'ps.purchasableId', (new Query())->select(['purchasableId'])->from(CommerceTable::CATALOG_PRICING)->where(['not', ['catalogPricingRuleId' => null]])])
    ->orderBy(['ps.purchasableId' => SORT_ASC])
    ->scalar();
$userId ??= (int)(new Query())->select(['id'])->from(Table::USERS)->orderBy(['id' => SORT_ASC])->scalar();
/** @var Variant|null $variant */
$variant = $variantId ? Variant::find()->id($variantId)->status(null)->forCustomer(false)->one() : null;
$user = $userId ? User::find()->id($userId)->status(null)->one() : null;
if (!$variant || !$user) {
    fwrite(STDERR, "no variant or user to test with\n");
    exit(2);
}
$guestPrice = (float)$variant->getBasePrice();
$customerPrice = round($guestPrice * 0.9, 2);
$higher = round($guestPrice * 1.5, 2);
echo "variant $variantId ($variant->sku): guest {$show($guestPrice)}, user $userId {$show($customerPrice)}\n";

$db = Craft::$app->getDb();
$transaction = $db->beginTransaction();
$resetCommerce = function() {
    (fn() => $this->_allCatalogPrices = null)->call(Commerce::getInstance()->getCatalogPricing());
    (fn() => $this->_hasCatalogPricingRules = null)->call(Commerce::getInstance()->getCatalogPricingRules());
};
$insertHistory = function(float $price, DateTime $at) use ($db, $history, $variant) {
    $db->createCommand()->insert($history, [
        'productId' => $variant->getPrimaryOwnerId(),
        'variantId' => $variant->id,
        'price' => $price,
        'dateChanged' => (clone $at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'uid' => StringHelper::UUID(),
    ], false)->execute();
};

try {
    // ----------------------------------------------------------------- setup
    // A rule for one customer, as Commerce saves it: its user list and its rows.
    Db::insert(CommerceTable::CATALOG_PRICING_RULES, [
        'name' => 'Company price (guest-prices test)', 'storeId' => $storeId, 'apply' => 'byPercent', 'applyAmount' => -0.1,
        'applyPriceType' => 'price', 'isPromotionalPrice' => false, 'enabled' => true,
    ]);
    $ruleId = (int)$db->getLastInsertID();
    Db::insert(CommerceTable::CATALOG_PRICING_RULES_USERS, ['catalogPricingRuleId' => $ruleId, 'userId' => $userId]);
    Db::insert(CommerceTable::CATALOG_PRICING, [
        'price' => $customerPrice, 'purchasableId' => $variant->id, 'storeId' => $storeId,
        'catalogPricingRuleId' => $ruleId, 'isPromotionalPrice' => false,
    ]);
    $hasBaseRow = (new Query())->from(CommerceTable::CATALOG_PRICING)
        ->where(['purchasableId' => $variant->id, 'storeId' => $storeId, 'catalogPricingRuleId' => null])->exists();
    if (!$hasBaseRow) {
        Db::insert(CommerceTable::CATALOG_PRICING, ['price' => $guestPrice, 'purchasableId' => $variant->id, 'storeId' => $storeId]);
    }
    // A reduction recorded 10 days ago, from a higher price 100 days ago.
    $db->createCommand()->delete($history, ['variantId' => $variant->id])->execute();
    $insertHistory($higher, new DateTime('-100 days', $utc));
    $insertHistory($guestPrice, new DateTime('-10 days', $utc));
    $resetCommerce();

    $guestLowest = $anchor->getVariantLowestBefore($variantId);
    $check($same($guestLowest, $higher), "no one signed in: lowest before the reduction recorded 10 days ago = {$show($higher)} ({$show($guestLowest)})");

    Craft::$app->getUser()->setIdentity($user);
    $resetCommerce();

    // The leak path: a query left to Commerce's default is the customer's.
    /** @var Variant|null $theirs */
    $theirs = Variant::find()->id($variantId)->status(null)->one();
    $check($theirs !== null && $same((float)$theirs->getSalePrice(), $customerPrice), "control: with user $userId signed in, Variant::find() prices {$show($customerPrice)} ({$show($theirs ? (float)$theirs->getSalePrice() : null)})");

    // ------------------------------------------------------------ price list
    $rows = (fn() => $this->baseRows())->call($plugin->getPriceList());
    $row = null;
    foreach ($rows as $item) {
        if ($item['variantId'] === (int)$variant->id) {
            $row = $item['row'];
            break;
        }
    }
    $check($row !== null && $row['maloprodajna_cijena'] === number_format($guestPrice, 2, '.', ''), "price list built with user $userId signed in: maloprodajna_cijena = the guest's {$show($guestPrice)} (" . ($row['maloprodajna_cijena'] ?? 'no row') . ')');
    $check($row !== null && $row['posebni_oblik_prodaje'] === 'NE', 'and the customer price is no special sale (' . ($row['posebni_oblik_prodaje'] ?? 'no row') . ')');

    // ---------------------------------------------- lowest before a reduction
    $lowest = $anchor->getVariantLowestBefore($variantId);
    $check($same($lowest, $guestLowest), "lowest before by id with user $userId signed in = the guest's {$show($guestLowest)} ({$show($lowest)})");
    $lowest = $anchor->getVariantLowestBefore($theirs);
    $check($same($lowest, $guestLowest), "the customer-priced Variant passed in gives the guest's too ({$show($lowest)})");
} finally {
    Craft::$app->getUser()->setIdentity(null);
    $transaction->rollBack();
}

$check(!(new Query())->from(CommerceTable::CATALOG_PRICING_RULES)->where(['name' => 'Company price (guest-prices test)'])->exists(), 'rolled back: no test rule left');

echo ($fail ? "$fail FAIL" : 'all PASS') . "\n";
exit($fail ? 1 : 0);
