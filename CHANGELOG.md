# Release Notes for Omnibus, Price History and Anchor Price

## 1.3.2 - 2026-10-21

### Fixed
- A product created together with its variants in one save (any importer) recorded no price history: the first-save handler listened to `Product::EVENT_AFTER_SAVE`, but Commerce 5 saves the variants after it, so they had no ids yet. The first price is now recorded on the variant's own first save, under its primary owner. Drafts, revisions and propagation to other sites are skipped; a variant added in a product draft gets its row when the draft is applied. Variants imported with 1.3.1 or earlier and not saved since have no rows: run `omnibus/anchor/backfill`.
- `product_price_history` had no index for the per-variant lookups (lowest price in a window, the duplicate check on every save), which scanned the whole table on large catalogues. A migration adds one on (`variantId`, `dateChanged`).

## 1.3.1 - 2026-10-17

### Fixed
- The lowest price before a reduction was empty for every variant on a Commerce promotional price or catalog pricing rule. The history records base prices only, so the latest row (usually the regular price, e.g. a backfill) was taken as the start of the reduction and the window before it was empty. The window now closes at the history row that lowered the price, or, when the selling price is below the latest recorded price, at the start (`dateFrom`) of the active guest catalog price that gives it, else now; the regular price then counts as the price in force.
- The window is compared in UTC, like the history rows: an explicit `saleStart` in the site's time zone, or the default "now", moved it by the offset.

### Changed
- `AnchorService::getVariantLowestBefore()` takes a Variant as well as an id (the parameter is now `$variant`; named-argument callers using `variantId:` need updating). The id alone costs a variant query, for the selling price.

## 1.3.0 - 2026-10-17

### Added
- A reference day per product: the `anchorDateRules` setting (CP table or `config/omnibus.php`) gives product types, or products related to a category or entry (subcategories included), another day than `anchorDate`, for the 2.5.2025 of food, drink, cosmetics, cleaning, toiletries and household goods (NN 101/2026-1212 t. IV; draft Pravilnik o isticanju bazne cijene, čl. 3(1)). `AnchorService::EVENT_DEFINE_ANCHOR_DATE` decides it in code. `AnchorService::anchorDateFor()`, `anchorDayFor()` and `anchorDates()`.
- `craft omnibus/anchor/import <file> --as-of=Y-m-d [--dry-run]` writes the prices of a past reference day from a `sku;price` CSV; a second run with the same day updates its rows. Variants without history after that day are skipped (run `anchor/backfill` first), so an old price never stands in for the prices since.
- The `anchorOverrideField` setting: a number or money field whose value, when filled, is the anchor (`source` `override`), for the draft Pravilnik's čl. 3(3) case the history cannot compute.
- Price list columns `neto_kolicina` (from `netQuantityField` or a row handler; draft Pravilnik o načinu objave cjenika, čl. 3(2)) and `naziv_posebnog_oblika_prodaje` (NN 101/2026-1213 t. III): the name of the catalog pricing rule behind the sale price, else the `saleName` setting (default "Sniženje"). Rules with a customer condition are not named.
- Each XML `proizvod` has a `sidreni_datum` attribute, its own reference day; rows carry it as `PriceListService::ROW_ANCHOR_DATE`.
- `craft.productPriceHistory.anchorDate(variant)`.

### Changed
- The CSV and XML gain two columns: `neto_kolicina` after `marka`, `naziv_posebnog_oblika_prodaje` after `posebni_oblik_prodaje`. Readers that go by position rather than header need updating.
- `AnchorService::getVariantAnchor()` takes a Variant as well as an id (the parameter is now `$variant`; named-argument callers using `variantId:` need updating); with rules, a date handler or an override field, the id alone costs a variant query.
- `omnibus/anchor/show` no longer lists the `--as-of` and `--store` options it ignored.
- The anchor's `date` is the variant's own reference day. The XML root `sidreni_datum` stays the default day, and the public index names every configured day.

## 1.2.1 - 2026-10-17

### Fixed
- `price-list/publish` writes `index.html` and the web shop's name in the primary site's language. From cron it used the console's language (usually English).

## 1.2.0 - 2026-10-17

