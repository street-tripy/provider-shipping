<?php
/**
 * The pipeline: split by provider, quote each one, merge.
 *
 * WooCommerce builds a shipping package per cart; a supplier plugin builds one per supplier.
 * This class does the opposite of both: it keeps ONE package and inside it groups the items
 * by provider, then asks each provider's own driver what its items cost (the driver is the
 * shipping method linked in the registry - Printify's method for Printify goods, the Tapstitch
 * method for apparel, the zone's Flat Rate for in-house goods). Only then does it decide what
 * the customer sees, per the site's display mode.
 *
 * Nothing here fetches a price from a supplier the site has not linked, and nothing here can
 * produce a free shipment: a provider with no answer falls back to its fixed amount, and if it
 * has none, the cart has no quote - which PSR_Guard turns into a blocked sale.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Quote {

	/**
	 * Items in a package, grouped by provider.
	 *
	 * @param array $package Package.
	 * @return array slug => array( 'items' => package item keys, 'count' => units )
	 */
	public static function group( $package ) {
		$groups = array();

		foreach ( (array) ( isset( $package['contents'] ) ? $package['contents'] : array() ) as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( $product->is_virtual() || $product->is_downloadable() ) {
				continue;
			}
			if ( method_exists( $product, 'is_missing' ) && $product->is_missing() ) {
				continue;
			}

			$slug = PSR_Registry::pricing_provider_for( $product );
			$slug = $slug['slug'];
			$qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = array( 'slug' => $slug, 'items' => array(), 'units' => 0 );
			}
			$groups[ $slug ]['items'][ $key ] = $item;
			$groups[ $slug ]['units']        += $qty;
		}

		return $groups;
	}

	/**
	 * A copy of the package containing only one provider's items.
	 *
	 * @param array  $package Package.
	 * @param array  $items   Item keys => contents.
	 * @param string $slug    Provider slug.
	 * @return array
	 */
	public static function sub_package( $package, $items, $slug ) {
		$sub = $package;

		$units  = 0;
		$weight = 0.0;
		$cost   = 0.0;
		$classes = array();

		foreach ( (array) $items as $key => $item ) {
			$qty        = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$units     += $qty;
			$cost      += (float) ( isset( $item['line_subtotal'] ) ? $item['line_subtotal'] : 0 );
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$weight += (float) $item['data']->get_weight() * $qty;
				$class   = $item['data']->get_shipping_class();
				if ( '' !== $class ) {
					$classes[ $class ] = isset( $classes[ $class ] ) ? $classes[ $class ] : '' ;
				}
			}
			$sub['contents'][ $key ] = $item;
		}

		$sub['contents']       = $items;
		$sub['count']          = $units;
		$sub['weight']         = $weight;
		$sub['subtotal']       = $cost;
		$sub['package_id']     = ( isset( $package['package_id'] ) ? $package['package_id'] : '1' ) . '.psr_' . $slug;
		$sub['id']             = $sub['package_id'];
		$sub['psr_provider']   = $slug;

		return $sub;
	}

	/**
	 * Quote a whole package: every provider in it, priced by its own driver.
	 *
	 * @param array $package Package.
	 * @return array providers|total|label|mode|action|reason|note
	 */
	public static function quote_package( $package ) {
		$groups = self::group( $package );

		$out = array(
			'providers' => array(),
			'total'     => 0.0,
			'label'     => __( 'Shipping', 'provider-shipping' ),
			'mode'      => (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN ),
			'action'    => 'allow',
			'reason'    => '',
			'note'      => '',
		);

		if ( empty( $groups ) ) {
			$out['action'] = 'allow';
			$out['reason'] = 'no provider goods in this package';
			return $out;
		}

		$country = PSR_Driver::country( $package );
		$labels  = array();
		$sum     = 0.0;
		$missing = array();

		foreach ( $groups as $slug => $group ) {
			$row = PSR_Registry::get( $slug );
			if ( ! $row ) {
				$missing[] = $slug . ' (no registry row)';
				continue;
			}

			$sub   = self::sub_package( $package, $group['items'], $slug );
			$quote = PSR_Driver::quote( $row, $sub );

			$line = array(
				'slug'    => $slug,
				'label'   => $row['label'],
				'units'   => (int) $group['units'],
				'cost'    => $quote['available'] ? (float) $quote['cost'] : 0.0,
				'base'    => isset( $quote['base'] ) ? (float) $quote['base'] : 0.0,
				'source'  => $quote['source'],
				'note'    => $quote['note'],
				'ok'      => (bool) $quote['available'],
			);

			$out['providers'][] = $line;

			if ( ! $quote['available'] ) {
				$missing[] = $row['label'] . ' (' . $quote['source'] . ( $quote['note'] ? ' ' . $quote['note'] : '' ) . ')';
				continue;
			}

			$sum   += $line['cost'];
			$labels[] = $row['label'];
		}

		if ( $missing ) {
			$out['action'] = 'block';
			$out['reason'] = 'no quote for: ' . implode( '; ', $missing );
			$out['total']  = 0.0;
			$out['label']  = PSR_Registry::merged_label( $labels ? $labels : array( 'shipping' ), self::label_options() );
			return $out;
		}

		$round  = self::shared_round( $groups );
		$merged = PSR_Registry::round_price( $sum, $round, $sum );

		$out['total'] = round( $merged, 2 );
		$out['label'] = PSR_Registry::merged_label( $labels, self::label_options() );

		if ( PSR_Registry::DISPLAY_MERGED_PUBLIC === $out['mode'] ) {
			$note = (string) get_option( 'psr_provider_note', 'This order ships in separate parcels from our suppliers.' );
			$out['note'] = trim( $note );
		}

		return $out;
	}

	/**
	 * Options the label builder needs, straight from the site switches.
	 *
	 * @return array
	 */
	public static function label_options() {
		return array(
			'mode'         => (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN ),
			'public_names' => (string) get_option( 'psr_public_names', 'no' ),
		);
	}

	/**
	 * The rounding applied to the merged total: the strictest rounding of the providers in
	 * the cart, so a merged price never rounds below what the suppliers cost.
	 *
	 * @param array $groups Groups.
	 * @return string
	 */
	protected static function shared_round( $groups ) {
		$order = array( 'up99' => 3, 'nearest99' => 2, 'int99' => 1, 'none' => 0 );
		$best  = 'up99';
		$score = -1;
		foreach ( $groups as $slug => $group ) {
			$row = PSR_Registry::get( $slug );
			if ( ! $row ) {
				continue;
			}
			$mode  = $row['round'];
			$value = isset( $order[ $mode ] ) ? $order[ $mode ] : 2;
			if ( $value > $score ) {
				$score = $value;
				$best  = $mode;
			}
		}
		return $best;
	}

	/**
	 * Breakdown lines for the admin (and for the log): provider, maths, amount.
	 *
	 * Amounts are plain decimals (`.','`): PHP's number_format inserts a thousands comma by
	 * default, and `(float) '1,234.56'` is 1.0 - so a four-figure shipping cost would arrive at
	 * the renderer as one currency unit.
	 *
	 * @param array $quote quote_package() output.
	 * @return array provider|note|cost
	 */
	public static function breakdown( $quote ) {
		$lines = array();
		foreach ( (array) ( isset( $quote['providers'] ) ? $quote['providers'] : array() ) as $p ) {
			$lines[] = array(
				'provider' => $p['label'],
				'note'     => trim( $p['units'] . ' x from this provider · ' . $p['source'] . ( $p['note'] ? ' · ' . $p['note'] : '' ) ),
				'cost'     => number_format( (float) $p['cost'], 2, '.', '' ),
				'base'     => number_format( (float) $p['base'], 2, '.', '' ),
			);
		}
		return $lines;
	}

	/**
	 * The shop's currency plugin, when one is installed and offers conversion helpers.
	 *
	 * @return object|null
	 */
	public static function multi_currency_service() {
		$svc = null;

		// WooPayments 11.x resolves services through a PHP-DI container.
		try {
			if ( function_exists( 'wcpay_get_container' ) && class_exists( 'WCPay\\MultiCurrency\\MultiCurrency' ) ) {
				$svc = wcpay_get_container()->get( 'WCPay\\MultiCurrency\\MultiCurrency' );
			}
		} catch ( \Throwable $e ) {
			$svc = null;
		}

		if ( ! is_object( $svc ) && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_service' ) ) {
			try {
				$svc = \WC_Payments::get_service( 'wcpay_multi_currency' );
			} catch ( \Throwable $e ) {
				$svc = null;
			}
		}

		if ( ! is_object( $svc ) || ! method_exists( $svc, 'get_selected_currency' ) ) {
			return null;
		}

		return $svc;
	}

	/**
	 * The shop's own currency maths, as numbers: rate, rounding, charm, and what this shop converts
	 * a base amount into with and without charm pricing.
	 *
	 * The point is to make a displayed price explainable. Measured on the live cart: a base $15.99
	 * shipping rate displayed **$30.00** in NZD. The products in the same cart convert at x1.836;
	 * WooPayments' own shipping conversion of 15.99 is `get_price( 15.99, 'shipping' )`, which
	 * includes the per-currency **charm** setting (`wcpay_multi_currency_price_charm_NZD`) and
	 * rounding (`wcpay_multi_currency_price_round_NZD`) - charm is the difference between x1.836 and
	 * x1.876, not a second conversion.
	 *
	 * @param string $code   Currency to report (defaults to the shopper's selected currency).
	 * @param float  $probe  A base-currency amount to convert.
	 * @return array
	 */
	public static function currency_report( $code = '', $probe = 15.99 ) {
		$probe = (float) $probe;
		$out   = array(
			'service'   => 'none',
			'base'      => (string) get_option( 'woocommerce_currency', 'USD' ),
			'probe'     => $probe,
			'currencies' => array(),
		);

		$svc = self::multi_currency_service();
		if ( ! $svc ) {
			$out['note'] = 'no currency converter installed - the store displays base currency only';
			return $out;
		}

		$out['service'] = get_class( $svc );

		try {
			$sel = $svc->get_selected_currency();
			$def = $svc->get_default_currency();

			$out['selected']  = is_object( $sel ) ? $sel->get_code() : '';
			$out['default']   = is_object( $def ) ? $def->get_code() : '';
			$out['converting'] = is_object( $sel ) && is_object( $def ) && $sel->get_code() !== $def->get_code();

			// Charm is applied to products by default; WooPayments' own filter decides shipping.
			$out['charm_only_products'] = method_exists( $svc, 'get_apply_charm_only_to_products' )
				? (bool) $svc->get_apply_charm_only_to_products()
				: null;
		} catch ( \Throwable $e ) {
			$out['error'] = $e->getMessage();
			return $out;
		}

		$currencies = array();
		try {
			$currencies = (array) $svc->get_enabled_currencies();
		} catch ( \Throwable $e ) {
			$currencies = array();
		}

		$want = strtoupper( (string) $code );

		foreach ( $currencies as $currency ) {
			if ( ! is_object( $currency ) || ! method_exists( $currency, 'get_code' ) ) {
				continue;
			}

			$row = array(
				'code'       => $currency->get_code(),
				'rate'       => method_exists( $currency, 'get_rate' ) ? (float) $currency->get_rate() : null,
				'is_default' => method_exists( $currency, 'get_is_default' ) ? (bool) $currency->get_is_default() : false,
				'symbol'     => method_exists( $currency, 'get_symbol' ) ? (string) $currency->get_symbol() : '',
			);

			foreach ( array( 'get_round' => 'round', 'get_charm' => 'charm' ) as $method => $key ) {
				if ( method_exists( $currency, $method ) ) {
					$row[ $key ] = (float) $currency->$method();
				}
			}

			$dec = method_exists( $currency, 'get_currency' ) && is_object( $currency->get_currency() ) && method_exists( $currency->get_currency(), 'get_decimal' )
				? (int) $currency->get_currency()->get_decimal()
				: null;
			if ( null !== $dec ) {
				$row['decimals'] = $dec;
			}

			if ( method_exists( $svc, 'get_price' ) ) {
				// The shop's real shipping conversion, charm and rounding included.
				$row['shipping'] = round( (float) $svc->get_price( $probe, 'shipping', $currency ), 2 );
				$row['product']  = round( (float) $svc->get_price( $probe, 'product', $currency ), 2 );
			}
			if ( method_exists( $svc, 'get_raw_conversion' ) && $out['default'] ) {
				// The same amount with no charm and no rounding - what a plain multiply gives.
				$row['raw'] = round( (float) $svc->get_raw_conversion( $probe, $currency->get_code(), $out['default'] ), 4 );
			}

			$out['currencies'][ $row['code'] ] = $row;

			if ( '' !== $want && $row['code'] === $want ) {
				$out['report_currency'] = $row;
			}
		}

		if ( '' === $want && ! empty( $out['selected'] ) && isset( $out['currencies'][ $out['selected'] ] ) ) {
			$out['report_currency'] = $out['currencies'][ $out['selected'] ];
		}

		return $out;
	}

	/**
	 * The store's currency settings, read the way the converter stores them.
	 *
	 * WooPayments 11.x does not expose its MultiCurrency service through the container
	 * (`wcpay_get_container()->get( 'wcpay_multi_currency' )` throws a ContainerException -
	 * measured), and it has no `WC_Payments::get_service()`. Its settings are options, so they are
	 * read directly: the enabled currency list, each currency's exchange-rate type, manual rate,
	 * price rounding and price charm, and the cached exchange rates.
	 *
	 * Read-only, prefix-whitelisted, and it is the only honest answer to "is this price converted
	 * once at the store's rate, twice, or with charm on top".
	 *
	 * @return array
	 */
	public static function converter_settings() {
		global $wpdb;

		$out = array( 'source' => 'unavailable', 'options' => array() );

		if ( ! isset( $wpdb ) ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wcpay\\_multi\\_currency%' ORDER BY option_name ASC LIMIT 60", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		$out['source'] = 'options';
		foreach ( $rows as $row ) {
			$value = (string) $row['option_value'];

			// The cached currency list is the converter's own record of its rates, rounding and
			// charm per currency. Decode it, do not truncate it.
			if ( 'wcpay_multi_currency_cached_currencies' === $row['option_name'] ) {
				$cached = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $row['option_value'] ) : @unserialize( $row['option_value'] );

				// The cache stores an envelope: { data: { currencies: {CODE: {...}}, updated: n } }.
				if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
					$cached = $cached['data'];
				}
				if ( is_array( $cached ) && isset( $cached['updated'] ) ) {
					$out['cache_updated'] = (string) $cached['updated'];
				}
				if ( is_array( $cached ) && isset( $cached['currencies'] ) && is_array( $cached['currencies'] ) ) {
					$cached = $cached['currencies'];
				}

				if ( is_array( $cached ) ) {
					$out['cached'] = array();
					foreach ( $cached as $code => $currency ) {
						if ( ! is_array( $currency ) ) {
							$out['cached'][ (string) $code ] = strlen( (string) (is_array( $currency ) ? '' : $currency) ) > 120 ? 'shape:' . implode( ',', array_keys( (array) $currency ) ) : (string) $currency;
							continue;
						}
						$out['cached'][ (string) $code ] = array(
							'rate'     => isset( $currency['rate'] ) ? (float) $currency['rate'] : null,
							'round'    => isset( $currency['round'] ) ? (float) $currency['round'] : null,
							'charm'    => isset( $currency['charm'] ) ? (float) $currency['charm'] : null,
							'symbol'   => isset( $currency['symbol'] ) ? (string) $currency['symbol'] : '',
							'decimals' => isset( $currency['decimal'] ) ? (int) $currency['decimal'] : (isset( $currency['decimals'] ) ? (int) $currency['decimals'] : null),
							'keys'     => array_keys( $currency ),
						);
					}
				}
				continue;
			}

			if ( strlen( $value ) > 240 ) {
				$value = substr( $value, 0, 240 ) . '…';
			}
			$out['options'][ $row['option_name'] ] = $value;
		}

		return $out;
	}

	/**
	 * The factor this shop applies to a shipping rate when it displays money.
	 *
	 * Measured from the rate, not from a currency plugin's API: the merged total the method
	 * charged (the store's base currency, on the rate as `psr_total`) and the cost the shop puts
	 * on screen. On this store the cost is rewritten by WooPayments Multi-Currency at
	 * `add_rate()` - `woocommerce_shipping_method_add_rate_args` →
	 * FrontendPrices::convert_shipping_method_rate_cost → MultiCurrency::get_price( $cost,
	 * 'shipping' ) - and prices are formatted in that currency (`woocommerce_currency`,
	 * `wc_get_price_decimals`, `woocommerce_price_format`, all filtered at 900). Anything that
	 * converts rate costs the same way is measured the same way, which is why this does not
	 * mention WooPayments in code.
	 *
	 * @param float $base_total  Merged total in the store's currency.
	 * @param float $shown_total The cost as the shop displays it.
	 * @return float 1.0 when nothing is being converted.
	 */
	public static function display_factor( $base_total, $shown_total ) {
		$base_total  = (float) $base_total;
		$shown_total = (float) $shown_total;

		if ( 0.0 >= $base_total || 0.0 > $shown_total ) {
			return 1.0;
		}

		$factor = $shown_total / $base_total;

		// Within a cent of parity is no conversion. A factor outside a plausible exchange range
		// means the two numbers are not comparable (a rate cost touched by something else), and
		// printing base amounts is then the honest answer.
		if ( abs( $factor - 1.0 ) < 0.005 || $factor > 500.0 || 0.002 > $factor ) {
			return 1.0;
		}

		return $factor;
	}

	/**
	 * Share a total across lines, in the shop's money, to the cent.
	 *
	 * Two gaps get closed here. A converted rate label and base-currency sub-lines cannot add up
	 * (measured: label $48.00 NZD over $20.99 + $4.99), and the merged total is rounded once for
	 * the whole rate while each provider line is rounded on its own, so the lines can be a cent
	 * short of the label even in the store's own currency (measured: 10.99 + 4.99 under 15.99).
	 *
	 * Floor every line at the shop's precision, then hand the rounding cents to the lines that
	 * lost most by rounding down. The lines add up to the total exactly, every line stays
	 * non-negative, and no line is moved by more than the handful of cents that exist to share.
	 *
	 * @param float[] $amounts   Line amounts, in the currency being shown.
	 * @param float   $total     The amount the lines must add up to.
	 * @param int     $precision Decimals the shop displays.
	 * @return float[]
	 */
	public static function split_across( $amounts, $total, $precision = 2 ) {
		$amounts   = array_values( (array) $amounts );
		$precision = max( 0, (int) $precision );
		$pow       = (int) pow( 10, $precision );

		if ( ! $amounts ) {
			return array();
		}

		$total_cents = (int) round( (float) $total * $pow );
		$cents       = array();
		$fracs       = array();
		$sum         = 0;

		foreach ( $amounts as $i => $amount ) {
			$raw   = (float) $amount * $pow;
			$floor = (int) floor( $raw );
			$cents[ $i ] = $floor;
			$fracs[ $i ] = $raw - $floor;
			$sum        += $floor;
		}

		$left  = $total_cents - $sum;
		$order = array_keys( $cents );
		usort(
			$order,
			function ( $a, $b ) use ( $fracs, $cents ) {
				if ( $fracs[ $a ] === $fracs[ $b ] ) {
					return $cents[ $b ] <=> $cents[ $a ];
				}
				return $fracs[ $a ] > $fracs[ $b ] ? -1 : 1;
			}
		);

		$n = count( $order );

		// A cent at a time, largest rounding loss first. The loop is bounded by the cents there
		// are to share, so it cannot run away on a nonsense total.
		if ( $left > 0 ) {
			for ( $i = 0, $max = min( $left, 1000 * $n ); $i < $max; $i++ ) {
				$cents[ $order[ $i % $n ] ] += 1;
			}
		} elseif ( $left < 0 ) {
			for ( $i = 0, $max = min( -$left, 1000 * $n ); $i < $max; $i++ ) {
				$idx = $order[ $n - 1 - ( $i % $n ) ];
				if ( $cents[ $idx ] > 0 ) {
					$cents[ $idx ] -= 1;
				}
			}
		}

		$out = array();
		foreach ( $cents as $c ) {
			$out[] = ((int) $c) / $pow;
		}
		return $out;
	}
}
