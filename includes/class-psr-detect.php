<?php
/**
 * Provider detect: ask each supplier which of the store's SKUs are theirs.
 *
 * This is the ground truth for the registry, and it comes from the suppliers' own endpoints,
 * not from guessing at SKU shapes:
 *
 *   Printify - the same shipping callback Printify's own plugin posts at checkout
 *              (POST api-prod.printify.com/v1/callbacks/woo/shipping). Its answer names the
 *              SKUs it recognises and what shipping that whole set costs. No key is needed
 *              for this call; the plugin ships with it.
 *   Printful - the sync plugin's own meta (`_printful_product_id`, its store mapping).
 *   Tapstitch - publishes a rate table by country and category, not by SKU. So the evidence
 *              is: the product's shipping class is a category Tapstitch prices, and no other
 *              supplier claims the SKU.
 *
 * Nothing is written from here without the site owner ticking rows and pressing Apply. Apply
 * snapshots the products it is about to change, so Restore puts them back.
 *
 * @package provider-shipping
 */

defined( 'ABSPATH' ) || exit;

class PSR_Detect {

	const PRINTIFY_URL = 'https://api-prod.printify.com/v1/callbacks/woo/shipping';
	const TAPSTITCH_URL = 'https://www.tapstitch.com/api/services/site/shipping-rates';
	const TTL          = 900;

	/**
	 * The report: one row per product, with what each supplier claimed.
	 *
	 * @param string $country Country to probe with.
	 * @return array
	 */
	public static function report( $country = '' ) {
		$country = $country ? PSR_Registry::country( $country ) : (string) get_option( 'psr_detect_country', 'US' );

		$catalog  = self::catalog();
		$skus     = array();
		foreach ( $catalog as $row ) {
			foreach ( $row['skus'] as $sku ) {
				$skus[] = $sku;
			}
		}

		$printify = self::probe_printify( $skus, $country );
		$printful = self::probe_printful();
		$tapslug  = self::tapstitch_categories( $country );

		$rows = array();
		foreach ( $catalog as $product ) {
			$claims = array();
			$costs  = array();

			foreach ( $product['skus'] as $sku ) {
				if ( isset( $printify['skus'][ $sku ] ) ) {
					$claims['printify'] = 'printify';
				}
				if ( isset( $printful[ $sku ] ) ) {
					$claims['printful'] = 'printful';
				}
			}

			$class_match = ( '' !== $product['class'] && isset( $tapslug[ $product['class'] ] ) ) ? $product['class'] : '';
			$suggest     = '';
			$why         = array();

			if ( isset( $claims['printify'] ) ) {
				$suggest = 'printify';
				$why[]   = 'Printify claims ' . count( $product['skus'] ) . ' of its SKUs';
			} elseif ( isset( $claims['printful'] ) ) {
				$suggest = 'printful';
				$why[]   = 'Printful claims ' . count( $product['skus'] ) . ' of its SKUs';
			} elseif ( '' !== $class_match ) {
				$suggest = 'tapstitch';
				$why[]   = 'no other supplier claims it, and Tapstitch prices class ' . $class_match;
			} else {
				$suggest = 'in-house';
				$why[]   = 'no supplier claims it and no Tapstitch category matches its class';
			}

			$cost = '';
			if ( 'printify' === $suggest && $printify['standard'] ) {
				$cost = '$' . number_format( (float) $printify['standard'], 2 ) . ' for the claimed set, ' . $country;
			}
			if ( 'tapstitch' === $suggest && isset( $tapslug[ $class_match ] ) ) {
				$cost = 'Tapstitch first item $' . number_format( (float) $tapslug[ $class_match ], 2 ) . ', ' . $country;
			}

			$rows[] = array(
				'product'    => (int) $product['id'],
				'name'       => $product['name'],
				'type'       => $product['type'],
				'skus'       => implode( ' ', array_slice( $product['skus'], 0, 3 ) ) . ( count( $product['skus'] ) > 3 ? ' +' . ( count( $product['skus'] ) - 3 ) : '' ),
				'sku_count'  => count( $product['skus'] ),
				'class'      => $product['class'],
				'variations' => (int) $product['variations'],
				'claimed_by' => $claims ? implode( '+', array_keys( $claims ) ) : '',
				'suggest'    => $suggest,
				'why'        => implode( '; ', $why ),
				'cost'       => $cost,
				'current'    => $product['provider'],
			);
		}

		return array(
			'country'  => $country,
			'probed'   => array( 'printify', 'printful', 'tapstitch-categories' ),
			'printify' => $printify,
			'printful' => $printful,
			'tapstitch' => $tapslug,
			'rows'     => $rows,
		);
	}

