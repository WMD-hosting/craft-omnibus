# Release Notes for Omnibus, Price History and Anchor Price

## 1.2.0 - 2026-10-17

### Added
- One price-list file per outlet ("prodajni objekt", NN 101/2026): sites with physical stores register them on `PriceListService::EVENT_REGISTER_OUTLETS`, each a `PriceListOutlet` with its own form, address and code in the file name (čl. VI). The web shop from the plugin settings stays the first outlet.
- An outlet with `inventoryLocationId` publishes `dostupnost` from that location's stock for variants that track inventory.
- `PriceListService::EVENT_DEFINE_ROW` changes or drops one row of one outlet (a store price, a product the store does not carry).
- `craft omnibus/price-list/outlets`; `publish` and `preview` take `--outlet=<code>`.
- The public index groups the files by day, then outlet.

### Changed
- `PriceListService::publish()` returns `['files' => [...per outlet], 'index', 'pruned']`, `rows()` takes a `PriceListOutlet`, and `xml()` takes the outlet as a third argument.
- `broj pohrane` counts runs per outlet and day (it counted every file of the day).
- File-name parts are transliterated to ASCII (`Šubićeva` becomes `subiceva`).
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
