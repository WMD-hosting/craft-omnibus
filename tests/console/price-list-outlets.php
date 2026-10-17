<?php
/**
 * Per-outlet price lists (1.2.0) against a real Craft install: a registered store
 * outlet gets its own CSV + XML, availability from its inventory location, row
 * overrides from EVENT_DEFINE_ROW, a per-outlet archive number, and a place in the
 * index under today's date.
 *
 * Usage, from any Craft install with Omnibus, Commerce inventory and stock:
 *   php tests/console/price-list-outlets.php <craft-root> [--src=<plugin src dir>]
 * --src loads the plugin classes from that directory instead of the install's
 * vendor copy (to test a checkout before it is released). Files go to a temporary
 * directory that is removed afterwards; nothing is saved. Exit code 0 = all PASS.
 */

$args = array_slice($argv, 1);
$src = null;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--src=')) {
        $src = realpath(substr($arg, 6)) ?: null;
        unset($args[$i]);
    }
}
$root = array_values($args)[0] ?? null;
if (!$root) {
    fwrite(STDERR, "usage: php tests/console/price-list-outlets.php <craft-root> [--src=<dir>]\n");
    exit(2);
}
chdir($root);
require $root . '/bootstrap.php';
if ($src !== null) {
    // Ahead of Composer, whose class map would still serve the vendor copy.
    spl_autoload_register(function(string $class) use ($src) {
        $prefix = 'wmd\\craftproductpricehistory\\';
        if (str_starts_with($class, $prefix) && is_file($file = $src . '/' . strtr(substr($class, strlen($prefix)), '\\', '/') . '.php')) {
            require $file;
        }
    }, true, true);
}
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\db\Table;
use craft\commerce\elements\Variant;
use craft\db\Query;
use wmd\craftproductpricehistory\events\DefinePriceListRowEvent;
use wmd\craftproductpricehistory\events\RegisterPriceListOutletsEvent;
use wmd\craftproductpricehistory\helpers\PriceListFiles;
use wmd\craftproductpricehistory\models\PriceListOutlet;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\services\PriceListService;
use yii\base\Event;

$fail = 0;
$check = function(bool $ok, string $what) use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $what\n";
    $fail += $ok ? 0 : 1;
};

$plugin = ProductHistory::$plugin;
if ($src !== null) {
    // Templates too (the install's plugins.php points the base path at vendor).
    $plugin->setBasePath($src);
    $file = (new ReflectionClass(PriceListService::class))->getFileName();
    $check(str_starts_with($file, $src), "plugin classes load from --src ($file)");
}

// The location with the fewest variants in stock (but some), so its availability differs from the web.
$location = (new Query())
    ->select(['it.inventoryLocationId', 'n' => 'COUNT(DISTINCT [[ii.purchasableId]])'])
    ->from(['it' => Table::INVENTORYTRANSACTIONS])
    ->innerJoin(['ii' => Table::INVENTORYITEMS], '[[ii.id]] = [[it.inventoryItemId]]')
    ->where(['it.type' => 'available'])
    ->groupBy(['it.inventoryLocationId'])
    ->having(['>', 'SUM([[it.quantity]])', 0])
    ->orderBy(['n' => SORT_ASC])
    ->scalar();
if (!$location) {
    fwrite(STDERR, "no inventory location holds stock\n");
    exit(2);
}

$dir = sys_get_temp_dir() . '/omnibus-outlets-' . bin2hex(random_bytes(4));
$plugin->getSettings()->priceListPath = $dir;

$outlet = new PriceListOutlet([
    'code' => 'test-store',
    'type' => 'Prodavaonica',
    'address' => 'Šubićeva 1, Đakovo',
    'name' => 'Test store',
    'inventoryLocationId' => (int)$location,
]);
Event::on(PriceListService::class, PriceListService::EVENT_REGISTER_OUTLETS, function(RegisterPriceListOutletsEvent $e) use ($outlet) {
    $e->outlets[] = $outlet;
    // Same file prefix as the web shop: must be skipped, not mixed in.
    $e->outlets[] = clone $e->outlets[0];
});

$web = $plugin->getPriceList()->rows();
$check(count($web) > 2, 'web shop has rows (' . count($web) . ')');
[$priced, $dropped] = [$web[0]['sifra'], $web[1]['sifra']];
Event::on(PriceListService::class, PriceListService::EVENT_DEFINE_ROW, function(DefinePriceListRowEvent $e) use ($priced, $dropped) {
    if ($e->outlet->code !== 'test-store' || $e->row === null) {
        return;
    }
    if ($e->row['sifra'] === $priced) {
        $e->row['maloprodajna_cijena'] = '1.23';
    } elseif ($e->row['sifra'] === $dropped) {
        $e->row = null;
    }
});

