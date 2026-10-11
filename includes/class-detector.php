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

		// A saved scraping profile takes precedence: a trained site is scraped
		// with its profile ("generic"), regardless of any platform markers.
		if ( null !== SWI_Profile::get( $base ) ) {
			return array(
				'platform' => 'generic',
				'base_url' => $base,
				'signals'  => array( 'profile' ),
			);
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

		// Fallback 3: AI. When no marker matched, let the AI analyze a product
		// page and build a scraping profile on the fly (auto-train).
		if ( '' === $platform ) {
			return self::detect_with_ai( $base );
		}

		return array(
			'platform' => $platform,
			'base_url' => $base,
			'signals'  => $signals,
		);
	}

	/**
	 * AI fallback: find a product page, ask the AI to build a scraping profile,
	 * save it, and report the site as "generic".
	 *
	 * @param string $base Normalized base URL.
	 * @return array|WP_Error
	 */
	private static function detect_with_ai( $base ) {
		// 1. Discover product URLs via the generic sitemap path.
		$urls = SWI_Crawler::discover( $base, 'generic' );
		if ( is_wp_error( $urls ) || empty( $urls ) ) {
			return new WP_Error( 'swi_unknown_platform', __( 'Could not detect the store platform and no products were found for AI analysis.', 'swi' ) );
		}

		// 2. Fetch a product page (try a few URLs in case one 404s).
		$html = null;
		foreach ( array_slice( $urls, 0, 3 ) as $product_url ) {
			$fetched = SWI_HTTP::get_with_retry( $product_url );
			if ( ! is_wp_error( $fetched ) && '' !== trim( $fetched ) ) {
				$html = $fetched;
				break;
			}
		}
		if ( null === $html ) {
			return new WP_Error( 'swi_unknown_platform', __( 'Could not detect the store platform (and no product page could be fetched for AI analysis).', 'swi' ) );
		}

		// 3. Ask the AI to build a profile.
		$profile = SWI_AI::analyze_page( $html );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$valid = SWI_Profile::validate( $profile );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// 4. Save it for this domain and proceed as "generic".
		SWI_Profile::save( $base, $valid );

		return array(
			'platform' => 'generic',
			'base_url' => $base,
			'signals'  => array( 'ai' ),
		);
	}
}
