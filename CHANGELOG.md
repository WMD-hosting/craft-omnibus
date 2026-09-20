# Release Notes for Omnibus, Price History and Anchor Price

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
