<?php
/**
 * Platform detection: identify whether a target URL is Shopify or WooCommerce
 * by inspecting the served HTML (no credentials required).
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Detector {

	/**
	 * Detect the platform of a store.
	 *
	 * @param string $url Target store URL.
	 * @return array|WP_Error Array with platform, base_url and signals.
	 */
	public static function detect( $url ) {
		$base = SWI_HTTP::normalize_url( $url );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$html = SWI_HTTP::get_with_retry( $base );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$platform = '';
		$signals  = array();

		// Shopify markers in the HTML.
		if (
			preg_match( '#<meta[^>]+name=["\']generator["\'][^>]+content=["\']Shopify["\']#i', $html ) ||
			preg_match( '#Shopify\.theme\s*=#i', $html ) ||
			preg_match( '#cdn\.shopify\.com#i', $html )
		) {
			$platform = 'shopify';
			$signals[] = 'html-markers';
		}

		// WooCommerce markers in the HTML.
		if ( '' === $platform ) {
			if (
				preg_match( '#<meta[^>]+name=["\']generator["\'][^>]+content=["\'][^"\']*WooCommerce[^"\']*["\']#i', $html ) ||
				preg_match( '#wp-content/plugins/woocommerce#i', $html ) ||
				preg_match( '#wc-cart-fragments#i', $html )
			) {
				$platform = 'woocommerce';
				$signals[] = 'html-markers';
			}
		}

		// Fallback 1: Shopify public products.json endpoint.
		if ( '' === $platform ) {
			$test = SWI_HTTP::get_with_retry( rtrim( $base, '/' ) . '/products.json?limit=1' );
			if ( ! is_wp_error( $test ) ) {
				$decoded = json_decode( $test, true );
				if ( is_array( $decoded ) && isset( $decoded['products'] ) ) {
					$platform  = 'shopify';
					$signals[] = 'products.json';
				}
			}
		}

		// Fallback 2: WooCommerce Store API.
		if ( '' === $platform ) {
			$test = SWI_HTTP::get_with_retry( rtrim( $base, '/' ) . '/wp-json/wc/store/products?per_page=1' );
			if ( ! is_wp_error( $test ) ) {
				$decoded = json_decode( $test, true );
				if ( is_array( $decoded ) ) {
					$platform  = 'woocommerce';
					$signals[] = 'wc-store-api';
				}
			}
		}

		if ( '' === $platform ) {
			return new WP_Error( 'swi_unknown_platform', __( 'Could not detect the store platform. It may not be a Shopify or WooCommerce site.', 'swi' ) );
		}

		return array(
			'platform' => $platform,
			'base_url' => $base,
			'signals'  => $signals,
		);
	}
}