	/**
	 * Every published product, its variations and their SKUs.
	 *
	 * @return array
	 */
	protected static function catalog() {
		$out = array();
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $out;
		}

		foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects' ) ) as $product ) {
			if ( $product->is_type( 'variation' ) ) {
				continue;
			}

			$skus  = array();
			$kids  = 0;
			$klass = (string) $product->get_shipping_class();

			if ( '' !== (string) $product->get_sku() ) {
				$skus[] = (string) $product->get_sku();
			}
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( ! $child instanceof WC_Product ) {
					continue;
				}
				$kids++;
				if ( '' !== (string) $child->get_sku() ) {
					$skus[] = (string) $child->get_sku();
				}
				if ( '' === $klass ) {
					$klass = (string) $child->get_shipping_class();
				}
			}

			$out[] = array(
				'id'         => (int) $product->get_id(),
				'name'       => (string) $product->get_name(),
				'type'       => (string) $product->get_type(),
				'skus'       => $skus,
				'class'      => $klass,
				'variations' => $kids,
				'provider'   => PSR_Registry::provider_for( $product ),
			);
		}

		return $out;
	}

	/**
	 * Printify's own answer: the SKUs it recognises, and the cost of shipping them.
	 *
	 * Payload shape is Printify's plugin's own (items keyed by SKU, with country/state/zip).
	 * Their response spells the standard rate "shipping_standart".
	 *
	 * @param array  $skus    Store SKUs.
	 * @param string $country Country code.
	 * @return array skus|standard|express|source
	 */
	public static function probe_printify( $skus, $country ) {
		$out = array(
			'skus'      => array(),
			'standard'  => null,
			'express'   => null,
			'source'    => 'not probed',
		);

		$skus = array_values( array_filter( array_map( 'trim', (array) $skus ) ) );
		if ( empty( $skus ) || '' === $country ) {
			$out['source'] = 'no skus or no country';
			return $out;
		}

		$key = 'psr_probe_printify_' . md5( implode( '|', $skus ) . '|' . $country );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$items = array();
		foreach ( $skus as $sku ) {
			$items[ $sku ] = array(
				'sku'        => $sku,
				'product_id' => 0,
				'parent_id'  => 0,
				'permalink'  => home_url( '/' ),
				'quantity'   => 1,
			);
		}

		$body = wp_json_encode(
			array(
				'items'   => $items,
				'country' => $country,
				'state'   => '',
				'zip'     => '',
			)
		);

		$res = wp_remote_post(
			self::PRINTIFY_URL . '?version=3.2',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $res ) ) {
			$out['source'] = 'request failed: ' . $res->get_error_code();
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			$out['source'] = 'http ' . $code;
			set_transient( $key, $out, 60 );
			return $out;
		}

		foreach ( (array) ( isset( $json['skus'] ) ? $json['skus'] : array() ) as $sku ) {
			$out['skus'][ (string) $sku ] = true;
		}
		$out['standard'] = self::cents( $json, 'shipping_standart' );
		if ( null === $out['standard'] ) {
			$out['standard'] = self::cents( $json, 'shipping_standard' );
		}
		$out['express'] = self::cents( $json, 'shipping_express' );
		$out['source']  = 'printify api, ' . count( $out['skus'] ) . ' skus claimed';

		set_transient( $key, $out, self::TTL );
		return $out;
	}

	/**
	 * Printful's own list of mapped products, from its plugin.
	 *
	 * @return array sku => true
	 */
	public static function probe_printful() {
		$out = array();

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $out;
		}

		// Printful's plugin tags what it syncs. That tag is its own statement about the item.
		foreach ( (array) wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects' ) ) as $product ) {
			foreach ( array( '_printful_product_id', 'printful_product_id', '_printful_variant_id' ) as $key ) {
				if ( (string) $product->get_meta( $key, true ) && (string) $product->get_sku() ) {
					$out[ (string) $product->get_sku() ] = true;
				}
			}
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( ! $child instanceof WC_Product ) {
					continue;
				}
				foreach ( array( '_printful_product_id', 'printful_product_id', '_printful_variant_id' ) as $key ) {
					if ( (string) $child->get_meta( $key, true ) && (string) $child->get_sku() ) {
						$out[ (string) $child->get_sku() ] = true;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Categories Tapstitch publishes a rate for, with the first-item cost.
	 *
	 * @param string $country Country code.
	 * @return array category_slug => first item cost
	 */
	public static function tapstitch_categories( $country ) {
		$out = array();
		if ( '' === $country ) {
			return $out;
		}

		$key = 'psr_probe_tapstitch_' . $country;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res = wp_remote_get(
			self::TAPSTITCH_URL . '?countryCodes=' . rawurlencode( $country ) . '&categorySlugs=hoodies,sweatpants,t-shirts,sweatshirts,shorts,tank-tops,long-sleeve-shirts,sportswear',
			array( 'timeout' => 20 )
		);

		if ( is_wp_error( $res ) ) {
			set_transient( $key, $out, 60 );
			return $out;
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			set_transient( $key, $out, 60 );
			return $out;
		}

		$slugs = array();
		if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
			$slugs = $json['data'];
		} elseif ( isset( $json['rates'] ) && is_array( $json['rates'] ) ) {
			$slugs = $json['rates'];
		} elseif ( isset( $json[0] ) && is_array( $json[0] ) ) {
			$slugs = $json;
		}

		foreach ( (array) $slugs as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row['categorySlug'] ) ? strtolower( (string) $row['categorySlug'] ) : '';
			$cost = isset( $row['shippingCost'] ) ? ( (float) $row['shippingCost'] / 100 ) : 0;
			if ( '' === $slug && isset( $row['categoryName'] ) ) {
				$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $row['categoryName'] ) );
			}
			if ( '' !== $slug && $cost > 0 && ( ! isset( $out[ $slug ] ) || $cost > $out[ $slug ] ) ) {
				$out[ $slug ] = round( $cost, 2 );
			}
		}

		set_transient( $key, $out, self::TTL );
		return $out;
	}

	/**
	 * Write provider meta for the rows the site owner ticked.
	 *
	 * Snapshot first: `psr_detect_snapshot` holds id => { provider, class }, and Restore
	 * writes exactly that back. Only `_psr_provider` is written; a product's name, price,
	 * status, slug, class and catalogue membership are never touched from here.
	 *
	 * @param array $picked product_id => provider slug (the ticked rows).
	 * @return array products|variations|skipped
	 */
	public static function apply( $picked ) {
		$made = array(
			'products'   => 0,
			'variations' => 0,
			'skipped'    => array(),
		);

		$picked = (array) $picked;
		if ( empty( $picked ) ) {
			return $made;
		}

		self::snapshot( array_keys( $picked ) );

		foreach ( $picked as $id => $slug ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;
			if ( ! $product instanceof WC_Product ) {
				$made['skipped'][] = (int) $id . ' not a product';
				continue;
			}
			$slug = PSR_Registry::alias( (string) $slug );
			if ( '' === $slug || ! PSR_Registry::get( $slug ) ) {
				$made['skipped'][] = (int) $id . ' provider ' . $slug . ' is not in the registry';
				continue;
			}

			PSR_Product::write( $product, $slug );
			$made['products']++;

			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( $child instanceof WC_Product ) {
					PSR_Product::write( $child, $slug );
					$made['variations']++;
				}
			}
		}

		return $made;
	}

	/**
	 * Store what these products say today, before anything is written.
	 *
	 * @param array $ids Product ids.
	 * @return int
	 */
	public static function snapshot( $ids ) {
		$snap = get_option( 'psr_detect_snapshot', array() );
		if ( ! is_array( $snap ) ) {
			$snap = array();
		}
		foreach ( (array) $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$snap[ (int) $id ] = array(
				'name'     => (string) $product->get_name(),
				'provider' => PSR_Registry::provider_for( $product ),
				'own_meta' => (string) $product->get_meta( PSR_Registry::META_KEY, true ),
			);
		}
		update_option( 'psr_detect_snapshot', $snap );
		return count( $snap );
	}

	/**
	 * Put the snapshot back.
	 *
	 * @return array products restored
	 */
	public static function restore() {
		$snap = (array) get_option( 'psr_detect_snapshot', array() );
		$n    = 0;
		foreach ( $snap as $id => $row ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			PSR_Product::write( $product, isset( $row['own_meta'] ) ? $row['own_meta'] : '' );
			$n++;
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( $child instanceof WC_Product ) {
					PSR_Product::write( $child, isset( $row['own_meta'] ) ? $row['own_meta'] : '' );
				}
			}
		}
		return array( 'products' => $n, 'variations' => 0 );
	}

	/**
	 * @param array  $res  Parsed response.
	 * @param string $key  Key to read.
	 * @return float|null
	 */
	protected static function cents( $res, $key ) {
		if ( ! is_array( $res ) || ! isset( $res[ $key ] ) || ! is_array( $res[ $key ] ) || ! isset( $res[ $key ]['cost'] ) ) {
			return null;
		}
		$cost = (float) $res[ $key ]['cost'];
		return $cost > 0 ? round( $cost / 100, 2 ) : null;
	}
}
