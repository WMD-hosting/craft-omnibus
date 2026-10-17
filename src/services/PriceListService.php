<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryTransactionType;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\FileHelper;
use craft\web\View;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use wmd\craftproductpricehistory\events\DefinePriceListRowEvent;
use wmd\craftproductpricehistory\events\RegisterPriceListOutletsEvent;
use wmd\craftproductpricehistory\helpers\PriceListFiles;
use wmd\craftproductpricehistory\models\PriceListOutlet;
use wmd\craftproductpricehistory\ProductHistory;

/**
 * Generates and publishes the machine-readable price list required by
 * NN 101/2026 (Odluka o objavi cjenika proizvoda i usluga):
 *
 * - one CSV and one XML per outlet ("prodajni objekt") per run, fields in the
 *   order of čl. III. The web shop comes from the plugin settings; physical
 *   stores are added through EVENT_REGISTER_OUTLETS;
 * - file name per čl. VI: oblik, adresa, oznaka prodajnog objekta, broj
 *   pohrane, vremenska oznaka;
 * - published under the web root, kept for `retentionDays` (čl. VII: 30),
 *   with an index the crawlers can follow, grouped by day, then outlet.
 *
 * @since 1.1.0
 */
class PriceListService extends Component
{
    /**
     * @event RegisterPriceListOutletsEvent Lists the outlets that get a file.
     * @since 1.2.0
     */
    public const EVENT_REGISTER_OUTLETS = 'registerOutlets';

    /**
     * @event DefinePriceListRowEvent One row of one outlet, before it is written.
     * @since 1.2.0
     */
    public const EVENT_DEFINE_ROW = 'defineRow';

    /**
     * @var string[] Outlets the last outlets() call skipped, with the reason.
     * @since 1.2.0
     */
    public array $skippedOutlets = [];

    /** @var list<PriceListOutlet>|null Outlets of the publish() run in progress. */
    private ?array $_runOutlets = null;

    /** Column order per čl. III of the decision. */
    public const COLUMNS = [
        'naziv',
        'sifra',
        'marka',
        'jedinica_mjere',
        'cijena_za_jedinicu_mjere',
        'maloprodajna_cijena',
        'posebni_oblik_prodaje',
        'sidrena_cijena',
        'barkod',
        'dostupnost',
    ];

    /**
     * The outlets, web shop first unless a handler moved or removed it. An
     * outlet whose file-name prefix repeats an earlier one is skipped: its
     * files would be indistinguishable.
     *
     * @return list<PriceListOutlet>
     * @since 1.2.0
     */
    public function outlets(): array
    {
        if ($this->_runOutlets !== null) {
            return $this->_runOutlets;
        }

        $settings = ProductHistory::$plugin->getSettings();
        $outlets = [new PriceListOutlet([
            'code' => $settings->storeCode,
            'type' => $settings->storeType,
            'address' => $settings->storeAddress,
            'name' => Craft::t('omnibus', 'Online store'),
        ])];

        if ($this->hasEventHandlers(self::EVENT_REGISTER_OUTLETS)) {
            $event = new RegisterPriceListOutletsEvent(['outlets' => $outlets]);
            $this->trigger(self::EVENT_REGISTER_OUTLETS, $event);
            $outlets = $event->outlets;
        }

        $unique = [];
        $this->skippedOutlets = [];
        foreach ($outlets as $outlet) {
            $reason = !$outlet->validate()
                ? implode(' ', $outlet->getErrorSummary(true))
                : (isset($unique[$outlet->prefix()]) ? "file prefix {$outlet->prefix()} is taken" : null);
            if ($reason !== null) {
                $this->skippedOutlets[] = "\"{$outlet->code}\": $reason";
                Craft::warning("Skipped price-list outlet \"{$outlet->code}\": $reason", __METHOD__);
                continue;
            }
            $unique[$outlet->prefix()] = $outlet;
        }

        return array_values($unique);
    }

    /**
     * The outlet with this code, or null.
     *
     * @since 1.2.0
     */
    public function getOutletByCode(string $code): ?PriceListOutlet
    {
        foreach ($this->outlets() as $outlet) {
            if ($outlet->code === $code) {
                return $outlet;
            }
        }
        return null;
    }

