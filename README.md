# Provider Shipping

A provider registry for a WooCommerce store that ships one cart from several suppliers.

Every product declares **who ships it**. Each provider is priced by the shipping method already
linked to it — a supplier's own plugin, the zone's Flat Rate for goods you ship yourself, a fixed
amount, or a supplier quote endpoint. The customer is shown **one rate** for the whole cart, built
from the suppliers' own prices, with a margin policy you control.

It is deliberately inert on install: nothing changes at checkout until you turn the pipeline on.

## Why this exists

A store that prints on demand with more than one supplier has a shipping problem WooCommerce does
not solve on its own:

* the cart splits into parcels, one per supplier, each with its own cost;
* the customer should not have to understand that, and should not see your suppliers' names;
* a single flat rate is a guess, and a guess that is too low is a loss on every order;
* if nothing can ship the cart, the store should not sell it.

This plugin makes the supplier list data (a row per provider), and makes the checkout rate the sum
of what those suppliers actually charge, priced by the customer's own destination, rounded upward,
never below cost.

## What it owns

1. **The provider registry** — who ships, which method prices them, in which zones, with what
   margin (handling, uplift, floor, cap, rounding).
2. **The provider declaration on products** — `_psr_provider` on a product or variation, set in the
   product editor (Providers tab), Quick edit, Bulk edit, the Products list column, CSV import, and
   a detect screen that proposes providers from the supplier plugins' own tags, shipping classes and
   SKU patterns. Writes are opt-in per row, and every write takes a snapshot you can restore.
3. **What a customer is allowed to see** — merged rate, supplier sub-totals for staff only, names
   hidden by default, per-provider visibility, and a "ships in separate parcels" note you can word.
4. **The rule that no quote means no sale** — a server-side guard, including the WooPayments
   express-checkout paths (Apple Pay / Google Pay) that bypass the normal checkout.

It does **not** own the rate maths. Providers are quoted through their own shipping methods, so a
new supplier is a row, not a code change.

## Requirements