### Added
- One price-list file per outlet ("prodajni objekt", NN 101/2026): sites with physical stores register them on `PriceListService::EVENT_REGISTER_OUTLETS`, each a `PriceListOutlet` with its own form, address and code in the file name (čl. VI). The web shop from the plugin settings stays the first outlet.
- An outlet with `inventoryLocationId` publishes `nedostupno` for variants the web sells only from stock when that location has none; otherwise it keeps the web shop's `dostupnost` (backorderable variants stay available).
- `PriceListService::EVENT_DEFINE_ROW` changes or drops one row of one outlet (a store price, a product the store does not carry).
- `craft omnibus/price-list/outlets`; `publish` and `preview` take `--outlet=<code>`. An outlet that is invalid or repeats another's file prefix is skipped, listed in `PriceListService::$skippedOutlets`, and makes `publish` exit with an error so cron monitoring sees it.
- The public index groups the files by day, then outlet.

### Changed
- `PriceListService::publish()` returns `['files' => [...per outlet], 'index', 'pruned', 'skipped']`, `rows()` takes a `PriceListOutlet`, and `xml()` takes the outlet as a third argument.
- `PriceListService::fileBase()` is deprecated (it names the web shop's file); use `PriceListFiles::base()`.
- `broj pohrane` counts runs per outlet and day (it counted every file of the day).
- File-name parts are transliterated to ASCII (`Šubićeva` becomes `subiceva`). A store whose old names kept diacritics starts a new prefix: `broj pohrane` restarts at 1 and the index lists the old files as a separate outlet until retention removes them.
- The `--store` option of `price-list/publish` is deprecated and ignored; it never changed the prices.

## 1.1.6 - 2026-10-13

### Changed
- No functional changes. The source now passes PHPStan (level 4) and ECS, and `phpstan/phpstan` is a dev dependency so `composer install` provides `vendor/bin/phpstan`.

## 1.1.5 - 2026-10-13

### Fixed
- Price history is recorded under the variant's primary product. When a product draft re-saved its live variants (for example after a variant was added in the draft), the rows were recorded under the draft's id and deleted along with the draft when it was applied. Rows lost that way before this release cannot be recovered by the plugin.

## 1.1.4 - 2026-10-11

### Fixed
- Saving a product whose product type has versioning enabled no longer fails with `TypeError: addProductHistoryPrice(): Argument #2 ($variantId) must be of type int, null given` when a variant changed since the last revision. Variant revisions are no longer passed to the price history.
- A variant without a store price row no longer records a 0.00 price as its first history entry.

## 1.1.3 - 2026-09-20

### Changed
- Plugin handle is `omnibus` (was `craft-product-price-history`). Console commands are now `craft omnibus/anchor/...` and `craft omnibus/price-list/...`, the translation category is `omnibus`. The `product_price_history` table and the `craft.productPriceHistory` variable are unchanged. Sites on the old handle uninstall it and install `omnibus`, then run `anchor/backfill --as-of=2026-09-10`.

## 1.1.2 - 2026-09-20

### Changed
- Plugin renamed to "Omnibus, Price History and Anchor Price".
- Anchor label setting is optional; when empty the translated default is used ("Anchor price", Croatian "Sidrena cijena").
- Control-panel headings and the generated price-list index no longer carry Croatian source strings; Croatian comes from translations.

## 1.1.1 - 2026-09-20

### Changed
- Plugin renamed to "Omnibus and Anchor Price"; every control-panel label and the public price-list page are now English source strings with Croatian translations.
- Price-list index date printed as d.m.Y.

## 1.1.0 - 2026-09-20

### Added
- Anchor price ("sidrena / dodatna cijena", NN 101/2026): the price applicable on the reference day (default 10 September 2026), or the first recorded price for products listed later. `craft.productPriceHistory.anchor(variant|product|id)`.
- Lowest price in the N days before a reduction started, the reduced price itself excluded (Zakon o zaštiti potrošača čl. 19, EU Omnibus). `craft.productPriceHistory.lowestBefore(variant)`.
- Daily machine-readable price list (Odluka o objavi cjenika proizvoda i usluga, NN 101/2026): CSV and XML with the čl. III columns, file name per čl. VI, retention per čl. VII, public index page. `craft omnibus/price-list/publish`.
- Console commands `anchor/backfill` (with `--as-of`), `anchor/show`, `price-list/publish`, `price-list/preview`.
- Plugin settings: reference day, label, lowest-price window, store identity for the file name, publish directory and route, retention, CSV delimiter, field mapping for brand, barcode and unit of measure.
- Croatian translations for the control panel.

### Changed
- Package renamed to `wmd/craft-omnibus`; the plugin handle `omnibus` and the `product_price_history` table are unchanged, so existing installs update in place.

## 1.0.0

- Initial release: price history per variant, lowest price in the last N days.
