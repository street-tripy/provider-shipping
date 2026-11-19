<?php
/**
 * The provider registry.
 *
 * A provider is one row: a label, how it gets priced, and the margin policy applied on top
 * of that price. This is the whole point of the plugin - adding a supplier is a row here,
 * not a code change.
 *
 * Drivers
 * -------
 *   method  - an existing WooCommerce shipping method instance prices this provider's goods.
 *             This is the normal case and it needs no supplier-specific code:
 *             `printify_shipping` (Printify's own plugin) quotes Printify items,
 *             `tapstitch_shipping` quotes apparel, `flat_rate` prices in-house goods.
 *   fixed   - a flat amount, typed by the site owner.
 *
 * Product meta
 * ------------
 * `_psr_provider` on a variation, inherited from the parent when the variation has none.
 * Any additional meta key a store already carries is added through the
 * `psr_provider_meta_keys` filter and read as an alias, so a store migrating from another
 * plugin needs no data migration.
 *
 * Options
 * -------
 *   psr_providers        slug => row
 *   psr_display_mode     merged_hidden | merged_admin | merged_public | per_provider
 *   psr_show_provider    hidden | admin | public  - the line on the product page
 *   psr_public_names     yes/no - may supplier names reach a customer at all
 *   psr_provider_note    the public sentence (names nobody by default)
 *   psr_default_provider provider for goods with no declaration (default in-house)
 *   psr_pipeline_mode    off | shadow | live
 *   psr_block_no_quote   yes/no, psr_no_quote_message text
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Registry {

	const META_KEY    = '_psr_provider';
	const OPTION      = 'psr_providers';

	const DISPLAY_MERGED_HIDDEN = 'merged_hidden';
	const DISPLAY_MERGED_ADMIN  = 'merged_admin';
	const DISPLAY_MERGED_PUBLIC = 'merged_public';
	const DISPLAY_PER_PROVIDER  = 'per_provider';

	const SHOW_HIDDEN = 'hidden';
	const SHOW_ADMIN  = 'admin';
	const SHOW_PUBLIC = 'public';

	public static function display_modes() {
		return array(
			self::DISPLAY_MERGED_HIDDEN => __( 'One merged rate, providers hidden', 'provider-shipping' ),
			self::DISPLAY_MERGED_ADMIN  => __( 'One merged rate, supplier sub-totals for staff only', 'provider-shipping' ),
			self::DISPLAY_MERGED_PUBLIC => __( 'One merged rate, plus a public "separate parcels" note', 'provider-shipping' ),
			self::DISPLAY_PER_PROVIDER  => __( 'One shipping choice per provider', 'provider-shipping' ),
		);
	}

	public static function show_modes() {
		return array(
			self::SHOW_HIDDEN => __( 'Hidden', 'provider-shipping' ),
			self::SHOW_ADMIN  => __( 'Staff only', 'provider-shipping' ),
			self::SHOW_PUBLIC => __( 'Everyone', 'provider-shipping' ),
		);
	}

	/**
	 * @return array slug => label, for selects
	 */
	public static function provider_labels() {
		$out = array();
		foreach ( self::all() as $slug => $row ) {
			$out[ $slug ] = $row['label'];
		}
		ksort( $out );
		return $out;
	}

	public static function rounds() {
		return array(
			'nearest99' => __( 'nearest .99, never below cost', 'provider-shipping' ),
			'up99'      => __( 'up to the next .99', 'provider-shipping' ),
			'int99'     => __( 'whole number minus .01', 'provider-shipping' ),
			'none'      => __( 'no rounding', 'provider-shipping' ),
		);
	}

	/**
	 * The registry this store starts with. Every value here is measured on the live store,
	 * not invented: 44 sticker SKUs are claimed by Printify's own endpoint, 25 apparel SKUs
	 * are not, Printful claims nothing, and the Key Tag has no SKU to claim at all.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'tapstitch' => self::row(
				'tapstitch',
				'Tapstitch',
				array(
					'driver'      => 'method',
					'method_id'   => 'tapstitch_shipping',
					'lane'        => 'Special Line',
					'fulfillment' => 'International',
					'handling'    => '1.50',
					'classes'     => "hoodies=hoodies\nsweatpants=sweatpants\nt-shirts=t-shirts\nstickers=stickers\nkeytag=keytag\n_default=hoodies",
					'visible'     => 'no',
					'enabled'     => 'yes',
				)
			),
			'printify' => self::row(
				'printify',
				'Printify',
				array(
					'driver'    => 'printify-api',
					'method_id' => 'printify_shipping',
					'lane'      => 'standard',
					'handling'  => '1.50',
					'classes'   => "stickers=stickers",
					'visible'   => 'no',
					'enabled'   => 'yes',
				)
			),
			'printful' => self::row(
				'printful',
				'Printful',
				array(
					'driver'    => 'method',
					'method_id' => 'printful_shipping',
					'lane'      => 'standard',
					'handling'  => '0',
					'fallback'  => 'block',
					'visible'   => 'no',
					'enabled'   => 'no',
				)
			),
			'in-house' => self::row(
				'in-house',
				'In-house',
				array(
					'driver'    => 'method',
					'method_id' => 'flat_rate',
					'handling'  => '0',
					'fallback'  => 'block',
					'visible'   => 'no',
					'enabled'   => 'yes',
				)
			),
		);
	}

	/**
	 * Build one complete row from partial input.
	 *
	 * @param string $slug Provider slug.
	 * @param string $label Label.
	 * @param array  $over Overrides.
	 * @return array
	 */
	public static function row( $slug, $label, $over = array() ) {
		$base = array(
			'slug'        => $slug,
			'label'       => $label,
			'driver'      => 'method',
			'method_id'   => '',
			'instance'    => 0,
			'lane'        => '',
			'fulfillment' => '',
			'handling'    => '',
			'uplift'      => '',
			'floor'       => '',
			'cap'         => '',
			'round'       => 'nearest99',
			'fixed_cost'  => '',
			'classes'     => '',
			'countries'   => array(),
			'serves_all'  => 'yes',
			'ttl'         => 900,
			'fallback'    => 'fixed',
			'enabled'     => 'yes',
			'visible'     => 'no',
		);
		return self::clean( array_merge( $base, (array) $over ) );
	}

	/**
	 * Normalise: every key present, sane types, known driver and rounding.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
public static function clean( $row ) {
		$row = is_array( $row ) ? $row : array();

		$out = array(
			'slug'        => self::slug( isset( $row['slug'] ) ? $row['slug'] : '' ),
			'label'       => isset( $row['label'] ) ? trim( (string) wc_clean( $row['label'] ) ) : '',
			'driver'      => isset( $row['driver'] ) ? (string) $row['driver'] : 'method',
			'method_id'   => isset( $row['method_id'] ) ? sanitize_key( $row['method_id'] ) : '',
			'instance'    => isset( $row['instance'] ) ? absint( $row['instance'] ) : 0,
			'lane'        => isset( $row['lane'] ) ? (string) wc_clean( $row['lane'] ) : '',
			'fulfillment' => isset( $row['fulfillment'] ) ? (string) wc_clean( $row['fulfillment'] ) : '',
			'handling'    => self::money( isset( $row['handling'] ) ? $row['handling'] : '' ),
			'uplift'      => self::money( isset( $row['uplift'] ) ? $row['uplift'] : '' ),
			'floor'       => self::money( isset( $row['floor'] ) ? $row['floor'] : '' ),
			'cap'         => self::money( isset( $row['cap'] ) ? $row['cap'] : '' ),
			'round'       => isset( $row['round'] ) ? (string) $row['round'] : 'nearest99',
			'fixed_cost'  => self::money( isset( $row['fixed_cost'] ) ? $row['fixed_cost'] : '' ),
			'classes'     => isset( $row['classes'] ) ? (string) $row['classes'] : '',
			'countries'   => array(),
			'serves_all'  => ( isset( $row['serves_all'] ) && 'no' === $row['serves_all'] ) ? 'no' : 'yes',
			'ttl'         => isset( $row['ttl'] ) ? max( 0, absint( $row['ttl'] ) ) : 900,
			'fallback'    => ( isset( $row['fallback'] ) && 'block' === $row['fallback'] ) ? 'block' : 'fixed',
			'enabled'     => ( isset( $row['enabled'] ) && 'no' === $row['enabled'] ) ? 'no' : 'yes',
			'visible'     => ( isset( $row['visible'] ) && 'yes' === $row['visible'] ) ? 'yes' : 'no',
		);

		if ( isset( $row['countries'] ) ) {
			$list = is_array( $row['countries'] ) ? $row['countries'] : preg_split( '/[, ]+/', (string) $row['countries'] );
			foreach ( (array) $list as $c ) {
				$c = self::country( $c );
				if ( 2 === strlen( $c ) ) {
					$out['countries'][] = $c;
				}
			}
			$out['countries'] = array_values( array_unique( $out['countries'] ) );
		}

		if ( '' === $out['label'] ) {
			$out['label'] = $out['slug'];
		}
		// 'tapstitch-api' is accepted as a synonym of the Tapstitch method driver, so an
		// existing config that names it keeps working.
		if ( 'tapstitch-api' === $out['driver'] ) {
			$out['driver']    = 'method';
			$out['method_id'] = '' !== $out['method_id'] ? $out['method_id'] : 'tapstitch_shipping';
		}
		if ( ! in_array( $out['driver'], array( 'method', 'printify-api', 'fixed' ), true ) ) {
			$out['driver'] = 'method';
		}
		if ( 'method' === $out['driver'] && '' === $out['method_id'] ) {
			$out['enabled'] = 'no'; // a driver with no method cannot price anything: say so.
		}
		if ( ! isset( self::rounds()[ $out['round'] ] ) ) {
			$out['round'] = 'nearest99';
		}
		return $out;
	}

	/**
	 * @return array slug => row
	 */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$rows = self::defaults();
		}
		$out = array();
		foreach ( $rows as $slug => $row ) {
			$row            = self::clean( (array) $row );
			$row['slug']    = self::slug( $slug );
			$out[ $row['slug'] ] = $row;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @return array enabled rows only
	 */
	public static function enabled() {
		$out = array();
		foreach ( self::all() as $slug => $row ) {
			if ( 'no' !== $row['enabled'] ) {
				$out[ $slug ] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param string $slug Provider slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$slug = self::slug( $slug );
		$rows = self::all();
		return isset( $rows[ $slug ] ) ? $rows[ $slug ] : null;
	}

	/**
	 * @param array $row Row to store.
	 * @return array|WP_Error
	 */
	public static function save( $row ) {
		$row = self::clean( (array) $row );
		if ( '' === $row['slug'] ) {
			return new WP_Error( 'psr_bad_slug', __( 'A provider needs a slug.', 'provider-shipping' ) );
		}
		$rows = self::all();
		$rows[ $row['slug'] ] = $row;
		update_option( self::OPTION, $rows );
		return $row;
	}

	/**
	 * Deleting a provider never edits a product: products that named it fall back to the
	 * site default and the unmarked report lists them.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function delete( $slug ) {
		$slug = self::slug( $slug );
		$rows = self::all();
		if ( ! isset( $rows[ $slug ] ) ) {
			return false;
		}
		unset( $rows[ $slug ] );
		update_option( self::OPTION, $rows );
		return true;
	}

	/**
	 * The provider a product declares (variation first, then the parent). '' when it declares
	 * nothing - which is not the same as "in-house": the default provider is applied by
	 * pricing_provider_for(), and the unmarked report lists these products.
	 *
	 * @param WC_Product|int $product Product or id.
	 * @return string
	 */
	public static function provider_for( $product ) {
		$product = self::product( $product );
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$slug = self::meta_slug( $product );
		if ( '' !== $slug ) {
			return $slug;
		}

		$parent_id = (int) $product->get_parent_id();
		$parent    = $parent_id ? self::product( $parent_id ) : null;
		if ( $parent instanceof WC_Product ) {
			return self::meta_slug( $parent );
		}
		return '';
	}

	/**
	 * The provider used to price a product, with the fallback resolved.
	 *
	 * @param WC_Product|int $product Product or id.
	 * @return array slug|label|via
	 */
	public static function pricing_provider_for( $product ) {
		$product = self::product( $product );
		$slug    = self::provider_for( $product );
		$row     = $slug ? self::get( $slug ) : null;

		if ( $row && 'no' !== $row['enabled'] ) {
			return array(
				'slug'  => $row['slug'],
				'label' => $row['label'],
				'via'   => 'meta',
			);
		}

		$default_slug = self::slug( (string) get_option( 'psr_default_provider', 'in-house' ) );
		$default      = self::get( $default_slug );
		if ( ! $default ) {
			$default = self::get( 'in-house' );
		}
		if ( ! $default ) {
			return array(
				'slug'  => 'in-house',
				'label' => 'In-house',
				'via'   => 'no registry row at all',
			);
		}

		return array(
			'slug'  => $default['slug'],
			'label' => $default['label'],
			'via'   => '' === $slug ? 'no declaration -> site default' : 'meta ' . $slug . ' is disabled or unknown -> site default',
		);
	}

	/**
	 * Class slugs this provider claims, from its `classes` map: one `slug=class` per line.
	 *
	 * @param array $row Provider row.
	 * @return array class_slug => class_slug
	 */
	public static function class_map( $row ) {
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $row['classes'] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$bits = explode( '=', $line, 2 );
			$key  = strtolower( trim( $bits[0] ) );
			$val  = isset( $bits[1] ) ? strtolower( trim( $bits[1] ) ) : '';
			if ( '' !== $key && '' !== $val ) {
				$map[ $key ] = $val;
			}
		}
		return $map;
	}

	/**
	 * @param array  $row     Provider row.
	 * @param string $country Country code.
	 * @return bool
	 */
	public static function serves( $row, $country ) {
		$country = self::country( $country );
		if ( '' === $country || 'yes' === $row['serves_all'] || empty( $row['countries'] ) ) {
			return true;
		}
		return in_array( $country, $row['countries'], true );
	}

	/* maths --------------------------------------------------------------------- */

	/**
	 * cost -> + handling -> + uplift% -> floor -> cap -> rounding.
	 *
	 * This is the "never lose money, stay fair" rule in one function, and the order of the
	 * clamps is the point:
	 *
	 *   min = max( driver cost, floor )  - a floor is a policy minimum, the supplier's cost is
	 *                                      a hard one. Charging under the cost is the loss the
	 *                                      store is trying to stop, so rounding and caps are
	 *                                      never allowed to go under it.
	 *   cap = policy maximum, applied after rounding - and if a cap sits below the cost, the
	 *         cost wins. A cap that makes the store lose money is a bug, not a feature.
	 *
	 * @param float $cost Driver's cost, in the store's base currency.
	 * @param array $row  Provider row.
	 * @return float
	 */
	public static function apply_margin( $cost, $row ) {
		$cost = (float) $cost;
		$raw  = $cost + (float) $row['handling'];

		if ( (float) $row['uplift'] > 0 ) {
			$raw += $raw * ( (float) $row['uplift'] / 100 );
		}

		$floor = (float) $row['floor'];
		if ( $floor > 0 && $raw < $floor ) {
			$raw = $floor;
		}

		$min = $cost > $floor ? $cost : $floor;
		$out = self::round_price( $raw, $row['round'], $min );

		$cap = (float) $row['cap'];
		if ( $cap > 0 && $out > $cap ) {
			$out = $cap;
		}
		if ( $out < $min ) {
			$out = self::round_price( $min, $row['round'], $min );
		}

		return round( $out, 2 );
	}

	/**
	 * Rounding to a .99 price, never below $never_below.
	 *
	 * @param float $raw          Amount.
	 * @param string $mode        nearest99|up99|int99|none.
	 * @param float  $never_below Floor.
	 * @return float
	 */
	public static function round_price( $raw, $mode, $never_below = 0 ) {
		$raw  = (float) $raw;
		$base = (float) $never_below;

		switch ( $mode ) {
			case 'none':
				$out = round( $raw, 2 );
				return $out < $base ? round( $base, 2 ) : $out;

			case 'int99': {
				$floor = (int) floor( $raw );
				$out   = $floor - 1 + 0.99;
				if ( $out < $base ) {
					$out = $floor + 0.99;
				}
				if ( $out < $base ) {
					$out = $base;
				}
				return round( $out, 2 );
			}

			case 'up99': {
				$out = (int) floor( $raw ) + 0.99;
				if ( $raw > $out ) {
					$out += 1;
				}
				return $out < $base ? round( $base + 0.99, 2 ) : round( $out, 2 );
			}

			default: { // nearest99
				$floor = (int) floor( $raw );
				$low   = $floor - 1 + 0.99;
				$high  = $floor + 0.99;
				if ( $raw > $high ) {
					$low  = $high;
					$high = $floor + 1 + 0.99;
				}
				$out = ( $raw - $low ) <= ( $high - $raw ) ? $low : $high;
				if ( $out < 0 ) {
					$out = 0.99;
				}
				if ( $out < $base ) {
					$out = $high;
				}
				return round( $out, 2 );
			}
		}
	}

	/**
	 * The checkout label for a merged cart. Names are only used when the site allows them, so
	 * "protect the supplier setup from copies" is the default at every layer.
	 *
	 * @param array $labels Provider labels, in cart order.
	 * @param array $opts  { mode, public_names }.
	 * @return string
	 */
	public static function merged_label( $labels, $opts = array() ) {
		$mode   = isset( $opts['mode'] ) ? $opts['mode'] : self::DISPLAY_MERGED_HIDDEN;
		$public = isset( $opts['public_names'] ) && 'yes' === $opts['public_names'];
		$labels = array_values( array_unique( (array) $labels ) );

		if ( 1 === count( $labels ) ) {
			return $public ? (string) $labels[0] : __( 'Shipping', 'provider-shipping' );
		}

		if ( $public && self::DISPLAY_PER_PROVIDER === $mode ) {
			return implode( ' + ', $labels );
		}
		if ( $public ) {
			/* translators: %s: provider labels */
			return sprintf( __( 'Shipping (%s)', 'provider-shipping' ), implode( ', ', $labels ) );
		}
		/* translators: %d: number of providers */
		return sprintf( __( 'Shipping (%d providers)', 'provider-shipping' ), count( $labels ) );
	}

	/**
	 * Products with no provider declaration - the list to clear.
	 *
	 * @return array
	 */
	public static function unmarked_products() {
		$out = array();
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $out;
		}
		foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects' ) ) as $product ) {
			if ( '' !== self::provider_for( $product ) ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $product->get_id(),
				'name'   => (string) $product->get_name(),
				'type'   => (string) $product->get_type(),
				'sku'    => (string) $product->get_sku(),
				'class'  => (string) $product->get_shipping_class(),
				'parent' => (int) $product->get_parent_id(),
			);
		}
		return $out;
	}

	/**
	 * Shipping method classes registered on this store: id => class, without building any of
	 * them.
	 *
	 * Read from the `woocommerce_shipping_methods` filter. The tempting accessor,
	 * WC_Shipping::get_shipping_methods(), constructs every method class - and Printify's and
	 * Printful's constructors register hooks and expect a settings screen. That is the trap
	 * documented in tapstitch-shipping-method, and the reason a settings or REST request can
	 * fatal. Nothing in this plugin builds a method it is not pricing.
	 *
	 * @return array method_id => class
	 */
	public static function method_classes() {
		$classes = (array) apply_filters( 'woocommerce_shipping_methods', array() );
		$known   = array(
			'flat_rate'          => 'WC_Shipping_Flat_Rate',
			'free_shipping'      => 'WC_Shipping_Free_Shipping',
			'local_pickup'       => 'WC_Shipping_Local_Pickup',
			'table_rate'         => 'WC_Shipping_Table_Rate',
			'tapstitch_shipping' => 'WC_Shipping_Tapstitch',
			'psr_shipping'       => 'PSR_Method',
			// The supplier plugins register their method class from woocommerce_shipping_init,
			// so on an early request the filter above does not list them yet. These are the
			// class names their own plugins use (read from the plugins' source).
			'printify_shipping'  => 'Printify_Shipping_Method',
			'printful_shipping'  => 'Printful_Shipping',
		);
		foreach ( $known as $id => $class ) {
			if ( ! isset( $classes[ $id ] ) && class_exists( $class ) ) {
				$classes[ $id ] = $class;
			}
		}
		ksort( $classes );
		return $classes;
	}

	/**
	 * Shipping method ids available on this store, for the driver picker.
	 *
	 * @return array id => title
	 */
	public static function method_choices() {
		$out = array( '' => __( '— none —', 'provider-shipping' ) );
		foreach ( self::method_classes() as $id => $class ) {
			if ( '' === $id || ! class_exists( $class ) ) {
				continue;
			}
			$vars   = (array) get_class_vars( $class );
			$title  = isset( $vars['method_title'] ) ? (string) $vars['method_title'] : '';
			$out[ $id ] = ( '' !== $title ? $title : self::method_label( $id ) ) . ' (' . $id . ')';
		}
		ksort( $out );
		return $out;
	}

	/**
	 * A readable name for a method id, without building the method.
	 *
	 * @param string $id Method id.
	 * @return string
	 */
	public static function method_label( $id ) {
		$labels = array(
			'flat_rate'          => 'Flat rate',
			'free_shipping'      => 'Free shipping',
			'local_pickup'       => 'Local pickup',
			'table_rate'         => 'Table rate',
			'tapstitch_shipping' => 'Tapstitch Shipping',
			'printify_shipping'  => 'Printify',
			'printful_shipping'  => 'Printful',
			'psr_shipping'       => 'Provider Shipping',
		);
		return isset( $labels[ $id ] ) ? $labels[ $id ] : ucwords( str_replace( '_', ' ', $id ) );
	}

	/**
	 * Instances of a method per zone, for the driver picker.
	 *
	 * WooCommerce's public zone accessor builds every method in the zone; the instance list is
	 * read from its tables instead - read-only, and stable across WooCommerce versions.
	 *
	 * @param string $method_id Method id.
	 * @return array zone_id => instance_id => label
	 */
	public static function method_instances( $method_id ) {
		$out = array();
		if ( '' === $method_id ) {
			return $out;
		}
		foreach ( self::zone_rows() as $zone ) {
			$zone_id = (int) $zone['id'];
			foreach ( self::zone_method_rows( $zone_id ) as $row ) {
				if ( $method_id === $row['method_id'] && $row['instance_id'] > 0 ) {
					$out[ $zone_id ][ $row['instance_id'] ] =
						self::method_label( $method_id ) . ' #' . $row['instance_id'] . ( $row['enabled'] ? '' : ' - disabled' );
				}
			}
		}
		return $out;
	}

	/**
	 * Every shipping zone, zone 0 included (WooCommerce's get_zones() omits it, and zone 0 is
	 * where the catch-all Flat Rate lives).
	 *
	 * @return array[] id|name
	 */
	public static function zone_rows() {
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		foreach ( (array) $wpdb->get_results(
			"SELECT zone_id AS id, zone_name AS name FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_id ASC" // phpcs:ignore
		) as $row ) {
			$out[] = array(
				'id'   => (int) $row->id,
				'name' => (string) $row->name,
			);
		}
		return $out;
	}

	/**
	 * A zone's method rows.
	 *
	 * @param int $zone_id Zone id.
	 * @return array[] method_id|instance_id|enabled
	 */
	public static function zone_method_rows( $zone_id ) {
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT method_id, instance_id, is_enabled FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE zone_id = %d ORDER BY method_order ASC", // phpcs:ignore
				(int) $zone_id
			)
		);
		foreach ( (array) $rows as $row ) {
			if ( empty( $row->method_id ) ) {
				continue;
			}
			$out[] = array(
				'method_id'   => (string) $row->method_id,
				'instance_id' => (int) $row->instance_id,
				'enabled'     => ! empty( $row->is_enabled ),
			);
		}
		return $out;
	}

	/* helpers ------------------------------------------------------------------- */

	/**
	 * @param mixed $product Product, id, or anything.
	 * @return WC_Product|null
	 */
	protected static function product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			return wc_get_product( (int) $product );
		}
		return null;
	}

	/**
	 * Every product meta key read as a provider declaration.
	 *
	 * `_psr_provider` is the plugin's own key. A store that already has the provider in a
	 * differently-named meta key adds it here and needs no migration:
	 *
	 *   add_filter(
	 *       'psr_provider_meta_keys',
	 *       function ( $keys ) {
	 *           $keys[] = 'legacy_provider_key';
	 *           return $keys;
	 *       }
	 *   );
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		$keys = array( self::META_KEY );

		foreach ( (array) apply_filters( 'psr_provider_meta_keys', array() ) as $key ) {
			if ( is_string( $key ) && '' !== trim( $key ) ) {
				$keys[] = trim( $key );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * @param WC_Product $product Product.
	 * @return string slug ('' when nothing usable is declared)
	 */
	protected static function meta_slug( $product ) {
		foreach ( self::meta_keys() as $key ) {
			$value = $product->get_meta( $key, true );
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return self::alias( $value );
			}
		}
		return '';
	}

	/**
	 * The store's own words for "I ship this myself" all mean one driver.
	 *
	 * @param string $value Declared value.
	 * @return string
	 */
	public static function alias( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$map   = array(
			'in-house'  => 'in-house',
			'inhouse'   => 'in-house',
			'self'      => 'in-house',
			'own'       => 'in-house',
			'site'      => 'in-house',
			'flat'      => 'in-house',
			'flat-rate' => 'in-house',
			'fixed'     => 'in-house',
			'none'      => '',
		);
		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}
		return self::slug( $value );
	}

	public static function slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		$slug = preg_replace( '/[^a-z0-9_\-]/', '-', $slug );
		$slug = trim( preg_replace( '/-+/', '-', (string) $slug ), '-' );
		return substr( (string) $slug, 0, 40 );
	}

	public static function country( $c ) {
		$c = strtoupper( trim( (string) $c ) );
		return ( 2 === strlen( $c ) ) ? $c : ( substr( $c, 0, 2 ) );
	}

	/**
	 * @param mixed $v Money-ish input.
	 * @return string normalized amount, '' for empty
	 */
	protected static function money( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$v = (float) str_replace( array( ',', '$', ' ' ), '', $v );
		return $v > 0 ? number_format( $v, 2, '.', '' ) : '';
	}
}