* WordPress 6.0+, PHP 7.4+
* WooCommerce 6.0+ (developed and tested against 11.x)
* For each provider you enable: a shipping method instance that prices its goods (for example a
  supplier's own WooCommerce plugin, `flat_rate`, or a method from the companion
  [Tapstitch Shipping Method](https://github.com/street-tripy/tapstitch-shipping-method)
  plugin). Providers with no working rate source are reported as unusable, never priced as free.

## Install

```
wp-content/plugins/provider-shipping/
```
then activate. Settings → Shipping → **Providers** is the registry. The pipeline starts **Off**, so
installation changes nothing.

## Pipeline modes

| Mode | What happens |
|---|---|
| `off` (default) | Inert. WooCommerce prices the cart as before. |
| `shadow` | Every cart is quoted through the registry **and logged** next to what the zone charged. Nothing at checkout changes. This is how you prove the merged numbers before trusting them. |
| `live` | This plugin's own method (`psr_shipping`) produces the checkout rate for zones that have an instance of it. |

Live mode has two requirements, both measurable in `GET /provider-shipping/v1/status`:

1. **A `psr_shipping` instance in the zone.** Add it in *Settings → Shipping → the zone → Add
   shipping method → Provider Shipping*. With no instance, Live mode has nothing to charge with.
2. **Any other plugin that merges checkout rates must yield.** Two engines filtering
   `woocommerce_package_rates` cancel each other out and the customer loses the merged rate.

## Money rules

Applied per provider, then merged for the cart:

```
min      = max( driver cost, floor )
price    = rounding( min + handling + uplift )   with rounding never below min
price    = min( price, cap )                     a cap below the cost cannot take effect
```

Rounding defaults to `nearest99` and **never rounds down below the supplier's cost**. Handling on a
provider row is a top-up on top of the driver's own price, not a re-charge — most supplier prices
already include the store's handling, so the shipped default is `0`.

`psr_live_fallback` (default **block**): when the registry cannot price a cart, Live mode offers
nothing, and the guard refuses the sale. The alternative (`flat`) leaves the zone's own Flat Rate
visible — which is how a store ends up selling shipping below what its supplier charges.

## Currency

All provider maths is done in the **store's base currency**. The shop's own currency plugin converts
the finished rate once, on display.

The registry quotes providers through their shipping methods, and multi-currency plugins convert a
rate's cost inside `WC_Shipping_Method::add_rate()`. Reading those costs without accounting for that
sums one provider in the customer's currency with another in base, and converting the merged total
again doubles the charge. So all quoting runs inside a counting window that temporarily asks
`wcpay_multi_currency_should_convert_shipping_amount` to leave costs in base currency
(`PSR_Driver::quote_begin()` / `quote_end()`, restored on the exception path).

Sub-totals are then converted by the factor the rate itself reveals (`PSR_Quote::display_factor()`)
and the rounding cents are shared by largest remainder (`PSR_Quote::split_across()`), so **the
sub-lines add up to the price the customer is charged, to the cent, in any currency at any
precision**. Staff additionally see the base amount in brackets, for invoice reconciliation. Nothing
is multiplied twice, and no currency plugin is named in the code.

## Diagnostics

All routes are under `provider-shipping/v1` and require `manage_woocommerce`.

| Route | Answers |
|---|---|
| `GET /status` | version, switches, provider rows with their zone instances, products with no provider declaration, guard owner and wiring, detect snapshot, editor state |
| `GET /selftest` | every assertion, PASS/FAIL with expected-and-got |
| `GET /quote?items=12:1,15:1&country=US&state=NY&postcode=10001` | the registry's price for a cart, per provider, with the driver's source |
| `GET /rates?zone=1&items=…` | what the checkout offers, **which plugin produced each rate**, and what was suppressed |
| `GET /breakdown?items=…` | the sub-total lines exactly as the checkout renders them, plus the currency block (`base_total`, `shown_total`, `factor`, `lines_sum`, `adds_up`). `?simulate_factor=1.82` prices the rate as a converting shop would — WooCommerce forces the store currency inside REST, so this is how the staff view of a converted cart is measured |
| `GET /currency?code=NZD&amount=15.99` | the shop's converter as it stores it: enabled currencies, exchange-rate type, rounding, charm, cached rates, and a probe of every lookup path |
| `GET /detect?country=US` | the supplier plugins' own SKU claims, as a review table |
| `GET /editor` | which editor wiring tags are live in this request (a diagnostic that cannot fatal a screen) |
| `POST /providers` · `/apply` · `/restore` · `/settings` | registry row write · write provider meta for ticked rows (snapshot first) · put the snapshot back · site switches, whitelisted values |

## Self-check

`GET /provider-shipping/v1/selftest` runs ~90 assertions: registry normalisation, the margin policy
(handling / uplift / floor / cap, including a cap below cost), rounding never under cost, the alias
table, class-map parsing, merged labels with and without public names, the guard's decision table,
the Live fallback policy, the currency split and factor, and the quoting window.

The cart checks run on **your** products, configured in Settings → Shipping → Providers → *Self-check
sample data* (JSON):

```json
{
  "products":  { "12": "tapstitch", "15": "printify", "17": "in-house" },
  "variation": 13,
  "inherits":  "tapstitch",
  "method":    "tapstitch_shipping",
  "zone":      1,
  "mixed":     [ "12:1", "15:1", "17:1" ],
  "groups":    "in-house,printify,tapstitch",
  "apparel":   [ "12:2" ],
  "in_house":  [ "17:1" ]
}
```

Nothing configured → those checks report as **skipped**, so a fresh install gets a clean report
instead of failures against products it does not have.

## Extension points and settings

```php
// Read a provider declaration from an extra product meta key (no data migration needed).
add_filter( 'psr_provider_meta_keys', function ( $keys ) {
    $keys[] = 'your_legacy_meta_key';
    return $keys;
} );
```

**Filter:** `psr_provider_meta_keys` — extra product meta keys read as a provider
declaration. That is the only filter this plugin defines.

**Site options** (all settable in *Settings → Shipping → Providers*, and by
`POST /provider-shipping/v1/settings`):

| Option | Values |
|---|---|
| `psr_providers` | the registry: slug → row (driver, method, instance, zones, class map, handling, uplift, floor, cap, rounding, enabled, visible) |
| `psr_pipeline_mode` | `off` (default) · `shadow` · `live` |
| `psr_live_fallback` | `block` (default) · `flat` |
| `psr_display_mode` | `merged_hidden` · `merged_admin` · `merged_public` · `per_provider` |
| `psr_show_provider` | `hidden` · `admin` · `public` — the line on the product page |
| `psr_public_names` | `yes` / `no` — may supplier names reach a customer at all |
| `psr_provider_note` | the "separate parcels" sentence |
| `psr_default_provider` | provider for goods with no declaration |
| `psr_block_no_quote` · `psr_no_quote_message` | the guard and its wording |
| `psr_selftest` | the sample products the cart checks run on (JSON) |

**WooCommerce hooks this plugin uses** (no template overrides, no JavaScript):
`woocommerce_cart_shipping_packages`, `woocommerce_load_shipping_methods`, `woocommerce_shipping_methods`,
`woocommerce_package_rates`, `woocommerce_after_shipping_rate` (the sub-total renderer),
`woocommerce_checkout_process` / `woocommerce_checkout_order_review` (the guard), the WooPayments
express-checkout display filters, `wcpay_multi_currency_should_convert_shipping_amount` (the quoting
window), the product-editor tags `woocommerce_product_data_tabs` /
`woocommerce_product_data_panels` / `woocommerce_process_product_meta` /
`woocommerce_variation_set_data`, the list columns `manage_edit-product_columns` /
`manage_product_posts_custom_column`, Quick edit, Bulk edit, the CSV importer/exporter column
filters, and `wp_ajax_psr_test_quote`.

## Development

```
php -l provider-shipping.php          # syntax
```
`GET /provider-shipping/v1/selftest` after activation is the test suite. Zips built with
`zip -r provider-shipping.zip provider-shipping -x '*.zip'` — do not ship `*.log` or local data.

## Data not included

No store data: no product list, no supplier rate tables, no credentials, no shop names. Provider
rows you create are your own configuration in the database.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
