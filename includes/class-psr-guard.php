<?php
/**
 * No shipping quote, no sale.
 *
 * A customer must not be able to place an order that the store cannot ship. This class makes
 * that a rule of the store rather than a side-effect of a template:
 *
 *   - the order is refused server-side (woocommerce_checkout_process) when the cart needs
 *     shipping and a package has no quoted price, nothing is chosen, or the chosen method is
 *     not among the quoted prices;
 *   - the same sentence appears in the order-review area, where the customer is looking;
 *   - WooPayments express checkout (Apple Pay / Google Pay) is switched off in the same case:
 *     that flow runs its own checkout and can complete an order without the shipping step
 *     being validated, so a notice on the page is not enough.
 *
 * One guard runs, not two. If tapstitch-shipping-method is installed and its guard is active,
 * this class registers nothing and says so - the switches are shared, so the site owner has
 * one behaviour to reason about.
 *
 * Switches: psr_block_no_quote (yes/no, default yes), psr_no_quote_message.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Guard {

	const BLOCK_KEY   = 'psr_block_no_quote';
	const MESSAGE_KEY = 'psr_no_quote_message';

	const DEFAULT_MESSAGE = 'We can\'t work out shipping for these items at this address. Please update your address, or come back to the cart and try again.';

	/** @var bool this plugin registered the guard, as opposed to deferring. */
	protected static $mine = false;

	/**
	 * Hook entry.
	 */
	public static function register() {
		if ( ! self::enabled() ) {
			return;
		}
		if ( self::owned_elsewhere() ) {
			// tapstitch-shipping-method guards this store already.
			return;
		}

		self::$mine = true;

		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'process' ), 5 );
		add_action( 'woocommerce_checkout_order_review', array( __CLASS__, 'notice' ), 5 );
		add_filter( 'wcpay_show_checkout_on_cart_page', array( __CLASS__, 'express_off' ), 20, 1 );
		add_filter( 'wcpay_show_checkout_on_product_page', array( __CLASS__, 'express_off' ), 20, 1 );
		add_filter( 'wcpay_show_express_checkout_button', array( __CLASS__, 'express_off' ), 20, 1 );
	}

	/**
	 * Is the block on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'no' !== get_option( self::BLOCK_KEY, 'yes' );
	}

	/**
	 * Is another plugin's guard already doing this job?
	 *
	 * @return bool
	 */
	public static function owned_elsewhere() {
		return class_exists( 'TSM_Guard' );
	}

	/**
	 * Who guards this store, for the status screen.
	 *
	 * @return array
	 */
	public static function state() {
		$owned = self::owned_elsewhere();

		// This plugin's own hooks. When tapstitch-shipping-method is present it owns the guard and
		// these stay false **by design** - reporting them as "wired" made a healthy store look
		// unguarded, so the effective guard's wiring is reported separately below.
		$own = array(
			'checkout_process' => (bool) has_action( 'woocommerce_checkout_process', array( __CLASS__, 'process' ) ),
			'order_review'     => (bool) has_action( 'woocommerce_checkout_order_review', array( __CLASS__, 'notice' ) ),
			'express_cart'     => (bool) has_filter( 'wcpay_show_checkout_on_cart_page', array( __CLASS__, 'express_off' ) ),
			'express_product'  => (bool) has_filter( 'wcpay_show_checkout_on_product_page', array( __CLASS__, 'express_off' ) ),
			'express_button'   => (bool) has_filter( 'wcpay_show_express_checkout_button', array( __CLASS__, 'express_off' ) ),
		);

		$state = array(
			'enabled'    => self::enabled(),
			'owner'      => $owned ? 'tapstitch-shipping-method (TSM_Guard)' : ( self::$mine ? 'provider-shipping' : 'provider-shipping (not registered in this request)' ),
			'wired'      => $owned && method_exists( 'TSM_Guard', 'wired' ) ? (array) TSM_Guard::wired() : $own,
			'own_wiring' => $own,
			'note'       => $owned
				? 'wired is the guard this store runs (TSM_Guard). own_wiring is this plugin\'s own hooks, which stay off while the other plugin owns the guard - one guard, not two.'
				: 'wired is this plugin\'s own hooks: it owns the guard in this request.',
			'message'    => self::message(),
		);

		if ( function_exists( 'WC' ) && WC()->cart ) {
			$state += self::decide( WC()->cart->needs_shipping(), self::rates_per_package(), self::chosen_methods() );
			$state['rates']  = self::rates_per_package();
			$state['chosen'] = self::chosen_methods();
		} else {
			$state['action'] = 'allow';
			$state['reason'] = 'no cart in this context';
		}

		return $state;
	}

	/**
	 * The sentence the customer sees.
	 *
	 * @return string
	 */
	public static function message() {
		$text = (string) get_option( self::MESSAGE_KEY, '' );
		if ( '' === trim( $text ) ) {
			$text = __( self::DEFAULT_MESSAGE, 'provider-shipping' );
		}
		return $text;
	}

	/**
	 * Refuse the order.
	 */
	public static function process() {
		$state = self::current();
		if ( 'block' !== $state['action'] ) {
			return;
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
		self::log( 'checkout blocked: ' . $state['reason'] );
	}

	/**
	 * Show it where the customer is looking.
	 */
	public static function notice() {
		$state = self::current();
		if ( 'block' === $state['action'] && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message(), 'error' );
		}
	}

	/**
	 * Express checkout off when nothing can ship the cart.
	 *
	 * @param mixed $show Whatever WooPayments asked for.
	 * @return bool
	 */
	public static function express_off( $show = false ) {
		$state = self::current();
		return 'block' === $state['action'] ? false : $show;
	}

	/**
	 * @return array decide() for the cart in this request.
	 */
	protected static function current() {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array(
				'action' => 'allow',
				'reason' => 'guard off, or no cart',
			);
		}
		return self::decide( WC()->cart->needs_shipping(), self::rates_per_package(), self::chosen_methods() );
	}

	/**
	 * The decision, as a pure function so it can be tested offline.
	 *
	 * @param bool  $needs_shipping Does the cart need shipping.
	 * @param array $rates          package index => list of available rate ids.
	 * @param array $chosen         chosen shipping method ids, in package order.
	 * @return array action allow|block, reason
	 */
	public static function decide( $needs_shipping, $rates, $chosen ) {
		if ( ! $needs_shipping ) {
			return array(
				'action' => 'allow',
				'reason' => 'no shipping needed',
			);
		}

		$packages = is_array( $rates ) ? $rates : array();
		if ( empty( $packages ) ) {
			return array(
				'action' => 'block',
				'reason' => 'no shipping package was built for this address',
			);
		}

		foreach ( array_values( $packages ) as $i => $list ) {
			$list = is_array( $list ) ? $list : array();
			if ( empty( $list ) ) {
				return array(
					'action' => 'block',
					'reason' => 'no shipping method quoted a price for package ' . $i,
				);
			}
			$pick = isset( $chosen[ $i ] ) ? (string) $chosen[ $i ] : '';
			if ( '' === $pick ) {
				return array(
					'action' => 'block',
					'reason' => 'no shipping method chosen for package ' . $i,
				);
			}
			if ( ! in_array( $pick, $list, true ) ) {
				return array(
					'action' => 'block',
					'reason' => 'the chosen shipping method for package ' . $i . ' is not among the quoted prices',
				);
			}
		}

		return array(
			'action' => 'allow',
			'reason' => 'every package has a quoted price',
		);
	}

	/**
	 * Rate ids WooCommerce quoted, per package.
	 *
	 * @return array
	 */
	public static function rates_per_package() {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( (array) WC()->cart->get_shipping_packages() as $i => $package ) {
			$list = array();
			foreach ( (array) ( isset( $package['rates'] ) ? $package['rates'] : array() ) as $key => $rate ) {
				$list[] = is_object( $rate ) ? (string) $rate->get_id() : (string) $key;
			}
			$out[ $i ] = $list;
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	public static function chosen_methods() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		return is_array( $chosen ) ? $chosen : array();
	}

	/**
	 * @param string $message Log line.
	 */
	protected static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'provider-shipping' ) );
		}
	}
}
