# Omnibus, Price History and Anchor Price for Craft Commerce

Price history per variant, and the two reference prices EU and Croatian law
require next to a selling price:

- **Lowest price in the 30 days before a reduction** (Directive (EU)
  2019/2161 "Omnibus"; in Croatia Zakon o zaštiti potrošača, čl. 19). Shown
  while an item sells below its regular price. The reduced price itself never
  counts as its own reference.
- **Sidrena cijena** ("dodatna cijena", Odluka o isticanju dodatne cijene, NN
  101/2026; postponed by NN 110/2026 to 17 November 2026, the day the new ZZP
  čl. 7 "bazna cijena" takes effect): the price applicable on the reference
  day, 10 September 2026, shown clearly next to every selling price. Food,
  drink, cosmetics, cleaning, toiletries and household goods keep 2 May 2025
  (t. IV), configurable per product type or category. Products listed later
  carry their first price with its date.

Plus the **daily price list** the same decision package requires from every
trader with a website (Odluka o objavi cjenika proizvoda i usluga, NN
101/2026): CSV and XML, prescribed columns, prescribed file name, published on
the site, kept 30 days.

## Requirements

Craft CMS 5.0+, Craft Commerce 5.0+, PHP 8.2+.

## Installation

```sh
composer require wmd/craft-omnibus
php craft plugin/install omnibus
```

The plugin records a history row whenever a variant's base price changes, and
the current price when a product is first saved.

### Installing on a store that already has prices

The anchor is read from history. A store that installs the plugin after the
reference day has no rows for that day; if its prices have not changed since,
assert that once:

```sh
php craft omnibus/anchor/backfill --as-of=2026-09-10
```

`anchor/backfill` without `--as-of` records today's price for variants that
have no history at all.

### Prices of an earlier reference day (2.5.2025)

No install has history from 2 May 2025. Import the prices in force that day
from a spreadsheet or ERP export, one `sku;price` per line, after the history
since has been recorded (the backfill above):

```sh
php craft omnibus/anchor/backfill --as-of=2026-09-10   # if not done yet
php craft omnibus/anchor/import prices-2025-05-02.csv --as-of=2025-05-02 --dry-run
php craft omnibus/anchor/import prices-2025-05-02.csv --as-of=2025-05-02
```

Each row becomes a history row dated noon of that day (site time zone). The
delimiter (`;`, `,`, tab) is detected, a header row is skipped, prices may be
written `1234.56`, `1234,56` or `1.234,56`; `1.234` is refused as ambiguous.
Running it again with the same day updates those rows. Unknown SKUs, bad
lines and variants with no history after that day (whose imported price
would stand for every day since) are listed, skipped, and make the command
exit non-zero.

A product that was on sale on that day takes, under the draft Pravilnik o
isticanju bazne cijene (čl. 3(3)), the lowest price of the 30 days before the
sale started, which a price history from 2026 cannot compute. Set
**Anchor price override field** to a number or money field on the variant or
product and type that price there; a filled override is the anchor.

## Settings

**Settings → Plugins → Omnibus, Price History and Anchor Price**

| Setting | Default | Notes |
|---|---|---|
| Reference day | 2026-09-10 | the day the anchor price is read for, unless a row below matches |
| Other reference days | none | `date`, product type handles, category or entry IDs (subcategories included); first match wins |
| Anchor price override field | empty | number/money field handle; a filled value is the anchor |
| Label | empty | printed next to the selling price; empty uses the translated default ("Anchor price", hr "Sidrena cijena") |
| Products listed after the reference day use their first price | on | the anchor then carries the listing date |
| Lowest-price window | 30 days | |
| Publish the daily price list | on | |
| Oblik / adresa / oznaka prodajnog objekta | | parts of the file name (čl. VI) |
| Publish directory | `@webroot/cjenik` | files must be public |
| Index route | `cjenik` | public page listing the files |
| Retention | 30 days | čl. VII |
| CSV delimiter | `;` | |
| Name of a reduction without a pricing rule | empty ("Sniženje") | `naziv_posebnog_oblika_prodaje` for a promotional price typed on the variant |
| Field mapping | brand: `productsBrandCategories` | handles for marka, neto količina, barkod, jedinica mjere, cijena za jedinicu mjere |

### Reference day per product

`config/omnibus.php` can hold the rules as arrays:

```php
return [
    'anchorDateRules' => [
        ['date' => '2025-05-02', 'productTypes' => ['food', 'cosmetics'], 'relatedTo' => [1201, 1202]],
    ],
];
```

Anything else goes through an event; `date` arrives as the rules' answer:

```php
use wmd\craftproductpricehistory\events\DefineAnchorDateEvent;
use wmd\craftproductpricehistory\services\AnchorService;

Event::on(AnchorService::class, AnchorService::EVENT_DEFINE_ANCHOR_DATE, function(DefineAnchorDateEvent $event) {
    // e.g. the goods group an ERP import stored on the product
    if ($event->variant->getOwner()?->getFieldValue('goodsGroup')?->value === 'food') {
        $event->date = '2025-05-02';
    }
});
```

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
returns `{ price, date, source }` where `date` is the variant's reference day
(or its first listing) and `source` is `anchorDate`, `firstListing` or
`override`. `anchorDate(variant)` gives a variant's reference day,
`anchorDate()` the default one. `lowestBefore(variant, saleStart = null, days = null)` closes
the window at `saleStart`, by default the moment the current price was
recorded.

