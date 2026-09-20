<?php

declare(strict_types=1);

namespace wmd\craftproductpricehistory\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use wmd\craftproductpricehistory\ProductHistory;

/**
 * Generates and publishes the machine-readable price list required by
 * NN 101/2026 (Odluka o objavi cjenika proizvoda i usluga):
 *
 * - one CSV and one XML per run, fields in the order of čl. III;
 * - file name per čl. VI: oblik, adresa, oznaka prodajnog objekta, broj
 *   pohrane, vremenska oznaka;
 * - published under the web root, kept for `retentionDays` (čl. VII: 30),
 *   with an index the crawlers can follow.
 *
 * @since 1.1.0
 */
class PriceListService extends Component
{
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
     * Builds the rows: one per enabled, live variant.
     *
     * @return list<array<string, string>>
     */
    public function rows(?int $storeId = null): array
    {
        $settings = ProductHistory::$plugin->getSettings();
        $anchor = ProductHistory::$plugin->getAnchor();
        $store = $storeId
            ? Commerce::getInstance()->getStores()->getStoreById($storeId)
            : Commerce::getInstance()->getStores()->getPrimaryStore();

        $rows = [];
        // Single-store today; Commerce resolves prices for the primary store.
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
            ];
        }

        return $rows;
    }

    /**
     * Writes the CSV and XML for today and prunes files older than the
     * retention period. Returns the written paths.
     *
     * @return array{csv: string, xml: string, index: string, rows: int, pruned: int}
     */
    public function publish(?int $storeId = null): array
    {
        $settings = ProductHistory::$plugin->getSettings();
        $dir = Craft::getAlias($settings->priceListPath);
        FileHelper::createDirectory($dir);

        $rows = $this->rows($storeId);
        $stamp = new DateTime('now', new DateTimeZone(Craft::$app->getTimeZone()));
        $base = $this->fileBase($stamp, $dir);

        $csvPath = "$dir/$base.csv";
        $xmlPath = "$dir/$base.xml";
        FileHelper::writeToFile($csvPath, $this->csv($rows, $settings->csvDelimiter));
        FileHelper::writeToFile($xmlPath, $this->xml($rows, $stamp));

        $pruned = $this->prune($dir, $settings->retentionDays);
        $indexPath = $this->writeIndex($dir);

        return ['csv' => $csvPath, 'xml' => $xmlPath, 'index' => $indexPath, 'rows' => count($rows), 'pruned' => $pruned];
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

    public function xml(array $rows, DateTime $stamp): string
    {
        $settings = ProductHistory::$plugin->getSettings();
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('cjenik');
        $root->setAttribute('oblik_prodajnog_objekta', $settings->storeType);
        $root->setAttribute('adresa_prodajnog_objekta', $settings->storeAddress);
        $root->setAttribute('oznaka_prodajnog_objekta', $settings->storeCode);
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
     * čl. VI: oblik, adresa, oznaka prodajnog objekta, broj pohrane, datum i
     * vrijeme. The archive number is the run count for the day.
     */
    public function fileBase(DateTime $stamp, string $dir): string
    {
        $settings = ProductHistory::$plugin->getSettings();
        $day = $stamp->format('Ymd');
        $existing = glob("$dir/*_{$day}_*.csv") ?: [];
        $archiveNumber = count($existing) + 1;

        $parts = [
            StringHelper::toKebabCase($settings->storeType) ?: 'trgovina',
            StringHelper::toKebabCase($settings->storeAddress) ?: 'bez-adrese',
            StringHelper::toKebabCase($settings->storeCode) ?: 'objekt',
            (string)$archiveNumber,
            $day . '_' . $stamp->format('His'),
        ];

        return implode('_', $parts);
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

    /** Plain HTML index of the published files, newest first, so crawlers and inspectors find them. */
    public function writeIndex(string $dir): string
    {
        $files = glob("$dir/*.{csv,xml}", GLOB_BRACE) ?: [];
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

        $items = '';
        foreach ($files as $file) {
            $name = basename($file);
            $when = date('Y-m-d H:i', filemtime($file));
            $items .= "<li><a href=\"{$name}\">{$name}</a> <small>({$when})</small></li>\n";
        }
        $html = "<!doctype html>\n<html lang=\"hr\"><head><meta charset=\"utf-8\"><title>Cjenik</title>"
            . "<meta name=\"robots\" content=\"index,follow\"></head><body>"
            . "<h1>Cjenik proizvoda</h1><p>Objavljeno prema Odluci o objavi cjenika proizvoda i usluga (NN 101/2026). Datoteke ostaju dostupne 30 dana.</p>"
            . "<ul>\n{$items}</ul></body></html>\n";
        $path = "$dir/index.html";
        FileHelper::writeToFile($path, $html);

        return $path;
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
