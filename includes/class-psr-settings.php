<?php
/**
 * WooCommerce → Settings → Shipping → Providers
 *
 * A section of the shipping settings, built with WooCommerce's own settings API:
 * `woocommerce_get_sections_shipping` adds the tab, `woocommerce_get_settings_shipping`
 * supplies the fields, and an unrecognised field type is rendered by
 * `woocommerce_settings_field_{type}` - which is how the registry table gets inside the
 * standard form, sharing its nonce, its capability check and its Save changes button.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Settings {

	public static function register() {
		add_filter( 'woocommerce_get_sections_shipping', array( __CLASS__, 'sections' ), 30 );
		add_filter( 'woocommerce_get_settings_shipping', array( __CLASS__, 'settings' ), 30, 2 );
		// WooCommerce renders an unknown settings type as `woocommerce_admin_field_{type}`
		// (measured in the live class-wc-admin-settings.php, the default case) and skips
		// saving items marked is_option=false.
		add_action( 'woocommerce_admin_field_psr_registry', array( __CLASS__, 'registry_field' ) );
		add_action( 'woocommerce_settings_save_shipping', array( __CLASS__, 'save' ) );
		// Row actions and styling belong to PSR_Registry_UI - registered once, there.
	}

	/**
	 * @param array $sections Shipping sections.
	 * @return array
	 */
	public static function sections( $sections ) {
		$sections['providers'] = __( 'Providers', 'provider-shipping' );
		return $sections;
	}

	/**
	 * @param array  $settings Current settings.
	 * @param string $section  Current section.
	 * @return array
	 */
	public static function settings( $settings, $section ) {
		if ( 'providers' !== $section ) {
			return $settings;
		}

		return array(
			array(
				'title' => __( 'Providers', 'provider-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Who ships what, which shipping method prices them, and what the customer is allowed to see. Rows are saved by Save changes at the bottom of this page. Provider slugs cannot be renamed after creation - a renamed slug would orphan the products that name it, so add the new one, re-declare the products, then delete the old.', 'provider-shipping' ),
				'id'    => 'psr_registry_head',
			),
			array(
				'type'     => 'psr_registry',
				'id'       => 'psr_registry',
				// Measured in class-wc-admin-settings.php: save_fields() skips items marked
				// is_option=false, and output_fields() renders an unknown type through
				// woocommerce_admin_field_{type}. So this renders the table and saves nothing
				// as an option - the rows are saved by PSR_Settings::save().
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'psr_registry_head',
			),
			array(
				'title' => __( 'What customers see', 'provider-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'A cart can be shipped by more than one supplier. These switches decide what a customer is shown. Supplier names stay private by default.', 'provider-shipping' ),
				'id'    => 'psr_display_head',
			),
			array(
				'name'     => __( 'When several providers ship one cart', 'provider-shipping' ),
				'type'     => 'select',
				'options'  => PSR_Registry::display_modes(),
				'default'  => PSR_Registry::DISPLAY_MERGED_ADMIN,
				'id'       => 'psr_display_mode',
				'desc_tip' => true,
				'desc'     => __( 'Merged = one shipping option, the sum of the providers. Per-provider = the customer chooses each parcel.', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'Show supplier names to customers', 'provider-shipping' ),
				'type'     => 'checkbox',
				'default'  => 'no',
				'id'       => 'psr_public_names',
				'desc_tip' => true,
				'desc'     => __( 'Off (default): the rate reads "Shipping (2 providers)" and no supplier is named where a customer can see it.', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'Public note under a merged rate', 'provider-shipping' ),
				'type'     => 'text',
				'css'      => 'width:100%;',
				'default'  => 'This order ships in separate parcels from our suppliers.',
				'id'       => 'psr_provider_note',
				'desc_tip' => true,
				'desc'     => __( 'Used by the "merged rate, public note" mode. It names no supplier.', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'Provider line on a product page', 'provider-shipping' ),
				'type'     => 'select',
				'options'  => PSR_Registry::show_modes(),
				'default'  => PSR_Registry::SHOW_HIDDEN,
				'id'       => 'psr_show_provider',
				'desc_tip' => true,
				'desc'     => __( 'Hidden (default). A provider is only named publicly if its row says "Name public". Staff see it in the product editor either way.', 'provider-shipping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'psr_display_head',
			),
			array(
				'title' => __( 'Defaults, pipeline and guard', 'provider-shipping' ),
				'type'  => 'title',
				'id'    => 'psr_pipeline_head',
			),
			array(
				'name'     => __( 'Provider for products with no declaration', 'provider-shipping' ),
				'type'     => 'select',
				'options'  => self::provider_options(),
				'default'  => 'in-house',
				'id'       => 'psr_default_provider',
				'desc_tip' => true,
				'desc'     => __( 'In-house means the store ships it itself, priced by the zone\'s existing Flat Rate.', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'Pipeline mode', 'provider-shipping' ),
				'type'     => 'select',
				'options'  => array(
					'off'    => __( 'Off - this plugin manages providers; the methods on the zones price the cart', 'provider-shipping' ),
					'shadow' => __( 'Shadow - quote every cart through the registry and log it, change nothing', 'provider-shipping' ),
					'live'   => __( 'Live - this plugin produces the checkout rate', 'provider-shipping' ),
				),
				'default'  => 'off',
				'id'       => 'psr_pipeline_mode',
				'desc_tip' => true,
				'desc'     => __( 'Shadow measures this plugin against what is live today on the same carts. Move to Live only when the numbers agree. Live needs a Provider Shipping instance in the zone (Settings → Shipping → the zone → add method).', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'When the registry cannot price a cart', 'provider-shipping' ),
				'type'     => 'select',
				'options'  => array(
					'block' => __( 'Offer nothing - the sale is blocked (default, never sell shipping at a loss)', 'provider-shipping' ),
					'flat'  => __( 'Leave the zone\'s own rates visible as a fallback', 'provider-shipping' ),
				),
				'default'  => 'block',
				'id'       => 'psr_live_fallback',
				'desc_tip' => true,
				'desc'     => __( 'Live mode. If a provider has no working rate source for a destination, the only price left is usually the store\'s Flat Rate - which is often below what the supplier charges for the same parcel. Blocking is the "never lose profit" rule; the customer sees the neutral message above.', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'Self-check sample data (JSON, optional)', 'provider-shipping' ),
				'type'     => 'textarea',
				'css'      => 'width:100%;min-height:90px;font-family:monospace;',
				'default'  => '',
				'id'       => 'psr_selftest',
				'desc_tip' => true,
				'desc'     => __( 'The cart checks in Tools / the self-check run on products you name here - product ids, a mixed cart, the provider groups they should produce. Leave it empty and those checks report as skipped, so a fresh install gets a clean report. Keys: products, variation, inherits, method, zone, mixed, groups, apparel, in_house.', 'provider-shipping' ),
			),
			array(
				'name'     => __( 'Block the sale when nothing can ship the cart', 'provider-shipping' ),
				'type'     => 'checkbox',
				'default'  => 'yes',
				'id'       => 'psr_block_no_quote',
				'desc_tip' => true,
				'desc'     => __( 'The order is refused server-side, and WooPayments express checkout (Apple Pay / Google Pay) is switched off in the same case. While tapstitch-shipping-method is installed and guarding, that guard runs and this switch is reported as shared.', 'provider-shipping' ),
			),
			array(
				'name'    => __( 'Message when checkout is blocked', 'provider-shipping' ),
				'type'    => 'text',
				'css'     => 'width:100%;',
				'default' => PSR_Guard::DEFAULT_MESSAGE,
				'id'      => 'psr_no_quote_message',
				'desc_tip' => true,
				'desc'    => __( 'Neutral wording, no contact address named.', 'provider-shipping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'psr_pipeline_head',
			),
		);
	}

	/**
	 * The registry table, inside the settings form.
	 */
	public static function registry_field() {
		PSR_Registry_UI::render_notices();
		echo '<div class="psr-registry">';
		echo '<p class="psr-note">';
		echo esc_html__( 'Each provider is priced by a shipping method that already exists on the store - Printify\'s method for Printify goods, the Tapstitch method for apparel, the zone\'s Flat Rate for in-house goods. Products declare their provider in the Providers tab of the product editor, in Quick and Bulk edit, in CSV, or from Tools → Provider detect.', 'provider-shipping' );
		echo '</p>';

		PSR_Registry_UI::render_table();

		$base = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=providers&psr_action=add&slug=__SLUG__' );
		echo '<p class="psr-add">';
		echo '<label for="psr-new-slug"><strong>' . esc_html__( 'Add a provider (slug):', 'provider-shipping' ) . '</strong></label>';
		echo '<input type="text" id="psr-new-slug" placeholder="supplier-name" autocomplete="off" />';
		echo '<a class="button button-secondary" id="psr-add-link" href="' . esc_url( wp_nonce_url( $base, 'psr_row' ) ) . '">' . esc_html__( 'Add provider', 'provider-shipping' ) . '</a>';
		echo '</p>';
		echo '<script>
			(function () {
				var link = document.getElementById( "psr-add-link" );
				var box  = document.getElementById( "psr-new-slug" );
				if ( ! link || ! box ) { return; }
				link.addEventListener( "click", function ( e ) {
					var slug = ( box.value || "" ).trim().toLowerCase().replace( /[^a-z0-9_-]+/g, "-" ).replace( /^-+|-+$/g, "" );
					if ( ! slug ) { e.preventDefault(); box.focus(); return; }
					link.href = link.href.replace( "__SLUG__", encodeURIComponent( slug ) );
				} );
			} )();
		</script>';
		echo '</div>';
	}

	/**
	 * Registry rows, saved with the rest of the shipping settings.
	 *
	 * WooCommerce fires `woocommerce_settings_save_shipping` with no arguments, so the section
	 * comes from its global, not from the hook signature.
	 */
	public static function save() {
		global $current_section;
		PSR_Registry_UI::save_rows( (string) $current_section );
	}

	/**
	 * Add / delete links.
	 */
	public static function row_actions() {
		PSR_Registry_UI::row_actions();
	}

	/**
	 * @return array slug => label
	 */
	public static function provider_options() {
		return PSR_Registry::provider_labels();
	}

	/**
	 * Screen styling.
	 */
	public static function style() {
		PSR_Registry_UI::style();
	}
}