$codes = array_map(fn($o) => $o->code, $plugin->getPriceList()->outlets());
$check(in_array('test-store', $codes, true), 'registered outlet is listed');
$check(count($codes) === count(array_unique(array_map(fn($o) => $o->prefix(), $plugin->getPriceList()->outlets()))), 'duplicate prefix skipped (' . implode(', ', $codes) . ')');
$check(count($plugin->getPriceList()->skippedOutlets) === 1, 'the skip is reported (' . implode('; ', $plugin->getPriceList()->skippedOutlets) . ')');

$result = $plugin->getPriceList()->publish();
$check(count($result['files']) === count($codes), 'one file pair per outlet (' . count($result['files']) . ')');
$check(count($result['skipped']) === 1, 'publish() returns the skipped outlet');
$mine = array_values(array_filter($result['files'], fn($f) => $f['code'] === 'test-store'))[0] ?? null;
$check($mine !== null && is_file($mine['csv']) && is_file($mine['xml']), 'test store CSV and XML written');
$check(str_starts_with(basename($mine['csv']), 'prodavaonica_subiceva-1-djakovo_test-store_1_' . date('Ymd') . '_'), 'file name per čl. VI, ASCII (' . basename($mine['csv']) . ')');

$csv = array_map(fn($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", substr(file_get_contents($mine['csv']), 3))));
$header = array_shift($csv);
$check($header === PriceListService::COLUMNS, 'CSV header in čl. III order');
$rows = array_combine(array_column($csv, 1), array_map(fn($r) => array_combine($header, $r), $csv));
$check(count($rows) === count($web) - 1 && !isset($rows[$dropped]), 'row dropped by EVENT_DEFINE_ROW');
$check(($rows[$priced]['maloprodajna_cijena'] ?? null) === '1.23', 'store price set by EVENT_DEFINE_ROW');

$stock = (new Query())
    ->select(['qty' => 'SUM([[it.quantity]])', 'ii.purchasableId'])
    ->from(['it' => Table::INVENTORYTRANSACTIONS])
    ->innerJoin(['ii' => Table::INVENTORYITEMS], '[[ii.id]] = [[it.inventoryItemId]]')
    ->where(['it.type' => 'available', 'it.inventoryLocationId' => $location])
    ->groupBy(['ii.purchasableId'])
    ->indexBy('purchasableId')
    ->column();
$wrong = 0;
$differs = 0;
$webBySku = array_column($web, null, 'sifra');
$purchasables = craft\commerce\Plugin::getInstance()->getPurchasables();
foreach (Variant::find()->sku(array_keys($rows))->status(null)->all() as $variant) {
    // The store narrows the web's answer for variants sold only from stock.
    $stockBound = $variant->inventoryTracked && !$purchasables->isPurchasableOutOfStockPurchasingAllowed($variant);
    $expected = $stockBound && ($stock[$variant->id] ?? 0) < 1 ? 'nedostupno' : $webBySku[$variant->sku]['dostupnost'];
    $wrong += $rows[$variant->sku]['dostupnost'] === $expected ? 0 : 1;
    $differs += $rows[$variant->sku]['dostupnost'] !== $webBySku[$variant->sku]['dostupnost'] ? 1 : 0;
}
$check($wrong === 0, "dostupnost from location #$location stock");
$check($differs > 0, "the store's availability differs from the web's ($differs row(s))");

$xml = simplexml_load_file($mine['xml']);
$check((string)$xml['oznaka_prodajnog_objekta'] === 'test-store' && (string)$xml['adresa_prodajnog_objekta'] === 'Šubićeva 1, Đakovo', 'XML carries the outlet');
$check(count($xml->proizvod) === count($rows), 'XML rows = CSV rows');

$index = file_get_contents($result['index']);
$check(str_contains($index, '<time datetime="' . date('Y-m-d') . '">') && str_contains($index, 'Test store'), 'index groups the store under today');
$check(strpos($index, $plugin->getPriceList()->outlets()[0]->displayName()) < strpos($index, 'Test store'), 'web shop listed before the store');

sleep(1);
$again = $plugin->getPriceList()->publish('test-store');
$check(count($again['files']) === 1 && PriceListFiles::parse(basename($again['files'][0]['csv']))['number'] === 2, 'second run the same day: broj pohrane 2, one outlet only');
$groups = $plugin->getPriceList()->publishedFiles($dir);
$store = array_values(array_filter($groups[0]['outlets'], fn($o) => $o['name'] === 'Test store'))[0] ?? ['files' => []];
$check(array_column($store['files'], 'number') === [2, 2, 1, 1], 'index lists the latest run first');

try {
    $plugin->getPriceList()->publish('no-such-outlet');
    $check(false, 'unknown outlet code is refused');
} catch (InvalidArgumentException) {
    $check(true, 'unknown outlet code is refused');
}

array_map('unlink', glob("$dir/*") ?: []);
rmdir($dir);

echo $fail ? "$fail FAIL\n" : "all PASS\n";
exit($fail ? 1 : 0);
