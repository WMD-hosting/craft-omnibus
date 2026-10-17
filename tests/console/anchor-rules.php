<?php
/**
 * The 17.11.2026 price rules (1.3.0) against a real Craft install: per-product
 * reference days (anchorDateRules by product type and by related category with
 * its descendants, EVENT_DEFINE_ANCHOR_DATE), the historic import
 * (`omnibus/anchor/import`), the override field, and the price list's
 * `naziv_posebnog_oblika_prodaje`, `neto_kolicina` and per-row `sidreni_datum`.
 *
 * Usage, from a Craft install with Omnibus, Commerce, two product types and
 * products related to a structure entry or category:
 *   php tests/console/anchor-rules.php <craft-root> [--src=<plugin src dir>]
 * Everything runs in one database transaction that is rolled back; files go to
 * a temporary directory that is removed. Exit code 0 = all PASS.
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
    fwrite(STDERR, "usage: php tests/console/anchor-rules.php <craft-root> [--src=<dir>]\n");
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
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use wmd\craftproductpricehistory\events\DefineAnchorDateEvent;
use wmd\craftproductpricehistory\events\DefinePriceListRowEvent;
use wmd\craftproductpricehistory\ProductHistory;
use wmd\craftproductpricehistory\records\ProductPriceHistoryRecord;
use wmd\craftproductpricehistory\services\AnchorService;
use wmd\craftproductpricehistory\services\PriceListService;
use yii\base\Event;
use yii\console\ExitCode;

$fail = 0;
$skip = 0;
$check = function(bool $ok, string $what) use (&$fail) {
    echo ($ok ? 'PASS' : 'FAIL') . "  $what\n";
    $fail += $ok ? 0 : 1;
};

$plugin = ProductHistory::$plugin;
if ($src !== null) {
    $plugin->setBasePath($src);
    // Translations too: Craft registered the category with the vendor path.
    $i18n = Craft::$app->getI18n();
    if (is_object($i18n->translations['omnibus'] ?? null)) {
        $i18n->translations['omnibus']->basePath = "$src/translations";
    } else {
        $i18n->translations['omnibus']['basePath'] = "$src/translations";
    }
    $file = (new ReflectionClass(AnchorService::class))->getFileName();
    $check(str_starts_with($file, $src), "plugin classes load from --src ($file)");
}

$anchor = $plugin->getAnchor();
$settings = $plugin->getSettings();
$settings->anchorDateRules = [];
$settings->anchorOverrideField = '';
$default = $settings->anchorDate;
$tz = new DateTimeZone(Craft::$app->getTimeZone());

// Two product types; within the second, a variant related to a structure entry
// or category, and one that is not. Each needs a history row on or before the default day.
$types = Commerce::getInstance()->getProductTypes()->getAllProductTypes();
if (count($types) < 2) {
    fwrite(STDERR, "needs two product types\n");
    exit(2);
}
$hasRowByDefaultDay = (new Query())
    ->select(['variantId'])
    ->from(ProductPriceHistoryRecord::tableName())
    ->where(['<=', 'dateChanged', (new DateTime("$default 23:59:59", $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')])
    ->distinct()
    ->column();

// Type B (relations, unmatched) is the one with the most history by the default day; type A any other.
$withHistory = (new Query())->select(['primaryOwnerId'])->distinct()->from(CommerceTable::VARIANTS)->where(['id' => $hasRowByDefaultDay])->column();
usort($types, fn($x, $y) => Product::find()->typeId($y->id)->id($withHistory)->status(null)->count() <=> Product::find()->typeId($x->id)->id($withHistory)->status(null)->count());
[$typeB, $typeA] = $types;
$variantA = Variant::find()->status(Variant::STATUS_ENABLED)->typeId($typeA->id)->orderBy('sku')->one();
$target = (new Query())
    ->select(['r.targetId'])
    ->from(['r' => Table::RELATIONS])
    ->innerJoin(['p' => CommerceTable::PRODUCTS], '[[p.id]] = [[r.sourceId]]')
    ->innerJoin(['se' => Table::STRUCTUREELEMENTS], '[[se.elementId]] = [[r.targetId]]')
    ->where(['p.typeId' => $typeB->id, 'p.id' => $withHistory])
    ->groupBy(['r.targetId'])
    ->having(['<', 'COUNT(DISTINCT [[p.id]])', (new Query())->from(CommerceTable::PRODUCTS)->where(['typeId' => $typeB->id])->count()])
    ->orderBy(['COUNT(DISTINCT [[p.id]])' => SORT_ASC])
    ->scalar();
$relatedProducts = Product::find()->typeId($typeB->id)->relatedTo((int)$target)->status(null)->ids();
$variantRel = Variant::find()->status(Variant::STATUS_ENABLED)->id($hasRowByDefaultDay)->productId($relatedProducts)->orderBy('sku')->one();
$variantPlain = Variant::find()->status(Variant::STATUS_ENABLED)->id($hasRowByDefaultDay)->typeId($typeB->id)->productId(array_values(array_diff(Product::find()->typeId($typeB->id)->status(null)->ids(), $relatedProducts)))->orderBy('sku')->one();
if (!$variantA || !$target || !$variantRel || !$variantPlain) {
    fwrite(STDERR, "test data not found (type A variant, related target, related and plain variant)\n");
    exit(2);
}
echo "using {$typeA->handle}:{$variantA->sku}, {$typeB->handle}:{$variantRel->sku} (related to #$target), {$typeB->handle}:{$variantPlain->sku}\n";

$db = Craft::$app->getDb();
$transaction = $db->beginTransaction();
$dir = sys_get_temp_dir() . '/omnibus-rules-' . bin2hex(random_bytes(4));
$csvFile = "$dir/prices-2025-05-02.csv";

try {
    // ---------------------------------------------------------------- defaults
    $check($anchor->anchorDateFor($variantA) === $default && $anchor->anchorDateFor((int)$variantA->id) === $default, "no rules: every variant takes $default");
    $before = $anchor->getVariantAnchor($variantPlain);

    // ------------------------------------------------------------------ import
    mkdir($dir);
    $prices = [$variantA->sku => 111.11, $variantRel->sku => 222.22, $variantPlain->sku => 333.33];
    file_put_contents($csvFile, "\xEF\xBB\xBFsku;cijena\n" . implode("\n", array_map(fn($sku, $p) => "$sku;" . number_format($p, 2, ',', '.'), array_keys($prices), $prices)) . "\nno-such-sku-xyz;5,00\nbad;1.234\n");
    $stamp = (new DateTime('2025-05-02 12:00:00', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $countAt = fn() => (int)(new Query())->from(ProductPriceHistoryRecord::tableName())->where(['dateChanged' => $stamp, 'variantId' => [$variantA->id, $variantRel->id, $variantPlain->id]])->count();

    // A variant without history since would carry the 2025 price into every later day: refused until backfilled.
    Db::delete(ProductPriceHistoryRecord::tableName(), ['variantId' => $variantA->id]);
    $refused = $anchor->importPrices([$variantA->sku => 1.0, strtolower($variantRel->sku) => 2.0], new DateTime('2025-05-02'), true);
    $check($refused['unrecorded'] === [$variantA->sku] && $refused['written'] === 1, 'a variant with no later history is skipped as unrecorded; SKUs match case-insensitively');
    $anchor->backfillMissing(new DateTime("$default 12:00:00"));
    $check((new Query())->from(ProductPriceHistoryRecord::tableName())->where(['variantId' => $variantA->id])->exists(), 'backfill records it again');

    $exit = $app->runAction('omnibus/anchor/import', [$csvFile, 'asOf' => '2025-05-02', 'dryRun' => true]);
    $check($exit === ExitCode::DATAERR && $countAt() === 0, 'dry run with an unknown SKU and a bad line: exit DATAERR, nothing written');
    $dry = $anchor->importPrices($prices, new DateTime('2025-05-02'), true);
    $check($dry['written'] === 3 && $countAt() === 0, 'a dry run counts what it would write');

    $check($app->runAction('omnibus/anchor/import', [$csvFile]) === ExitCode::USAGE, 'import without --as-of is refused');
    $check($app->runAction('omnibus/anchor/import', [$csvFile, 'asOf' => (new DateTime('+2 days'))->format('Y-m-d')]) === ExitCode::USAGE, 'import with a future --as-of is refused');

    file_put_contents($csvFile, implode("\n", array_map(fn($sku, $p) => "$sku;" . number_format($p, 2, ',', ''), array_keys($prices), $prices)) . "\n");
    $exit = $app->runAction('omnibus/anchor/import', [$csvFile, 'asOf' => '2025-05-02']);
    $check($exit === ExitCode::OK && $countAt() === 3, "import writes one row per variant at $stamp UTC (noon, site time)");
    $again = $anchor->importPrices($prices, new DateTime('2025-05-02'));
    $check($again['written'] === 0 && $again['unchanged'] === 3 && $countAt() === 3, 'a second run with the same prices changes nothing');
    $changed = $anchor->importPrices([$variantA->sku => 111.5], new DateTime('2025-05-02'));
    $check($changed['updated'] === 1 && $countAt() === 3, 'a second run with another price updates the row');

    $check($anchor->getVariantAnchor($variantPlain) == $before, "without rules the import leaves the $default anchor alone");

    // ------------------------------------------------------- rules by type/relation
    // The CP's editable table posts comma-separated strings.
    $settings->anchorDateRules = [
        'row0' => ['date' => '2025-05-02', 'productTypes' => $typeA->handle, 'relatedTo' => ''],
        'row1' => ['date' => '2025-05-02', 'productTypes' => '', 'relatedTo' => (string)$target],
    ];
    $check($settings->validate(['anchorDateRules']), 'CP-shaped rules validate');
    $check($anchor->anchorDateFor($variantA) === '2025-05-02', "product type {$typeA->handle} takes 2025-05-02");
    $check($anchor->anchorDateFor((int)$variantRel->id) === '2025-05-02', "a product related to #$target takes 2025-05-02 (by id)");
    $check($anchor->anchorDateFor($variantPlain) === $default, "an unmatched product keeps $default");
    $check($anchor->anchorDates() === array_values(array_unique([$default, '2025-05-02'])), 'anchorDates() lists the default first');

    $a = $anchor->getVariantAnchor($variantA);
    $check($a !== null && abs($a['price'] - 111.5) < 0.001 && $a['date']->format('Y-m-d') === '2025-05-02' && $a['source'] === AnchorService::SOURCE_ANCHOR_DATE, 'anchor = imported 2025-05-02 price, dated 2025-05-02');
    $check($anchor->getVariantAnchor((int)$variantA->id) == $a, 'the id gives the same anchor as the variant');
    $check($anchor->getVariantAnchor($variantPlain) == $before, "the unmatched product's anchor is unchanged");

    // The day ends at local midnight, not UTC midnight: 23:30 counts, 00:30 the next day does not.
    $utc = fn(string $local) => (new DateTime($local, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    foreach (['2025-05-02 23:30:00' => 444.44, '2025-05-03 00:30:00' => 555.55] as $local => $price) {
        // The table has uid but no dateCreated/dateUpdated: no audit columns.
        $db->createCommand()->insert(ProductPriceHistoryRecord::tableName(), ['productId' => $variantRel->getPrimaryOwnerId(), 'variantId' => $variantRel->id, 'price' => $price, 'dateChanged' => $utc($local), 'uid' => craft\helpers\StringHelper::UUID()], false)->execute();
    }
    $r = $anchor->getVariantAnchor($variantRel);
    $check($r !== null && abs($r['price'] - 444.44) < 0.001, "the reference day ends at local midnight ({$tz->getName()}): " . ($r['price'] ?? '-'));
    Db::delete(ProductPriceHistoryRecord::tableName(), ['variantId' => $variantRel->id, 'price' => [444.44, 555.55]]);

    // A rule naming a parent also covers its children: nest the target under another element.
    $node = (new Query())->select(['structureId', 'lft', 'rgt'])->from(Table::STRUCTUREELEMENTS)->where(['elementId' => $target])->one();
    $parent = (int)(new Query())->select(['id'])->from(Table::ELEMENTS)
        ->where(['not in', 'id', (new Query())->select(['targetId'])->from(Table::RELATIONS)])
        ->andWhere(['not in', 'id', (new Query())->select(['elementId'])->from(Table::STRUCTUREELEMENTS)->where(['structureId' => $node['structureId']])->andWhere(['not', ['elementId' => null]])])
        ->andWhere(['type' => 'craft\\elements\\Entry'])
        ->scalar();
    Db::insert(Table::STRUCTUREELEMENTS, [
        'structureId' => $node['structureId'], 'elementId' => $parent, 'root' => null,
        'lft' => (int)$node['lft'] - 1, 'rgt' => (int)$node['rgt'] + 1, 'level' => 1,
    ]);
    $settings->anchorDateRules = [['date' => '2025-06-15', 'relatedTo' => [$parent]]];
    $check($anchor->anchorDateFor($variantRel) === '2025-06-15', "a rule on a parent (#$parent) covers its child #$target");
    $check($anchor->anchorDateFor($variantA) === $default, 'and nothing outside it');

    $settings->anchorDateRules = [['date' => '2025-5-2', 'productTypes' => 'x'], ['date' => '2025-05-02']];
    $check(!$settings->validate(['anchorDateRules']) && count($settings->getErrors('anchorDateRules')) === 2, 'broken rules fail validation (' . implode(' ', $settings->getErrors('anchorDateRules')) . ')');
    // config/omnibus.php is never validated: a rule without a valid day is ignored, not "today".
    $settings->anchorDateRules = [['date' => '', 'productTypes' => [$typeA->handle]], ['date' => 'soon', 'productTypes' => [$typeA->handle]]];
    $check($anchor->anchorDateFor($variantA) === $default, 'unvalidated rules without a valid day are ignored');

    // ------------------------------------------------------------------- event
    $settings->anchorDateRules = [['date' => '2025-05-02', 'productTypes' => [$typeA->handle]]];
    $handler = function(DefineAnchorDateEvent $e) use ($variantPlain, $variantA) {
        if ($e->variant->id === $variantPlain->id) {
            $e->date = '2025-05-02';
        } elseif ($e->variant->id === $variantA->id) {
            $e->date = 'soon';
        }
    };
    Event::on(AnchorService::class, AnchorService::EVENT_DEFINE_ANCHOR_DATE, $handler);
    $p = $anchor->getVariantAnchor((int)$variantPlain->id);
    $check($p !== null && $p['date']->format('Y-m-d') === '2025-05-02' && abs($p['price'] - 333.33) < 0.001, 'EVENT_DEFINE_ANCHOR_DATE moves a variant to 2025-05-02');
    $check($anchor->anchorDateFor($variantA) === '2025-05-02', "a handler's invalid date is ignored, the rule's day stays");
    Event::off(AnchorService::class, AnchorService::EVENT_DEFINE_ANCHOR_DATE, $handler);

    // ---------------------------------------------------------------- override
    $overrideField = null;
    foreach (['variant' => $variantA, 'product' => $variantA->getOwner()] as $element) {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof craft\fields\Number || $field instanceof craft\fields\Money) {
                $overrideField = $field;
                break 2;
            }
        }
    }
    if ($overrideField) {
        $settings->anchorOverrideField = $overrideField->handle;
        $copy = clone $variantA;
        $holder = $copy->getFieldLayout()?->getFieldByHandle($overrideField->handle) ? $copy : $copy->getOwner();
        $holder->setFieldValue($overrideField->handle, $overrideField instanceof craft\fields\Money ? ['value' => '77.40', 'currency' => 'EUR'] : 77.4);
        $o = $anchor->getVariantAnchor($copy);
        $check($o !== null && abs($o['price'] - 77.4) < 0.001 && $o['source'] === AnchorService::SOURCE_OVERRIDE && $o['date']->format('Y-m-d') === '2025-05-02', "override field {$overrideField->handle} is the anchor, with the variant's day");
        $holder->setFieldValue($overrideField->handle, $overrideField instanceof craft\fields\Money ? ['value' => '0', 'currency' => 'EUR'] : 0);
        $check($anchor->getVariantAnchor($copy)['source'] === AnchorService::SOURCE_ANCHOR_DATE, 'an override of 0 falls back to the history');
        $holder->setFieldValue($overrideField->handle, null);
        $check($anchor->getVariantAnchor($copy)['source'] === AnchorService::SOURCE_ANCHOR_DATE, 'an empty override field falls back to the history');
        $settings->anchorOverrideField = '';
    } else {
        echo "SKIP  override field: no number or money field on {$typeA->handle}\n";
        $skip++;
    }

    // -------------------------------------------------------------- price list
    // A promotional catalog pricing rule on the related variant, as Commerce would have generated it.
    $storeId = Commerce::getInstance()->getStores()->getCurrentStore()->id;
    $now = Db::prepareDateForDb(new DateTime());
    Db::insert(CommerceTable::CATALOG_PRICING_RULES, [
        'name' => 'Testna jesenska akcija', 'storeId' => $storeId, 'apply' => 'byPercent', 'applyAmount' => -0.1,
        'applyPriceType' => 'price', 'isPromotionalPrice' => true, 'enabled' => true,
    ]);
    $ruleId = (int)$db->getLastInsertID();
    $salePrice = round((float)$variantRel->basePrice * 0.9, 2);
    Db::insert(CommerceTable::CATALOG_PRICING, [
        'price' => $salePrice, 'purchasableId' => $variantRel->id, 'storeId' => $storeId,
        'catalogPricingRuleId' => $ruleId, 'isPromotionalPrice' => true,
    ]);
    // A customer-group rule at the same price, named to sort first: not a published sale.
    Db::insert(CommerceTable::CATALOG_PRICING_RULES, [
        'name' => 'A samo za VIP kupce', 'storeId' => $storeId, 'apply' => 'byPercent', 'applyAmount' => -0.1,
        'applyPriceType' => 'price', 'isPromotionalPrice' => true, 'enabled' => true,
    ]);
    $vipRuleId = (int)$db->getLastInsertID();
    Db::insert(CommerceTable::CATALOG_PRICING_RULES_USERS, ['catalogPricingRuleId' => $vipRuleId, 'userId' => (int)(new Query())->select(['id'])->from(Table::USERS)->scalar()]);
    Db::insert(CommerceTable::CATALOG_PRICING, [
        'price' => $salePrice, 'purchasableId' => $variantRel->id, 'storeId' => $storeId,
        'catalogPricingRuleId' => $vipRuleId, 'isPromotionalPrice' => true,
    ]);
    // Variants loaded above made Commerce cache the catalog prices; a publish run starts without.
    (fn() => $this->_allCatalogPrices = null)->call(Commerce::getInstance()->getCatalogPricing());
    (fn() => $this->_hasCatalogPricingRules = null)->call(Commerce::getInstance()->getCatalogPricingRules());
    // The variant whose promotional price was typed in, if any.
    $typedPromo = (new Query())->select(['p.sku'])->from(['ps' => CommerceTable::PURCHASABLES_STORES])
        ->innerJoin(['p' => CommerceTable::PURCHASABLES], '[[p.id]] = [[ps.purchasableId]]')
        ->where(['ps.storeId' => $storeId])->andWhere(['not', ['ps.basePromotionalPrice' => null]])
        ->andWhere('[[ps.basePromotionalPrice]] < [[ps.basePrice]]')->scalar();

    $settings->priceListPath = $dir;
    $settings->anchorDateRules = [['date' => '2025-05-02', 'productTypes' => [$typeA->handle]]];
    $rowHandler = function(DefinePriceListRowEvent $e) use ($variantA) {
        if ($e->variantId === $variantA->id && $e->row !== null) {
            $e->row['neto_kolicina'] = '0,5 l';
        }
    };
    Event::on(PriceListService::class, PriceListService::EVENT_DEFINE_ROW, $rowHandler);
    $result = $plugin->getPriceList()->publish();
    Event::off(PriceListService::class, PriceListService::EVENT_DEFINE_ROW, $rowHandler);
    $web = $result['files'][0];

    $csv = array_map(fn($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", substr(file_get_contents($web['csv']), 3))));
    $header = array_shift($csv);
    $check($header === PriceListService::COLUMNS && in_array('neto_kolicina', $header, true) && in_array('naziv_posebnog_oblika_prodaje', $header, true), 'CSV header carries neto_kolicina and naziv_posebnog_oblika_prodaje');
    $check(!in_array(PriceListService::ROW_ANCHOR_DATE, $header, true), 'the row date is not a CSV column');
    $rows = array_combine(array_column($csv, 1), array_map(fn($r) => array_combine($header, $r), $csv));
    $rel = $rows[$variantRel->sku] ?? [];
    $check(($rel['posebni_oblik_prodaje'] ?? '') === 'DA' && ($rel['naziv_posebnog_oblika_prodaje'] ?? '') === 'Testna jesenska akcija' && ($rel['maloprodajna_cijena'] ?? '') === number_format($salePrice, 2, '.', ''), 'a pricing rule\'s sale names the rule (' . ($rel['naziv_posebnog_oblika_prodaje'] ?? '?') . ' at ' . ($rel['maloprodajna_cijena'] ?? '?') . ')');
    if ($typedPromo && isset($rows[$typedPromo])) {
        $check($rows[$typedPromo]['naziv_posebnog_oblika_prodaje'] === 'Sniženje', "a typed promotional price ($typedPromo) gets the default name, in Croatian");
    } else {
        echo "SKIP  typed promotional price: none in this store\n";
        $skip++;
    }
    $regular = array_filter($rows, fn($r) => $r['posebni_oblik_prodaje'] === 'NE');
    $check($regular !== [] && array_filter($regular, fn($r) => $r['naziv_posebnog_oblika_prodaje'] !== '') === [], 'regular prices have no sale name');
    $check(($rows[$variantA->sku]['neto_kolicina'] ?? '') === '0,5 l', 'EVENT_DEFINE_ROW fills neto_kolicina');
    $check(($rows[$variantA->sku]['sidrena_cijena'] ?? '') === '111.50', 'the type A row publishes the 2025-05-02 anchor');

    $xml = simplexml_load_file($web['xml']);
    $byCode = [];
    foreach ($xml->proizvod as $item) {
        $byCode[(string)$item->sifra] = $item;
    }
    $check((string)$xml['sidreni_datum'] === $default, "root sidreni_datum stays the default ($default)");
    $check((string)($byCode[$variantA->sku]['sidreni_datum'] ?? '') === '2025-05-02', 'a type A proizvod carries sidreni_datum 2025-05-02');
    $check((string)($byCode[$variantPlain->sku]['sidreni_datum'] ?? '') === $before['date']->format('Y-m-d'), 'an unmatched proizvod carries its own day (' . ($byCode[$variantPlain->sku]['sidreni_datum'] ?? '?') . ')');
    $check(isset($byCode[$variantRel->sku]->naziv_posebnog_oblika_prodaje) && isset($byCode[$variantA->sku]->neto_kolicina), 'XML has the new elements');

    $index = file_get_contents($result['index']);
    $check(str_contains($index, '02.05.2025') && str_contains($index, (new DateTime($default))->format('d.m.Y')), 'the index names both reference days');
} finally {
    $transaction->rollBack();
    array_map('unlink', glob("$dir/*") ?: []);
    @rmdir($dir);
}

$check((new Query())->from(ProductPriceHistoryRecord::tableName())->where(['dateChanged' => $stamp])->count() == 0, 'rolled back: no imported rows left');

echo ($fail ? "$fail FAIL" : 'all PASS') . ($skip ? ", $skip SKIP" : '') . "\n";
exit($fail ? 1 : 0);
