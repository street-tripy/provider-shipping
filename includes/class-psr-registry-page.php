<?php
/**
 * Provider Shipping - registry overview, provider detect, and the self-check.
 *
 * Tools → Provider registry. This is the screen that answers "which of my products belong to
 * which supplier, and how do I know?": it asks each supplier's own endpoint which of the
 * store's SKUs it recognises, shows the answer as a table, and writes provider meta only for
 * the rows the site owner ticks - after snapshotting them, with a Restore button.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Registry_Page {

	const PAGE = 'psr-registry';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'actions' ) );
		add_action( 'admin_head', array( __CLASS__, 'style' ) );
	}

	/**
	 * Under Tools, next to the other site-health screens.
	 */
	public static function menu() {
		add_management_page(
			__( 'Provider registry', 'provider-shipping' ),
			__( 'Provider registry', 'provider-shipping' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Apply / restore. Nonce-checked, manage_woocommerce-only.
	 */
	public static function actions() {
		if ( ! isset( $_POST['psr_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'psr_registry' );

		$base = admin_url( 'tools.php?page=' . self::PAGE );

		if ( 'detect' === $_POST['psr_action'] ) {
			$country = isset( $_POST['psr_country'] ) ? PSR_Registry::country( $_POST['psr_country'] ) : 'US';
			update_option( 'psr_detect_country', $country );
			delete_transient( 'psr_probe_printify_' . $country );
			delete_transient( 'psr_probe_tapstitch_' . $country );
			PSR_Detect::report( $country );
			wp_safe_redirect( $base . '&psr_ran=1&country=' . $country );
			exit;
		}

		if ( 'apply' === $_POST['psr_action'] ) {
			$picked = isset( $_POST['psr_apply'] ) ? (array) $_POST['psr_apply'] : array();
			$suggest = isset( $_POST['psr_suggest'] ) ? (array) $_POST['psr_suggest'] : array();
			$rows    = array();
			foreach ( $picked as $id => $slug ) {
				$rows[ (int) $id ] = $slug;
			}
			$made = PSR_Detect::apply( $rows );
			set_transient( 'psr_notice', array( 'apply', $made ), 60 );
			wp_safe_redirect( $base );
			exit;
		}

		if ( 'restore' === $_POST['psr_action'] ) {
			set_transient( 'psr_notice', array( 'restore', PSR_Detect::restore() ), 60 );
			wp_safe_redirect( $base );
			exit;
		}

		if ( 'selftest' === $_POST['psr_action'] ) {
			set_transient( 'psr_selftest', PSR_Selftest::run(), 60 );
			wp_safe_redirect( $base . '&psr_selftest=1' );
			exit;
		}
	}

	/**
	 * The screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$country = (string) get_option( 'psr_detect_country', 'US' );
		$report  = PSR_Detect::report( $country );
		$unmarked = PSR_Registry::unmarked_products();
		$snapshot = (array) get_option( 'psr_detect_snapshot', array() );
		$test     = get_transient( 'psr_selftest' );

		echo '<div class="wrap psr-wrap">';
		echo '<h1>' . esc_html__( 'Provider registry', 'provider-shipping' ) . '</h1>';

		self::notice();

		echo '<p class="psr-lead">' . esc_html__( 'Provider Shipping prices a cart by asking each provider - through the shipping method you linked to it - what its own goods cost. Everything on this page is read from your live store and from your suppliers\' own endpoints; nothing here is estimated, and nothing is written to a product until you tick rows and press Apply.', 'provider-shipping' ) . '</p>';

		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=providers' ) ) . '">'
			. esc_html__( 'Edit providers: Settings → Shipping → Providers', 'provider-shipping' ) . '</a> '
			. '<span class="psr-muted">' . esc_html__( 'labels, driver methods, margins, display mode, guard', 'provider-shipping' ) . '</span></p>';

		self::registry_block();
		self::detect_block( $report, $country, $snapshot );
		self::unmarked_block( $unmarked );
		self::selftest_block( $test );

		echo '</div>';
	}

	/**
	 * Registry summary.
	 */
	protected static function registry_block() {
		echo '<h2>' . esc_html__( 'Providers', 'provider-shipping' ) . '</h2>';
		echo '<table class="widefat striped psr-t"><thead><tr>'
			. '<th>' . esc_html__( 'Provider', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Priced by', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Margin', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Serves', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'If it cannot be priced', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Name public', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'State', 'provider-shipping' ) . '</th></tr></thead><tbody>';

		foreach ( PSR_Registry::all() as $slug => $row ) {
			$found = array();
			foreach ( PSR_Registry::method_instances( $row['method_id'] ) as $zone_id => $per_zone ) {
				foreach ( $per_zone as $instance_id => $label ) {
					$found[] = 'zone ' . (int) $zone_id . ' #' . (int) $instance_id;
				}
			}
			$driver = 'fixed' === $row['driver']
				? 'fixed ' . ( $row['fixed_cost'] ? '$' . $row['fixed_cost'] : '(no amount)' )
				: $row['method_id'] . ( $found ? ' (' . implode( ', ', $found ) . ')' : ' - no instance in any zone' );

			$margin = array();
			$margin[] = 'handling ' . ( $row['handling'] ? '$' . $row['handling'] : '0' );
			$margin[] = 'uplift ' . ( $row['uplift'] ? $row['uplift'] . '%' : '0' );
			$margin[] = 'floor ' . ( $row['floor'] ? '$' . $row['floor'] : '-' );
			$margin[] = 'cap ' . ( $row['cap'] ? '$' . $row['cap'] : '-' );
			$margin[] = $row['round'];

			echo '<tr>'
				. '<th>' . esc_html( $row['label'] ) . ' <span class="psr-muted">' . esc_html( $slug ) . '</span></th>'
				. '<td>' . esc_html( $driver ) . '</td>'
				. '<td>' . esc_html( implode( ' · ', $margin ) ) . '</td>'
				. '<td>' . esc_html( 'yes' === $row['serves_all'] ? __( 'everywhere', 'provider-shipping' ) : implode( ',', $row['countries'] ) ) . '</td>'
				. '<td>' . esc_html( 'block' === $row['fallback'] ? __( 'no quote - blocks the sale', 'provider-shipping' ) : ( 'fixed ' . ( $row['fixed_cost'] ? '$' . $row['fixed_cost'] : '(no amount)' ) ) ) . '</td>'
				. '<td>' . esc_html( $row['visible'] ) . '</td>'
				. '<td>' . ( 'yes' === $row['enabled'] ? '<span class="psr-on">on</span>' : '<span class="psr-off">off</span>' ) . '</td>'
				. '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Detect table, with the Apply form.
	 *
	 * @param array  $report   Detect report.
	 * @param string $country  Country probed.
	 * @param array  $snapshot Snapshot size.
	 */
	protected static function detect_block( $report, $country, $snapshot ) {
		echo '<h2>' . esc_html__( 'Provider detect', 'provider-shipping' ) . '</h2>';
		echo '<p class="psr-muted">' . esc_html__( 'Ask each supplier which of the store\'s SKUs are theirs. Printify answers from the same shipping callback its own plugin posts at checkout; Printful from the tags its plugin writes; Tapstitch from the categories it publishes rates for, matched against each product\'s shipping class. Nothing is written until you tick rows and press Apply. Apply snapshots the products it is about to change, so Restore puts them back.', 'provider-shipping' ) . '</p>';

		$claims = array();
		foreach ( $report['rows'] as $row ) {
			$key = $row['claimed_by'] ? $row['claimed_by'] : 'no supplier claim';
			if ( ! isset( $claims[ $key ] ) ) {
				$claims[ $key ] = 0;
			}
			$claims[ $key ]++;
		}
		$parts = array();
		foreach ( $claims as $k => $v ) {
			$parts[] = $k . ': ' . (int) $v;
		}
		$parts[] = 'products: ' . count( $report['rows'] );
		$parts[] = 'country probed: ' . $report['country'];
		$parts[] = 'snapshot holds ' . count( $snapshot ) . ' products';

		echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '" class="psr-form">';
		wp_nonce_field( 'psr_registry' );
		echo '<p>' . esc_html__( 'Probe country:', 'provider-shipping' )
			. ' <input type="text" name="psr_country" value="' . esc_attr( $report['country'] ) . '" size="3" maxlength="2" />'
			. ' <button class="button" type="submit" name="psr_action" value="detect">' . esc_html__( 'Ask the suppliers again', 'provider-shipping' ) . '</button></p>';
		echo '<p class="psr-muted">' . esc_html( implode( ' | ', $parts ) ) . '</p>';

		echo '<table class="widefat psr-t"><thead><tr>'
			. '<th>' . esc_html__( 'Apply', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Product', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'SKUs', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Class', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Supplier says', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Its own cost sample', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Why', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Now on the product', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Set to', 'provider-shipping' ) . '</th></tr></thead><tbody>';

		foreach ( $report['rows'] as $row ) {
			echo '<tr>'
				. '<td><input type="checkbox" name="psr_apply[' . (int) $row['product'] . ']" value="' . esc_attr( $row['suggest'] ) . '"' . checked( $row['suggest'] !== $row['current'], true, false ) . ' /></td>'
				. '<td><a href="' . esc_url( get_edit_post_link( $row['product'] ) ) . '">#' . (int) $row['product'] . '</a> ' . esc_html( $row['name'] )
				. ( $row['variations'] ? ' <span class="psr-muted">' . (int) $row['variations'] . ' variations</span>' : '' ) . '</td>'
				. '<td class="psr-sku">' . esc_html( $row['skus'] ? $row['skus'] : '(no SKU)' ) . '</td>'
				. '<td>' . esc_html( $row['class'] ) . '</td>'
				. '<td>' . ( $row['claimed_by'] ? '<span class="psr-on">' . esc_html( $row['claimed_by'] ) . '</span>' : '<span class="psr-muted">no claim</span>' ) . '</td>'
				. '<td>' . esc_html( $row['cost'] ? $row['cost'] : '-' ) . '</td>'
				. '<td class="psr-muted">' . esc_html( $row['why'] ) . '</td>'
				. '<td>' . ( $row['current'] ? esc_html( $row['current'] ) : '<span class="psr-muted">not set</span>' ) . '</td>'
				. '<td><select name="psr_suggest[' . (int) $row['product'] . ']">';
			foreach ( PSR_Registry::all() as $slug => $prow ) {
				echo '<option value="' . esc_attr( $slug ) . '"' . selected( $row['suggest'], $slug, false ) . '>' . esc_html( $prow['label'] ) . '</option>';
			}
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><button class="button button-primary" type="submit" name="psr_action" value="apply">' . esc_html__( 'Apply the ticked rows', 'provider-shipping' ) . '</button> '
			. '<button class="button" type="submit" name="psr_action" value="restore" onclick="return confirm(\'' . esc_js( __( 'Restore the provider meta of every product in the snapshot?', 'provider-shipping' ) ) . ')">' . esc_html__( 'Restore snapshot', 'provider-shipping' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array $unmarked Products with no declaration.
	 */
	protected static function unmarked_block( $unmarked ) {
		echo '<h2>' . esc_html__( 'Products with no provider', 'provider-shipping' ) . '</h2>';
		if ( ! $unmarked ) {
			echo '<p class="psr-on">' . esc_html__( 'Every published product declares a provider.', 'provider-shipping' ) . '</p>';
			return;
		}
		echo '<p class="psr-muted">' . esc_html__( 'These declare nothing, so they are priced by the site default provider (Settings → Shipping → Providers). Clear this list by setting a provider, or by detecting one above.', 'provider-shipping' ) . '</p>';
		echo '<table class="widefat striped psr-t"><thead><tr>'
			. '<th>' . esc_html__( 'Product', 'provider-shipping' ) . '</th><th>' . esc_html__( 'Type', 'provider-shipping' ) . '</th><th>' . esc_html__( 'SKU', 'provider-shipping' ) . '</th><th>' . esc_html__( 'Class', 'provider-shipping' ) . '</th></tr></thead><tbody>';
		foreach ( $unmarked as $p ) {
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( $p['id'] ) ) . '">#' . (int) $p['id'] . '</a> ' . esc_html( $p['name'] ) . '</td>'
				. '<td>' . esc_html( $p['type'] ) . '</td><td>' . esc_html( $p['sku'] ) . '</td><td>' . esc_html( $p['class'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Self-check results.
	 *
	 * @param array|false $test Cached run.
	 */
	protected static function selftest_block( $test ) {
		echo '<h2>' . esc_html__( 'Self-check', 'provider-shipping' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">';
		wp_nonce_field( 'psr_registry' );
		echo '<p><button class="button" type="submit" name="psr_action" value="selftest">' . esc_html__( 'Run the self-check on this server', 'provider-shipping' ) . '</button> '
			. '<span class="psr-muted">' . esc_html__( 'registry maths, provider grouping from real products, merged labels, the guard decision table', 'provider-shipping' ) . '</span></p>';
		echo '</form>';

		if ( ! is_array( $test ) ) {
			return;
		}
		echo '<p><strong>' . esc_html( sprintf( '%d of %d pass, %d fail', $test['passed'], $test['total'], $test['failed'] ) ) . '</strong></p>';
		echo '<table class="widefat psr-t"><thead><tr><th>' . esc_html__( 'Check', 'provider-shipping' ) . '</th><th>' . esc_html__( 'Result', 'provider-shipping' ) . '</th><th>' . esc_html__( 'Detail', 'provider-shipping' ) . '</th></tr></thead><tbody>';
		foreach ( $test['cases'] as $case ) {
			echo '<tr><td>' . esc_html( $case['name'] ) . '</td>'
				. '<td>' . ( $case['pass'] ? '<span class="psr-on">PASS</span>' : '<span class="psr-off">FAIL</span>' ) . '</td>'
				. '<td class="psr-muted">' . esc_html( $case['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Apply / restore results.
	 */
	protected static function notice() {
		$notice = get_transient( 'psr_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'psr_notice' );
		$counts = (array) $notice[1];
		$text   = 'apply' === $notice[0]
			? sprintf( 'Provider meta written: %d products and %d variations. Restore puts them back to what they were before this Apply.', (int) $counts['products'], (int) $counts['variations'] )
			: sprintf( 'Restored %d products from the snapshot.', (int) $counts['products'] );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		if ( ! empty( $counts['skipped'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( 'Skipped: ' . implode( '; ', (array) $counts['skipped'] ) ) . '</p></div>';
		}
	}

	/**
	 * Screen styling.
	 */
	public static function style() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		echo '<style>
		.psr-t{margin:0 0 20px}
		.psr-t td,.psr-t th{padding:7px 9px}
		.psr-muted{color:#646970;font-size:12px}
		.psr-sku{font-family:monospace;font-size:11px}
		.psr-on{color:#1e6b3a;font-weight:600}
		.psr-off{color:#984110;font-weight:600}
		.psr-lead{max-width:900px}
		.psr-form{margin:0 0 20px}
		</style>';
	}
}