    /**
     * Builds the rows of one outlet (the first outlet when null): one per
     * enabled, live variant.
     *
     * @return list<array<string, string>>
     */
    public function rows(?PriceListOutlet $outlet = null): array
    {
        $outlet ??= $this->outlets()[0] ?? null;
        if ($outlet === null) {
            return [];
        }

        return $this->outletRows($this->baseRows(), $outlet);
    }

    /**
     * Writes today's CSV and XML for every outlet (or the one with
     * `$outletCode`), prunes files older than the retention period and
     * rewrites the index.
     *
     * @return array{files: list<array{code: string, name: string, csv: string, xml: string, rows: int}>, index: string, pruned: int, skipped: string[]}
     */
    public function publish(?string $outletCode = null): array
    {
        $settings = ProductHistory::$plugin->getSettings();
        $dir = Craft::getAlias($settings->priceListPath);
        FileHelper::createDirectory($dir);

        // Cron runs in the console's language; the index and outlet names are the site's.
        $language = Craft::$app->language;
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->language = Craft::$app->getSites()->getPrimarySite()->language;
        }

        try {
            // Handlers of EVENT_REGISTER_OUTLETS run once per run, not per file and index.
            $this->_runOutlets = null;
            $this->_runOutlets = $outlets = $this->outlets();

            if ($outletCode !== null) {
                $outlets = array_values(array_filter($outlets, static fn(PriceListOutlet $o) => $o->code === $outletCode));
                if ($outlets === []) {
                    throw new InvalidArgumentException("No price-list outlet has the code \"$outletCode\".");
                }
            }

            // One read of the catalogue and one timestamp for the whole run.
            $base = $this->baseRows();
            $stamp = new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
            $written = [];

            foreach ($outlets as $outlet) {
                $rows = $this->outletRows($base, $outlet);
                $name = PriceListFiles::base($outlet->prefix(), $stamp, glob("$dir/*.csv") ?: []);
                $csvPath = "$dir/$name.csv";
                $xmlPath = "$dir/$name.xml";
                FileHelper::writeToFile($csvPath, $this->csv($rows, $settings->csvDelimiter));
                FileHelper::writeToFile($xmlPath, $this->xml($rows, $stamp, $outlet));
                $written[] = ['code' => $outlet->code, 'name' => $outlet->displayName(), 'csv' => $csvPath, 'xml' => $xmlPath, 'rows' => count($rows)];
            }

            $pruned = $this->prune($dir, $settings->retentionDays);
            $indexPath = $this->writeIndex($dir);
        } finally {
            $this->_runOutlets = null;
            Craft::$app->language = $language;
        }

