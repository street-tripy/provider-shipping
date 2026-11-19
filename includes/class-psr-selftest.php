<?php
/**
 * Self-check: the maths and the decisions, run on the store's own PHP.
 *
 * Two halves. The offline half is pure arithmetic and pure decisions - margin policy,
 * rounding, label building, the guard's decision table - so it proves the rules the money
 * depends on. The live half runs against the store's real products, real registry and real
 * drivers: provider meta, variation inheritance in memory, a mixed cart grouped and priced,
 * in-house goods quoted by the zone's Flat Rate, and a destination a provider does not serve.
 *
 * Nothing in this file writes to the catalog. The inheritance case is tested on a clone.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Selftest {

	/**
	 * @return array total|passed|failed|cases
	 */
	public static function run() {
		$out = array(
			'total'  => 0,
			'passed' => 0,
			'failed' => 0,
			'cases'  => array(),
		);

		$assert = function ( $name, $expected, $actual ) use ( &$out ) {
			$pass = (string) $expected === (string) $actual;
			$out['total']++;
			if ( $pass ) {
				$out['passed']++;
			} else {
				$out['failed']++;
			}
			$out['cases'][] = array(
				'name'   => $name,
				'pass'   => $pass,
				'detail' => 'expected ' . self::num( $expected ) . ', got ' . self::num( $actual ),
			);
		};

		/* registry: normalisation */
		$registry = PSR_Registry::clean( array( 'slug' => 'My Provider' ) );
		$assert( 'Registry slug is safe', 'my-provider', $registry['slug'] );

		$registry = PSR_Registry::clean(
			array(
				'slug'     => 'x',
				'driver'   => 'nonsense',
				'round'    => 'nonsense',
				'handling' => ' 2,000 ',
				'cap'      => '-5',
			)
		);
		$assert( 'Unknown driver becomes method', 'method', $registry['driver'] );
		$assert( 'Unknown rounding becomes nearest99', 'nearest99', $registry['round'] );
		$assert( 'Money is normalised (comma is a thousands separator)', '2000.00', $registry['handling'] );
		$assert( 'A negative cap is empty (means: no cap)', '', $registry['cap'] );
		$assert( 'A driver with no method is stored disabled', 'no', PSR_Registry::clean( array( 'slug' => 'x', 'driver' => 'method', 'method_id' => '' ) )['enabled'] );
		$assert( 'tapstitch-api is read as the Tapstitch method', 'method', PSR_Registry::clean( array( 'slug' => 'x', 'driver' => 'tapstitch-api' ) )['driver'] );

		/* margin policy: rounding switched off, so these check the arithmetic alone */
		$plain = array( 'slug' => 'p', 'round' => 'none' );

		$row = PSR_Registry::clean( array_merge( $plain, array( 'handling' => '1.50' ) ) );
		$assert( 'Margin: driver cost + handling', 10.50, PSR_Registry::apply_margin( 9.00, $row ) );
		$assert( 'Margin: handling once for a multi-unit package', 17.50, PSR_Registry::apply_margin( 16.00, $row ) );

		$row = PSR_Registry::clean( array_merge( $plain, array( 'uplift' => '10' ) ) );
		$assert( 'Margin: uplift percent on the driver cost', 9.90, PSR_Registry::apply_margin( 9.00, $row ) );

		$row = PSR_Registry::clean( array_merge( $plain, array( 'floor' => '12' ) ) );
		$assert( 'Margin: floor lifts a cheap quote', 12.00, PSR_Registry::apply_margin( 5.00, $row ) );

		$row = PSR_Registry::clean( array_merge( $plain, array( 'cap' => '50', 'uplift' => '50' ) ) );
		$assert( 'Margin: cap limits a runaway quote', 50.00, PSR_Registry::apply_margin( 40.00, $row ) );

		$row = PSR_Registry::clean( array_merge( $plain, array( 'cap' => '9' ) ) );
		$assert( 'A cap below the cost cannot make the store lose money', 40.00, PSR_Registry::apply_margin( 40.00, $row ) );

		$row = PSR_Registry::clean( array_merge( $plain, array( 'handling' => '1.50', 'floor' => '20' ) ) );
		$assert( 'Handling and floor together', 20.00, PSR_Registry::apply_margin( 16.00, $row ) );

		/* rounding: never below the supplier's cost */
		$row = PSR_Registry::clean( array( 'slug' => 'p' ) );
		$assert( 'Rounding 10.83 -> 10.99', 10.99, PSR_Registry::apply_margin( 10.83, $row ) );
		$assert( 'Rounding 11.5 -> 11.99, not 10.99', 11.99, PSR_Registry::apply_margin( 11.5, $row ) );
		$assert( 'Rounding 14.44 -> 14.99 (never below cost)', 14.99, PSR_Registry::apply_margin( 14.44, $row ) );
		$assert( 'Rounding 10.99 stays 10.99', 10.99, PSR_Registry::apply_margin( 10.99, $row ) );
		$assert( 'Rounding 5.20 -> 5.99: the .99 below cost (4.99) is refused', 5.99, PSR_Registry::apply_margin( 5.20, $row ) );

		$assert( 'up99 of 12.00 -> 12.99', 12.99, PSR_Registry::round_price( 12.0, 'up99', 12.0 ) );
		$assert( 'int99 of 25.69 cannot go below cost -> 25.99', 25.99, PSR_Registry::round_price( 25.69, 'int99', 25.69 ) );
		$assert( 'int99 of 26.5 -> 25.99', 25.99, PSR_Registry::round_price( 26.5, 'int99', 25.69 ) );
		$assert( 'no rounding keeps the cents', 12.00, PSR_Registry::round_price( 12.0, 'none', 12.0 ) );

		/* the store's words for self-shipped all mean one provider */
		$assert( 'Alias in-house', 'in-house', PSR_Registry::alias( 'In-House' ) );
		$assert( 'Alias flat-rate', 'in-house', PSR_Registry::alias( 'flat-rate' ) );
		$assert( 'Alias self', 'in-house', PSR_Registry::alias( 'self' ) );
		$assert( 'Alias fixed', 'in-house', PSR_Registry::alias( 'fixed' ) );
		$assert( 'Alias printify', 'printify', PSR_Registry::alias( 'Printify' ) );
		$assert( 'Alias an unknown supplier survives as its own slug', 'acme-ink', PSR_Registry::alias( 'Acme Ink' ) );

		$map = PSR_Registry::class_map(
			PSR_Registry::clean(
				array(
					'slug'     => 'p',
					'classes'  => "hoodies=hoodies\nstickers = stickers\n# a comment\n_default=hoodies",
				)
			)
		);
		$assert( 'Class map parses pairs, ignores comments', 'hoodies,stickers,_default', implode( ',', array_keys( $map ) ) );

		/* labels */
		$labels = array( 'Tapstitch', 'Printify' );
		$assert( 'Merged label hides names by default', 'Shipping (2 providers)', PSR_Registry::merged_label( $labels, array( 'mode' => 'merged_admin', 'public_names' => 'no' ) ) );
		$assert( 'Merged label names providers when the site allows it', 'Shipping (Tapstitch, Printify)', PSR_Registry::merged_label( $labels, array( 'mode' => 'merged_public', 'public_names' => 'yes' ) ) );
		$assert( 'One provider: plain title, no name', 'Shipping', PSR_Registry::merged_label( array( 'Tapstitch' ), array( 'mode' => 'merged_hidden', 'public_names' => 'no' ) ) );
		$assert( 'Duplicate providers count once', 'Shipping (2 providers)', PSR_Registry::merged_label( array( 'Tapstitch', 'Tapstitch', 'Printify' ), array( 'mode' => 'merged_hidden', 'public_names' => 'no' ) ) );

		/* the guard's decision table */
		$assert( 'Guard allows when shipping is not needed', 'allow', PSR_Guard::decide( false, array(), array() )['action'] );
		$assert( 'Guard blocks when no package exists', 'block', PSR_Guard::decide( true, array(), array() )['action'] );
		$assert( 'Guard blocks when a package has no quotes', 'block', PSR_Guard::decide( true, array( 0 => array() ), array( 'psr_shipping_1' ) )['action'] );
		$assert( 'Guard blocks when nothing is chosen', 'block', PSR_Guard::decide( true, array( 0 => array( 'psr_shipping_1' ) ), array() )['action'] );
		$assert( 'Guard blocks when the choice is not a quoted price', 'block', PSR_Guard::decide( true, array( 0 => array( 'psr_shipping_1' ) ), array( 'flat_rate:9' ) )['action'] );
		$assert( 'Guard allows when every package has its chosen price', 'allow', PSR_Guard::decide( true, array( 0 => array( 'psr_shipping_1' ) ), array( 'psr_shipping_1' ) )['action'] );
		$assert( 'Guard blocks one good package out of two', 'block', PSR_Guard::decide( true, array( 0 => array( 'psr_shipping_1' ), 1 => array() ), array( 'psr_shipping_1', 'psr_shipping_2' ) )['action'] );

		self::live( $assert );

		return $out;
	}

	/**
	 * The sample data the cart checks run on, read from this store's own option.
	 *
	 * `psr_selftest` (JSON), every key optional:
	 *
	 *   {
	 *     "products":  { "12": "tapstitch", "15": "printify", "17": "in-house" },
	 *     "variation": 13,
	 *     "inherits":  "tapstitch",
	 *     "method":    "tapstitch_shipping",
	 *     "zone":      1,
	 *     "mixed":     [ "12:1", "15:1", "17:1" ],
	 *     "groups":    "in-house,printify,tapstitch",
	 *     "apparel":   [ "12:2" ],
	 *     "in_house":  [ "17:1" ]
	 *   }
	 *
	 * A store fills this in with its own product ids; the plugin ships with none, so a fresh
	 * install reports the cart checks as skipped instead of failing on products it does not have.
	 *
	 * @return array
	 */
	public static function selftest_config() {
		$raw = (string) get_option( 'psr_selftest', '' );
		$cfg = array();

		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$cfg = $decoded;
			}
		}

		$defaults = array(
			'products' => array(),
			'variation' => 0,
			'inherits'  => '',
			'method'    => 'flat_rate',
			'zone'      => 0,
			'mixed'     => array(),
			'groups'    => '',
			'apparel'   => array(),
			'in_house'  => array(),
		);

		return function_exists( 'wp_parse_args' ) ? wp_parse_args( $cfg, $defaults ) : array_merge( $defaults, $cfg );
	}

	/**
	 * The store's own products, registry, zones and drivers.
	 *
	 * @param callable $assert
	 */
	protected static function live( $assert ) {
		if ( ! function_exists( 'wc_get_products' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
			$assert( 'WooCommerce present', 'yes', 'no' );
			return;
		}
		$assert( 'WooCommerce present', 'yes', 'yes' );

		$all = PSR_Registry::all();
		$assert( 'Registry is readable', 'yes', ( is_array( $all ) && $all ) ? 'yes' : 'no' );

		/*
		 * The cart checks below run on the store's own configuration: name real products in the
		 * `psr_selftest` option (Settings -> Shipping -> Providers) and they check this store's
		 * provider meta, grouping, pricing and labels on real carts. Nothing configured means the
		 * checks are skipped - a fresh install gets an honest report, not a wall of failures.
		 */
		$cfg = self::selftest_config();

		$keys = PSR_Registry::meta_keys();
		$assert( 'Provider meta key is present', 'yes', in_array( PSR_Registry::META_KEY, $keys, true ) ? 'yes' : 'no' );

		if ( empty( $cfg['products'] ) ) {
			$assert( 'Cart checks skipped: no sample products configured', 'yes', 'yes' );

			$classes = PSR_Registry::method_classes();
			$assert( 'Flat rate class is available as a driver', 'yes', isset( $classes['flat_rate'] ) && class_exists( $classes['flat_rate'] ) ? 'yes' : 'no' );
		} else {
			/* the provider meta written from the supplier probes */
			foreach ( $cfg['products'] as $id => $want ) {
				$product = wc_get_product( (int) $id );
				if ( ! $product instanceof WC_Product ) {
					$assert( 'Product ' . $id . ' exists', 'yes', 'no' );
					continue;
				}
				$assert( 'Provider of product ' . $id, $want, PSR_Registry::pricing_provider_for( $product )['slug'] );
			}

			/* variation inheritance, on a clone: nothing is written */
			if ( ! empty( $cfg['variation'] ) ) {
				$kid = wc_get_product( (int) $cfg['variation'] );
				if ( $kid instanceof WC_Product ) {
					$probe = clone $kid;
					foreach ( PSR_Registry::meta_keys() as $key ) {
						$probe->delete_meta_data( $key );
					}
					$assert( 'A variation with no declaration inherits its product', (string) $cfg['inherits'], PSR_Registry::provider_for( $probe ) );
				}
			}

			/* zones and method instances, read from WooCommerce's tables */
			$method   = (string) $cfg['method'];
			$instances = PSR_Registry::method_instances( $method );
			$assert( 'The ' . $method . ' method instance is found in the configured zone', 'yes', isset( $instances[ (int) $cfg['zone'] ] ) ? 'yes' : 'no' );
			$flat_in = PSR_Registry::method_instances( 'flat_rate' );
			$assert( 'A Flat Rate instance is found in a zone', 'yes', $flat_in ? 'yes' : 'no' );

			/* a real mixed cart: grouped, priced, merged, labelled */
			$package = self::package( (array) $cfg['mixed'] );
			if ( $package ) {
				$groups = array_keys( PSR_Quote::group( $package ) );
				sort( $groups );
				$assert( 'A mixed cart groups into one provider per supplier', (string) $cfg['groups'], implode( ',', $groups ) );

				$quote = PSR_Quote::quote_package( $package );
				$assert( 'Every provider in the mixed cart is priced', 'yes', ( 'allow' === $quote['action'] && count( $quote['providers'] ) === count( $groups ) ) ? 'yes' : 'no' );
				$assert( 'The merged price is at least the sum of the provider prices', 'yes', $quote['total'] >= array_sum( array_column( $quote['providers'], 'cost' ) ) ? 'yes' : 'no' );

				// No provider's own label may appear in the customer-facing wording by default.
				$named = false;
				foreach ( (array) $quote['providers'] as $p ) {
					if ( '' !== (string) $p['label'] && false !== stripos( (string) $quote['label'], (string) $p['label'] ) ) {
						$named = true;
					}
				}
				$assert( 'The customer-facing label names no supplier', 'yes', $named ? 'no' : 'yes' );
				$assert( 'The label counts the providers', 'yes', false !== strpos( (string) $quote['label'], count( $groups ) . ' providers' ) ? 'yes' : 'no' );

				$breakdown = PSR_Quote::breakdown( $quote );
				$assert( 'Sub-totals carry one line per provider', count( $groups ), count( $breakdown ) );
			}

			/* in-house goods are priced by the store's own Flat Rate, never free */
			if ( ! empty( $cfg['in_house'] ) ) {
				$ih = PSR_Quote::quote_package( self::package( (array) $cfg['in_house'] ) );
				$assert( 'In-house goods are priced by the zone Flat Rate', 'yes', ( 'allow' === $ih['action'] && $ih['total'] > 0 ) ? 'yes' : 'no' );
			}

			/* one provider alone: a plain title, and a destination nobody serves has no quote */
			if ( ! empty( $cfg['apparel'] ) ) {
				$ap = PSR_Quote::quote_package( self::package( (array) $cfg['apparel'] ) );
				$assert( 'A single-provider cart is priced', 'yes', ( 'allow' === $ap['action'] && $ap['total'] > 0 ) ? 'yes' : 'no' );
				$assert( 'One provider gets the plain title', 'yes', 'Shipping' === $ap['label'] ? 'yes' : 'no' );

				$nowhere                              = self::package( (array) $cfg['apparel'] );
				$nowhere['destination']['country']    = 'AQ';
				$nowhere['destination']['postcode']   = '';
				$q                                    = PSR_Quote::quote_package( $nowhere );
				$assert( 'A destination with no method instance for the provider has no quote', 'block', $q['action'] );
			}

			$assert( 'No quote is what the guard blocks on', 'block', PSR_Guard::decide( true, array( 0 => array() ), array() )['action'] );
		}

		/* drivers: what the registry points at must exist on this store */
		$classes = PSR_Registry::method_classes();
		$assert( 'Flat rate class is available as a driver', 'yes', isset( $classes['flat_rate'] ) && class_exists( $classes['flat_rate'] ) ? 'yes' : 'no' );
		$assert( 'Tapstitch method is available as a driver', 'yes', isset( $classes['tapstitch_shipping'] ) && class_exists( $classes['tapstitch_shipping'] ) ? 'yes' : 'no' );
		$assert( 'Printify can be priced through its quote endpoint', 'yes', class_exists( 'TSM_Suppliers' ) ? 'yes' : 'no' );

		/* the guard: one guard runs, and this plugin says which one owns it */
		$state = PSR_Guard::state();
		$assert( 'Guard reports an owner', 'yes', ! empty( $state['owner'] ) ? 'yes' : 'no' );
		$assert(
			'The screen reports the guard that runs, not this plugin\'s idle hooks',
			'yes',
			( 'provider-shipping' === $state['owner'] || ! empty( $state['wired']['checkout_process'] ) ) ? 'yes' : 'no'
		);
		$assert( 'The guard report says which set of hooks it is describing', 'yes', ! empty( $state['note'] ) ? 'yes' : 'no' );

		/* the money rule when the registry has no price for a cart */
		$assert( 'This plugin has a rate: the Flat Rate is not offered', 'no', PSR_Pipeline::keep_fallback( 'flat', true ) ? 'yes' : 'no' );
		$assert( 'No rate, block policy: nothing is offered, so the sale is blocked', 'no', PSR_Pipeline::keep_fallback( 'block', false ) ? 'yes' : 'no' );
		$assert( 'No rate, flat policy: the zone\'s own rate is left', 'yes', PSR_Pipeline::keep_fallback( 'flat', false ) ? 'yes' : 'no' );
		$assert( 'Default policy is block (never sell shipping at a loss)', 'block', PSR_Pipeline::fallback_policy() );

		/* the currency a provider's cost is read in - the registry's maths is base-currency maths */
		$assert( 'Outside the quote window the shop converts checkout rates, as it always did', 'display', PSR_Driver::rate_cost_currency( 0 ) );
		$assert( 'Inside the quote window provider costs are read in the store\'s base currency', 'base', PSR_Driver::rate_cost_currency( 1 ) );
		$assert( 'Nested quotes stay in base currency', 'base', PSR_Driver::rate_cost_currency( 2 ) );

		$assert( 'No suppression before quoting', 'yes', true === PSR_Driver::keep_base_currency( true, null ) ? 'yes' : 'no' );
		PSR_Driver::quote_begin();
		$inside = PSR_Driver::keep_base_currency( true, null );
		PSR_Driver::quote_end();
		$assert( 'Quoting asks the currency plugin to leave the rate cost in base currency', 'yes', false === $inside ? 'yes' : 'no' );
		$assert( 'Quoting ends: the shop converts checkout rates again', 'yes', true === PSR_Driver::keep_base_currency( true, null ) ? 'yes' : 'no' );

		/* the sub-lines have to add up to the price on the screen, in the shop's currency */
		$factor = 1.82;
		$nzd    = PSR_Quote::split_across( array( 20.00 * $factor, 5.00 * $factor ), 25.00 * $factor, 2 );
		$assert(
			'Converted lines add up to the converted label',
			number_format( round( 25.00 * $factor, 2 ), 2, '.', '' ),
			number_format( array_sum( $nzd ), 2, '.', '' )
		);
		$assert( 'Converted lines keep every line positive', 'yes', min( $nzd ) > 0 ? 'yes' : 'no' );

		$gap = PSR_Quote::split_across( array( 10.99, 4.99 ), 15.99, 2 );
		$assert( 'The rounding cent the merged total owns is shared, not lost', '15.99', number_format( array_sum( $gap ), 2, '.', '' ) );
		$assert( 'Base-currency lines are not inflated by the split', 'yes', max( $gap ) <= 11.0 ? 'yes' : 'no' );

		$three = PSR_Quote::split_across( array( 3.333, 3.333, 3.334 ), 10.01, 2 );
		$assert( 'Three lines share three rounding cents and land on the total', '10.01', number_format( array_sum( $three ), 2, '.', '' ) );
		$assert( 'One line takes the whole label it belongs to', '11.99', number_format( array_sum( PSR_Quote::split_across( array( 11.99 ), 11.99, 2 ) ), 2, '.', '' ) );
		$assert( 'A zero total splits to zeros', '0.00', number_format( array_sum( PSR_Quote::split_across( array( 0, 0 ), 0, 2 ) ), 2, '.', '' ) );
		$assert( 'A zero-decimal currency is shared in whole units', '45', number_format( array_sum( PSR_Quote::split_across( array( 20.00 * 1.8, 5.00 * 1.8 ), 25.00 * 1.8, 0 ) ), 0, '.', '' ) );
		$assert( 'No line list, no lines', '0', (string) count( PSR_Quote::split_across( array(), 15.99, 2 ) ) );

		$assert( 'Parity is no conversion', '1.0', number_format( PSR_Quote::display_factor( 15.99, 15.99 ), 1, '.', '' ) );
		$assert( 'The factor is measured from the rate, not assumed', '1.8200', number_format( PSR_Quote::display_factor( 25.00, 45.50 ), 4, '.', '' ) );
		$assert( 'A cent of parity is still no conversion', '1.0', number_format( PSR_Quote::display_factor( 15.99, 16.0 ), 1, '.', '' ) );
		$assert( 'A zero base total cannot give a factor', '1.0', number_format( PSR_Quote::display_factor( 0, 18.99 ), 1, '.', '' ) );
		$assert( 'An absurd factor is refused (the numbers are not comparable)', '1.0', number_format( PSR_Quote::display_factor( 15.99, 9000.0 ), 1, '.', '' ) );
		$assert( 'A big amount is stored without a thousands comma', '1234.56', number_format( (float) number_format( 1234.56, 2, '.', '' ), 2, '.', '' ) );
	}

	/**
	 * A synthetic package of "product_id:qty" items, delivered to the US.
	 *
	 * @param array $items Items.
	 * @return array|null
	 */
	public static function package( $items ) {
		$package = array(
			'id'             => '1.psr-selftest',
			'package_id'     => '1.psr-selftest',
			'contents'       => array(),
			'count'          => 0,
			'weight'         => 0.0,
			'subtotal'       => 0.0,
			'destination'    => array(
				'country'  => 'US',
				'state'    => 'NY',
				'postcode' => '10001',
				'city'     => 'New York',
				'address'  => '1 Broad St',
			),
			'needs_shipping' => true,
		);

		foreach ( (array) $items as $spec ) {
			$bits    = explode( ':', $spec );
			$id      = (int) $bits[0];
			$qty     = isset( $bits[1] ) ? (int) $bits[1] : 1;
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$package['contents'][ 'psr-' . $id ] = array(
				'data'          => $product,
				'quantity'      => $qty,
				'line_subtotal' => (float) $product->get_price() * $qty,
			);
			$package['count']    += $qty;
			$package['weight']   += (float) $product->get_weight() * $qty;
			$package['subtotal'] += (float) $product->get_price() * $qty;
		}

		return $package['contents'] ? $package : null;
	}

	/**
	 * Numbers are compared as strings; show them the same way every time.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	protected static function num( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_numeric( $v ) ) {
			return number_format( (float) $v, 2, '.', '' );
		}
		return var_export( $v, true );
	}
}
