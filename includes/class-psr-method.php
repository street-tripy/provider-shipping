<?php
/**
 * The plugin's shipping method: the merged provider rate.
 *
 * A zone instance of this method is what produces the customer-facing rate in Live mode.
 * It prices the package through PSR_Quote (every provider in the cart, priced by its own
 * driver, margin applied, merged, rounded), labels it per the display mode, and carries the
 * per-provider sub-totals on the rate.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Method extends WC_Shipping_Method {

	/** @var array Last quote. */
	protected $quote = array();

	/** @var string What happened, for the log and the status screen. */
	protected $note = '';

	/**
	 * @param int $instance_id Zone method instance id.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'psr_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_id          = 'psr_shipping';
		$this->method_title       = __( 'Provider Shipping', 'provider-shipping' );
		$this->method_description = __( 'Prices a cart through the providers its items belong to, using the shipping methods linked in Settings → Shipping → Providers, and offers one merged rate.', 'provider-shipping' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );

		$this->init();
	}

	/**
	 * Load settings.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled      = 'yes' === $this->get_option( 'enabled', 'yes' ) ? 'yes' : 'no';
		$this->title        = (string) $this->get_option( 'title', __( 'Shipping', 'provider-shipping' ) );
		$this->multi_label  = (string) $this->get_option( 'multi_label', __( 'Shipping (%d providers)', 'provider-shipping' ) );
		$this->show_break   = (string) $this->get_option( 'breakdown', 'admin' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Instance settings.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable this method', 'provider-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer this rate in this zone', 'provider-shipping' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => __( 'Rate title', 'provider-shipping' ),
				'type'    => 'text',
				'default' => __( 'Shipping', 'provider-shipping' ),
				'desc'    => __( 'Used when one provider ships the whole cart.', 'provider-shipping' ),
			),
			'multi_label' => array(
				'title'   => __( 'Multi-provider title', 'provider-shipping' ),
				'type'    => 'text',
				'default' => __( 'Shipping (%d providers)', 'provider-shipping' ),
				'desc'    => __( '%d is replaced by the number of providers. Supplier names are not shown to customers unless the site allows them.', 'provider-shipping' ),
			),
			'breakdown'   => array(
				'title'   => __( 'Sub-totals under the rate', 'provider-shipping' ),
				'type'    => 'select',
				'default' => 'admin',
				'options' => array(
					'hidden' => __( 'Never', 'provider-shipping' ),
					'admin'  => __( 'Staff only', 'provider-shipping' ),
					'public' => __( 'Everyone', 'provider-shipping' ),
				),
				'desc'    => __( 'The per-provider amounts, in the store\'s base currency - the money the suppliers bill.', 'provider-shipping' ),
			),
			'min_total'   => array(
				'title'   => __( 'Never below this total', 'provider-shipping' ),
				'type'    => 'text',
				'default' => '',
				'desc'    => __( 'Optional. A floor for the merged rate, in the store\'s base currency. Shipping is never free by accident.', 'provider-shipping' ),
			),
			'debug'       => array(
				'title'   => __( 'Log every quote', 'provider-shipping' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write the per-provider maths to the plugin log', 'provider-shipping' ),
				'default' => 'yes',
			),
		);
	}

	/** @var string */
	public $multi_label = 'Shipping (%d providers)';

	/** @var string */
	public $show_break = 'admin';

	/**
	 * Price the package.
	 *
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
		$this->quote = PSR_Quote::quote_package( $package );
		$this->note  = 'block' === $this->quote['action'] ? 'no quote: ' . $this->quote['reason'] : 'ok';

		if ( 'block' === $this->quote['action'] ) {
			$this->log();
			return; // no rate at all: PSR_Guard is what stops the sale.
		}

		$mode = (string) get_option( 'psr_display_mode', PSR_Registry::DISPLAY_MERGED_ADMIN );

		if ( PSR_Registry::DISPLAY_PER_PROVIDER === $mode ) {
			$this->add_per_provider_rates();
			$this->log();
			return;
		}

		$total = (float) $this->quote['total'];
		$floor = (float) $this->get_option( 'min_total' );
		if ( $floor > 0 && $total < $floor ) {
			$total = $floor;
		}
		if ( $total <= 0 ) {
			$this->note = 'merged total is zero - refused';
			$this->log();
			return;
		}

		$this->add_rate(
			array(
				'id'        => 'psr_shipping_' . $this->instance_id,
				'label'     => $this->label(),
				'cost'      => $total,
				'calc_tax'  => 'per_item',
				'meta_data' => $this->meta( $total ),
			)
		);

		$this->note = 'merged ' . number_format( $total, 2 );
		$this->log();
	}

	/**
	 * Per-provider display: one shipping choice per provider.
	 */
	protected function add_per_provider_rates() {
		$names_public = 'yes' === (string) get_option( 'psr_public_names', 'no' );
		$labels       = array();

		foreach ( (array) $this->quote['providers'] as $p ) {
			if ( ! $p['ok'] || (float) $p['cost'] <= 0 ) {
				continue;
			}
			$label = $names_public ? $p['label'] : __( 'Shipping', 'provider-shipping' );
			if ( isset( $labels[ $label ] ) ) {
				$label .= ' ' . ( count( $labels ) + 1 );
			}
			$labels[ $label ] = true;

			$this->add_rate(
				array(
					'id'        => 'psr_shipping_' . $this->instance_id . '_' . $p['slug'],
					'label'     => $label,
					'cost'      => (float) $p['cost'],
					'calc_tax'  => 'per_item',
					'meta_data' => array(
						'psr_provider' => $p['slug'],
						'psr_cost'     => number_format( (float) $p['cost'], 2, '.', '' ),
						'psr_source'   => (string) $p['source'],
					),
				)
			);
		}

		$this->note = 'per-provider, ' . count( $labels ) . ' rate(s)';
	}

	/**
	 * The customer-facing label.
	 *
	 * @return string
	 */
	public function label() {
		$labels = array();
		foreach ( (array) $this->quote['providers'] as $p ) {
			$labels[] = $p['label'];
		}

		if ( count( $labels ) < 2 ) {
			return $this->title ? $this->title : __( 'Shipping', 'provider-shipping' );
		}

		$public = 'yes' === (string) get_option( 'psr_public_names', 'no' );
		if ( ! $public ) {
			$fmt = strpos( $this->multi_label, '%d' ) !== false ? $this->multi_label : __( 'Shipping (%d providers)', 'provider-shipping' );
			return sprintf( $fmt, count( $labels ) );
		}
		/* translators: %s: provider labels */
		return sprintf( __( 'Shipping (%s)', 'provider-shipping' ), implode( ' + ', $labels ) );
	}

	/**
	 * Meta on the rate: the maths, so an order can be reconciled against supplier invoices and
	 * the sub-totals survive WooCommerce's session cache.
	 *
	 * Values must be scalars: WC_Shipping_Method::add_rate() runs meta through wc_clean(), and
	 * array values arrive as empty strings.
	 *
	 * @param float $total Merged total.
	 * @return array
	 */
	protected function meta( $total ) {
		$lines = PSR_Quote::breakdown( $this->quote );
		$flat  = array();
		foreach ( $lines as $i => $line ) {
			$flat[ 'l' . $i ] = $line['provider'] . '|' . $line['note'] . '|' . $line['cost'];
		}

		return array(
			'psr_providers'  => implode( '+', wp_list_pluck( (array) $this->quote['providers'], 'slug' ) ),
			'psr_labels'     => implode( ' + ', wp_list_pluck( (array) $this->quote['providers'], 'label' ) ),
			'psr_total'      => number_format( (float) $total, 2, '.', '' ),
			'psr_breakdown'  => wp_json_encode( $flat ),
			'psr_lines'      => (string) count( $lines ),
			'psr_source'     => implode( ' | ', wp_list_pluck( (array) $this->quote['providers'], 'source' ) ),
			'psr_note'       => (string) $this->note,
		);
	}

	/**
	 * Per-provider trace, for the log.
	 */
	protected function log() {
		if ( 'yes' !== $this->get_option( 'debug', 'yes' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$parts = array();
		foreach ( (array) $this->quote['providers'] as $p ) {
			$parts[] = $p['slug'] . ' x' . (int) $p['units'] . ' = ' . number_format( (float) $p['cost'], 2 ) . ' (' . $p['source'] . ( $p['note'] ? ' ' . $p['note'] : '' ) . ')';
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->info(
				'psr#' . $this->instance_id . ' ' . $this->note . ' :: ' . implode( ' | ', $parts ),
				array( 'source' => 'provider-shipping' )
			);
		}
	}

	/**
	 * @return array this instance's state, for the status screen.
	 */
	public function state() {
		return array(
			'instance' => (int) $this->instance_id,
			'enabled'  => $this->enabled,
			'title'    => $this->title,
			'label'    => $this->quote ? $this->label() : '',
			'total'    => isset( $this->quote['total'] ) ? $this->quote['total'] : 0,
			'note'     => $this->note,
			'providers' => wp_list_pluck( (array) ( isset( $this->quote['providers'] ) ? $this->quote['providers'] : array() ), 'slug' ),
		);
	}
}
