<?php
/**
 * Checkout wiring: the method, the packages, the visible rates, the sub-totals.
 *
 * Modes, from Settings → Shipping → Providers:
 *
 *   off    the plugin manages the registry only. The zone's own methods price the cart.
 *   shadow every package is quoted through the registry *and logged*, next to the rates
 *          WooCommerce is offering. Nothing at checkout changes. This is how the merged
 *          numbers are measured against what is live today.
 *   live   this plugin's method (psr_shipping, class PSR_Method) produces the checkout rate,
 *          from the same registry maths the shadow log reports, and the provider drivers are
 *          hidden from the customer.
 *
 * Why the hooks live here and not in the method: WooCommerce builds the cart's shipping
 * packages before it constructs the method instances that price them, so a package-level
 * rule has to be registered at plugin boot. (Measured the hard way: a method's own
 * constructor runs after `woocommerce_cart_shipping_packages` has fired.)
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Pipeline {

	/**
	 * Hook entry. Runs on plugins_loaded, after WooCommerce is up.
	 */
	public static function register() {
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'method' ) );

		$mode = self::mode();

		if ( 'off' !== $mode ) {
			// Packages are merged by destination so a cart is one shipment, not one per
			// supplier plugin. Registered at boot: methods are built after packages.
			add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'merge_packages' ), 20, 1 );
			add_action( 'woocommerce_load_shipping_methods', array( __CLASS__, 'ensure' ), 20, 1 );
		}

		if ( 'shadow' === $mode ) {
			add_filter( 'woocommerce_package_rates', array( __CLASS__, 'shadow' ), 90, 2 );
		}

		if ( 'live' === $mode ) {
			add_filter( 'woocommerce_package_rates', array( __CLASS__, 'only_merged' ), 100, 2 );
		}

		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'breakdown' ), 10, 2 );
	}

	/**
	 * @return string off|shadow|live
	 */
	public static function mode() {
		$mode = (string) get_option( 'psr_pipeline_mode', 'off' );
		return in_array( $mode, array( 'off', 'shadow', 'live' ), true ) ? $mode : 'off';
	}

	/**
	 * @param array $methods Registered shipping methods.
	 * @return array
	 */
	public static function method( $methods ) {
		$methods['psr_shipping'] = 'PSR_Method';
		return $methods;
	}

	/**
	 * One shipment per destination.
	 *
	 * Printify's plugin splits a cart into "ours" and "theirs" packages, and WooCommerce
	 * renders a separate Shipment - each with its own choice - per package. Merging packages
	 * that share a destination first is what makes "one rate for the cart" possible.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function merge_packages( $packages ) {
		$by_destination = array();

		foreach ( (array) $packages as $index => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$dest  = isset( $package['destination'] ) ? (array) $package['destination'] : array();
			$key   = implode(
				'|',
				array(
					isset( $dest['country'] ) ? $dest['country'] : '',
					isset( $dest['state'] ) ? $dest['state'] : '',
					isset( $dest['postcode'] ) ? $dest['postcode'] : '',
					isset( $dest['city'] ) ? $dest['city'] : '',
					isset( $dest['address'] ) ? $dest['address'] : '',
				)
			);

			if ( ! isset( $by_destination[ $key ] ) ) {
				$package['contents'] = (array) ( isset( $package['contents'] ) ? $package['contents'] : array() );
				$by_destination[ $key ] = $package;
				continue;
			}

			$merged = $by_destination[ $key ];
			foreach ( (array) ( isset( $package['contents'] ) ? $package['contents'] : array() ) as $k => $item ) {
				if ( isset( $merged['contents'][ $k ] ) && isset( $merged['contents'][ $k ]['quantity'] ) ) {
					$merged['contents'][ $k ]['quantity'] += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
					continue;
				}
				$merged['contents'][ $k ] = $item;
			}
			$merged['count']    = (int) ( isset( $merged['count'] ) ? $merged['count'] : 0 ) + (int) ( isset( $package['count'] ) ? $package['count'] : 0 );
			$merged['weight']   = (float) ( isset( $merged['weight'] ) ? $merged['weight'] : 0 ) + (float) ( isset( $package['weight'] ) ? $package['weight'] : 0 );
			$merged['subtotal'] = (float) ( isset( $merged['subtotal'] ) ? $merged['subtotal'] : 0 ) + (float) ( isset( $package['subtotal'] ) ? $package['subtotal'] : 0 );

			$by_destination[ $key ] = $merged;
		}

		$out = array_values( $by_destination );
		return $out ? $out : $packages;
	}

	/**
	 * Put this plugin's method back on the list when a supplier plugin wipes it.
	 *
	 * Printify's plugin calls unregister_shipping_methods() for the packages it claims and
	 * registers only itself, so the store's own method disappears. Re-register it here - from
	 * the method table, not from the zone's method list: asking a zone for its methods
	 * constructs every method in it, which is what makes a supplier plugin's admin code run in
	 * the middle of a checkout.
	 *
	 * @param array $package Package.
	 */
	public static function ensure( $package = array() ) {
		if ( ! is_array( $package ) || empty( $package ) || ! function_exists( 'WC' ) || ! WC()->shipping ) {
			return;
		}

		$zone_id = self::zone_id_for( $package );
		foreach ( PSR_Registry::zone_method_rows( $zone_id ) as $row ) {
			if ( 'psr_shipping' !== $row['method_id'] || ! $row['enabled'] ) {
				continue;
			}
			$loaded = (array) WC()->shipping->get_shipping_methods();
			if ( isset( $loaded['psr_shipping'] ) ) {
				return;
			}
			WC()->shipping->register_shipping_method( new PSR_Method( (int) $row['instance_id'] ) );
			return;
		}
	}

	/**
	 * Live mode: the customer gets this plugin's merged rate. The provider drivers stay
	 * available as rate sources, they are just not offered as shipping choices.
	 *
	 * When the registry cannot price the cart, what is left is the store's own Flat Rate - and a
	 * Flat Rate set below what the supplier actually charges is precisely the loss a store of this
	 * shape must not take. `psr_live_fallback` decides:
	 *
	 *   block (default) - offer nothing. The cart has no quote, and PSR_Guard refuses the sale.
	 *   flat            - leave the zone's own methods visible, as a fallback a customer can pick.
	 *
	 * @param array $rates   Rates WooCommerce offers for this package.
	 * @param array $package Package.
	 * @return array
	 */
	public static function only_merged( $rates, $package = array() ) {
		$keep = array();

		foreach ( (array) $rates as $key => $rate ) {
			$id = is_object( $rate ) ? (string) $rate->get_id() : (string) $key;
			if ( 0 === strpos( $id, 'psr_shipping_' ) ) {
				$keep[ $id ] = $rate;
			}
		}

		if ( ! $keep ) {
			// The registry has no price for this cart. "block" is the money rule: an
			// underpriced fallback is the exact loss the store asked to stop, so offer
			// nothing and let the guard refuse the sale. "flat" keeps today's behaviour.
			return self::keep_fallback( self::fallback_policy(), false ) ? $rates : array();
		}

		return $keep;
	}

	/**
	 * Should the zone's own rates be left on screen? Pure function, for the self-check.
	 *
	 * This plugin's own rate wins when it exists. When it does not, only the chosen fallback
	 * policy may leave the Flat Rate visible.
	 *
	 * @param string $policy       block|flat.
	 * @param bool   $has_own_rate Does this plugin have a rate for the cart.
	 * @return bool
	 */
	public static function keep_fallback( $policy, $has_own_rate ) {
		if ( $has_own_rate ) {
			return false;
		}
		return 'flat' === $policy;
	}

	/**
	 * @return string block|flat - what happens when the registry cannot price a cart.
	 */
	public static function fallback_policy() {
		$mode = (string) get_option( 'psr_live_fallback', 'block' );
		return 'flat' === $mode ? 'flat' : 'block';
	}

	/**
	 * Shadow mode: log the registry's answer next to the rates on the screen.
	 *
	 * @param array $rates   Rates being offered.
	 * @param array $package Package.
	 * @return array untouched
	 */
	public static function shadow( $rates, $package = array() ) {
		$quote = PSR_Quote::quote_package( $package );
		$live  = array();

		foreach ( (array) $rates as $key => $rate ) {
			if ( is_object( $rate ) ) {
				$live[] = $rate->get_id() . ' ' . $rate->get_label() . ' = ' . number_format( (float) $rate->get_cost(), 2 );
			}
		}

		$merged = array();
		foreach ( (array) $quote['providers'] as $p ) {
			$merged[] = $p['slug'] . '=' . number_format( (float) $p['cost'], 2 ) . ' from ' . $p['source'];
		}

		self::log(
			'SHADOW zone ' . self::zone_id_for( $package ) . ' ' . PSR_Driver::country( $package )
			. ' items ' . (int) ( isset( $package['count'] ) ? $package['count'] : 0 )
			. ' | registry says ' . ( 'block' === $quote['action'] ? 'NO QUOTE: ' . $quote['reason'] : $quote['label'] . ' = ' . number_format( (float) $quote['total'], 2 ) . ' (' . implode( ' + ', $merged ) . ')' )
			. ' | checkout offers ' . ( $live ? implode( ' ; ', $live ) : 'nothing' )
		);

		return $rates;
	}

	/**
	 * The per-provider sub-totals under the rate.
	 *
	 * WooCommerce's own shipping template fires this right after each rate label, so the line
	 * needs no template override and no JavaScript. The amounts travel on the rate itself
	 * (`psr_breakdown` meta), so they are there on re-rendered requests too, not only on the
	 * request that calculated the rate.
	 *
	 * Meta values must be scalars - add_rate() runs them through wc_clean(), and array values
	 * arrive as empty strings - so the breakdown is a JSON string of "provider|note|cost".
	 *
	 * @param WC_Shipping_Rate $rate  Rate.
	 * @param int              $index Package index.
	 */
	public static function breakdown( $rate, $index = 0 ) {
		if ( ! $rate instanceof WC_Shipping_Rate ) {
			return;
		}

		$raw  = $rate->get_meta_data();
		$raw  = is_array( $raw ) ? $raw : array();
		$json = isset( $raw['psr_breakdown'] ) ? (string) $raw['psr_breakdown'] : '';
		if ( '' === $json ) {
			return;
		}

		$lines = json_decode( $json, true );
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return;
		}

		$show = false;
		foreach ( $lines as $line ) {
			if ( is_string( $line ) && false !== strpos( $line, '|' ) ) {
				$show = true;
				break;
			}
		}
		if ( ! $show ) {
			return;
		}

		// Who is allowed to see the maths: the rate's own breakdown setting, then the site's
		// display mode. Staff with manage_woocommerce always see it when breakdown is admin.
		$cap  = (string) $rate->get_meta_data( 'psr_breakdown_cap' );
		$mode = (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN );

		$public = in_array( $mode, array( PSR_Registry::DISPLAY_MERGED_PUBLIC ), true )
			|| 'yes' === (string) get_option( 'psr_public_names', 'no' )
			|| ( isset( $raw['psr_breakdown_cap'] ) && 'public' === (string) $raw['psr_breakdown_cap'] );

		if ( ! $public && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$names_public = 'yes' === (string) get_option( 'psr_public_names', 'no' );
		$is_staff     = current_user_can( 'manage_woocommerce' );

		// Money: the breakdown meta is the store's base currency, the cost on the rate is what
		// this shop displays - WooPayments Multi-Currency rewrites a shipping rate's cost into the
		// customer's currency at add_rate() time. Printing the meta amounts next to a converted
		// label is the bug: label "Shipping (2 providers): $48.00" over lines $20.99 + $4.99.
		// Convert by the factor the rate itself reveals, then share the rounding cents, so the
		// lines add up to the price the customer pays, to the cent, in any currency.
		$precision  = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$base_total = isset( $raw['psr_total'] ) ? (float) $raw['psr_total'] : 0.0;
		$shown_cost = (float) $rate->get_cost();
		$factor     = PSR_Quote::display_factor( $base_total, $shown_cost );
		$converted  = $shown_cost !== $base_total;

		$rows = array();
		foreach ( array_values( $lines ) as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$bits = explode( '|', $line, 3 );
			$rows[] = array(
				'provider' => isset( $bits[0] ) ? $bits[0] : '',
				'note'     => isset( $bits[1] ) ? $bits[1] : '',
				'base'     => isset( $bits[2] ) ? (float) $bits[2] : 0.0,
				'units'    => preg_match( '/^\s*(\d+)/', isset( $bits[1] ) ? $bits[1] : '', $m ) ? (int) $m[1] : 0,
			);
		}

		$shown = PSR_Quote::split_across(
			array_map(
				function ( $row ) use ( $factor ) {
					return (float) $row['base'] * $factor;
				},
				$rows
			),
			$shown_cost,
			$precision
		);

		echo '<ul class="psr-breakdown">';
		foreach ( $rows as $i => $row ) {
			$amount = isset( $shown[ $i ] ) ? (float) $shown[ $i ] : 0.0;

			// The note carries the rate source ("#7 = Shipping", "printify api"), which is the
			// supplier's identity in prose. Staff see it; a customer sees only the parcel's size.
			// An anonymous line is numbered, so two parcels are not identical rows.
			$provider = $names_public
				? $row['provider']
				: sprintf(
					/* translators: %d: which parcel, in order */
					__( 'Provider %d', 'provider-shipping' ),
					(int) $i + 1
				);
			$note = ( $names_public || $is_staff )
				? $row['note']
				: sprintf(
					/* translators: %d: number of items in this parcel */
					_n( '%d item in this parcel', '%d items in this parcel', $row['units'], 'provider-shipping' ),
					$row['units']
				);

			echo '<li class="psr-bd-row">';
			echo '<span class="psr-bd-provider">' . esc_html( $provider ) . '</span> ';
			echo '<span class="psr-bd-note">' . esc_html( $note ) . '</span> ';
			echo '<span class="psr-bd-cost">' . wp_kses_post(
				function_exists( 'wc_price' ) ? wc_price( $amount, array( 'decimals' => $precision ) ) : number_format( $amount, $precision, '.', ',' )
			) . '</span>';

			// Staff reconcile against supplier invoices, which are in the store's currency.
			if ( $is_staff && $converted ) {
				echo ' <span class="psr-bd-base">(' . esc_html(
					number_format( (float) $row['base'], 2, '.', '' ) . ' ' . (string) get_option( 'woocommerce_currency', 'USD' )
				) . ')</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The zone a package belongs to.
	 *
	 * @param array $package Package.
	 * @return WC_Shipping_Zone|null
	 */
	public static function zone_for( $package ) {
		$id = self::zone_id_for( $package );
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return null;
		}
		try {
			return WC_Shipping_Zones::get_zone( $id );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Zone id from a package id. WooCommerce builds package ids as
	 * "<cart-id>-<zone_id>-<index>"; a supplier plugin's sub-package adds its own suffix.
	 *
	 * @param array $package Package.
	 * @return int
	 */
	public static function zone_id_for( $package ) {
		$package_id = isset( $package['id'] ) ? (string) $package['id'] : '';
		$parts      = explode( '-', $package_id );
		// The zone number is the numeric part after the cart hash; the trailing index is too.
		foreach ( $parts as $i => $part ) {
			if ( $i > 0 && ctype_digit( $part ) ) {
				return (int) $part;
			}
		}
		return 0;
	}

	/**
	 * @param string $message Log line.
	 */
	public static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->info( $message, array( 'source' => 'provider-shipping' ) );
		}
	}
}
