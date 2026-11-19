<?php
/**
 * Provider Shipping - the registry table.
 *
 * WooCommerce → Settings → Shipping → **Providers**, rendered inside WooCommerce's own
 * settings form: same nonce (`woocommerce-settings`), same capability
 * (`manage_woocommerce`), same Save changes button as the shipping zones next door.
 *
 * The table is one settings item of an unrecognised type. Core renders an unknown type through
 * `woocommerce_admin_field_{type}` (measured in the live class-wc-admin-settings.php), and skips
 * saving items marked `is_option => false` - so the table renders here and PSR_Registry_UI::save
 * stores the rows.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Registry_UI {

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'row_actions' ) );
		add_action( 'admin_head', array( __CLASS__, 'style' ) );
	}

	/**
	 * @return array slug => label
	 */
	public static function provider_labels() {
		$out = array();
		foreach ( PSR_Registry::all() as $slug => $row ) {
			$out[ $slug ] = $row['label'];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The table.
	 */
	public static function render_table() {
		$base    = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=providers' );
		$rows    = PSR_Registry::all();
		$methods = PSR_Registry::method_choices();
		$rounds  = PSR_Registry::rounds();
		$focus   = isset( $_GET['slug'] ) ? PSR_Registry::slug( (string) $_GET['slug'] ) : '';

		echo '<table class="psr-table">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Provider', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Shown as', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Priced by', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Lane / origin', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Handling', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Uplift %', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Floor', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Cap', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Rounding', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Classes', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Serves', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'If it cannot be priced', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'On', 'provider-shipping' ) . '</th>'
			. '<th>' . esc_html__( 'Name public', 'provider-shipping' ) . '</th>'
			. '<th></th></tr></thead><tbody>';

		foreach ( $rows as $slug => $row ) {
			$name  = 'psr_rows[' . $slug . ']';
			$insts = PSR_Registry::method_instances( $row['method_id'] );
			$count = 0;
			foreach ( $insts as $per_zone ) {
				$count += count( $per_zone );
			}

			echo '<tr' . ( $focus === $slug ? ' class="psr-focused"' : '' ) . '>';
			echo '<th>' . esc_html( $slug )
				. '<input type="hidden" name="psr_original[' . esc_attr( $slug ) . ']" value="' . esc_attr( $slug ) . '" /></th>';
			echo '<td><input type="text" name="' . esc_attr( $name ) . '[label]" value="' . esc_attr( $row['label'] ) . '" class="psr-s" /></td>';

			echo '<td><select name="' . esc_attr( $name ) . '[driver]" class="psr-s">'
				. '<option value="method"' . selected( $row['driver'], 'method', false ) . '>' . esc_html__( 'a shipping method', 'provider-shipping' ) . '</option>'
				. '<option value="printify-api"' . selected( $row['driver'], 'printify-api', false ) . '>' . esc_html__( 'Printify\'s quote endpoint', 'provider-shipping' ) . '</option>'
				. '<option value="fixed"' . selected( $row['driver'], 'fixed', false ) . '>' . esc_html__( 'a fixed amount', 'provider-shipping' ) . '</option>'
				. '</select>';

			if ( 'method' === $row['driver'] ) {
				echo '<br /><select name="' . esc_attr( $name ) . '[method_id]" class="psr-s">';
				foreach ( $methods as $id => $label ) {
					echo '<option value="' . esc_attr( $id ) . '"' . selected( $row['method_id'], $id, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select><br /><select name="' . esc_attr( $name ) . '[instance]" class="psr-s">'
					. '<option value="0">' . esc_html__( 'the instance in the cart\'s zone', 'provider-shipping' ) . '</option>';
				foreach ( $insts as $zone_id => $per_zone ) {
					foreach ( $per_zone as $instance_id => $label ) {
						echo '<option value="' . (int) $instance_id . '"' . selected( $row['instance'], $instance_id, false ) . '>'
							. esc_html( 'zone ' . (int) $zone_id . ' - ' . $label ) . '</option>';
					}
				}
				echo '</select>';
				if ( 0 === $count && '' !== $row['method_id'] ) {
					echo '<span class="psr-warn">' . esc_html__( 'that method has no instance in any zone - add it under Shipping zones, or this provider cannot be priced', 'provider-shipping' ) . '</span>';
				}
			}
			if ( 'fixed' === $row['driver'] ) {
				echo '<br /><input type="text" name="' . esc_attr( $name ) . '[fixed_cost]" value="' . esc_attr( $row['fixed_cost'] ) . '" class="psr-xs" placeholder="' . esc_attr__( 'amount', 'provider-shipping' ) . '" />';
			}
			echo '</td>';

			echo '<td><input type="text" name="' . esc_attr( $name ) . '[lane]" value="' . esc_attr( $row['lane'] ) . '" class="psr-s" placeholder="' . esc_attr__( 'lane', 'provider-shipping' ) . '" /><br />'
				. '<input type="text" name="' . esc_attr( $name ) . '[fulfillment]" value="' . esc_attr( $row['fulfillment'] ) . '" class="psr-s" placeholder="' . esc_attr__( 'origin', 'provider-shipping' ) . '" /></td>';

			foreach ( array( 'handling', 'uplift', 'floor', 'cap' ) as $field ) {
				echo '<td><input type="text" name="' . esc_attr( $name ) . '[' . $field . ']" value="' . esc_attr( $row[ $field ] ) . '" class="psr-xs" /></td>';
			}

			echo '<td><select name="' . esc_attr( $name ) . '[round]" class="psr-s">';
			foreach ( $rounds as $k => $v ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $row['round'], $k, false ) . '>' . esc_html( $v ) . '</option>';
			}
			echo '</select></td>';

			echo '<td><textarea name="' . esc_attr( $name ) . '[classes]" class="psr-ta" rows="3" placeholder="hoodies=hoodies">' . esc_textarea( $row['classes'] ) . '</textarea></td>';

			echo '<td><label><input type="checkbox" name="' . esc_attr( $name ) . '[serves_all]" value="yes"' . checked( $row['serves_all'], 'yes', false ) . ' /> ' . esc_html__( 'everywhere', 'provider-shipping' ) . '</label><br />'
				. '<input type="text" name="' . esc_attr( $name ) . '[countries]" value="' . esc_attr( implode( ',', $row['countries'] ) ) . '" class="psr-m" placeholder="US,CA,GB" /></td>';

			echo '<td><select name="' . esc_attr( $name ) . '[fallback]" class="psr-s">'
				. '<option value="fixed"' . selected( $row['fallback'], 'fixed', false ) . '>' . esc_html__( 'charge a fixed amount', 'provider-shipping' ) . '</option>'
				. '<option value="block"' . selected( $row['fallback'], 'block', false ) . '>' . esc_html__( 'no quote - blocks the sale', 'provider-shipping' ) . '</option>'
				. '</select><br />'
				. '<input type="text" name="' . esc_attr( $name ) . '[fixed_cost]" value="' . esc_attr( $row['fixed_cost'] ) . '" class="psr-xs" placeholder="' . esc_attr__( 'fixed', 'provider-shipping' ) . '" /></td>';

			echo '<td><input type="checkbox" name="' . esc_attr( $name ) . '[enabled]" value="yes"' . checked( $row['enabled'], 'yes', false ) . ' /></td>';
			echo '<td><input type="checkbox" name="' . esc_attr( $name ) . '[visible]" value="yes"' . checked( $row['visible'], 'yes', false ) . ' /></td>';

			echo '<td><a class="psr-del" href="' . esc_url( wp_nonce_url( $base . '&psr_action=delete&slug=' . $slug, 'psr_row' ) ) . '"'
				. ' onclick="return confirm(\'' . esc_js( __( 'Delete this provider? Products naming it fall back to the site default provider.', 'provider-shipping' ) ) . ')">'
				. esc_html__( 'delete', 'provider-shipping' ) . '</a></td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Add and delete. Nonce-checked, manage_woocommerce-only, and neither touches a product.
	 */
	public static function row_actions() {
		if ( ! isset( $_GET['page'], $_GET['section'], $_GET['psr_action'] ) ) {
			return;
		}
		if ( 'wc-settings' !== $_GET['page'] || 'providers' !== $_GET['section'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'psr_row' );

		$slug = PSR_Registry::slug( isset( $_GET['slug'] ) ? (string) $_GET['slug'] : '' );

		if ( 'add' === $_GET['psr_action'] ) {
			if ( '' === $slug ) {
				self::flash( 'badslug' );
			}
			if ( PSR_Registry::get( $slug ) ) {
				self::flash( 'exists', $slug );
			}
			PSR_Registry::save(
				array(
					'slug'   => $slug,
					'label'  => ucwords( str_replace( '-', ' ', $slug ) ),
					'driver' => 'fixed',
				)
			);
			self::flash( 'added', $slug );
		}

		if ( 'delete' === $_GET['psr_action'] ) {
			if ( '' === $slug ) {
				self::flash( 'badslug' );
			}
			PSR_Registry::delete( $slug );
			self::flash( 'deleted', $slug );
		}
	}

	/**
	 * Save the rows.
	 *
	 * WooCommerce has already checked the settings nonce before firing
	 * `woocommerce_settings_save_shipping`, and it saves its own switches on the same hook, so
	 * this handler stores only the registry option and must not redirect.
	 *
	 * @param string $section Section being saved.
	 */
	public static function save_rows( $section ) {
		if ( 'providers' !== $section ) {
			return;
		}
		if ( ! isset( $_POST['psr_rows'] ) || ! is_array( $_POST['psr_rows'] ) ) {
			return;
		}
		check_admin_referer( 'woocommerce-settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$posts = (array) wp_unslash( $_POST['psr_rows'] );
		$orig  = isset( $_POST['psr_original'] ) ? (array) wp_unslash( $_POST['psr_original'] ) : array();
		$after = array();

		foreach ( $posts as $slug => $row ) {
			$key = isset( $orig[ $slug ] ) ? PSR_Registry::slug( (string) $orig[ $slug ] ) : PSR_Registry::slug( (string) $slug );
			if ( '' === $key ) {
				continue;
			}
			$row           = PSR_Registry::clean( (array) $row );
			$row['slug']   = $key;
			$after[ $key ] = $row;
		}

		// A row this screen did not post keeps its stored value: a partial post can never
		// blank the registry.
		foreach ( PSR_Registry::all() as $slug => $row ) {
			if ( ! isset( $after[ $slug ] ) ) {
				$after[ $slug ] = $row;
			}
		}

		update_option( PSR_Registry::OPTION, $after );
	}

	/**
	 * Saved-action notices, from the add / delete links.
	 */
	public static function render_notices() {
		$messages = array(
			'added'   => __( 'Provider added. Set its label, driver and margin, then Save changes.', 'provider-shipping' ),
			'exists'  => __( 'That provider already exists.', 'provider-shipping' ),
			'badslug' => __( 'A provider needs a slug: letters, numbers, dashes.', 'provider-shipping' ),
			'deleted' => __( 'Provider deleted. Products naming it fall back to the site default provider until you re-declare them.', 'provider-shipping' ),
		);
		$key = isset( $_GET['psr_msg'] ) ? (string) $_GET['psr_msg'] : '';
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
	}

	/**
	 * @param string $msg  Message key.
	 * @param string $slug Provider slug.
	 */
	protected static function flash( $msg, $slug = '' ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=providers&psr_msg=' . rawurlencode( $msg ) );
		if ( '' !== $slug ) {
			$url .= '&slug=' . rawurlencode( $slug );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Screen styling.
	 */
	public static function style() {
		if ( ! isset( $_GET['page'] ) || 'wc-settings' !== $_GET['page'] ) {
			return;
		}
		echo '<style>
		.psr-registry{margin:0 0 24px}
		.psr-table{width:100%;border-collapse:collapse;font-size:12px;margin:0 0 12px}
		.psr-table th,.psr-table td{border:1px solid #dcdcdc;padding:6px;vertical-align:top;text-align:left;background:#fff}
		.psr-table thead th{background:#f6f7f7}
		.psr-table tr.psr-focused th{background:#ecf7ed}
		.psr-s{width:150px}.psr-m{width:120px}.psr-xs{width:58px}.psr-ta{width:150px;font-family:monospace;font-size:11px}
		.psr-warn{display:block;color:#984110;font-size:11px}
		.psr-note{color:#50575e;font-size:12px;max-width:900px}
		.psr-del{color:#b32d2e;font-size:11px}
		.psr-add{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
		.psr-add input{width:170px}
		</style>';
	}
}
