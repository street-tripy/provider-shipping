=== Provider Shipping ===
Contributors: street-tripy
Tags: woocommerce, shipping, provider registry, print on demand, rates, checkout
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 11.3
Stable tag: 0.1.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One cart, several suppliers: every product declares who ships it, each provider is priced by its own shipping method, and the customer sees one merged rate built from the suppliers' real prices.

== Description ==

A WooCommerce store that prints on demand with more than one supplier has a shipping problem the
core does not solve: the cart splits into parcels, one per supplier, each with its own cost; a
single flat rate is a guess; and a guess that is too low is a loss on every order.

This plugin makes the supplier list into data — one row per provider — and makes the checkout rate
the sum of what those suppliers actually charge, priced by the customer's own destination, rounded
upward and never below cost.

= What it owns =

1. *The provider registry* — who ships, which shipping method prices them, in which zones, with
   what margin (handling, uplift, floor, cap, rounding).
2. *The provider declaration on products* — `_psr_provider`, set in the product editor's Providers
   tab, Quick edit, Bulk edit, the Products list column, CSV, and a detect screen that proposes
   providers from the supplier plugins' own tags, shipping classes and SKU patterns. Writes are
   opt-in per row, and each write takes a snapshot you can restore.
3. *What a customer is allowed to see* — one merged rate, supplier sub-totals for staff only,
   supplier names hidden by default, per-provider visibility, and a "ships in separate parcels"
   note you word yourself.
4. *The rule that no quote means no sale* — a server-side guard, covering the WooPayments express
   checkout paths (Apple Pay / Google Pay) that bypass the normal checkout.

It does not own the rate maths: providers are quoted through their own shipping methods, so adding
a supplier is a row, not a code change.

= Inert by design =

The pipeline defaults to Off. Installing this plugin changes nothing at checkout. Shadow mode logs
the registry's answer beside what the zone charged, on the same carts, which is how the merged
numbers get compared before anything is trusted.

= Money rules =

`min = max( driver cost, floor )`, then `handling + uplift`, then rounding that is never below the
cost, then the cap last (a cap below the cost cannot take effect). Default rounding is `nearest99`.
Handling on a provider row is a top-up on top of the driver's price, not a re-charge: shipped
defaults are 0.

= Currency =

All provider maths is in the store's base currency; the shop converts the finished rate once on
display. Sub-totals are converted by the factor the rate itself reveals and the rounding cents are
shared by largest remainder, so the lines a customer sees add up to the price charged, to the cent,
in any currency. Staff also see the base amount in brackets for invoice reconciliation.

= Diagnostics =

`provider-shipping/v1` (all routes need `manage_woocommerce`): `status`, `selftest`, `quote`,
`rates`, `breakdown`, `currency`, `detect`, `editor`, `providers`, `apply`, `restore`, `settings`. The
self-check runs ~90 assertions; the cart checks run on products you name in the settings field
`psr_selftest` and report as skipped when nothing is configured.

= Extension points =

`psr_provider_meta_keys` is the filter this plugin defines: read a provider declaration from an
extra product meta key, so a store migrating from another plugin needs no data migration. Behaviour
is set by options — `psr_providers`, `psr_pipeline_mode`, `psr_live_fallback`, `psr_display_mode`,
`psr_show_provider`, `psr_public_names`, `psr_provider_note`, `psr_default_provider`,
`psr_block_no_quote`, `psr_no_quote_message`, `psr_selftest` — all on the settings screen, all
settable over REST.

== Installation ==

1. Upload `provider-shipping` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to *Settings → Shipping → Providers* and add one row per supplier: the shipping method that
   prices it, the zones it serves, and the margin policy.
4. Declare providers on products (product editor → Providers, Quick/Bulk edit, CSV, or
   *Tools → Provider detect*).
5. Add a *Provider Shipping* method instance to each zone that should use the merged rate.
6. Run Shadow mode for a while and compare `provider-shipping/v1/status` and the log against the
   prices customers are actually being charged, then switch to Live.

