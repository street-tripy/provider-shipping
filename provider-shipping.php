<?php
/**
 * Plugin Name:             Provider Shipping
 * Plugin URI:              https://github.com/street-tripy/provider-shipping
 * Description:             Provider registry, product provider meta and a merged checkout rate for a WooCommerce store that ships through several print-on-demand suppliers. Each provider is priced by the shipping method linked to it; the customer sees one rate.
 * Version:                 0.1.7
 * Requires at least:       6.0
 * Requires PHP:            7.4
 * Author:                  street-tripy
 * Author URI:              https://github.com/street-tripy
 * License:                 GPL-2.0-or-later
 * License URI:             https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             provider-shipping
 * WC requires at least:    6.0
 * WC tested up to:         11.3
 */

/**
 * Provider Shipping
 *
 * Owns four things:
 *
 *   1. the provider registry - who ships, which shipping method prices them, on what margin;
 *   2. the provider meta on products, and every screen that sets it (Product data tab,
 *      Quick/Bulk edit, the Products column, CSV, and Tools → Provider detect);
 *   3. what a customer is allowed to see (merged rate, sub-totals, supplier names);
 *   4. the rule that no shipping quote means no sale.
 *
 * It does not own the rate maths. Each provider's price comes from its driver: the supplier's
 * own shipping method, the store's Flat Rate, a fixed amount, or Printify's quote endpoint.
 * Adding a supplier is a row in the registry.
 *
 * Ships inert: the pipeline defaults to Off, so installing this plugin changes nothing at
 * checkout. Shadow mode logs the registry's answer beside what the zone charged, on the same
 * carts, which is how the merged numbers get compared before anything is trusted.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

define( 'PSR_VERSION', '0.1.7' );
define( 'PSR_FILE', __FILE__ );
define( 'PSR_PATH', plugin_dir_path( __FILE__ ) );
define( 'PSR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin bootstrap.
 */
final class Provider_Shipping_Plugin {

	/** @var Provider_Shipping_Plugin */
	private static $instance = null;

	/**
	 * @return Provider_Shipping_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Constructor work: files, hooks that must exist early, activation.
	 *
	 * Only dependency-free files are required here. Plugin main files are included in
	 * alphabetical order, and "provider-shipping" sorts before "woocommerce", so at this moment
	 * WooCommerce's own classes do not exist yet - a file that says
	 * `class PSR_Method extends WC_Shipping_Method` would fatal the whole site. That is exactly
	 * what the first 0.1.0 build did. The files with WooCommerce parents are required from
	 * load() below, which runs on plugins_loaded, after every plugin file is included.
	 */
	private function boot() {
		require_once PSR_PATH . 'includes/class-psr-registry.php';

		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'load' ), 25 );
		register_activation_hook( PSR_FILE, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Nothing in this plugin reads order tables, so custom order tables are fine, and the
	 * cart/checkout blocks are declared because the provider line is rendered by the classic
	 * hooks the block cart also fires.
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PSR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PSR_FILE, true );
		}
	}

	/**
	 * Translations live in /languages.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'provider-shipping', false, dirname( plugin_basename( PSR_FILE ) ) . '/languages' );
	}

	/**
	 * Only run when WooCommerce is present - and load the WooCommerce-dependent files here,
	 * not at file scope.
	 */
	public function load() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
			return;
		}

		require_once PSR_PATH . 'includes/class-psr-registry-ui.php';
		require_once PSR_PATH . 'includes/class-psr-driver.php';
		require_once PSR_PATH . 'includes/class-psr-quote.php';
		require_once PSR_PATH . 'includes/class-psr-method.php';
		require_once PSR_PATH . 'includes/class-psr-pipeline.php';
		require_once PSR_PATH . 'includes/class-psr-guard.php';
		require_once PSR_PATH . 'includes/class-psr-detect.php';
		require_once PSR_PATH . 'includes/class-psr-selftest.php';
		require_once PSR_PATH . 'includes/class-psr-settings.php';
		require_once PSR_PATH . 'includes/class-psr-product.php';
		require_once PSR_PATH . 'includes/class-psr-registry-page.php';
		require_once PSR_PATH . 'includes/class-psr-rest.php';

		PSR_Settings::register();
		PSR_Registry_UI::register();
		PSR_Product::register();
		PSR_Registry_Page::register();
		PSR_Rest::register();

		// The checkout engine. Registered at plugin boot, not from a method instance:
		// WooCommerce builds the cart's shipping packages before it constructs the methods
		// that price them, so a package-level rule cannot live inside a method.
		PSR_Pipeline::register();

		// No shipping quote, no sale. Registers nothing while tapstitch-shipping-method's
		// guard is active - one guard runs, and the status screen says which.
		PSR_Guard::register();

		add_filter( 'plugin_action_links_' . plugin_basename( PSR_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * @param string[] $links Plugin row actions.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&section=providers' ) ) . '">Providers</a>';
		$links[] = '<a href="' . esc_url( admin_url( 'tools.php?page=psr-registry' ) ) . '">Provider&nbsp;detect</a>';
		return $links;
	}

	/**
	 * @param string[] $meta Existing links.
	 * @param string   $file Plugin file.
	 * @return string[]
	 */
	public function row_meta( $meta, $file ) {
		if ( plugin_basename( PSR_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( admin_url( 'tools.php?page=psr-registry' ) ) . '">Self-check and detect</a>';
		}
		return $meta;
	}

	/**
	 * Seed the registry with this store's measured providers on activation. Nothing is
	 * seeded over an existing registry, and activating this plugin changes no checkout
	 * behaviour: the pipeline starts Off.
	 */
	public static function activate() {
		if ( ! get_option( PSR_Registry::OPTION ) ) {
			update_option( PSR_Registry::OPTION, PSR_Registry::defaults() );
		}
		if ( false === get_option( 'psr_pipeline_mode', false ) ) {
			update_option( 'psr_pipeline_mode', 'off' );
		}
	}
}

Provider_Shipping_Plugin::instance();
