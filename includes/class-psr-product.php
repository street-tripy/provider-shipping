<?php
/**
 * Provider meta on the product - every place it can be set.
 *
 *   - Product data → **Providers** tab: one select, variations inherit it, plus a test-quote
 *     button that prices this product alone to a postcode.
 *   - Products list column, Quick Edit, Bulk Edit.
 *   - CSV import and export column.
 *   - The product-page line, governed by the site's visibility switch (hidden by default).
 *
 * The meta key is `_psr_provider`. It is the only product data this plugin writes.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Product {

	public static function register() {
		add_filter( 'woocommerce_settings_tabs_data', array( __CLASS__, 'tab' ) );

		// The classic (meta-box) product editor. Measured in the live
		// html-product-data-panel.php: `woocommerce_product_write_panel_tabs` and
		// `woocommerce_product_write_panels` are ACTIONS fired with no arguments (deprecated in
		// WooCommerce 2.6), so hooking a filter with a required parameter to them fatals the
		// product screen - which is what the fatal log of 2026-11-19 08:15 recorded. The current
		// API is the `woocommerce_product_data_tabs` filter and the
		// `woocommerce_product_data_panels` action, and that is what this uses.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'classic_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'classic_panel' ) );

		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_classic' ), 20, 2 );
		add_action( 'woocommerce_variation_options_collapse', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_variation_set_data', array( __CLASS__, 'variation_save' ), 10, 3 );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );

		add_action( 'woocommerce_product_quick_edit_end', array( __CLASS__, 'quick_edit' ) );
		add_action( 'woocommerce_product_get_inline_data', array( __CLASS__, 'quick_edit_inline' ), 10, 2 );
		add_action( 'woocommerce_product_set_inline_data', array( __CLASS__, 'quick_edit_inline' ), 10, 2 );
		add_filter( 'woocommerce_bulk_edit_data', array( __CLASS__, 'bulk_edit' ) );
		add_action( 'woocommerce_bulk_edit_data_post', array( __CLASS__, 'bulk_edit_save' ), 10, 4 );

		add_filter( 'woocommerce_product_csv_exporter_columns', array( __CLASS__, 'csv_column' ) );
		add_filter( 'woocommerce_product_csv_importer_columns', array( __CLASS__, 'csv_column' ) );
		add_filter( 'woocommerce_shop_loop_item_title', array( __CLASS__, 'noop' ) );

		add_action( 'woocommerce_product_meta_start', array( __CLASS__, 'line' ), 15 );
		add_action( 'wp_ajax_psr_test_quote', array( __CLASS__, 'test_quote' ) );
		add_action( 'admin_head', array( __CLASS__, 'style' ) );
		add_action( 'admin_footer', array( __CLASS__, 'script' ) );
	}

	public static function noop( $x ) {
		return $x;
	}

	/* ------------------------------------------------------------------ *
	 * Product data → Providers
	 * ------------------------------------------------------------------ */

	/**
	 * The React product editor's tab data.
	 *
	 * @param array $tabs Pane definitions.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['psr'] = array(
			'title'     => __( 'Providers', 'provider-shipping' ),
			'priority'  => 25,
			'section'   => 'psr_options',
			'sub_pages' => 0,
			'help_text' => __( 'Which supplier ships this product. A variation inherits the product\'s provider unless it declares its own. Provider Shipping uses this to choose the rate source, and to group the cart at checkout.', 'provider-shipping' ),
			'fields'    => array(
				array(
					'title' => __( 'Providers', 'provider-shipping' ),
					'type'  => 'title',
					'id'    => 'psr_tab_title',
				),
				array(
					'name'        => '_psr_provider',
					'title'       => __( 'Provider', 'provider-shipping' ),
					'type'        => 'select',
					'class'       => 'psr-select',
					'desc_tip'    => true,
					'description' => __( 'The supplier that ships this product.', 'provider-shipping' ),
					'options'     => self::options(),
					'default'     => '',
				),
				array(
					'name'        => '_psr_provider_note',
					'title'       => __( 'Internal note', 'provider-shipping' ),
					'type'        => 'text',
					'description' => __( 'For staff only. Never shown to customers.', 'provider-shipping' ),
					'default'     => '',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'psr_tab_title',
				),
			),
		);
		return $tabs;
	}

	/**
	 * Classic (meta-box) editor: the tab in the Product data strip.
	 *
	 * @param array $tabs Tabs as WooCommerce builds them.
	 * @return array
	 */
	public static function classic_tab( $tabs ) {
		$tabs['psr'] = array(
			'label'  => __( 'Providers', 'provider-shipping' ),
			'target' => 'psr_panel_data',
			'class'  => array( 'show_if_simple', 'show_if_variable' ),
		);
		return $tabs;
	}

	/**
	 * Classic editor: the panel that tab opens.
	 */
	public static function classic_panel() {
		global $post_ID;

		$value = '';
		$note  = '';
		if ( $post_ID && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $post_ID );
			if ( $product instanceof WC_Product ) {
				$value = (string) $product->get_meta( PSR_Registry::META_KEY, true );
				$note  = (string) $product->get_meta( '_psr_provider_note', true );
			}
		}

		echo '<div id="psr_panel_data" class="panel woocommerce_options_panel">';
		echo '<div class="options_group psr-classic">';
		echo '<p class="form-field psr-field"><label for="psr_provider">' . esc_html__( 'Provider', 'provider-shipping' ) . '</label>';
		self::select( 'psr_provider', $value, 'psr_provider' );
		echo '<span class="woocommerce-help-tip" data-tip="' . esc_attr__( 'Which supplier ships this product. Variations inherit it unless they declare their own. Provider Shipping prices goods through the provider\'s linked shipping method.', 'provider-shipping' ) . '"></span>';
		echo '</p>';
		echo '<p class="form-field psr-field"><label for="psr_provider_note">' . esc_html__( 'Internal note', 'provider-shipping' ) . '</label>';
		echo '<input type="text" name="psr_provider_note" id="psr_provider_note" value="' . esc_attr( $note ) . '" />';
		echo '<span class="woocommerce-help-tip" data-tip="' . esc_attr__( 'For staff only. Never shown to customers.', 'provider-shipping' ) . '"></span>';
		echo '</p>';
		echo '<p class="form-field psr-field"><label>&nbsp;</label><button type="button" class="button psr-quote-button" data-product="' . (int) $post_ID . '">' . esc_html__( 'Test quote this product', 'provider-shipping' ) . '</button> '
			. '<input type="text" class="psr-q-country" size="3" maxlength="2" placeholder="US" /> '
			. '<input type="text" class="psr-q-postcode" size="8" placeholder="postcode" /> '
			. '<input type="number" class="psr-q-qty" size="2" value="1" min="1" max="20" /></p>';
		echo '<p class="form-field psr-field"><span class="psr-test-out"></span></p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * The select, rendered in the General panel too so the field is reachable in every
	 * WooCommerce editor version.
	 */
	public static function classic_field() {
		global $post_ID;

		$value = '';
		if ( $post_ID && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $post_ID );
			if ( $product instanceof WC_Product ) {
				$value = (string) $product->get_meta( PSR_Registry::META_KEY, true );
			}
		}

		echo '<div class="options_group psr-classic">';
		echo '<p class="form-field psr-field"><label for="psr_provider">' . esc_html__( 'Provider', 'provider-shipping' ) . '</label>';
		self::select( 'psr_provider', $value );
		echo '<span class="woocommerce-help-tip" data-tip="' . esc_attr__( 'Which supplier ships this product. Variations inherit it. Provider Shipping prices goods through the provider\'s linked shipping method.', 'provider-shipping' ) . '"></span>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * @param int        $post_id Post id.
	 * @param WC_Product $product Product.
	 */
	public static function save_classic( $post_id, $product = null ) {
		if ( ! isset( $_POST['psr_provider'] ) && ! isset( $_POST['_psr_provider'] ) ) {
			return;
		}
		$product = $product instanceof WC_Product ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( (int) $post_id ) : null );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$raw = isset( $_POST['psr_provider'] ) ? (string) wp_unslash( $_POST['psr_provider'] ) : (string) wp_unslash( $_POST['_psr_provider'] );
		self::write( $product, $raw );
	}

	/**
	 * A provider select on every variation row (the Variations pane).
	 *
	 * @param int        $loop       Row index.
	 * @param array|null $var        Variation data.
	 * @param WC_Product $variation  Variation.
	 */
	public static function variation_field( $loop, $var, $variation ) {
		$value = $variation instanceof WC_Product ? (string) $variation->get_meta( PSR_Registry::META_KEY, true ) : '';
		echo '<label class="psr-var-label" for="psr_provider_' . (int) $loop . '">' . esc_html__( 'Provider', 'provider-shipping' ) . '</label>';
		self::select( 'psr_provider[' . (int) $loop . ']', $value, 'psr_provider_' . (int) $loop );
		echo '<input type="checkbox" class="chb" name="psr_provider_keep[' . (int) $loop . ']" value="yes" checked="checked" title="' . esc_attr__( 'Keep the current provider', 'provider-shipping' ) . '" />';
	}

	/**
	 * @param array      $saved      Saved variation data.
	 * @param WC_Product $variation  Variation.
	 * @param array      $post       Posted variation row.
	 */
	public static function variation_save( $saved, $variation, $post ) {
		if ( ! isset( $post['psr_provider'] ) || ! $variation instanceof WC_Product ) {
			return $saved;
		}
		$slug = PSR_Registry::alias( (string) $post['psr_provider'] );
		if ( '' !== $slug ) {
			$variation->update_meta_data( PSR_Registry::META_KEY, $slug );
			$variation->save();
		}
		return $saved;
	}

	/* ------------------------------------------------------------------ *
	 * Products list, quick and bulk edit
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $columns List columns.
	 * @return array
	 */
	public static function column( $columns ) {
		$columns['psr_provider'] = __( 'Provider', 'provider-shipping' );
		return $columns;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 */
	public static function column_value( $column, $post_id ) {
		if ( 'psr_provider' !== $column || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( (int) $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$slug = PSR_Registry::provider_for( $product );
		$row  = $slug ? PSR_Registry::get( $slug ) : null;

		if ( '' === $slug ) {
			echo '<span class="psr-pill psr-pill-none">' . esc_html__( 'not set → site default', 'provider-shipping' ) . '</span>';
			return;
		}
		$off = ( $row && 'no' === $row['enabled'] ) ? ' psr-pill-off' : '';
		echo '<span class="psr-pill' . esc_attr( $off ) . '">' . esc_html( $row ? $row['label'] : $slug ) . '</span>';
	}

	/**
	 * Quick Edit.
	 */
	public static function quick_edit() {
		$value = '';
		if ( isset( $_GET['post'] ) ) {
			$value = (string) get_post_meta( (int) $_GET['post'], PSR_Registry::META_KEY, true );
		}
		echo '<div class="inline-columns"><label for="psr_provider_qe">' . esc_html__( 'Provider', 'provider-shipping' ) . '</label>';
		self::select( 'psr_provider', $value, 'psr_provider_qe' );
		echo '</div>';
	}

	/**
	 * Values shown in the inline editor.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Inline data.
	 * @return array
	 */
	public static function quick_edit_inline( $post_id, $data = array() ) {
		$value = (string) get_post_meta( (int) $post_id, PSR_Registry::META_KEY, true );
		echo '<field name="psr_provider" value="' . esc_attr( $value ) . '" />';
		if ( is_array( $data ) ) {
			$data['psr_provider'] = $value;
			return $data;
		}
		return $data;
	}

	/**
	 * @param array $bulk Bulk edit options.
	 * @return array
	 */
	public static function bulk_edit( $bulk ) {
		$options = self::options();
		unset( $options[''] );
		$bulk['psr_provider'] = $options;
		return $bulk;
	}

	/**
	 * @param string $which  top|bottom.
	 * @param array  $bulk   Request.
	 * @param string $stat   Result.
	 * @param array  $results Counted ids.
	 */
	public static function bulk_edit_save( $which, $bulk, $stat, $results ) {
		if ( empty( $bulk['psr_provider'] ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$slug = PSR_Registry::alias( (string) $bulk['psr_provider'] );
		if ( '' === $slug ) {
			return;
		}
		foreach ( (array) $results['ids'] as $post_id ) {
			self::write( wc_get_product( (int) $post_id ), $slug );
		}
	}

	/* ------------------------------------------------------------------ *
	 * CSV
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $columns Exporter / importer columns.
	 * @return array
	 */
	public static function csv_column( $columns ) {
		$columns['psr_provider'] = __( 'Provider', 'provider-shipping' );
		return $columns;
	}

	/* ------------------------------------------------------------------ *
	 * The product page
	 * ------------------------------------------------------------------ */

	/**
	 * "Ships from: X", under the site's visibility switch. Hidden by default, because the
	 * site owner keeps supplier identity private; per-provider visibility is a second gate,
	 * so a provider can never be named by a customer if its row says not to show it.
	 */
	public static function line() {
		if ( ! function_exists( 'wc_get_product' ) || ! is_singular( 'product' ) ) {
			return;
		}
		global $product;
		$product = is_object( $product ) ? $product : ( get_the_ID() ? wc_get_product( get_the_ID() ) : null );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$mode = (string) get_option( 'psr_show_provider', PSR_Registry::SHOW_HIDDEN );
		if ( PSR_Registry::SHOW_HIDDEN === $mode ) {
			return;
		}
		if ( PSR_Registry::SHOW_ADMIN === $mode && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$slug   = PSR_Registry::provider_for( $product );
		$pricing = PSR_Registry::pricing_provider_for( $product );
		$row     = PSR_Registry::get( $pricing['slug'] );

		// Gate 2: this provider's name is not public.
		if ( ! $row || 'yes' !== $row['visible'] ) {
			return;
		}
		// Gate 3: the site has not allowed supplier names for customers at all.
		if ( 'yes' !== (string) get_option( 'psr_public_names', 'no' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="psr-ships-from">'
			. esc_html__( 'Ships from', 'provider-shipping' ) . ': <span>' . esc_html( $row['label'] ) . '</span>'
			. ( '' === $slug && current_user_can( 'manage_woocommerce' ) ? ' <em>' . esc_html__( '(site default)', 'provider-shipping' ) . '</em>' : '' )
			. '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Test quote button
	 * ------------------------------------------------------------------ */

	/**
	 * Price this product alone, to a postcode, through its provider's driver.
	 */
	public static function test_quote() {
		check_ajax_referer( 'psr_test_quote', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'error' => 'not allowed' ) );
		}
		$id      = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$country = isset( $_POST['country'] ) ? PSR_Registry::country( $_POST['country'] ) : '';
		$qty     = isset( $_POST['qty'] ) ? max( 1, (int) $_POST['qty'] ) : 1;

		if ( ! $id || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( array( 'error' => 'no product' ) );
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( array( 'error' => 'no product' ) );
		}

		$package = array(
			'id'             => 'psr-test',
			'contents'       => array(
				'psr-' . $product->get_id() => array(
					'data'     => $product,
					'quantity' => $qty,
				),
			),
			'subtotal'       => (float) $product->get_price() * $qty,
			'count'          => $qty,
			'weight'         => (float) $product->get_weight() * $qty,
			'destination'    => array(
				'country' => $country,
				'state'   => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
				'postcode' => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '',
				'city'    => '',
				'address' => '',
			),
			'needs_shipping' => true,
		);

		$quote = PSR_Quote::quote_package( $package );
		wp_send_json( $quote );
	}

	/* ------------------------------------------------------------------ *
	 * Shared pieces
	 * ------------------------------------------------------------------ */

	/**
	 * @return array '' => inherit, slug => label
	 */
	public static function options() {
		$out = array( '' => __( '— inherit / not set —', 'provider-shipping' ) );
		foreach ( PSR_Registry::all() as $slug => $row ) {
			$out[ $slug ] = $row['label'] . ( 'no' === $row['enabled'] ? ' (' . __( 'off', 'provider-shipping' ) . ')' : '' );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @param string $name  Field name.
	 * @param string $value Current value.
	 * @param string $id    Field id.
	 */
	protected static function select( $name, $value, $id = '' ) {
		$id = $id ? $id : preg_replace( '/[^a-z0-9_]/i', '_', (string) $name );
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" class="psr-select">';
		foreach ( self::options() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Write the provider on one product. Nothing else on the product is touched.
	 *
	 * @param WC_Product|null $product Product.
	 * @param string          $slug    Provider slug ('' clears it).
	 * @return bool
	 */
	public static function write( $product, $slug ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$slug = PSR_Registry::alias( (string) $slug );
		if ( '' === $slug ) {
			$product->delete_meta_data( PSR_Registry::META_KEY );
		} else {
			$product->update_meta_data( PSR_Registry::META_KEY, $slug );
		}
		$product->save();
		return true;
	}

	/**
	 * Editor styles.
	 */
	public static function style() {
		echo '<style>
		.psr-pill{background:#e7f4e8;border:1px solid #bfe0c6;border-radius:3px;padding:2px 6px;font-size:11px;white-space:nowrap}
		.psr-pill-none{background:#f4f4f4;border-color:#ddd;color:#646970}
		.psr-pill-off{background:#f7e5e5;border-color:#e0bfbf;color:#984110}
		.psr-select{max-width:200px}
		.psr-var-label{display:inline-block;width:80px}
		.psr-ships-from{font-size:12px;color:#50575e;margin:4px 0}
		.psr-test-quote{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px}
		.psr-test-quote input{width:90px}
		.psr-test-out{display:block;margin-top:6px;font-size:12px;color:#50575e;max-width:520px}
		</style>';
	}

	/**
	 * Test-quote button: markup + fetch, no plugin-specific JS file.
	 */
	public static function script() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		$url   = admin_url( 'admin-ajax.php' );
		$nonce = wp_create_nonce( 'psr_test_quote' );
		$js = <<<'JS'
(function () {
	function block(root) {
		if ( ! root || root.dataset.psrQ ) { return; }
		root.dataset.psrQ = "1";
		var wrap = document.createElement("p");
		wrap.className = "psr-test-quote";
		wrap.innerHTML = "<strong>Test quote</strong>"
			+ "<input type=\"text\" data-psr=\"country\" placeholder=\"country US\" maxlength=\"2\" />"
			+ "<input type=\"text\" data-psr=\"postcode\" placeholder=\"postcode\" />"
			+ "<input type=\"number\" data-psr=\"qty\" value=\"1\" min=\"1\" max=\"20\" />"
			+ "<button type=\"button\" class=\"button\">quote this product to this address</button>"
			+ "<span class=\"psr-test-out\"></span>";
		root.appendChild(wrap);
		wrap.querySelector("button").addEventListener("click", function () {
			var out = wrap.querySelector(".psr-test-out");
			var id  = (new URLSearchParams(location.search)).get("post");
			out.textContent = "quoting...";
			var f = new FormData();
			f.set("action", "psr_test_quote");
			f.set("nonce", "__NONCE__");
			f.set("product_id", id);
			f.set("country", wrap.querySelector("[data-psr=country]").value);
			f.set("postcode", wrap.querySelector("[data-psr=postcode]").value);
			f.set("qty", wrap.querySelector("[data-psr=qty]").value);
			fetch("__URL__", { method: "POST", credentials: "same-origin", body: f })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					var d = j && j.data ? j.data : j;
					if ( ! d ) { out.textContent = "no answer"; return; }
					var lines = [];
					(d.providers || []).forEach(function (p) {
						lines.push(p.label + " " + p.cost + " (" + p.source + ")" + (p.note ? " - " + p.note : ""));
					});
					out.textContent = (d.label || "") + " = " + (d.total || "?")
						+ (lines.length ? " | " + lines.join(" | ") : "")
						+ (d.action && d.action === "block" ? " | BLOCKS THE SALE: nothing can ship this cart" : "");
				})
				.catch(function () { out.textContent = "request failed"; });
		});
	}
	function mount() {
		block(document.querySelector("#general-product-tab"));
		block(document.querySelector("#psr_tab_data"));
		block(document.querySelector(".psr-classic"));
	}
	mount();
	document.addEventListener("click", function () { setTimeout(mount, 400); }, true);
})();
JS;

		echo '<script>' . str_replace(
			array( '__NONCE__', '__URL__' ),
			array( esc_js( $nonce ), esc_url( $url ) ),
			$js
		) . '</script>';
	}
}
