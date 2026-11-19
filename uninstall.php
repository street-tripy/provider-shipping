<?php
/**
 * Provider Shipping - uninstall.
 *
 * Deletes only this plugin's own options and transients. Product meta is left alone on
 * purpose: `_psr_provider` is inert once the plugin is gone, and deleting a store's product
 * data from an uninstall script is not a decision an uninstall script should make.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'psr_providers',
	'psr_display_mode',
	'psr_public_names',
	'psr_provider_note',
	'psr_show_provider',
	'psr_default_provider',
	'psr_pipeline_mode',
	'psr_live_fallback',
	'psr_block_no_quote',
	'psr_no_quote_message',
	'psr_selftest',
	'psr_detect_snapshot',
	'psr_detect_country',
	'psr_version',
);

foreach ( $options as $key ) {
	delete_option( $key );
	delete_site_option( $key );
}

// Caches. The rate probes store transients; they expire on their own, and clearing them here
// means a re-install starts from the suppliers' live answers, not from an old snapshot.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_psr_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_psr_' ) . '%'
	)
);

if ( is_multisite() ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->blogmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'psr_' ) . '%'
		)
	);
}