== Frequently Asked Questions ==

= Will customers see my suppliers? =

No. Supplier names are hidden by default and the switch to reveal them is global. The rate source
text (`#7 = Shipping`, `printify api`) is staff-only wording.

= What if nothing can ship the cart? =

Nothing is offered and the sale is refused server-side, with a neutral message you can word. The
express-checkout buttons are disabled in the same case.

= Can it charge below what my supplier charges? =

No. The clamp order makes that impossible: the floor is applied before rounding, rounding never
goes below cost, and a cap below cost cannot take effect.

= Does it work with a multi-currency plugin? =

Yes. The registry prices in base currency, the shop converts once, and the sub-lines are reconciled
to the displayed price to the cent.

= I already have a provider meta key from another plugin. =

Add it: `add_filter( 'psr_provider_meta_keys', fn( $k ) => array_merge( $k, ['your_key'] ) );` No
data migration is needed.

== Changelog ==

= 0.1.7 =
* Quoting now runs inside a counting window that asks the shop's currency plugin to leave provider
  rate costs in base currency (`wcpay_multi_currency_should_convert_shipping_amount`). Method-driver
  costs arrive converted, API drivers do not, and summing the two then converting the merged total
  doubled the shipping charge. Restored on the exception path.
* `GET /provider-shipping/v1/currency` — the store's converter settings read from where they are
  stored (enabled currencies, exchange-rate type, manual rate, price rounding, price charm, cached
  rates) plus a probe of every lookup path.
* Self-check assertions for the quoting window (base inside, display outside, nesting, restore).

= 0.1.6 =
* Sub-total lines add up in every currency: the factor is measured from the rate itself
  (`display_factor()`) and the rounding cents are shared by largest remainder
  (`split_across()`), so lines equal the price charged to the cent in any currency at any
  precision. Staff see the base amount in brackets. This also closes the base-currency cent gap
  between a rounded merged total and its individually rounded lines.
* Anonymous sub-lines are numbered (`Provider 1`, `Provider 2`).
* Breakdown amounts are stored without a thousands comma (`(float) '1,234.56'` is 1.0).
* The status screen reports the guard that *runs* (`wired`) separately from this plugin's own
  idle hooks (`own_wiring`).
* `GET /provider-shipping/v1/breakdown?simulate_factor=…` to measure the staff view of a converted
  cart, which REST cannot otherwise show.

= 0.1.5 =
* `psr_live_fallback` — the money rule for Live mode. `block` (default) offers nothing when the
  registry cannot price a cart, so the guard refuses the sale; `flat` leaves the zone's own rates
  visible.

= 0.1.4 =
* `POST /provider-shipping/v1/settings`, `GET /rates` (which plugin produced each rate, plus
  `suppressed`) and `GET /breakdown` (sub-total lines as rendered, with the hook state).
* Sub-total renderer privacy fix: the rate source text is staff-only, customers see the parcel's
  item count.
* Classic product editor: the Providers pane uses `woocommerce_product_data_tabs` /
  `woocommerce_product_data_panels`.

= 0.1.3 =
* Editor wiring probe, with a diagnostic route that cannot take a store down: filter tags are
  fired only when every registered callback is callable.

= 0.1.0 =
* First release: provider registry (rows, drivers, margin policy), product provider meta and its
  screens, provider detection with snapshot/restore, display modes (merged / staff sub-totals /
  public note / per-provider), the Off / Shadow / Live pipeline with a `psr_shipping` method, the
  purchase guard shared with any existing guard, per-provider shipping packages, and a self-check.

== Upgrade Notice ==

= 0.1.7 =
Currency-safe quoting: shipping is converted once, not twice. No data migration.

= 0.1.6 =
Sub-totals add up in every currency. No data migration.

= 0.1.0 =
Inert on install; turn the pipeline on when you are ready.
