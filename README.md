# Omnibus & Sidrena cijena for Craft Commerce

Price history per variant, and the two reference prices EU and Croatian law
require next to a selling price:

- **Lowest price in the 30 days before a reduction** (Directive (EU)
  2019/2161 "Omnibus"; in Croatia Zakon o zaštiti potrošača, čl. 19). Shown
  while an item sells below its regular price. The reduced price itself never
  counts as its own reference.
- **Sidrena cijena** ("dodatna cijena", Odluka o isticanju dodatne cijene, NN
  101/2026, in force 1 October 2026): the price applicable on the reference
  day, 10 September 2026, shown clearly next to every selling price. Products
  listed later carry their first price with its date.

Plus the **daily price list** the same decision package requires from every
trader with a website (Odluka o objavi cjenika proizvoda i usluga, NN
101/2026): CSV and XML, prescribed columns, prescribed file name, published on
the site, kept 30 days.

## Requirements

Craft CMS 5.0+, Craft Commerce 5.0+, PHP 8.2+.

## Installation

```sh
composer require wmd/craft-omnibus
php craft plugin/install craft-product-price-history
```

The plugin records a history row whenever a variant's base price changes, and
the current price when a product is first saved.

### Installing on a store that already has prices

The anchor is read from history. A store that installs the plugin after the
reference day has no rows for that day; if its prices have not changed since,
assert that once:

```sh
php craft craft-product-price-history/anchor/backfill --as-of=2026-09-10
```

`anchor/backfill` without `--as-of` records today's price for variants that
have no history at all.

## Settings

**Settings → Plugins → Omnibus & Sidrena cijena**

| Setting | Default | Notes |
|---|---|---|
| Reference day | 2026-09-10 | the day the anchor price is read for |
| Label | Sidrena cijena | printed next to the selling price |
| Products listed after the reference day use their first price | on | the anchor then carries the listing date |
| Lowest-price window | 30 days | |
| Publish the daily price list | on | |
| Oblik / adresa / oznaka prodajnog objekta | | parts of the file name (čl. VI) |
| Publish directory | `@webroot/cjenik` | files must be public |
| Index route | `cjenik` | public page listing the files |
| Retention | 30 days | čl. VII |
| CSV delimiter | `;` | |
| Field mapping | brand: `productsBrandCategories` | handles for marka, barkod, jedinica mjere, cijena za jedinicu mjere |

## Twig

```twig
{% set a = craft.productPriceHistory.anchor(variant) %}
{% if a %}
  {{ craft.productPriceHistory.anchorLabel() }} {{ a.price|commerceCurrency(currency) }} ({{ a.date|date('j. n. Y.') }})
{% endif %}

{% if variant.onPromotion or variant.salePrice < variant.basePrice %}
  {% set lowest = craft.productPriceHistory.lowestBefore(variant) %}
  {% if lowest is not null %}
    Najniža cijena u 30 dana prije sniženja: {{ lowest|commerceCurrency(currency) }}
  {% endif %}
{% endif %}
```

`anchor()` accepts a Variant, a Product (its default variant) or an id and
returns `{ price, date, source }` where `source` is `anchorDate` or
`firstListing`. `lowestBefore(variant, saleStart = null, days = null)` closes
the window at `saleStart`, by default the moment the current price was
recorded.

The original API stays: `getVariantLowestPrice(id, days, fallback)` and
`getProductLowestPrice(id, days, fallback)`.

## Price list

```sh
php craft craft-product-price-history/price-list/publish   # write today's CSV + XML, prune, rebuild the index
php craft craft-product-price-history/price-list/preview   # print the first rows, write nothing
```

Run `publish` from cron every day before 08:00 local time:

```
0 7 * * * cd /path/to/site && php craft craft-product-price-history/price-list/publish >/dev/null 2>&1
```

Columns, in the order of čl. III: `naziv`, `sifra`, `marka`, `jedinica_mjere`,
`cijena_za_jedinicu_mjere`, `maloprodajna_cijena`, `posebni_oblik_prodaje`
(DA/NE), `sidrena_cijena`, `barkod`, `dostupnost`. File name per čl. VI:
`{oblik}_{adresa}_{oznaka}_{broj-pohrane}_{YYYYMMDD_HHMMSS}.csv|xml`. The
decision does not prescribe column names or an XSD; adjust if the Ministry
publishes a technical specification.

## Console

| Command | Does |
|---|---|
| `anchor/backfill [--as-of=Y-m-d] [--store=id]` | record the current price for variants with no history |
| `anchor/show <variantId>` | print the anchor and the lowest price before now |
| `price-list/publish [--store=id]` | write, prune, index |
| `price-list/preview` | print rows |

## Legal references

- Directive (EU) 2019/2161, art. 6a of Directive 98/6/EC (price reductions).
- Zakon o zaštiti potrošača, čl. 19 (NN 19/2022, 59/2026).
- Odluka o isticanju dodatne cijene kao mjera izravne kontrole cijena, NN 101/2026.
- Odluka o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena, NN 101/2026.
- Zakon o iznimnim mjerama kontrole cijena, NN 40/2025 (penalties).

This plugin implements the rules as published; it is not legal advice.

## License

MIT. Developed by [WMD](https://wmd.hr).
