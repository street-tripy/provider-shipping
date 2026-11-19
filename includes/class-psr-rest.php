<?php
/**
 * Provider Shipping - diagnostics over REST.
 *
 * Read routes are manage_woocommerce-gated. The write routes are the two the build needs and
 * nothing else: save a provider row, and apply provider meta the detect screen proposed.
 * No product is created, renamed, re-slugged, published, unpublished or deleted by any route
 * in this plugin: the only product data written is `_psr_provider`.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Rest {

	const NS = 'provider-shipping/v1';

	/**
	 * Hook entry.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_status' ),
			)
		);

		register_rest_route(
			self::NS,
			'/selftest',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_selftest' ),
			)
		);

		register_rest_route(
			self::NS,
			'/detect',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'country' => array( 'type' => 'string', 'default' => 'US' ),
				),
				'callback'            => array( __CLASS__, 'rest_detect' ),
			)
		);

		register_rest_route(
			self::NS,
			'/editor',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'callback'            => array( __CLASS__, 'rest_editor' ),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'display_mode'    => array( 'type' => 'string', 'default' => '' ),
					'public_names'    => array( 'type' => 'string', 'default' => '' ),
					'show_provider'   => array( 'type' => 'string', 'default' => '' ),
					'provider_note'   => array( 'type' => 'string', 'default' => '' ),
					'default_provider' => array( 'type' => 'string', 'default' => '' ),
					'pipeline_mode'   => array( 'type' => 'string', 'default' => '' ),
					'live_fallback'   => array( 'type' => 'string', 'default' => '' ),
					'block_no_quote'  => array( 'type' => 'string', 'default' => '' ),
					'no_quote_message' => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_settings' ),
			)
		);

		register_rest_route(
			self::NS,
			'/currency',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'code'  => array( 'type' => 'string', 'default' => '' ),
					'amount' => array( 'type' => 'string', 'default' => '15.99' ),
				),
				'callback'            => array( __CLASS__, 'rest_currency' ),
			)
		);

		register_rest_route(
			self::NS,
			'/rates',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'items'    => array( 'type' => 'string', 'default' => '12:1,15:1' ),
					'zone'     => array( 'type' => 'string', 'default' => '1' ),
					'country'  => array( 'type' => 'string', 'default' => 'US' ),
					'state'    => array( 'type' => 'string', 'default' => 'NY' ),
					'postcode' => array( 'type' => 'string', 'default' => '10001' ),
				),
				'callback'            => array( __CLASS__, 'rest_rates' ),
			)
		);

		register_rest_route(
			self::NS,
			'/breakdown',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'items'    => array( 'type' => 'string', 'default' => '12:1,15:1' ),
					'zone'     => array( 'type' => 'string', 'default' => '1' ),
					'country'  => array( 'type' => 'string', 'default' => 'US' ),
					'state'    => array( 'type' => 'string', 'default' => 'NY' ),
					'postcode' => array( 'type' => 'string', 'default' => '10001' ),
					// Nonzero: price the rate as if this shop converted it into another currency
					// at that factor, so the staff view of the sub-lines (converted amounts plus
					// the base amounts in brackets) is measurable. WooCommerce forces the store
					// currency inside REST, so a probe can never see a conversion by itself.
					'simulate_factor' => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_breakdown' ),
			)
		);

		register_rest_route(
			self::NS,
			'/quote',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'items'    => array( 'type' => 'string', 'default' => '12:1' ),
					'country'  => array( 'type' => 'string', 'default' => 'US' ),
					'state'    => array( 'type' => 'string', 'default' => 'NY' ),
					'postcode' => array( 'type' => 'string', 'default' => '10001' ),
				),
				'callback'            => array( __CLASS__, 'rest_quote' ),
			)
		);

		register_rest_route(
			self::NS,
			'/providers',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'label'      => array( 'type' => 'string', 'default' => '' ),
					'driver'     => array( 'type' => 'string', 'default' => '' ),
					'method_id'  => array( 'type' => 'string', 'default' => '' ),
					'instance'   => array( 'type' => 'string', 'default' => '' ),
					'handling'   => array( 'type' => 'string', 'default' => '' ),
					'uplift'     => array( 'type' => 'string', 'default' => '' ),
					'floor'      => array( 'type' => 'string', 'default' => '' ),
					'cap'        => array( 'type' => 'string', 'default' => '' ),
					'round'      => array( 'type' => 'string', 'default' => '' ),
					'classes'    => array( 'type' => 'string', 'default' => '' ),
					'enabled'    => array( 'type' => 'string', 'default' => '' ),
					'visible'    => array( 'type' => 'string', 'default' => '' ),
					'slug'       => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_providers' ),
			)
		);

		register_rest_route(
			self::NS,
			'/apply',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'confirm' => array( 'type' => 'string', 'default' => 'no' ),
					'rows'    => array( 'type' => 'string', 'default' => '' ),
				),
				'callback'            => array( __CLASS__, 'rest_apply' ),
			)
		);

		register_rest_route(
			self::NS,
			'/restore',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'confirm' => array( 'type' => 'string', 'default' => 'no' ),
				),
				'callback'            => array( __CLASS__, 'rest_restore' ),
			)
		);
	}

	/**
	 * The product editor's own view of this plugin: which panes exist, and the field the
	 * Providers tab saves. Reported here so "the tab is in the editor" is a read fact.
	 *
	 * Measured on this store (fatal log 2026-11-19 08:01): firing `manage_edit-product_columns`
	 * from a REST request fatals, because the theme hooks a function onto it that only exists on
	 * an admin screen - `presscore_admin_add_sidebars_columns`. So nothing is fired here until
	 * every callback registered on the tag is confirmed callable; a tag with an uncallable hook is
	 * reported by name instead of crashing the request.
	 *
	 * @return array
	 */
	protected static function editor_state() {
		$state = array();

		// Which tags are safe to fire, and what the theme/plugin hooks say about them.
		$tags = array(
			'panes'       => 'woocommerce_settings_tabs_data',
			'csv_column'  => 'woocommerce_product_csv_exporter_columns',
			'list_column' => 'manage_edit-product_columns',
		);

		foreach ( $tags as $key => $tag ) {
			list( $ok, $bad ) = self::filter_is_safe( $tag );
			if ( ! $ok ) {
				$state[ $key ] = 'refused: ' . $tag . ' hooks an uncallable function (' . implode( ', ', $bad ) . ')';
			}
		}

		if ( ! isset( $state['panes'] ) ) {
			$tabs  = (array) apply_filters( 'woocommerce_settings_tabs_data', array() );
			$field = '';
			foreach ( isset( $tabs['psr']['fields'] ) ? (array) $tabs['psr']['fields'] : array() as $f ) {
				if ( isset( $f['name'] ) && PSR_Registry::META_KEY === $f['name'] ) {
					$field = (string) $f['name'];
					break;
				}
			}
			$state['panes']          = array_keys( $tabs );
			$state['providers_pane'] = isset( $tabs['psr'] ) ? 'yes' : 'no';
			$state['provider_field'] = $field;
		}

		foreach ( array( 'csv_column', 'list_column' ) as $key ) {
			if ( isset( $state[ $key ] ) ) {
				continue;
			}
			$columns      = (array) apply_filters( $tags[ $key ], array() );
			$state[ $key ] = in_array( 'psr_provider', array_keys( $columns ), true ) ? 'yes' : 'no';
		}

		return $state;
	}

	/**
	 * Is every callback registered on a filter tag callable in this request?
	 *
	 * A theme that hooks a function which is only loaded on an admin screen turns
	 * `apply_filters()` into a fatal for everyone who fires that tag elsewhere. A diagnostic
	 * route must not be able to take a store down, so the registry is inspected before firing,
	 * and the broken hook is named in the report.
	 *
	 * WP_Hook keeps its callback table private, so it is read through Reflection: no hooks are
	 * fired, nothing is modified, and a failure to inspect means "do not fire".
	 *
	 * @param string $tag Filter tag.
	 * @return array array( bool ok, string[] uncallable )
	 */
	protected static function filter_is_safe( $tag ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return array( true, array() );
		}
		if ( ! $wp_filter[ $tag ] instanceof WP_Hook ) {
			return array( false, array( 'the tag is not a WP_Hook' ) );
		}

		try {
			$ref  = new ReflectionObject( $wp_filter[ $tag ] );
			$prop = $ref->getProperty( 'callbacks' );
			$prop->setAccessible( true );
			$sets = (array) $prop->getValue( $wp_filter[ $tag ] );
		} catch ( Throwable $e ) {
			return array( false, array( 'not inspectable: ' . $e->getMessage() ) );
		}

		$bad = array();
		foreach ( $sets as $per_priority ) {
			foreach ( (array) $per_priority as $hook_row ) {
				if ( ! isset( $hook_row['function'] ) || ! is_callable( $hook_row['function'] ) ) {
					$bad[] = self::describe_callback( isset( $hook_row['function'] ) ? $hook_row['function'] : null );
				}
			}
		}

		return array( empty( $bad ), $bad );
	}

	/**
	 * A readable name for a registered callback.
	 *
	 * @param mixed $callback Hooked function.
	 * @return string
	 */
	protected static function describe_callback( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) ) {
			return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1];
		}
		if ( $callback instanceof Closure ) {
			return 'closure';
		}
		return is_object( $callback ) ? get_class( $callback ) : var_export( $callback, true );
	}

	/**
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /status - registry, switches, guard, providers, unmarked products.
	 */
	public static function rest_status() {
		$rows    = array();
		foreach ( PSR_Registry::all() as $slug => $row ) {
			$insts = PSR_Registry::method_instances( $row['method_id'] );
			$found = array();
			foreach ( $insts as $zone_id => $per_zone ) {
				foreach ( $per_zone as $instance_id => $label ) {
					$found[] = (int) $zone_id . '#' . (int) $instance_id;
				}
			}
			$rows[] = array(
				'slug'       => $row['slug'],
				'label'      => $row['label'],
				'driver'     => $row['driver'],
				'method_id'  => $row['method_id'],
				'instance'   => (int) $row['instance'],
				'instances'  => $found,
				'lane'       => $row['lane'],
				'fulfillment' => $row['fulfillment'],
				'handling'   => $row['handling'],
				'uplift'     => $row['uplift'],
				'floor'      => $row['floor'],
				'cap'        => $row['cap'],
				'round'      => $row['round'],
				'fallback'   => $row['fallback'],
				'fixed_cost' => $row['fixed_cost'],
				'classes'    => PSR_Registry::class_map( $row ),
				'serves_all' => $row['serves_all'],
				'countries'  => $row['countries'],
				'enabled'    => $row['enabled'],
				'visible'    => $row['visible'],
			);
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'php'       => PHP_VERSION,
				'wc'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'psr'       => PSR_VERSION,
				'switches'  => array(
					'display_mode'    => (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN ),
					'public_names'    => (string) get_option( 'psr_public_names', 'no' ),
					'show_provider'   => (string) get_option( 'psr_show_provider', PSR_Registry::SHOW_HIDDEN ),
					'default_provider' => (string) get_option( 'psr_default_provider', 'in-house' ),
					'pipeline_mode'   => PSR_Pipeline::mode(),
					'live_fallback'   => PSR_Pipeline::fallback_policy(),
					'block_no_quote'  => (string) get_option( 'psr_block_no_quote', 'yes' ),
					'note'            => (string) get_option( 'psr_provider_note', '' ),
					'message'         => PSR_Guard::message(),
				),
				'providers' => $rows,
				'unmarked'  => PSR_Registry::unmarked_products(),
				'methods'   => array_keys( PSR_Registry::method_classes() ),
				'editor'    => self::editor_state(),
				'guard'     => PSR_Guard::state(),
				'pipeline'  => array(
					'mode'       => PSR_Pipeline::mode(),
					'live_fallback' => PSR_Pipeline::fallback_policy(),
					'zone_psr_instances' => self::psr_instances(),
				),
				'snapshot'  => count( (array) get_option( 'psr_detect_snapshot', array() ) ),
			)
		);
	}

	/**
	 * GET /selftest
	 */
	public static function rest_selftest() {
		$out = PSR_Selftest::run();
		return rest_ensure_response( array( 'ok' => true, 'summary' => $out, 'cases' => $out['cases'] ) );
	}

	/**
	 * GET /editor - the product editor's view of this plugin (Providers pane, columns),
	 * and any filter tag on this store that hooks an uncallable function.
	 */
	public static function rest_editor() {
		return rest_ensure_response( array( 'ok' => true, 'editor' => self::editor_state() ) );
	}

	/**
	 * POST /settings - the site switches, `manage_woocommerce` only.
	 *
	 * Same effect as the Save changes button on Settings → Shipping → Providers, for the build
	 * scripts and for a careful one-switch-at-a-time change. Values are whitelisted; anything
	 * unrecognised is reported back and not written.
	 */
	public static function rest_settings( $request ) {
		$map = array(
			'display_mode'     => array( 'psr_display_mode', array_keys( PSR_Registry::display_modes() ) ),
			'public_names'     => array( 'psr_public_names', array( 'yes', 'no' ) ),
			'show_provider'    => array( 'psr_show_provider', array_keys( PSR_Registry::show_modes() ) ),
			'default_provider' => array( 'psr_default_provider', array_keys( PSR_Registry::all() ) ),
			'pipeline_mode'    => array( 'psr_pipeline_mode', array( 'off', 'shadow', 'live' ) ),
			'live_fallback'    => array( 'psr_live_fallback', array( 'block', 'flat' ) ),
			'block_no_quote'   => array( 'psr_block_no_quote', array( 'yes', 'no' ) ),
		);

		$written = array();
		$refused = array();

		foreach ( $map as $key => $spec ) {
			$value = (string) $request->get_param( $key );
			if ( '' === $value ) {
				continue;
			}
			if ( ! in_array( $value, $spec[1], true ) ) {
				$refused[ $key ] = $value . ' (allowed: ' . implode( ', ', $spec[1] ) . ')';
				continue;
			}
			update_option( $spec[0], $value );
			$written[ $key ] = $value;
		}

		foreach ( array( 'provider_note' => 'psr_provider_note', 'no_quote_message' => 'psr_no_quote_message' ) as $key => $option ) {
			$value = $request->get_param( $key );
			if ( null === $value || '' === $value ) {
				continue;
			}
			update_option( $option, wc_clean( (string) $value ) );
			$written[ $key ] = (string) get_option( $option );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'written' => $written,
				'refused' => $refused,
				'state'   => array(
					'display_mode'   => (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN ),
					'public_names'   => (string) get_option( 'psr_public_names', 'no' ),
					'show_provider'  => (string) get_option( 'psr_show_provider', PSR_Registry::SHOW_HIDDEN ),
					'default_provider' => (string) get_option( 'psr_default_provider', 'in-house' ),
					'pipeline_mode'  => PSR_Pipeline::mode(),
					'block_no_quote' => (string) get_option( 'psr_block_no_quote', 'yes' ),
					'psr_instances'  => self::psr_instances(),
				),
			)
		);
	}

	/**
	 * GET /currency - the shop's own currency maths, as numbers.
	 *
	 * Answers "is this price the base amount converted once, or twice?" for any amount, for every
	 * enabled currency, with and without the shop's charm/rounding settings.
	 */
	public static function rest_currency( $request ) {
		$amount = (float) $request->get_param( 'amount' );
		$code   = (string) $request->get_param( 'code' );

		$report = PSR_Quote::currency_report( $code, $amount );

		// The registry's own read of the shop: base total and displayed cost on a live rate, so the
		// factor the renderer measures can be compared against the converter's own numbers.
		$report['quote_depth']      = (int) class_exists( 'PSR_Driver' ) ? PSR_Driver::quote_depth() : 0;
		$report['rate_cost_currency'] = class_exists( 'PSR_Driver' ) ? PSR_Driver::rate_cost_currency( PSR_Driver::quote_depth() ) : '';

		// What is actually on this server, so a failed lookup is visible instead of silent.
		$report['probes'] = array(
			'function wcpay_get_container' => function_exists( 'wcpay_get_container' ),
			'class WCPay MultiCurrency'    => class_exists( 'WCPay\\MultiCurrency\\MultiCurrency' ),
			'class WC_Payments'            => class_exists( 'WC_Payments' ),
			'WC_Payments::get_service'     => method_exists( 'WC_Payments', 'get_service' ),
			'container->get by id'         => '',
			'service class'                => '',
			'service methods'              => array(),
			'error'                        => '',
		);

		try {
			if ( function_exists( 'wcpay_get_container' ) ) {
				$by_id = wcpay_get_container()->get( 'wcpay_multi_currency' );
				$report['probes']['container->get by id'] = is_object( $by_id ) ? get_class( $by_id ) : gettype( $by_id );
			}
		} catch ( \Throwable $e ) {
			$report['probes']['container->get by id'] = get_class( $e );
		}

		try {
			if ( function_exists( 'wcpay_get_container' ) ) {
				$by_class = wcpay_get_container()->get( 'WCPay\\MultiCurrency\\MultiCurrency' );
				$report['probes']['container->get by class'] = is_object( $by_class ) ? get_class( $by_class ) : gettype( $by_class );
			}
		} catch ( \Throwable $e ) {
			$report['probes']['container->get by class'] = get_class( $e );
		}

		try {
			$svc = PSR_Quote::multi_currency_service();
			if ( $svc ) {
				$report['probes']['service class']   = get_class( $svc );
				$report['probes']['service methods'] = array_slice(
					array_values(
						array_filter(
							get_class_methods( $svc ),
							function ( $m ) {
								return preg_match( '/currency|price|conversion/i', $m );
							}
						)
					),
					0,
					12
				);
			}
		} catch ( \Throwable $e ) {
			$report['probes']['error'] = get_class( $e ) . ': ' . $e->getMessage();
		}

		$report['settings'] = PSR_Quote::converter_settings();

		// What a base amount becomes at the store's own rate, with no charm and no rounding: the
		// number to compare a screen price against.
		$rate = (float) get_option( 'wcpay_multi_currency_manual_rate_' . strtoupper( (string) $code ), 0 );
		$type = (string) get_option( 'wcpay_multi_currency_exchange_rate_' . strtoupper( (string) $code ), 'automatic' );

		$report['manual_rate_for_report'] = $rate;
		$report['exchange_rate_type']     = $type;
		$report['rounding']               = get_option( 'wcpay_multi_currency_price_rounding_' . strtoupper( (string) $code ), null );
		$report['charm']                  = get_option( 'wcpay_multi_currency_price_charm_' . strtoupper( (string) $code ), null );

		return rest_ensure_response( $report );
	}

	/**
	 * GET /rates - exactly what a shopper is offered in a zone, and which plugin produced it.
	 *
	 * Prices a synthetic cart through the zone's enabled methods (built from WooCommerce's method
	 * table, so no supplier plugin is woken up), then runs the store's `woocommerce_package_rates`
	 * filters - the same ones the checkout runs - and reports what survives, with the meta each
	 * rate carries. The meta is what tells tapstitch-shipping-method's rate from this plugin's.
	 */
	public static function rest_rates( $request ) {
		$items = array();
		foreach ( preg_split( '/[,; ]+/', (string) $request->get_param( 'items' ) ) as $bit ) {
			if ( '' !== trim( $bit ) ) {
				$items[] = trim( $bit );
			}
		}

		$package = PSR_Selftest::package( $items );
		if ( ! $package ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'no usable items in "items" (product_id:qty)' ) );
		}

		$zone = (int) $request->get_param( 'zone' );
		$package['destination']['country']  = PSR_Registry::country( (string) $request->get_param( 'country' ) );
		$package['destination']['state']    = (string) $request->get_param( 'state' );
		$package['destination']['postcode'] = (string) $request->get_param( 'postcode' );
		$package['id']                      = 'psr-probe-' . $zone;
		$package['package_id']              = $package['id'];

		$offered = array();
		$all     = array();

		foreach ( PSR_Registry::zone_method_rows( $zone ) as $row ) {
			if ( ! $row['enabled'] ) {
				continue;
			}
			$classes = PSR_Registry::method_classes();
			if ( ! isset( $classes[ $row['method_id'] ] ) || ! class_exists( $classes[ $row['method_id'] ] ) ) {
				continue;
			}

			$method = new $classes[ $row['method_id'] ]( $row['instance_id'] );
			$method->calculate_shipping( $package );

			foreach ( (array) $method->rates as $id => $rate ) {
				$meta   = (array) $rate->get_meta_data();
				$all[ (string) $id ] = array(
					'method'    => $row['method_id'] . '#' . $row['instance_id'],
					'label'     => (string) $rate->get_label(),
					'cost'      => (float) $rate->get_cost(),
					'from'      => array_key_exists( 'psr_total', $meta ) ? 'provider-shipping' : ( array_key_exists( 'tapstitch_breakdown', $meta ) ? 'tapstitch-shipping-method' : 'other' ),
					'providers' => isset( $meta['psr_providers'] ) ? $meta['psr_providers'] : ( isset( $meta['tapstitch_providers'] ) ? $meta['tapstitch_providers'] : '' ),
					'lines'     => isset( $meta['psr_lines'] ) ? (int) $meta['psr_lines'] : ( isset( $meta['tapstitch_breakdown'] ) ? substr_count( (string) $meta['tapstitch_breakdown'], '|' ) : 0 ),
				);
			}
		}

		/** This filter is documented by WooCommerce: the rates a package offers at checkout. */
		$offered = apply_filters( 'woocommerce_package_rates', $all, $package );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'zone'      => $zone,
				'dest'      => $package['destination'],
				'pipeline'  => PSR_Pipeline::mode(),
				'computed'  => $all,
				'at_checkout' => is_array( $offered ) ? array_values( $offered ) : array(),
				'suppressed' => is_array( $offered ) ? array_values( array_diff_key( $all, $offered ) ) : array(),
			)
		);
	}

	/**
	 * GET /breakdown - what a shopper and a staff member see under the rate.
	 *
	 * Prices a cart through this plugin's own method in a zone, then reports the rate's meta,
	 * the sub-total lines exactly as the `woocommerce_after_shipping_rate` renderer produces
	 * them, and whether that hook is wired in this request. That is the difference between
	 * "the switch is set to show sub-totals" and "the sub-totals reach the screen".
	 */
	public static function rest_breakdown( $request ) {
		$items = array();
		foreach ( preg_split( '/[,; ]+/', (string) $request->get_param( 'items' ) ) as $bit ) {
			if ( '' !== trim( $bit ) ) {
				$items[] = trim( $bit );
			}
		}

		$package = PSR_Selftest::package( $items );
		if ( ! $package ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'no usable items in "items" (product_id:qty)' ) );
		}

		$zone       = (int) $request->get_param( 'zone' );
		$package['destination']['country']  = PSR_Registry::country( (string) $request->get_param( 'country' ) );
		$package['destination']['state']    = (string) $request->get_param( 'state' );
		$package['destination']['postcode'] = (string) $request->get_param( 'postcode' );
		// WooCommerce builds package ids as "<cart hash>-<zone id>"; the method finds its own
		// instance in that zone, so make the id say which zone this is.
		$package['id']         = 'psr-probe-' . $zone;
		$package['package_id'] = $package['id'];

		$instance = 0;
		foreach ( PSR_Registry::method_instances( 'psr_shipping' ) as $zone_id => $per_zone ) {
			if ( (int) $zone_id === $zone ) {
				$instance = (int) key( $per_zone );
				break;
			}
		}
		if ( 0 === $instance ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'error'   => 'no Provider Shipping (psr_shipping) instance in zone ' . $zone . ' - Live mode has nothing to charge with',
					'zones'   => self::psr_instances(),
				)
			);
		}

		$method = new PSR_Method( $instance );
		$method->calculate_shipping( $package );

		// WooCommerce keeps the rates a method added on the public $rates property.
		$rates = (array) $method->rates;

		$report = array();
		$sim    = (float) $request->get_param( 'simulate_factor' );

		foreach ( $rates as $id => $rate ) {
			$meta = (array) $rate->get_meta_data();

			// Simulated currency: what WooPayments Multi-Currency does to a shipping rate
			// (`woocommerce_shipping_method_add_rate_args` →
			// FrontendPrices::convert_shipping_method_rate_cost →
			// MultiCurrency::get_price( $cost, 'shipping' )) - rewrite the rate's cost, leave the
			// maths in the meta in the store's currency. That is the exact shape the renderer has
			// to cope with, and it is the shape REST can never show on its own.
			$precision_probe = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
			if ( $sim > 0.5 && 1.0 !== $sim ) {
				$rate->set_cost( round( (float) $rate->get_cost() * $sim, $precision_probe ) );
			}

			// The sub-total lines, rendered exactly as the checkout renders them.
			ob_start();
			PSR_Pipeline::breakdown( $rate, 0 );
			$html = ob_get_clean();

			// Currency: the rate's cost is what the shop displays, `psr_total` is the store's
			// base currency. WooPayments Multi-Currency rewrites the cost at add_rate(), so on a
			// front-end request these differ and the lines must be converted by that factor.
			// WooCommerce forces the store currency inside REST, so a probe here measures parity;
			// the customer's screen is where the conversion is visible.
			$precision  = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
			$base_total = isset( $meta['psr_total'] ) ? (float) $meta['psr_total'] : 0.0;
			$base_lines = array();
			foreach ( (array) ( isset( $meta['psr_breakdown'] ) ? json_decode( (string) $meta['psr_breakdown'], true ) : array() ) as $line ) {
				if ( is_string( $line ) ) {
					$bits         = explode( '|', $line, 3 );
					$base_lines[] = isset( $bits[2] ) ? (float) $bits[2] : 0.0;
				}
			}
			$factor = PSR_Quote::display_factor( $base_total, (float) $rate->get_cost() );
			$shown  = PSR_Quote::split_across(
				array_map(
					function ( $a ) use ( $factor ) {
						return (float) $a * $factor;
					},
					$base_lines
				),
				(float) $rate->get_cost(),
				$precision
			);

			$report[] = array(
				'id'         => (string) $id,
				'label'      => (string) $rate->get_label(),
				'cost'       => (float) $rate->get_cost(),
				'meta_keys'  => array_keys( $meta ),
				'providers'  => isset( $meta['psr_providers'] ) ? $meta['psr_providers'] : '',
				'breakdown'  => isset( $meta['psr_breakdown'] ) ? json_decode( (string) $meta['psr_breakdown'], true ) : null,
				'note'       => isset( $meta['psr_note'] ) ? $meta['psr_note'] : '',
				'html'       => trim( (string) $html ),
				'html_nodes' => substr_count( (string) $html, '<li' ),
				'currency'   => array(
					'showing'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
					'base'        => (string) get_option( 'woocommerce_currency', 'USD' ),
					'base_total'  => (float) $base_total,
					'shown_total' => (float) $rate->get_cost(),
					'factor'      => (float) $factor,
					'precision'   => (int) $precision,
					'lines'       => array_map(
						function ( $a ) {
							return round( (float) $a, 2 );
						},
						$base_lines
					),
					'shown'       => array_map(
						function ( $a ) {
							return round( (float) $a, 2 );
						},
						$shown
					),
					'lines_sum'   => round( array_sum( $shown ), $precision ),
					'adds_up'     => abs( array_sum( $shown ) - (float) round( (float) $rate->get_cost(), $precision ) ) < 0.005,
				),
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'zone'     => $zone,
				'instance' => $instance,
				'simulated_factor' => (float) $sim,
				'switches' => array(
					'display_mode' => (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN ),
					'public_names' => (string) get_option( 'psr_public_names', 'no' ),
					'note'         => (string) get_option( 'psr_provider_note', '' ),
					'can_see'      => current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'not staff',
				),
				'wired'    => array(
					'after_shipping_rate' => (bool) has_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'noop' ) ) || (bool) has_action( 'woocommerce_after_shipping_rate', array( 'PSR_Pipeline', 'breakdown' ) ),
				),
				'rates'    => $report,
			)
		);
	}

	/**
	 * Placeholder so has_action() can report a tag this plugin owns.
	 *
	 * @return void
	 */
	public static function noop() {}

	/**
	 * GET /detect?country=US - the suppliers' own claims, as a review table.
	 */
	public static function rest_detect( $request ) {
		$report = PSR_Detect::report( (string) $request->get_param( 'country' ) );
		return rest_ensure_response( array( 'ok' => true, 'report' => $report ) );
	}

	/**
	 * GET /quote?items=12:1,15:1&country=US - what the registry says this cart costs.
	 */
	public static function rest_quote( $request ) {
		$items = (string) $request->get_param( 'items' );
		$spec  = array();
		foreach ( preg_split( '/[,; ]+/', $items ) as $bit ) {
			if ( '' !== trim( $bit ) ) {
				$spec[] = trim( $bit );
			}
		}

		$package = PSR_Selftest::package( $spec );
		if ( ! $package ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'no usable items in "items" (product_id:qty)' ) );
		}
		$package['destination']['country']  = PSR_Registry::country( (string) $request->get_param( 'country' ) );
		$package['destination']['state']    = (string) $request->get_param( 'state' );
		$package['destination']['postcode'] = (string) $request->get_param( 'postcode' );

		$quote = PSR_Quote::quote_package( $package );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'destination' => $package['destination'],
				'items'     => $spec,
				'quote'     => $quote,
				'breakdown' => PSR_Quote::breakdown( $quote ),
				'guard'     => PSR_Guard::decide( true, array_values( array_filter( array( $quote['action'] === 'allow' ? array( 'psr' ) : array() ) ) ), array( 'psr' ) ),
			)
		);
	}

	/**
	 * POST /providers - create or update one provider row.
	 */
	public static function rest_providers( $request ) {
		$row = PSR_Registry::clean(
			array(
				'slug'      => $request->get_param( 'slug' ),
				'label'     => $request->get_param( 'label' ),
				'driver'    => $request->get_param( 'driver' ),
				'method_id' => $request->get_param( 'method_id' ),
				'instance'  => $request->get_param( 'instance' ),
				'handling'  => $request->get_param( 'handling' ),
				'uplift'    => $request->get_param( 'uplift' ),
				'floor'     => $request->get_param( 'floor' ),
				'cap'       => $request->get_param( 'cap' ),
				'round'     => $request->get_param( 'round' ),
				'classes'   => $request->get_param( 'classes' ),
				'enabled'   => $request->get_param( 'enabled' ),
				'visible'   => $request->get_param( 'visible' ),
			)
		);

		if ( '' === $row['slug'] ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'slug required' ) );
		}

		PSR_Registry::save( $row );

		return rest_ensure_response(
			array(
				'ok'    => true,
				'row'   => $row,
				'state' => self::provider_state( $row['slug'] ),
			)
		);
	}

	/**
	 * POST /apply - write provider meta for the rows the detect screen proposed.
	 *
	 * rows = "product_id=provider,product_id=provider". confirm=yes is required, and the
	 * products involved are snapshotted first so /restore puts them back.
	 */
	public static function rest_apply( $request ) {
		if ( 'yes' !== (string) $request->get_param( 'confirm' ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'error'   => 'confirm=yes is required: this writes provider meta to products',
					'plan'    => self::plan( (string) $request->get_param( 'rows' ) ),
				)
			);
		}

		$picked = array();
		foreach ( self::plan( (string) $request->get_param( 'rows' ) ) as $row ) {
			$picked[ $row['product'] ] = $row['provider'];
		}

		$made = PSR_Detect::apply( $picked );

		return rest_ensure_response(
			array(
				'ok'     => true,
				'wrote'  => $made,
				'state'  => PSR_Registry::unmarked_products(),
			)
		);
	}

	/**
	 * POST /restore - put the snapshot back.
	 */
	public static function rest_restore( $request ) {
		if ( 'yes' !== (string) $request->get_param( 'confirm' ) ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'error'   => 'confirm=yes is required: this writes provider meta to products',
					'snapshot' => (array) get_option( 'psr_detect_snapshot', array() ),
				)
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'restored' => PSR_Detect::restore() ) );
	}

	/**
	 * "id=slug,id=slug" -> rows, validated against the registry. Nothing is written here.
	 *
	 * @param string $rows Spec.
	 * @return array
	 */
	protected static function plan( $rows ) {
		$plan = array();
		foreach ( preg_split( '/[,; ]+/', (string) $rows ) as $bit ) {
			$bit = trim( $bit );
			if ( '' === $bit ) {
				continue;
			}
			$bits    = explode( '=', $bit, 2 );
			$id      = (int) $bits[0];
			$slug    = PSR_Registry::alias( isset( $bits[1] ) ? $bits[1] : '' );
			$product = $id && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

			$plan[] = array(
				'product'  => $id,
				'name'     => $product instanceof WC_Product ? $product->get_name() : '',
				'provider' => $slug,
				'known'    => (bool) PSR_Registry::get( $slug ),
				'now'      => $product instanceof WC_Product ? PSR_Registry::provider_for( $product ) : '',
				'variations' => $product instanceof WC_Product ? count( (array) $product->get_children() ) : 0,
			);
		}
		return $plan;
	}

	/**
	 * @param string $slug Provider slug.
	 * @return array
	 */
	protected static function provider_state( $slug ) {
		$row = PSR_Registry::get( $slug );
		if ( ! $row ) {
			return array();
		}
		$insts = PSR_Registry::method_instances( $row['method_id'] );
		$found = array();
		foreach ( $insts as $zone_id => $per_zone ) {
			foreach ( $per_zone as $instance_id => $label ) {
				$found[] = (int) $zone_id . '#' . (int) $instance_id;
			}
		}
		return array(
			'slug'      => $row['slug'],
			'driver'    => $row['driver'],
			'method_id' => $row['method_id'],
			'instances' => $found,
			'enabled'   => $row['enabled'],
		);
	}

	/**
	 * Where a Provider Shipping instance sits.
	 *
	 * @return array
	 */
	protected static function psr_instances() {
		$out = array();
		foreach ( PSR_Registry::method_instances( 'psr_shipping' ) as $zone_id => $per_zone ) {
			foreach ( $per_zone as $instance_id => $label ) {
				$out[] = 'zone ' . (int) $zone_id . ' instance ' . (int) $instance_id;
			}
		}
		return $out;
	}
}