        return ['files' => $written, 'index' => $indexPath, 'pruned' => $pruned, 'skipped' => $this->skippedOutlets];
    }

    /**
     * The published files grouped by day, then outlet, for the public index.
     *
     * @param string|null $baseUrl Prefix of the file links; null links by file name only.
     * @return list<array{date: string, outlets: list<array<string, mixed>>}>
     * @since 1.2.0
     */
    public function publishedFiles(string $dir, ?string $baseUrl = null): array
    {
        $files = [];
        foreach (is_dir($dir) ? (glob("$dir/*.{csv,xml}", GLOB_BRACE) ?: []) : [] as $path) {
            $name = basename($path);
            $files[] = [
                'name' => $name,
                'url' => $baseUrl === null ? $name : rtrim($baseUrl, '/') . '/' . $name,
                'size' => (int)filesize($path),
            ];
        }

        $outlets = [];
        foreach ($this->outlets() as $outlet) {
            $outlets[$outlet->prefix()] = ['name' => $outlet->displayName(), 'address' => $outlet->address];
        }

        return PriceListFiles::group($files, $outlets);
    }

    // =========================================================================
    // Rows
    // =========================================================================

    /**
     * The outlet-independent part of every row, read once per run.
     *
     * @return list<array{variantId: int, stockBound: bool, row: array<string, string>}>
     */
    private function baseRows(): array
    {
        $settings = ProductHistory::$plugin->getSettings();
        $anchor = ProductHistory::$plugin->getAnchor();
        $purchasables = Commerce::getInstance()->getPurchasables();

        $rows = [];
        // Prices are the current Commerce store's.
        $query = Variant::find()
            ->status(Variant::STATUS_ENABLED)
            ->orderBy('sku');

        foreach ($query->each() as $variant) {
            /** @var Variant $variant */
            $product = $variant->getOwner();
            if (!$product instanceof Product || !$product->enabled) {
                continue;
            }

            $anchorRow = $anchor->getVariantAnchor($variant->id);
            $salePrice = (float)$variant->salePrice;
            $basePrice = (float)$variant->basePrice;
            $onSale = $variant->getOnPromotion() || $salePrice < $basePrice;

            $rows[] = [
                'variantId' => (int)$variant->id,
                // Stock-tracked and not sellable without stock: a store's own stock decides.
                'stockBound' => $variant->inventoryTracked && !$purchasables->isPurchasableOutOfStockPurchasingAllowed($variant),
                'row' => [
                    'naziv' => $this->variantName($product, $variant),
                    'sifra' => (string)$variant->sku,
                    'marka' => $this->fieldText($product, $settings->brandField),
                    'jedinica_mjere' => $this->fieldText($variant, $settings->unitField) ?: $this->fieldText($product, $settings->unitField),
                    'cijena_za_jedinicu_mjere' => $this->fieldText($variant, $settings->unitPriceField) ?: $this->fieldText($product, $settings->unitPriceField),
                    'maloprodajna_cijena' => $this->money($salePrice),
                    'posebni_oblik_prodaje' => $onSale ? 'DA' : 'NE',
                    'sidrena_cijena' => $anchorRow ? $this->money($anchorRow['price']) : '',
                    'barkod' => $this->fieldText($variant, $settings->barcodeField) ?: $this->fieldText($product, $settings->barcodeField),
                    'dostupnost' => ($variant->availableForPurchase && $variant->getIsAvailable()) ? 'dostupno' : 'nedostupno',
                ],
            ];
        }

        return $rows;
    }

    /**
     * One outlet's rows: the web prices; a variant the web sells only from
     * stock is `nedostupno` where the outlet's inventory location has none;
     * then the handlers of EVENT_DEFINE_ROW.
     *
     * @param list<array{variantId: int, stockBound: bool, row: array<string, string>}> $base
     * @return list<array<string, string>>
     */
    private function outletRows(array $base, PriceListOutlet $outlet): array
    {
        $stock = $outlet->inventoryLocationId ? $this->locationStock($outlet->inventoryLocationId) : null;
        $hasHandlers = $this->hasEventHandlers(self::EVENT_DEFINE_ROW);

        $rows = [];
        foreach ($base as $item) {
            $row = $item['row'];
            // Only ever narrows the web's answer: what the web cannot sell, no store sells either.
            if ($stock !== null && $item['stockBound'] && $row['dostupnost'] === 'dostupno' && ($stock[$item['variantId']] ?? 0) < 1) {
                $row['dostupnost'] = 'nedostupno';
            }

            if ($hasHandlers) {
                $event = new DefinePriceListRowEvent(['outlet' => $outlet, 'variantId' => $item['variantId'], 'row' => $row]);
                $this->trigger(self::EVENT_DEFINE_ROW, $event);
                if ($event->row === null) {
                    continue;
                }
                $row = $event->row;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Available stock per purchasable at one inventory location: the sum of
     * its `available` transactions, which is how Commerce defines it.
     *
     * @return array<int, int> purchasable ID => available quantity
     */
    private function locationStock(int $locationId): array
    {
        $rows = (new Query())
            ->select(['ii.purchasableId', 'qty' => 'SUM([[it.quantity]])'])
            ->from(['it' => CommerceTable::INVENTORYTRANSACTIONS])
            ->innerJoin(['ii' => CommerceTable::INVENTORYITEMS], '[[ii.id]] = [[it.inventoryItemId]]')
            ->where(['it.inventoryLocationId' => $locationId, 'it.type' => InventoryTransactionType::AVAILABLE->value])
            ->groupBy(['ii.purchasableId'])
            ->all();

        $stock = [];
        foreach ($rows as $row) {
            $stock[(int)$row['purchasableId']] = (int)$row['qty'];
        }
        return $stock;
    }

    // =========================================================================
    // Formats
    // =========================================================================

    public function csv(array $rows, string $delimiter = ';'): string
    {
        $out = fopen('php://temp', 'r+');
        // UTF-8 BOM so spreadsheet software reads diacritics correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, $delimiter, '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn($c) => $row[$c] ?? '', self::COLUMNS), $delimiter, '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public function xml(array $rows, DateTime $stamp, ?PriceListOutlet $outlet = null): string
    {
        $settings = ProductHistory::$plugin->getSettings();
        $outlet ??= $this->outlets()[0] ?? new PriceListOutlet(['code' => $settings->storeCode, 'type' => $settings->storeType, 'address' => $settings->storeAddress]);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('cjenik');
        $root->setAttribute('oblik_prodajnog_objekta', $outlet->type);
        $root->setAttribute('adresa_prodajnog_objekta', $outlet->address);
        $root->setAttribute('oznaka_prodajnog_objekta', $outlet->code);
        $root->setAttribute('datum_objave', $stamp->format(DATE_ATOM));
        $root->setAttribute('sidreni_datum', $settings->anchorDate);
        $doc->appendChild($root);

        foreach ($rows as $row) {
            $item = $doc->createElement('proizvod');
            foreach (self::COLUMNS as $column) {
                $item->appendChild($doc->createElement($column))->appendChild($doc->createTextNode((string)($row[$column] ?? '')));
            }
            $root->appendChild($item);
        }

        return $doc->saveXML();
    }

    // =========================================================================
    // Files
    // =========================================================================

    /**
     * @deprecated in 1.2.0. Names now depend on the outlet; use PriceListFiles::base().
     */
    public function fileBase(DateTime $stamp, string $dir): string
    {
        $outlet = $this->outlets()[0] ?? null;
        $prefix = $outlet?->prefix() ?? PriceListFiles::prefix('', '', '');

        return PriceListFiles::base($prefix, $stamp, glob("$dir/*.csv") ?: []);
    }

    /** Deletes published files older than the retention period. Returns the count. */
    public function prune(string $dir, int $retentionDays): int
    {
        $cutoff = time() - $retentionDays * 86400;
        $pruned = 0;
        foreach (glob("$dir/*.{csv,xml}", GLOB_BRACE) ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $pruned++;
            }
        }
        return $pruned;
    }

    /**
     * Static copy of the public index next to the files, grouped by day, then
     * outlet, so crawlers that read the directory find every file.
     */
    public function writeIndex(string $dir): string
    {
        $html = $this->renderIndex($this->publishedFiles($dir));
        $path = "$dir/index.html";
        FileHelper::writeToFile($path, $html);

        return $path;
    }

    /**
     * The index page (also served on `priceListRoute`).
     *
     * @param list<array{date: string, outlets: list<array<string, mixed>>}> $days
     * @since 1.2.0
     */
    public function renderIndex(array $days): string
    {
        $settings = ProductHistory::$plugin->getSettings();

        return Craft::$app->getView()->renderTemplate('omnibus/_price-list', [
            'days' => $days,
            'anchorDate' => $settings->anchorDate,
            'retentionDays' => $settings->retentionDays,
        ], View::TEMPLATE_MODE_CP);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function variantName(Product $product, Variant $variant): string
    {
        $title = trim((string)$variant->title);
        $productTitle = trim((string)$product->title);
        if ($title === '' || $title === $productTitle) {
            return $productTitle;
        }
        return str_contains($title, $productTitle) ? $title : "$productTitle, $title";
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** Reads a field as text: plain values, or the titles of related elements. */
    private function fieldText(Product|Variant $element, string $handle): string
    {
        if ($handle === '' || !$element->getFieldLayout()?->getFieldByHandle($handle)) {
            return '';
        }
        $value = $element->getFieldValue($handle);
        if ($value === null || $value === '') {
            return '';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if ($value instanceof \craft\elements\db\ElementQuery) {
            return implode(', ', array_map(static fn($e) => (string)$e->title, $value->all()));
        }
        if (is_iterable($value)) {
            $parts = [];
            foreach ($value as $v) {
                $parts[] = is_object($v) && isset($v->title) ? (string)$v->title : (string)$v;
            }
            return implode(', ', array_filter($parts));
        }
        return method_exists($value, '__toString') ? (string)$value : '';
    }
}
