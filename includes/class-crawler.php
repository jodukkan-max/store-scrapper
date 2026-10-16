<?php
/**
 * Product URL discovery: sitemaps first, then listing/collection pages.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Crawler {

	/**
	 * Discover product URLs for a store.
	 *
	 * @param string $base_url Target store URL.
	 * @param string $platform shopify|woocommerce.
	 * @return array|WP_Error Array of product URLs.
	 */
	public static function discover( $base_url, $platform ) {
		$base = rtrim( SWI_HTTP::normalize_url( $base_url ), '/' );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$urls = array();

		// 1. Sitemaps (highest yield, fewest requests).
		foreach ( self::candidate_sitemaps( $base, $platform ) as $sitemap ) {
			$found = self::parse_sitemap( $sitemap, 0 );
			$urls  = array_merge( $urls, $found );
			if ( count( $found ) > 0 ) {
				break;
			}
		}

		// 2. Fallback: crawl listing/collection pages.
		if ( empty( $urls ) ) {
			$urls = self::crawl_listings( $base, $platform );
		}

		$urls = self::filter_product_urls( $urls, $platform, $base );

		if ( empty( $urls ) ) {
			return new WP_Error( 'swi_no_products', __( 'No product URLs were discovered on this site.', 'swi' ) );
		}

		return $urls;
	}

	/**
	 * Discover up to $limit product URLs (bounded for the catalog UI so huge
	 * stores don't hang the request).
	 *
	 * @param string $base_url Target store URL.
	 * @param string $platform Platform slug.
	 * @param int    $limit    Max product URLs to return (0 = no limit).
	 * @return array|WP_Error
	 */
	public static function discover_limited( $base_url, $platform, $limit = 500 ) {
		$base = rtrim( SWI_HTTP::normalize_url( $base_url ), '/' );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$urls = array();

		foreach ( self::candidate_sitemaps( $base, $platform ) as $sitemap ) {
			$found = self::parse_sitemap_limited( $sitemap, 0, $limit );
			$urls  = array_merge( $urls, $found );
			if ( count( $found ) > 0 ) {
				break;
			}
		}

		if ( empty( $urls ) ) {
			$urls = self::crawl_listings( $base, $platform );
		}

		$urls = self::filter_product_urls( $urls, $platform, $base );

		// Deduplicate by product slug (last path segment) so multi-country
		// stores (same product under /gb/en/, /al/sq/, …) show each product once.
		$urls = self::dedupe_by_slug( $urls );

		if ( $limit > 0 ) {
			$urls = array_slice( $urls, 0, $limit );
		}

		if ( empty( $urls ) ) {
			return new WP_Error( 'swi_no_products', __( 'No product URLs were discovered on this site.', 'swi' ) );
		}

		return $urls;
	}

	/**
	 * Deduplicate product URLs by their slug (last non-empty path segment),
	 * keeping the first occurrence (which is typically the store's primary
	 * locale for multi-country sitemap indexes).
	 *
	 * @param array $urls Product URLs.
	 * @return array
	 */
	private static function dedupe_by_slug( $urls ) {
		$seen = array();
		$out  = array();
		foreach ( $urls as $u ) {
			$path = (string) wp_parse_url( (string) $u, PHP_URL_PATH );
			$seg  = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
			$slug = end( $seg );
			if ( '' === $slug ) {
				$slug = $u;
			}
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$out[]         = $u;
		}
		return $out;
	}

	/**
	 * Recursively parse a sitemap (or sitemap index) but STOP once $limit URLs
	 * have been collected, so a store with dozens of product sitemaps doesn't
	 * stall the catalog request.
	 *
	 * @param string $url    Sitemap URL.
	 * @param int    $depth  Recursion depth.
	 * @param int    $limit  Max URLs to collect.
	 * @param array  $state  By-ref accumulator { count }.
	 * @return array
	 */
	private static function parse_sitemap_limited( $url, $depth = 0, $limit = 500, &$state = null ) {
		if ( null === $state ) {
			$state = array( 'count' => 0, 'children' => 0 );
		}
		if ( $depth > 3 || $state['count'] >= $limit ) {
			return array();
		}

		$body = SWI_HTTP::get_with_retry( $url );
		if ( is_wp_error( $body ) ) {
			return array();
		}

		$xml = self::sitemap_xml( $body );
		if ( false === $xml ) {
			return array();
		}

		// Sitemap index: recurse into child sitemaps until the limit is hit,
		// or we've fetched too many children (huge multi-country indexes; a
		// render-proxy fetch can take seconds, so cap hard to stay snappy).
		if ( 'sitemapindex' === strtolower( $xml->getName() ) ) {
			$locs = array();
			foreach ( $xml->sitemap as $child ) {
				if ( $state['count'] >= $limit || $state['children'] >= 6 ) {
					break;
				}
				$child_url = (string) $child->loc;
				if ( $child_url ) {
					$state['children']++;
					$locs = array_merge( $locs, self::parse_sitemap_limited( $child_url, $depth + 1, $limit, $state ) );
				}
			}
			return $locs;
		}

		$locs = array();
		foreach ( $xml->url as $u ) {
			if ( $state['count'] >= $limit ) {
				break;
			}
			$loc = (string) $u->loc;
			if ( $loc ) {
				$locs[] = $loc;
				$state['count']++;
			}
		}

		return $locs;
	}

	/**
	 * Fetch products in bulk from the platform's public JSON endpoint.
	 *
	 * This returns title, price, thumbnail and URL for every product in just a
	 * couple of paginated requests (instead of scraping each product page), so
	 * the selection screen populates near-instantly.
	 *
	 * Generic (trained) sites have no public JSON endpoint, so this returns a
	 * WP_Error to signal the caller to fall back to sitemap discovery + per-page
	 * previews.
	 *
	 * @param string $base_url Target store URL.
	 * @param string $platform shopify|woocommerce|generic.
	 * @return array|WP_Error Array of product arrays:
	 *                         array( 'source_url', 'title', 'thumbnail', 'price', 'currency' ).
	 */
	public static function bulk_products( $base_url, $platform ) {
		$base = rtrim( SWI_HTTP::normalize_url( $base_url ), '/' );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if ( 'shopify' === $platform ) {
			return self::bulk_shopify( $base );
		}

		if ( 'woocommerce' === $platform ) {
			return self::bulk_woocommerce( $base );
		}

		// Generic: no bulk endpoint — signal the caller to use sitemaps.
		return new WP_Error( 'swi_no_bulk', __( 'This site uses a saved profile; discovery falls back to the sitemap.', 'swi' ) );
	}

	/**
	 * Fetch all products from the WooCommerce Store API (public, no auth).
	 *
	 * @param string $base Normalized base URL.
	 * @return array|WP_Error
	 */
	private static function bulk_woocommerce( $base ) {
		$products = array();
		$page     = 1;
		$per_page = 100;

		while ( $page <= 100 ) {
			$data = SWI_HTTP::get_json( add_query_arg( array( 'per_page' => $per_page, 'page' => $page ), $base . '/wp-json/wc/store/products' ) );

			if ( is_wp_error( $data ) ) {
				if ( 1 === $page ) {
					return $data; // Endpoint unavailable: surface the error so we can fall back to sitemap.
				}
				break;
			}

			if ( ! is_array( $data ) || empty( $data ) ) {
				break;
			}

			foreach ( $data as $p ) {
				$products[] = self::normalize_woo_store_product( $p );
			}

			if ( count( $data ) < $per_page ) {
				break;
			}
			$page++;
		}

		return $products;
	}

	/**
	 * Fetch all products from Shopify's public products.json.
	 *
	 * @param string $base Normalized base URL.
	 * @return array|WP_Error
	 */
	private static function bulk_shopify( $base ) {
		$products = array();
		$page     = 1;
		$limit    = 250;

		while ( $page <= 100 ) {
			$data = SWI_HTTP::get_json( add_query_arg( array( 'limit' => $limit, 'page' => $page ), $base . '/products.json' ) );

			if ( is_wp_error( $data ) ) {
				if ( 1 === $page ) {
					return $data;
				}
				break;
			}

			$items = isset( $data['products'] ) ? (array) $data['products'] : array();
			if ( empty( $items ) ) {
				break;
			}

			foreach ( $items as $p ) {
				$products[] = self::normalize_shopify_product( $p, $base );
			}

			if ( count( $items ) < $limit ) {
				break;
			}
			$page++;
		}

		return $products;
	}

	/**
	 * Normalize a WooCommerce Store API product into the preview shape.
	 *
	 * @param array $p Raw product.
	 * @return array
	 */
	private static function normalize_woo_store_product( $p ) {
		$image = '';
		if ( ! empty( $p['images'] ) && is_array( $p['images'] ) ) {
			$first = $p['images'][0];
			if ( ! empty( $first['src'] ) ) {
				$image = (string) $first['src'];
			} elseif ( ! empty( $first['thumbnail'] ) ) {
				$image = (string) $first['thumbnail'];
			}
		}

		$price    = '';
		$currency = '';
		if ( ! empty( $p['prices'] ) && is_array( $p['prices'] ) ) {
			$prices = $p['prices'];
			$raw    = isset( $prices['price'] ) ? (string) $prices['price'] : '';
			if ( '' === $raw ) {
				$raw = isset( $prices['regular_price'] ) ? (string) $prices['regular_price'] : '';
			}

			// The WooCommerce Store API returns prices in minor units
			// (cents / fils). Scale back to the display value using the
			// currency_minor_unit the API reports (default 2).
			if ( is_numeric( $raw ) && '' !== $raw ) {
				$minor   = isset( $prices['currency_minor_unit'] ) ? (int) $prices['currency_minor_unit'] : 2;
				$minor   = max( 0, min( 6, $minor ) );
				$price   = (string) round( (float) $raw / pow( 10, $minor ), $minor );
			}

			$currency = isset( $prices['currency_code'] ) ? (string) $prices['currency_code'] : '';
		}

		// Categories (for the folded-category list). Each entry: {id, name}.
		$cats = array();
		if ( ! empty( $p['categories'] ) && is_array( $p['categories'] ) ) {
			foreach ( $p['categories'] as $c ) {
				$cats[] = array(
					'id'   => isset( $c['id'] ) ? (int) $c['id'] : 0,
					'name' => isset( $c['name'] ) ? trim( (string) $c['name'] ) : '',
				);
			}
		}

		return array(
			'source_url' => isset( $p['permalink'] ) ? (string) $p['permalink'] : '',
			'title'      => isset( $p['name'] ) ? trim( (string) $p['name'] ) : '',
			'thumbnail'  => $image,
			'price'      => $price,
			'currency'   => $currency,
			'categories' => $cats,
		);
	}

	/**
	 * Normalize a Shopify products.json product into the preview shape.
	 *
	 * @param array  $p    Raw product.
	 * @param string $base Normalized base URL.
	 * @return array
	 */
	private static function normalize_shopify_product( $p, $base ) {
		$image = '';
		if ( ! empty( $p['images'] ) && is_array( $p['images'] ) && ! empty( $p['images'][0]['src'] ) ) {
			$image = (string) $p['images'][0]['src'];
		}

		// Shopify's public /products.json returns money as a decimal string
		// (e.g. "25.00"), NOT cents — unlike the embedded ShopifyAnalytics
		// payload used by the scrape path, which IS in cents. Keep the value
		// as-is here so the preview matches the real display price.
		$price = '';
		if ( ! empty( $p['variants'] ) && is_array( $p['variants'] ) ) {
			$raw = isset( $p['variants'][0]['price'] ) ? (string) $p['variants'][0]['price'] : '';
			if ( is_numeric( $raw ) && '' !== $raw ) {
				$price = $raw;
			}
		}

		$handle = isset( $p['handle'] ) ? (string) $p['handle'] : '';

		// Shopify has no category hierarchy in products.json; use product_type
		// (e.g. "Shoes") as the single grouping key, and tags as a fallback.
		$cats = array();
		$type = isset( $p['product_type'] ) ? trim( (string) $p['product_type'] ) : '';
		if ( '' !== $type ) {
			$cats[] = array( 'id' => 0, 'name' => $type );
		}

		return array(
			'source_url' => $handle ? $base . '/products/' . $handle : '',
			'title'      => isset( $p['title'] ) ? trim( (string) $p['title'] ) : '',
			'thumbnail'  => $image,
			'price'      => $price,
			'currency'   => '',
			'categories' => $cats,
		);
	}

	/**
	 * Fetch the WooCommerce category tree (id, name, parent, count) from the
	 * public Store API. Returns [] when unavailable (generic/Shopify).
	 *
	 * @param string $base Normalized base URL.
	 * @return array
	 */
	public static function woocommerce_categories( $base ) {
		$base    = rtrim( SWI_HTTP::normalize_url( $base ), '/' );
		$data    = SWI_HTTP::get_json( add_query_arg( array( 'per_page' => 100 ), $base . '/wp-json/wc/store/products/categories' ) );
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return array();
		}

		$cats = array();
		foreach ( $data as $c ) {
			$cats[] = array(
				'id'     => isset( $c['id'] ) ? (int) $c['id'] : 0,
				'name'   => isset( $c['name'] ) ? trim( (string) $c['name'] ) : '',
				'parent' => isset( $c['parent'] ) ? (int) $c['parent'] : 0,
				'count'  => isset( $c['count'] ) ? (int) $c['count'] : 0,
			);
		}

		return $cats;
	}

	/**
	 * Build the ordered list of sitemap candidates for a platform.
	 *
	 * @param string $base     Normalized base URL.
	 * @param string $platform Platform slug.
	 * @return array
	 */
	private static function candidate_sitemaps( $base, $platform ) {
		if ( 'shopify' === $platform ) {
			return array(
				$base . '/sitemap_products_1.xml',
				$base . '/sitemap.xml',
			);
		}

		// WooCommerce + generic share the standard sitemap locations; the
		// sitemap index is recursed automatically when present.
		return array(
			$base . '/sitemap.xml',
			$base . '/sitemap_index.xml',
			$base . '/wp-sitemap.xml',
			$base . '/product-sitemap.xml',
			$base . '/sitemap_products_1.xml',
		);
	}

	/**
	 * Parse a sitemap body into a SimpleXMLElement, transparently gunzipping
	 * `.xml.gz` sitemaps (which many sites — incl. Magento/headless — use).
	 *
	 * @param string $body Raw response body.
	 * @return SimpleXMLElement|false
	 */
	private static function sitemap_xml( $body ) {
		$body = (string) $body;
		if ( '' === $body ) {
			return false;
		}

		// gzip magic bytes 1f 8b.
		if ( "\x1f\x8b" === substr( $body, 0, 2 ) ) {
			$decoded = function_exists( 'gzdecode' ) ? @gzdecode( $body ) : false;
			if ( false !== $decoded ) {
				$body = $decoded;
			}
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();

		return $xml;
	}

	/**
	 * Recursively parse a sitemap (or sitemap index) and return <loc> URLs.
	 *
	 * @param string $url   Sitemap URL.
	 * @param int    $depth Recursion depth.
	 * @return array
	 */
	private static function parse_sitemap( $url, $depth = 0 ) {
		if ( $depth > 3 ) {
			return array();
		}

		$body = SWI_HTTP::get_with_retry( $url );
		if ( is_wp_error( $body ) ) {
			return array();
		}

		$xml = self::sitemap_xml( $body );
		if ( false === $xml ) {
			return array();
		}

		// Sitemap index: recurse into child sitemaps.
		if ( 'sitemapindex' === strtolower( $xml->getName() ) ) {
			$locs = array();
			foreach ( $xml->sitemap as $child ) {
				$child_url = (string) $child->loc;
				if ( $child_url ) {
					$locs = array_merge( $locs, self::parse_sitemap( $child_url, $depth + 1 ) );
				}
			}
			return $locs;
		}

		$locs = array();
		foreach ( $xml->url as $u ) {
			$loc = (string) $u->loc;
			if ( $loc ) {
				$locs[] = $loc;
			}
		}

		return $locs;
	}

	/**
	 * Crawl homepage + listing pages, following pagination/collection links.
	 *
	 * @param string $base      Normalized base URL.
	 * @param string $platform  Platform slug.
	 * @param int    $max_pages Maximum pages to visit.
	 * @return array
	 */
	private static function crawl_listings( $base, $platform, $max_pages = 15 ) {
		$found   = array();
		$visited = array();
		$queue   = array( $base );

		if ( 'shopify' === $platform ) {
			$product_pattern = '#/products/[^/]+#i';
		} elseif ( 'woocommerce' === $platform ) {
			$product_pattern = '#/products?/[^/]+#i';
		} else {
			$product_pattern = ''; // generic: no reliable URL shape — rely on sitemap.
		}

		while ( ! empty( $queue ) && count( $visited ) < $max_pages ) {
			$url = array_shift( $queue );

			if ( isset( $visited[ $url ] ) ) {
				continue;
			}
			$visited[ $url ] = true;

			$html = SWI_HTTP::get_with_retry( $url );
			if ( is_wp_error( $html ) ) {
				continue;
			}

			$doc = new DOMDocument();
			libxml_use_internal_errors( true );
			@$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
			libxml_clear_errors();

			$xpath = new DOMXPath( $doc );
			foreach ( $xpath->query( '//a[@href]' ) as $a ) {
				$href = $a->getAttribute( 'href' );
				$abs  = self::absolute_url( $href, $base );
				if ( ! $abs || is_wp_error( $abs ) ) {
					continue;
				}

				$path = (string) wp_parse_url( $abs, PHP_URL_PATH );

				if ( '' !== $product_pattern && preg_match( $product_pattern, $path ) ) {
					$found[] = $abs;
				} elseif (
					preg_match( '#/collections/#i', $abs ) ||
					preg_match( '#/product-category/#i', $abs ) ||
					preg_match( '#/catalogue/category/#i', $abs ) ||
					preg_match( '#/page/\d+#i', $abs )
				) {
					if ( count( $visited ) + count( $queue ) < $max_pages + 10 ) {
						$queue[] = $abs;
					}
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Keep only URLs that look like product pages and belong to the target host.
	 *
	 * @param array  $urls     Candidate URLs.
	 * @param string $platform Platform slug.
	 * @param string $base     Normalized base URL.
	 * @return array
	 */
	private static function filter_product_urls( $urls, $platform, $base ) {
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );

		$out = array();
		foreach ( $urls as $u ) {
			$u   = trim( (string) $u );
			$par = wp_parse_url( $u );

			if ( empty( $par['host'] ) || empty( $par['path'] ) ) {
				continue;
			}

			if ( $par['host'] !== $host && ! self::is_subdomain( $par['host'], $host ) ) {
				continue;
			}

			$path = $par['path'];

			if ( 'shopify' === $platform ) {
				if ( preg_match( '#/products/[^/]+#i', $path ) ) {
					$out[] = $u;
				}
			} elseif ( 'woocommerce' === $platform ) {
				if ( preg_match( '#/products?/[^/]+#i', $path ) && ! preg_match( '#/product-category/|/product-tag/|/products/page/#i', $path ) ) {
					$out[] = $u;
				}
			} else {
				// Generic: keep sitemap URLs that are not obviously non-product
				// pages. Product pages outnumber utility pages, and the profile
				// (applied at import time) is what actually extracts the data.
				if ( ! self::is_non_product_path( $path ) ) {
					$out[] = $u;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a URL path looks like a non-product page (for generic sites).
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	private static function is_non_product_path( $path ) {
		$path = strtolower( (string) $path );

		// Homepage and bare language roots ("/", "/el/", "/en/") are never products.
		if ( '' === $path || '/' === $path || 1 === preg_match( '#^/[a-z]{2,3}/?$#', $path ) ) {
			return true;
		}

		$exclude = array(
			'/cart', '/checkout', '/account', '/login', '/register', '/wishlist',
			'/category/', '/tag/', '/collections/', '/catalogue/category/',
			'/blog/', '/news/', '/search', '/about', '/contact', '/policy',
			'/privacy', '/terms', '/shipping', '/returns', '/faq',
			'/page/', '/product-category/', '/product-tag/',
		);

		foreach ( $exclude as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a (possibly relative) href against the base URL.
	 *
	 * @param string $href Href value.
	 * @param string $base Normalized base URL.
	 * @return string|WP_Error|false
	 */
	private static function absolute_url( $href, $base ) {
		$href = trim( (string) $href );
		if ( '' === $href || '#' === $href[0] ) {
			return false;
		}

		if ( preg_match( '#^(https?:)?//#i', $href ) ) {
			return SWI_HTTP::normalize_url( $href );
		}

		$parts  = wp_parse_url( $base );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		if ( 0 === strpos( $href, '/' ) ) {
			return $scheme . '://' . $host . $href;
		}

		$dir = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
		if ( '.' === $dir ) {
			$dir = '/';
		}

		return $scheme . '://' . $host . rtrim( $dir, '/' ) . '/' . $href;
	}

	/**
	 * Whether a host is a subdomain of another host.
	 *
	 * @param string $candidate Host to test.
	 * @param string $host      Base host.
	 * @return bool
	 */
	private static function is_subdomain( $candidate, $host ) {
		return ( '.' . $host ) === substr( $candidate, - ( strlen( $host ) + 1 ) );
	}
}