The original API stays: `getVariantLowestPrice(id, days, fallback)` and
`getProductLowestPrice(id, days, fallback)`.

## Price list

```sh
php craft omnibus/price-list/publish   # write today's CSV + XML per outlet, prune, rebuild the index
php craft omnibus/price-list/preview   # print the first rows, write nothing
php craft omnibus/price-list/outlets   # list the outlets and their file names
```

Run `publish` from cron every day before 08:00 local time:

```
0 7 * * * cd /path/to/site && php craft omnibus/price-list/publish >/dev/null 2>&1
```

Columns, in the order of čl. III: `naziv`, `sifra`, `marka`, `neto_kolicina`,
`jedinica_mjere`, `cijena_za_jedinicu_mjere`, `maloprodajna_cijena`,
`posebni_oblik_prodaje` (DA/NE), `naziv_posebnog_oblika_prodaje`,
`sidrena_cijena`, `barkod`, `dostupnost`. `neto_kolicina` comes from the draft
Pravilnik o načinu objave cjenika (e-Savjetovanje, until 13 November 2026),
filled from `netQuantityField` or by a row handler such as wmd/craft-units.
`naziv_posebnog_oblika_prodaje` is the name of the Commerce catalog pricing
rule that gives the sale price (customer-group rules do not count), else
the setting above: name the rules as customers see them. Each XML `proizvod`
carries `sidreni_datum`, the day its `sidrena_cijena` refers to; the root
attribute keeps the default day. Readers should go by the header, not
the column position. `sidrena_cijena` keeps its name until the Pravilnik on
bazna cijena is final. File name per čl. VI:
`{oblik}_{adresa}_{oznaka}_{broj-pohrane}_{YYYYMMDD_HHMMSS}.csv|xml`, the
outlet parts ASCII and kebab-cased; `broj pohrane` counts the outlet's runs
that day. The
decision does not prescribe column names or an XSD; adjust if the Ministry
publishes a technical specification.

### One file per store

The settings describe one outlet, the web shop. A retailer with physical
stores publishes a file per store ("prodajni objekt") every day; the site adds
them from a module or plugin:

```php
use wmd\craftproductpricehistory\events\RegisterPriceListOutletsEvent;
use wmd\craftproductpricehistory\models\PriceListOutlet;
use wmd\craftproductpricehistory\services\PriceListService;
use yii\base\Event;

Event::on(PriceListService::class, PriceListService::EVENT_REGISTER_OUTLETS, function(RegisterPriceListOutletsEvent $event) {
    $event->outlets[] = new PriceListOutlet([
        'code' => 'zagreb-ilica',           // oznaka, unique
        'type' => 'prodavaonica',           // oblik
        'address' => 'Ilica 42, Zagreb',    // adresa
        'name' => 'Trgovina Zagreb, Ilica', // shown on the index
        'inventoryLocationId' => 14,        // stock that decides "dostupnost"
    ]);
});
```

Each outlet's file has the web prices. With `inventoryLocationId`, a variant
the web sells only from stock is `nedostupno` when that location has none;
otherwise, and without a location, availability is the web shop's. An outlet
that is invalid or repeats another's file name is skipped, and `publish`
exits with an error. A store with its own prices, or
one that does not carry a product, changes the row:

```php
use wmd\craftproductpricehistory\events\DefinePriceListRowEvent;

Event::on(PriceListService::class, PriceListService::EVENT_DEFINE_ROW, function(DefinePriceListRowEvent $event) {
    if ($event->outlet->code === 'zagreb-ilica' && $event->row['sifra'] === 'ABC-1') {
        $event->row['maloprodajna_cijena'] = '19.90';
        // $event->row = null; leaves the product out of this store's file
    }
});
```

The index (`priceListRoute` and `index.html` next to the files) lists the
files by day, newest first, then by outlet. Files of a store that is no longer
registered stay listed under their code until retention removes them.

## Console

| Command | Does |
|---|---|
| `anchor/backfill [--as-of=Y-m-d] [--store=id]` | record the current price for variants with no history |
| `anchor/import <file> --as-of=Y-m-d [--dry-run] [--delimiter=;]` | import `sku;price` of a past reference day |
| `anchor/show <variantId>` | print the anchor and the lowest price before now |
| `price-list/publish [--outlet=code]` | write every outlet (or one), prune, index |
| `price-list/preview [--outlet=code]` | print rows of the web shop (or one outlet) |
| `price-list/outlets` | list outlets, their stock location and file prefix |

## Legal references

- Directive (EU) 2019/2161, art. 6a of Directive 98/6/EC (price reductions).
- Zakon o zaštiti potrošača, čl. 19 (NN 19/2022, 59/2026).
- Odluka o isticanju dodatne cijene kao mjera izravne kontrole cijena, NN 101/2026.
- Odluka o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena, NN 101/2026.
- Odluke o izmjeni, NN 110/2026 (both decisions apply from 17 November 2026).
- Zakon o zaštiti potrošača, čl. 7 (bazna cijena, NN 59/2026, from 17 November 2026).
- Drafts in e-Savjetovanje (until 13 November 2026): Pravilnik o isticanju
  bazne cijene proizvoda, Pravilnik o načinu objave cjenika proizvoda.
- Zakon o iznimnim mjerama kontrole cijena, NN 40/2025 (penalties).

This plugin implements the rules as published; it is not legal advice.

## License

MIT. Developed by [WMD](https://wmd.hr).
