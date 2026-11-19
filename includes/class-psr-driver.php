<?php
/**
 * Provider drivers: asking each provider what its own goods cost to ship.
 *
 * The registry says *how* a provider is priced. This class executes it:
 *
 *   method  - instantiate the linked WooCommerce shipping method instance and let it quote a
 *             sub-package containing only that provider's items. This is the normal case and
 *             it needs no code per supplier: Printify's own method quotes Printify's items,
 *             the Tapstitch method quotes apparel, the zone's Flat Rate quotes in-house goods.
 *   fixed   - the amount the site owner set for that provider.
 *
 * A provider with no answer is not silently free: the row's fallback (fixed amount, or "no
 * quote", which the guard turns into a blocked sale) decides what happens.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Driver {

	/**
	 * How deep into the registry's own quoting we are. Zero outside it, so the shop converts real
	 * checkout rates exactly as it always has.
	 *
	 * @var int
	 */
	private static $quote_depth = 0;

	/**
	 * The price for one provider's items in a package, with the margin policy applied.
	 *
	 * @param array $row     Provider row.
	 * @param array $package Package containing only that provider's items.
	 * @return array available|cost|label|source|note
	 */
	public static function quote( $row, $package ) {
		$out = array(
			'available' => false,
			'cost'      => null,
			'label'     => $row['label'],
			'source'    => '',
			'note'      => '',
		);

		if ( empty( $package['contents'] ) ) {
			$out['note']   = 'no goods for this provider in the cart';
			$out['source'] = 'nothing to ship';
			return $out;
		}

		if ( ! PSR_Registry::serves( $row, self::country( $package ) ) ) {
			$out['note']   = $row['label'] . ' does not ship to ' . self::country( $package );
			$out['source'] = 'country not served';
			return $out;
		}

		$raw = null;

		if ( 'fixed' === $row['driver'] ) {
			$raw = (float) $row['fixed_cost'];
			$out['source'] = 'fixed amount';
			if ( $raw <= 0 ) {
				$out['note'] = 'fixed driver has no amount set';
				$raw         = null;
			}
		} elseif ( 'printify-api' === $row['driver'] && class_exists( 'TSM_Suppliers' ) ) {
			// The supplier's own endpoint, through the code path tapstitch-shipping-method
			// proved on this server. Used when the zone has no Printify method instance.
			$q          = TSM_Suppliers::printify_quote( $package );
			$raw        = $q['available'] && $q['standard'] ? (float) $q['standard'] : null;
			$out['source'] = 'printify api';
			if ( null === $raw ) {
				$out['note'] = 'Printify quoted nothing (' . $q['source'] . ')';
			}
		} elseif ( 'method' === $row['driver'] ) {
			$method = self::method_for( $row, $package );
			if ( ! $method ) {
				$out['note']   = 'no shipping method instance to price ' . $row['label'];
				$out['source'] = 'driver not found';
			} else {
				$rates  = self::rates( $method, $package );
				$pick   = self::best_rate( $rates );
				$raw    = $pick['cost'];
				$out['source'] = (string) $method->method_id . '#' . (int) $method->get_instance_id() . ' = ' . $pick['label'];
				$out['label']  = $row['label'];
				if ( null === $raw ) {
					$out['note'] = $row['label'] . ' returned no priced rate';
				}
			}
		}

		if ( null === $raw ) {
			if ( (float) $row['fixed_cost'] > 0 && 'block' !== $row['fallback'] ) {
				$raw          = (float) $row['fixed_cost'];
				$out['note'] .= ' -> fallback fixed amount';
				$out['source'] .= ' + fallback';
			} else {
				return $out;
			}
		}

		$out['available'] = true;
		$out['cost']      = PSR_Registry::apply_margin( $raw, $row );
		$out['base']      = $raw;

		return $out;
	}

	/**
	 * The method instance that prices this provider, in this package's zone.
	 *
	 * instance = 0 means "the instance of that method in the zone the cart falls in", which is
	 * what a store with one instance per zone wants. The class is built only for the one
	 * provider being priced - never by listing the zone's methods.
	 *
	 * @param array $row     Provider row.
	 * @param array $package Package.
	 * @return WC_Shipping_Method|null
	 */
	public static function method_for( $row, $package ) {
		if ( '' === $row['method_id'] ) {
			return null;
		}

		$classes = PSR_Registry::method_classes();
		if ( ! isset( $classes[ $row['method_id'] ] ) || ! class_exists( $classes[ $row['method_id'] ] ) ) {
			return null;
		}
		$class = $classes[ $row['method_id'] ];

		$instance = (int) $row['instance'];

		if ( 0 === $instance ) {
			$zone_id  = self::zone_id( $package );
			$instance = self::instance_in_zone( $row['method_id'], $zone_id );
			if ( 0 === $instance ) {
				// No instance of this method in the cart's zone: nothing to ask.
				return null;
			}
		}

		$method = new $class( $instance );
		return $method;
	}

	/**
	 * Rates from a method, tolerating the two ways a method exposes them.
	 *
	 * Read inside the registry's own quoting window (quote_window()), so the costs come back in the
	 * store's base currency. See quote_begin() for why that is not optional.
	 *
	 * @param WC_Shipping_Method $method  Method.
	 * @param array              $package Package.
	 * @return array
	 */
	protected static function rates( $method, $package ) {
		$rates = array();

		self::quote_begin();
		try {
			if ( method_exists( $method, 'get_rates_for_package' ) ) {
				$rates = (array) $method->get_rates_for_package( $package );
			} elseif ( method_exists( $method, 'calculate_shipping' ) ) {
				$method->calculate_shipping( $package );
				$rates = (array) $method->get_shipping_rates();
			}
		} catch ( Throwable $e ) {
			$rates = array();
		}
		self::quote_end();

		return $rates;
	}

	/**
	 * Run a callback inside the registry's quoting window.
	 *
	 * @param callable $fn Returns the quoted value.
	 * @return mixed
	 */
	public static function quote_window( $fn ) {
		self::quote_begin();
		try {
			return is_callable( $fn ) ? call_user_func( $fn ) : null;
		} catch ( Throwable $e ) {
			return null;
		} finally {
			self::quote_end();
		}
	}

	/**
	 * Ask the shop's currency plugin not to convert the rate costs we are about to read.
	 *
	 * WooPayments Multi-Currency (11.3.0 here) rewrites a shipping rate's cost into the customer's
	 * currency inside `WC_Shipping_Method::add_rate()`
	 * (`woocommerce_shipping_method_add_rate_args` at 99 →
	 * FrontendPrices::convert_shipping_method_rate_cost → MultiCurrency::get_price( $cost,
	 * 'shipping' )), gated by the filter `wcpay_multi_currency_should_convert_shipping_amount`,
	 * which exists precisely for methods whose costs must not be converted twice.
	 *
	 * The registry quotes providers *through* their shipping methods, so the cost we read off a
	 * method is in the customer's currency, while an API driver (Printify) answers in the store's
	 * base currency. Adding those is the double charge that was measured on the live cart: a hoodie
	 * and a sticker priced 15.99 base in USD showed **$48.00 in NZD** - the method's 10.99 arrived
	 * as 20.99 (converted, charm-adjusted), the API's 4.99 arrived as 4.99 (base), the merged 25.99
	 * was converted a second time. Shipping converted at x2.974 in the same cart the products
	 * converted at x1.84.
	 *
	 * So: quote in base, charge in base, let the shop convert the finished rate exactly once.
	 */
	public static function quote_begin() {
		self::$quote_depth++;
		if ( 1 === self::$quote_depth ) {
			add_filter( 'wcpay_multi_currency_should_convert_shipping_amount', array( __CLASS__, 'keep_base_currency' ), 10, 2 );
		}
	}

	/**
	 * Leave the shop's conversion as it was for everyone else.
	 *
	 * Nested quotes are counted, and the filter is removed at the outermost exit - including the
	 * exception path, because a leaked suppression would stop the shop converting real checkout
	 * rates, which under-charges in every non-base currency.
	 */
	public static function quote_end() {
		if ( self::$quote_depth > 0 ) {
			self::$quote_depth--;
		}
		if ( 0 === self::$quote_depth ) {
			remove_filter( 'wcpay_multi_currency_should_convert_shipping_amount', array( __CLASS__, 'keep_base_currency' ), 10 );
		}
	}

	/**
	 * @param bool $convert Default from the currency plugin.
	 * @param mixed $method The shipping method adding the rate.
	 * @return bool
	 */
	public static function keep_base_currency( $convert = true, $method = null ) {
		return 'base' === self::rate_cost_currency( self::$quote_depth ) ? false : $convert;
	}

	/**
	 * The currency a rate cost arrives in, as a rule the self-check can test.
	 *
	 * @param int $depth Quote nesting (0 = an ordinary checkout request).
	 * @return string base|display
	 */
	public static function rate_cost_currency( $depth ) {
		return (int) $depth > 0 ? 'base' : 'display';
	}

	/**
	 * How deep into the registry's own quoting this request is. Zero is an ordinary request.
	 *
	 * @return int
	 */
	public static function quote_depth() {
		return (int) self::$quote_depth;
	}

	/**
	 * The rate the store pays for: the cheapest priced rate the method offers.
	 *
	 * @param array $rates Rates.
	 * @return array cost|label
	 */
	protected static function best_rate( $rates ) {
		$best  = null;
		$label = '';
		foreach ( (array) $rates as $rate ) {
			if ( ! is_object( $rate ) ) {
				continue;
			}
			$cost = (float) $rate->get_cost();
			if ( $cost <= 0 ) {
				continue;
			}
			if ( null === $best || $cost < $best ) {
				$best  = $cost;
				$label = (string) $rate->get_label();
			}
		}
		return array(
			'cost'  => $best,
			'label' => $label,
		);
	}

	/**
	 * Zone the package belongs to.
	 *
	 * @param array $package Package.
	 * @return int
	 */
	protected static function zone_id( $package ) {
		if ( class_exists( 'WC_Shipping_Zones' ) ) {
			$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
			if ( $zone ) {
				return (int) $zone->get_id();
			}
		}

		// WooCommerce builds package ids as "<cart hash>-<zone id>"; a supplier plugin's
		// sub-package appends its own suffix.
		$package_id = isset( $package['id'] ) ? (string) $package['id'] : '';
		foreach ( explode( '-', $package_id ) as $i => $part ) {
			if ( $i > 0 && ctype_digit( $part ) ) {
				return (int) $part;
			}
		}
		return 0;
	}

	/**
	 * The instance of a method in a zone, from WooCommerce's own method table.
	 *
	 * @param string $method_id Method id.
	 * @param int    $zone_id   Zone id.
	 * @return int
	 */
	protected static function instance_in_zone( $method_id, $zone_id ) {
		foreach ( PSR_Registry::zone_method_rows( (int) $zone_id ) as $row ) {
			if ( $method_id === $row['method_id'] && $row['enabled'] && $row['instance_id'] > 0 ) {
				return (int) $row['instance_id'];
			}
		}
		return 0;
	}

	/**
	 * @param array $package Package.
	 * @return string
	 */
	public static function country( $package ) {
		$dest = isset( $package['destination'] ) ? (array) $package['destination'] : array();
		return PSR_Registry::country( isset( $dest['country'] ) ? $dest['country'] : '' );
	}
}
