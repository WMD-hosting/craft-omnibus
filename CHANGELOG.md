# Release Notes for Omnibus and Anchor Price

## 1.1.1 - 2026-09-20

### Changed
- Plugin renamed to "Omnibus and Anchor Price"; every control-panel label and the public price-list page are now English source strings with Croatian translations.
- Price-list index date printed as d.m.Y.

## 1.1.0 - 2026-09-20

### Added
- Anchor price ("sidrena / dodatna cijena", NN 101/2026): the price applicable on the reference day (default 10 September 2026), or the first recorded price for products listed later. `craft.productPriceHistory.anchor(variant|product|id)`.
- Lowest price in the N days before a reduction started, the reduced price itself excluded (Zakon o zaštiti potrošača čl. 19, EU Omnibus). `craft.productPriceHistory.lowestBefore(variant)`.
- Daily machine-readable price list (Odluka o objavi cjenika proizvoda i usluga, NN 101/2026): CSV and XML with the čl. III columns, file name per čl. VI, retention per čl. VII, public index page. `craft craft-product-price-history/price-list/publish`.
- Console commands `anchor/backfill` (with `--as-of`), `anchor/show`, `price-list/publish`, `price-list/preview`.
- Plugin settings: reference day, label, lowest-price window, store identity for the file name, publish directory and route, retention, CSV delimiter, field mapping for brand, barcode and unit of measure.
- Croatian translations for the control panel.

### Changed
- Package renamed to `wmd/craft-omnibus`; the plugin handle `craft-product-price-history` and the `product_price_history` table are unchanged, so existing installs update in place.

## 1.0.0

- Initial release: price history per variant, lowest price in the last N days.
