<?php
/**
 * Generic product scraper driven by a SWI_Profile.
 *
 * Applies a learned profile (extraction rules) to a product page's HTML and
 * returns the same normalized product array that SWI_Importer consumes, so
 * arbitrary (non-Shopify, non-WooCommerce) sites flow through the existing
 * import pipeline unchanged.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Generic_Scraper {

	/**
	 * Fetch + scrape a product URL using a profile.
	 *
	 * @param string $url     Product URL.
	 * @param array  $profile Profile array.
	 * @return array|WP_Error Normalized product data, or WP_Error.
	 */
	public static function scrape( $url, $profile ) {
		$html = SWI_HTTP::get_with_retry( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return self::scrape_html( $html, $url, $profile );
	}

	/**
	 * Scrape raw HTML using a profile.
	 *
	 * @param string $html    Raw HTML.
	 * @param string $url     Base URL (for resolving relative image URLs).
	 * @param array  $profile Profile array.
	 * @return array|WP_Error
	 */
	public static function scrape_html( $html, $url, $profile ) {
		$doc = self::load_dom( $html );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$xpath = new DOMXPath( $doc );

		$data = array(
			'title'             => '',
			'short_description' => '',
			'description'       => '',
			'price'             => '',
			'sale_price'        => '',
			'currency'          => '',
			'sku'               => '',
			'images'            => array(),
			'categories'        => array(),
			'type'              => 'simple',
			'variants'          => array(),
			'swatches'          => array(),
		);

		$fields = isset( $profile['fields'] ) && is_array( $profile['fields'] ) ? $profile['fields'] : array();
		foreach ( $fields as $field => $rule ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			if ( ! is_array( $rule ) || empty( $rule['strategy'] ) ) {
				continue; // null / unknown field rule — leave the default empty value.
			}

			$value = self::extract_field( $doc, $xpath, $html, $rule );
			if ( null !== $value ) {
				// images/categories are list fields — coerce a scalar result
				// (e.g. a JSON-LD strategy that returned one value) into a list.
				if ( in_array( $field, array( 'images', 'categories' ), true ) && ! is_array( $value ) ) {
					$value = array( $value );
				}
				$data[ $field ] = $value;
			}
		}

		// Variable detection + variants.
		if ( isset( $profile['variable'] ) && is_array( $profile['variable'] ) ) {
			self::apply_variable( $doc, $xpath, $html, $profile['variable'], $data );
		}

		// Deterministic enrichment (independent of the AI): normalize prices,
		// fill any still-empty scalar fields from meta/JSON-LD, capture images
		// from every known source, upgrade CDN image quality, and extract
		// color/image swatches — so the AI's occasional miss is self-healed.
		self::enrich_data( $doc, $xpath, $html, $url, $data );

		return $data;
	}

	/**
	 * Self-healing enrichment pass. Runs after the AI's profile extraction so
	 * it only fills GAPS — a field the profile got right is never overwritten.
	 *
	 * Order:
	 *   1. Normalize price strings (strip currency symbols/separators).
	 *   2. Back-fill empty scalar fields (title, description, price, sku,
	 *      currency, categories) from <meta> + JSON-LD + <h1>/<title>.
	 *   3. Back-fill images from JSON-LD / og:image / srcset / embedded JSON /
	 *      gallery containers, then upgrade CDN resolution.
	 *   4. Extract color/image swatches (DOM + embedded JSON).
	 *
	 * @param DOMDocument $doc  Document.
	 * @param DOMXPath    $xpath XPath instance.
	 * @param string      $html Raw HTML.
	 * @param string      $url  Base URL.
	 * @param array       $data Data (by reference).
	 */
	private static function enrich_data( $doc, $xpath, $html, $url, &$data ) {
		// 1. Normalize prices (also derives currency from the symbol).
		$data['price']      = self::normalize_price( $data['price'], $data['currency'] );
		$data['sale_price'] = self::normalize_price( $data['sale_price'], $data['currency'] );
		foreach ( $data['variants'] as $i => $variant ) {
			$data['variants'][ $i ]['price']      = self::normalize_price( $variant['price'], $data['currency'] );
			$data['variants'][ $i ]['sale_price'] = self::normalize_price( $variant['sale_price'], $data['currency'] );
		}

		// 1b. Clean variant names (strip " - 5% Off", " - Out of Stock",
		// "View All Options", …) and drop placeholder variants — regardless of
		// which embedded JSON path the AI picked.
		$data['variants'] = self::clean_variants( $data['variants'] );

		// 1c. For variable products, reconcile the parent price: if it doesn't
		// match any variant price (e.g. the AI grabbed a "from"/regular price),
		// snap it to the minimum variant price.
		self::reconcile_parent_price( $data );

		// 2. Back-fill empty scalar fields.
		self::enrich_scalars( $doc, $xpath, $data );

		// 2b. Deterministic variant fallback: when the profile produced no
		// variants (the AI often chases a hard __APOLLO_STATE__ / GraphQL path
		// and misses the schema.org JSON-LD `offers` array), extract variants
		// directly from the Product JSON-LD offers. Each offer = one variant.
		if ( empty( $data['variants'] ) ) {
			$fallback = self::variants_from_jsonld_offers( $xpath );
			if ( ! empty( $fallback ) ) {
				$data['variants'] = $fallback;
				$data['type']     = 'variable';
			}
		}

		// 3. Images: multiple sources, then absolutize + upgrade CDN quality.
		if ( empty( $data['images'] ) ) {
			$data['images'] = self::enrich_images( $doc, $xpath, $html );
		}
		$data['images'] = self::absolutize_images( $data['images'], $url );
		$data['images'] = array_map( array( __CLASS__, 'upgrade_image_url' ), $data['images'] );
		foreach ( $data['variants'] as $i => $variant ) {
			if ( ! empty( $variant['image'] ) ) {
				$data['variants'][ $i ]['image'] = self::upgrade_image_url( self::absolutize_url( $variant['image'], $url ) );
			}
		}

		// 4. Swatches.
		if ( empty( $data['swatches'] ) ) {
			$data['swatches'] = self::extract_swatches( $doc, $xpath, $data );
		}
	}

	/**
	 * Clean every variant: strip promo/stock suffixes from attribute values and
	 * drop placeholder variants ("View All Options", "Select …").
	 *
	 * @param array $variants Normalized variants.
	 * @return array
	 */
	private static function clean_variants( $variants ) {
		$out = array();
		foreach ( (array) $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$attrs = array();
			$empty = true;
			foreach ( (array) $variant['attributes'] as $name => $value ) {
				$clean = self::clean_variant_name( $value );
				if ( '' === $clean ) {
					continue; // placeholder value ("View All Options", etc.).
				}
				$attrs[ $name ] = $clean;
				$empty          = false;
			}

			// Drop the variant entirely if it has no real attribute values.
			if ( $empty && empty( $variant['sku'] ) ) {
				continue;
			}

			$variant['attributes'] = $attrs;
			$out[]                 = $variant;
		}

		return $out;
	}

	/**
	 * For variable products, if the parent price doesn't match any variant
	 * price, snap it to the minimum variant price (the WooCommerce convention
	 * and the value the storefront displays as "from $X").
	 *
	 * @param array $data Data (by reference).
	 */
	private static function reconcile_parent_price( &$data ) {
		if ( 'variable' !== $data['type'] || empty( $data['variants'] ) ) {
			return;
		}

		$prices = array();
		foreach ( $data['variants'] as $v ) {
			$p = self::to_float_raw( isset( $v['price'] ) ? $v['price'] : '' );
			if ( $p > 0 ) {
				$prices[] = $p;
			}
		}
		if ( empty( $prices ) ) {
			return;
		}

		$parent = self::to_float_raw( $data['price'] );
		$min    = min( $prices );

		// Snap when empty, or when it matches no variant (within 1 cent).
		$matches = false;
		foreach ( $prices as $p ) {
			if ( abs( $p - $parent ) < 0.01 ) {
				$matches = true;
				break;
			}
		}

		if ( $parent <= 0 || ! $matches ) {
			$data['price'] = (string) $min;
		}
	}

	/**
	 * Parse a price string to a float (handles symbols + separators), reusing
	 * the same logic as the importer without needing a WC_Product.
	 *
	 * @param string $value Price string.
	 * @return float
	 */
	private static function to_float_raw( $value ) {
		$s = preg_replace( '/[^0-9.,\-]/', '', (string) $value );
		if ( '' === $s ) {
			return 0.0;
		}
		$last_comma = strrpos( $s, ',' );
		$last_dot   = strrpos( $s, '.' );
		if ( false !== $last_comma && false !== $last_dot ) {
			if ( $last_comma > $last_dot ) {
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $last_comma && false === $last_dot ) {
			$parts = explode( ',', $s );
			$after = end( $parts );
			$s     = ( count( $parts ) > 1 && 3 === strlen( $after ) ) ? str_replace( ',', '', $s ) : str_replace( ',', '.', $s );
		}
		return (float) $s;
	}

	/**
	 * Deterministic variant extraction from schema.org JSON-LD `offers` arrays.
	 *
	 * Many stores (Notino, headless/Magento) put the variants in a Product
	 * JSON-LD node whose `offers` array holds one Offer per variation (name,
	 * price, sku, image, url). This is the reliable source the AI sometimes
	 * misses when it chases a GraphQL `__APOLLO_STATE__` path instead.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array Normalized variants.
	 */
	private static function variants_from_jsonld_offers( $xpath ) {
		$parent_name = '';

		foreach ( self::flatten_jsonld( $xpath ) as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( ! in_array( 'Product', $type, true ) || empty( $node['offers'] ) ) {
				continue;
			}

			if ( '' === $parent_name && ! empty( $node['name'] ) ) {
				$parent_name = trim( (string) $node['name'] );
			}

			$offers = isset( $node['offers'][0] ) ? $node['offers'] : array( $node['offers'] );
			if ( count( $offers ) < 2 ) {
				continue; // simple product.
			}

			$variants = array();
			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) ) {
					continue;
				}

				$offer_name = isset( $offer['name'] ) ? (string) $offer['name'] : '';
				$attr       = self::parse_offer_attribute( $parent_name, $offer_name );
				if ( '' === $attr['value'] ) {
					continue;
				}

				$variants[] = array(
					'attributes' => array( $attr['label'] => $attr['value'] ),
					'price'      => isset( $offer['price'] ) ? (string) $offer['price'] : '',
					'sale_price' => '',
					'sku'        => isset( $offer['sku'] ) ? (string) $offer['sku'] : '',
					'image'      => isset( $offer['image'] ) ? (string) $offer['image'] : '',
					'stock'      => null,
				);
			}

			if ( count( $variants ) >= 2 ) {
				return $variants;
			}
		}

		return array();
	}

	/**
	 * Parse an offer name into an attribute {label, value}.
	 *
	 * "Bielenda GLAZE & GLOW shade 02 30 g" → label "Shade", value "02".
	 * Strips the parent product-name prefix, a leading descriptor word
	 * (shade/color/…), and a trailing volume ("30 g").
	 *
	 * @param string $parent_name Parent product name.
	 * @param string $offer_name  Offer name.
	 * @return array {label, value}.
	 */
	private static function parse_offer_attribute( $parent_name, $offer_name ) {
		$rest = trim( (string) $offer_name );
		if ( '' !== (string) $parent_name && 0 === stripos( $rest, (string) $parent_name ) ) {
			$rest = trim( (string) substr( $rest, strlen( (string) $parent_name ) ) );
		}

		$label = 'Shade';
		$value = $rest;

		if ( preg_match( '/^(shade|shades|color|colour|colors|colours|scent|fragrance|flavor|flavour|tone|finish)\b[\s:\-\x{00A0}]*/iu', $value, $dm ) ) {
			$label = ucfirst( strtolower( $dm[1] ) );
			$value = trim( (string) preg_replace( '/^(shade|shades|color|colour|colors|colours|scent|fragrance|flavor|flavour|tone|finish)\b[\s:\-\x{00A0}]*/iu', '', $value ) );
		}

		// Drop a trailing volume/size ("30 g", "200 ml") — NBSP-safe.
		$value = trim( (string) preg_replace( '/[\s\x{00A0}]*\d+(?:[.,]\d+)?[\s\x{00A0}]*(ml|g|mg|l|kg|oz|cl)[\s\x{00A0}]*$/iu', '', $value ) );

		return array(
			'label' => $label,
			'value' => $value,
		);
	}

	/**
	 * Load an HTML string into a DOMDocument.
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|WP_Error
	 */
	private static function load_dom( $html ) {
		$doc = new DOMDocument( '1.0', 'UTF-8' );

		libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return new WP_Error( 'swi_parse_error', __( 'Failed to parse the product page.', 'swi' ) );
		}

		return $doc;
	}

	/**
	 * Extract a single field according to its rule.
	 *
	 * Returns null when the field could not be extracted, so the caller keeps
	 * the default empty value.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param DOMXPath    $xpath XPath instance.
	 * @param string      $html Raw HTML (for regex strategy).
	 * @param array       $rule Rule map.
	 * @return mixed Extracted value, or null.
	 */
	private static function extract_field( $doc, $xpath, $html, $rule ) {
		$strategy = isset( $rule['strategy'] ) ? (string) $rule['strategy'] : '';

		switch ( $strategy ) {
			case 'meta':
				return self::extract_meta( $xpath, $rule );
			case 'jsonld':
				return self::extract_jsonld( $xpath, $rule );
			case 'jsonld_all':
				return self::extract_jsonld_all( $xpath, $rule );
			case 'selector':
				return self::extract_selector( $xpath, $rule, false );
			case 'selector_all':
				return self::extract_selector( $xpath, $rule, true );
			case 'regex':
				return self::extract_regex( $html, $rule );
			default:
				return null;
		}
	}

	/**
	 * Extract a value from a <meta> tag (property= or name=).
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param array    $rule  Rule map (key: property/name).
	 * @return string|null
	 */
	private static function extract_meta( $xpath, $rule ) {
		$key = isset( $rule['key'] ) ? (string) $rule['key'] : '';
		if ( '' === $key ) {
			return null;
		}

		$node = $xpath->query( '//meta[@property="' . $key . '"]' )->item( 0 );
		if ( ! $node ) {
			$node = $xpath->query( '//meta[@name="' . $key . '"]' )->item( 0 );
		}
		if ( ! $node ) {
			return null;
		}

		$value = trim( $node->getAttribute( 'content' ) );
		return '' !== $value ? $value : null;
	}

	/**
	 * Extract a scalar value from schema.org JSON-LD via a dot path.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param array    $rule  Rule map (path: dot path).
	 * @return string|null
	 */
	private static function extract_jsonld( $xpath, $rule ) {
		$nodes = self::flatten_jsonld( $xpath );
		$path  = isset( $rule['path'] ) ? (string) $rule['path'] : '';
		if ( '' === $path || empty( $nodes ) ) {
			return null;
		}

		// Prefer a ProductGroup/Product node; try the path against each
		// candidate in order, returning the first non-empty scalar result.
		// Fall back to any other JSON-LD node (BreadcrumbList, etc.) when the
		// product nodes don't carry the requested path.
		$preferred  = array( 'ProductGroup', 'Product' );
		$candidates = array();
		$rest       = array();
		foreach ( $nodes as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( count( array_intersect( $preferred, $type ) ) > 0 ) {
				$candidates[] = $node;
			} else {
				$rest[] = $node;
			}
		}
		if ( empty( $candidates ) ) {
			$candidates = $nodes;
		} else {
			$candidates = array_merge( $candidates, $rest );
		}

		foreach ( $candidates as $source ) {
			$value = self::dot_get( $source, $path );
			if ( is_scalar( $value ) ) {
				$value = trim( (string) $value );
				if ( '' !== $value ) {
					// JSON-LD embedded in HTML often double-encodes entities.
					return html_entity_decode( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' );
				}
			} elseif ( is_array( $value ) ) {
				// List result (e.g. Product.image = [url, url, ...]). Flatten
				// to a string list so the images/categories field keeps the
				// whole array instead of being dropped (extract_jsonld only
				// returned scalars before, collapsing a 10-image gallery to 1).
				$list = array();
				foreach ( $value as $entry ) {
					if ( is_scalar( $entry ) ) {
						$entry = trim( (string) $entry );
						if ( '' !== $entry ) {
							$list[] = html_entity_decode( html_entity_decode( $entry, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' );
						}
					} elseif ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
						$list[] = (string) $entry['url'];
					} elseif ( is_array( $entry ) && ! empty( $entry['@id'] ) ) {
						$list[] = (string) $entry['@id'];
					}
				}
				if ( ! empty( $list ) ) {
					return $list;
				}
			}
		}

		return null;
	}

	/**
	 * Extract ALL values matching a dot path from JSON-LD (array result).
	 *
	 * Used for list-shaped data such as a BreadcrumbList's
	 * "itemListElement.name" → ["Home", "Make up", "Face"]. When a path
	 * segment is a sequential array, the remaining segments are mapped over
	 * every element and flattened.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param array    $rule  Rule map (path: dot path).
	 * @return array
	 */
	private static function extract_jsonld_all( $xpath, $rule ) {
		$nodes = self::flatten_jsonld( $xpath );
		$path  = isset( $rule['path'] ) ? (string) $rule['path'] : '';
		if ( '' === $path || empty( $nodes ) ) {
			return array();
		}

		$tokens = self::tokenize_path( $path );
		if ( empty( $tokens ) ) {
			return array();
		}

		// Prefer ProductGroup/Product, then any node.
		$preferred = array( 'ProductGroup', 'Product' );
		$candidates = array();
		$rest       = array();
		foreach ( $nodes as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( count( array_intersect( $preferred, $type ) ) > 0 ) {
				$candidates[] = $node;
			} else {
				$rest[] = $node;
			}
		}
		if ( empty( $candidates ) ) {
			$candidates = $nodes;
		} else {
			$candidates = array_merge( $candidates, $rest );
		}

		foreach ( $candidates as $source ) {
			$values = self::dot_walk_all( $source, $tokens );
			// Drop generic breadcrumb "home"/"shop" links so they don't end up
			// as product categories.
			$home_labels = array( 'home', 'home page', 'shop', 'back to products' );
			$values      = array_values( array_filter( array_map( 'strval', $values ), function ( $v ) use ( $home_labels ) {
				$v = trim( $v );
				return '' !== $v && ! in_array( strtolower( $v ), $home_labels, true );
			} ) );
			if ( ! empty( $values ) ) {
				return array_map(
					function ( $v ) {
						return html_entity_decode( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' );
					},
					$values
				);
			}
		}

		return array();
	}

	/**
	 * Tokenize a dot path into key / [N] index segments.
	 *
	 * @param string $path Dot path.
	 * @return array
	 */
	private static function tokenize_path( $path ) {
		$path = (string) $path;
		// Accept a JSONPath-style root prefix so the AI can emit "$.offers"
		// (or "$.itemListElement.name") without the "$" being treated as a
		// literal key. "$" alone and "$." both mean "the root".
		$path = preg_replace( '/^\$\.?/', '', trim( $path ) );
		preg_match_all( '/\.?([^.\[\]]+)|\[(\d+)\]/', $path, $m, PREG_SET_ORDER );
		$tokens = array();
		foreach ( $m as $t ) {
			if ( isset( $t[1] ) && '' !== $t[1] ) {
				$tokens[] = array( 'key', $t[1] );
			} elseif ( isset( $t[2] ) && '' !== $t[2] ) {
				$tokens[] = array( 'index', (int) $t[2] );
			}
		}
		return $tokens;
	}

	/**
	 * Walk tokens, mapping over sequential arrays to collect every match.
	 *
	 * @param mixed $data   Decoded data.
	 * @param array $tokens Remaining path tokens.
	 * @return array
	 */
	private static function dot_walk_all( $data, $tokens ) {
		if ( empty( $tokens ) ) {
			return is_scalar( $data ) ? array( $data ) : array();
		}
		if ( ! is_array( $data ) ) {
			return array();
		}

		// Sequential array: map over every element.
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			$out = array();
			foreach ( $data as $item ) {
				$out = array_merge( $out, self::dot_walk_all( $item, $tokens ) );
			}
			return $out;
		}

		$token = array_shift( $tokens );
		if ( 'index' === $token[0] ) {
			return array_key_exists( $token[1], $data ) ? self::dot_walk_all( $data[ $token[1] ], $tokens ) : array();
		}

		$seg = $token[1];
		if ( array_key_exists( $seg, $data ) ) {
			return self::dot_walk_all( $data[ $seg ], $tokens );
		}

		return array();
	}

	/**
	 * Flatten every JSON-LD node (carrying an @type) into a list.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array
	 */
	private static function flatten_jsonld( $xpath ) {
		$nodes = $xpath->query( '//script[@type="application/ld+json"]' );
		$out   = array();

		foreach ( $nodes as $node ) {
			$json = trim( $node->nodeValue );
			if ( '' === $json ) {
				continue;
			}

			$decoded = json_decode( $json, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				continue;
			}

			self::collect_jsonld_nodes( $decoded, $out );
		}

		return $out;
	}

	/**
	 * Whether a schema.org `Product` node has an `offers` array of at least
	 * `$min` entries (i.e. a variable product where each offer is a variant).
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param int      $min   Minimum offers count to count as variable.
	 * @return bool
	 */
	private static function jsonld_has_offers_array( $xpath, $min = 2 ) {
		foreach ( self::flatten_jsonld( $xpath ) as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( ! in_array( 'Product', $type, true ) ) {
				continue;
			}
			if ( isset( $node['offers'] ) && is_array( $node['offers'] ) ) {
				$offers = isset( $node['offers'][0] ) ? $node['offers'] : array( $node['offers'] );
				if ( count( $offers ) >= $min ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Produce a compact "source inventory" of an HTML document: which structured
	 * data sources exist (JSON-LD nodes, meta tags, embedded JSON/state, DOM
	 * variant signals). This is the "map" the AI agent is given so it picks the
	 * right extraction strategy instead of guessing — e.g. chasing a GraphQL
	 * __APOLLO_STATE__ cache when a schema.org `offers` array is available.
	 *
	 * @param string $html Raw HTML.
	 * @return string Human-readable inventory.
	 */
	public static function source_inventory( $html ) {
		$lines = array();
		$doc   = self::load_dom( $html );
		if ( is_wp_error( $doc ) ) {
			return '';
		}
		$xpath = new DOMXPath( $doc );

		$jsonld = self::flatten_jsonld( $xpath );
		if ( ! empty( $jsonld ) ) {
			$lines[] = '=== JSON-LD (schema.org) — most reliable source ===';
			$i       = 0;
			foreach ( $jsonld as $node ) {
				$i++;
				$type = isset( $node['@type'] ) ? ( is_array( $node['@type'] ) ? implode( ',', $node['@type'] ) : (string) $node['@type'] ) : '?';
				$s    = '  #' . $i . ' @type=' . $type;
				if ( ! empty( $node['name'] ) ) {
					$s .= ' | name=' . mb_substr( (string) $node['name'], 0, 60 );
				}
				if ( 'Product' === $type || 'ProductGroup' === $type ) {
					$offers = isset( $node['offers'] ) && is_array( $node['offers'] ) ? ( isset( $node['offers'][0] ) ? count( $node['offers'] ) : 1 ) : 0;
					$hv     = isset( $node['hasVariant'] ) && is_array( $node['hasVariant'] ) ? ( isset( $node['hasVariant'][0] ) ? count( $node['hasVariant'] ) : 1 ) : 0;
					$s     .= ' | offers=' . $offers . ' | hasVariant=' . $hv;
					if ( $offers > 1 ) {
						$s .= '  <-- VARIABLE: each offer is one variant';
					}
				}
				$lines[] = $s;
			}
		}

		// Meta tags of interest.
		$meta = array();
		if ( preg_match_all( '/<meta[^>]*>/i', $html, $mm ) ) {
			foreach ( $mm[0] as $tag ) {
				$key = preg_match( '/(?:property|name)=["\']([^"\']+)["\']/i', $tag, $k ) ? $k[1] : '';
				$val = preg_match( '/content=["\']([^"\']*)["\']/i', $tag, $v ) ? $v[1] : '';
				if ( '' === $key || '' === $val ) {
					continue;
				}
				$lk = strtolower( $key );
				if ( false !== strpos( $lk, 'title' ) || false !== strpos( $lk, 'image' ) || false !== strpos( $lk, 'description' ) || false !== strpos( $lk, 'price' ) || false !== strpos( $lk, 'currency' ) || false !== strpos( $lk, 'brand' ) || false !== strpos( $lk, 'sku' ) ) {
					$meta[] = '  ' . $key . ' = ' . mb_substr( $val, 0, 120 );
				}
			}
		}
		if ( ! empty( $meta ) ) {
			$lines[] = '=== <meta> tags ===';
			$lines   = array_merge( $lines, array_slice( $meta, 0, 24 ) );
		}

		// Embedded JSON / client state.
		$embedded = array();
		if ( false !== strpos( $html, '__APOLLO_STATE__' ) ) {
			$embedded[] = '  - __APOLLO_STATE__ (GraphQL client cache — variants are __ref pointers, NOT directly readable; prefer the JSON-LD offers array instead)';
		}
		if ( false !== strpos( $html, '__NEXT_DATA__' ) ) {
			$embedded[] = '  - __NEXT_DATA__ (Next.js page props — may hold product/variant data under props.pageProps)';
		}
		if ( preg_match( '/<script[^>]*type=["\']application\/json["\']/i', $html ) ) {
			$embedded[] = '  - application/json script block(s)';
		}
		if ( preg_match( '/data-props=|data-product=|data-variants=/i', $html ) ) {
			$embedded[] = '  - data-props / data-product / data-variants attributes';
		}
		if ( ! empty( $embedded ) ) {
			$lines[] = '=== Embedded JSON / client state ===';
			$lines   = array_merge( $lines, $embedded );
		}

		// DOM signals.
		$dom = array();
		if ( preg_match( '/<select[\s>]/i', $html ) ) {
			$dom[] = '  - <select> element(s) present (check for variant dropdowns)';
		}
		$imgs    = preg_match_all( '/<img[\s>]/i', $html );
		$srcsets = preg_match_all( '/srcset=/i', $html );
		if ( $imgs ) {
			$dom[] = '  - ' . $imgs . ' <img> tags (' . $srcsets . ' with srcset)';
		}
		if ( ! empty( $dom ) ) {
			$lines[] = '=== DOM signals ===';
			$lines   = array_merge( $lines, $dom );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Recursively collect JSON-LD nodes carrying an @type key.
	 *
	 * @param mixed $data  Decoded JSON.
	 * @param array $out   Accumulator.
	 * @param int   $depth Recursion guard.
	 */
	private static function collect_jsonld_nodes( $data, &$out, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 10 ) {
			return;
		}

		if ( isset( $data['@type'] ) ) {
			$out[] = $data;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				self::collect_jsonld_nodes( $value, $out, $depth + 1 );
			}
		}
	}

	/**
	 * Read a dot path from an array, supporting:
	 *   - key access: "sku", "offers.price", "brand.name"
	 *   - array indexing: "hasVariant[0].offers.price", "itemListElement[2].name"
	 *   - implicit descend into the first element of a sequential array
	 *     (e.g. "offers.price" where offers = [ {...} ]).
	 *   - "$", "" and "." all mean "the root".
	 *
	 * @param array  $data Decoded data.
	 * @param string $path Dot path.
	 * @return mixed Value, or null.
	 */
	private static function dot_get( $data, $path ) {
		$path = trim( (string) $path );
		if ( '' === $path || '$' === $path || '.' === $path ) {
			return $data;
		}

		$tokens = self::tokenize_path( $path );
		$value  = $data;
		foreach ( $tokens as $token ) {
			if ( ! is_array( $value ) ) {
				return null;
			}

			if ( 'index' === $token[0] ) {
				if ( array_key_exists( $token[1], $value ) ) {
					$value = $value[ $token[1] ];
					continue;
				}
				return null;
			}

			$seg = $token[1];
			if ( array_key_exists( $seg, $value ) ) {
				$value = $value[ $seg ];
				continue;
			}

			// Implicit: descend into the first element of a sequential array,
			// then look up the key there.
			if ( isset( $value[0] ) && is_array( $value[0] ) && array_key_exists( $seg, $value[0] ) ) {
				$value = $value[0][ $seg ];
				continue;
			}

			return null;
		}

		return $value;
	}

	/**
	 * Extract via CSS selector (first match or all matches).
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param array    $rule  Rule map (selector, attribute).
	 * @param bool     $all   Whether to return all matches.
	 * @return string|array|null
	 */
	private static function extract_selector( $xpath, $rule, $all ) {
		$selector = isset( $rule['selector'] ) ? (string) $rule['selector'] : '';
		if ( '' === $selector ) {
			return null;
		}

		$nodes     = $xpath->query( self::css_to_xpath( $selector ) );
		$attribute = isset( $rule['attribute'] ) ? (string) $rule['attribute'] : '';

		$values = array();
		if ( ! $nodes ) {
			return null; // invalid selector → query() returned false.
		}
		foreach ( $nodes as $node ) {
			if ( '' !== $attribute ) {
				$values[] = trim( $node->getAttribute( $attribute ) );
			} else {
				$values[] = trim( $node->textContent );
			}
		}

		$values = array_values(
			array_filter(
				$values,
				function ( $v ) {
					return '' !== $v;
				}
			)
		);

		if ( empty( $values ) ) {
			return null;
		}

		return $all ? $values : $values[0];
	}

	/**
	 * Extract via a regex against the raw HTML.
	 *
	 * @param string $html Raw HTML.
	 * @param array  $rule Rule map (pattern).
	 * @return string|null
	 */
	private static function extract_regex( $html, $rule ) {
		$pattern = isset( $rule['pattern'] ) ? (string) $rule['pattern'] : '';
		if ( '' === $pattern ) {
			return null;
		}

		if ( preg_match( $pattern, $html, $m ) ) {
			return isset( $m[1] ) ? trim( $m[1] ) : trim( $m[0] );
		}

		return null;
	}

	/**
	 * Convert a limited CSS selector to an XPath expression.
	 *
	 * Supports the subset the AI is instructed to emit: tag, .class, #id,
	 * [attr], [attr="v"], tag.class, tag#id, tag[attr="v"], combined with
	 * descendant (space), child (>), and comma groups.
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath expression.
	 */
	private static function css_to_xpath( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return '//*';
		}

		// Strip whitespace around child combinators ("a > b" → "a>b") so the
		// tokenizer emits a single ">" comb and never builds "////" paths.
		$selector = preg_replace( '/\s*>\s*/', '>', $selector );

		if ( false !== strpos( $selector, ',' ) ) {
			$branches = array();
			foreach ( array_map( 'trim', explode( ',', $selector ) ) as $sub ) {
				$branches[] = preg_replace( '#^//#', '', self::css_to_xpath( $sub ) );
			}
			return '//' . implode( ' | //', $branches );
		}

		$tokens = array();
		$len    = strlen( $selector );
		$i      = 0;

		while ( $i < $len ) {
			if ( ctype_space( $selector[ $i ] ) ) {
				while ( $i < $len && ctype_space( $selector[ $i ] ) ) {
					$i++;
				}
				$tokens[] = array( 'type' => 'comb', 'value' => ' ' );
				continue;
			}

			if ( '>' === $selector[ $i ] ) {
				$tokens[] = array( 'type' => 'comb', 'value' => '>' );
				$i++;
				continue;
			}

			$start = $i;
			while ( $i < $len && ! ctype_space( $selector[ $i ] ) && '>' !== $selector[ $i ] ) {
				$i++;
			}
			$tokens[] = array( 'type' => 'sel', 'value' => substr( $selector, $start, $i - $start ) );
		}

		$xpath = '';
		foreach ( $tokens as $token ) {
			if ( 'sel' === $token['type'] ) {
				$fragment = self::compound_to_xpath( $token['value'] );
				$xpath   .= ( '' === $xpath ) ? '//' . $fragment : $fragment;
			} else {
				$xpath .= ( '>' === $token['value'] ) ? '/' : '//';
			}
		}

		if ( '' === $xpath ) {
			return '//*';
		}
		if ( '//' !== substr( $xpath, 0, 2 ) ) {
			$xpath = '//' . $xpath;
		}

		return $xpath;
	}

	/**
	 * Convert a single compound selector (no combinators) to an XPath fragment.
	 *
	 * @param string $compound Compound selector.
	 * @return string XPath fragment (no leading //).
	 */
	private static function compound_to_xpath( $compound ) {
		$compound = trim( (string) $compound );
		if ( '' === $compound || '*' === $compound ) {
			return '*';
		}

		$pred = array();

		// Attributes first (values may contain . or #).
		if ( preg_match_all( '/\[([a-zA-Z0-9_:@-]+)(?:\s*=\s*(["\'])(.*?)\2)?\]/', $compound, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				if ( isset( $a[2] ) && '' !== $a[2] ) {
					$pred[] = '@' . $a[1] . "='" . $a[3] . "'";
				} else {
					$pred[] = '@' . $a[1];
				}
			}
			$compound = preg_replace( '/\[[^\]]*\]/', '', $compound );
		}

		// #id.
		if ( preg_match( '/#([a-zA-Z0-9_-]+)/', $compound, $m ) ) {
			$pred[]   = "@id='" . $m[1] . "'";
			$compound = str_replace( '#' . $m[1], '', $compound );
		}

		// .class (multiple).
		if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $compound, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $c ) {
				$pred[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . $c[1] . " ')";
			}
			$compound = preg_replace( '/\.[a-zA-Z0-9_-]+/', '', $compound );
		}

		// Tag name.
		$tag = '*';
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*|\*)/', $compound, $m ) ) {
			$tag = $m[1];
		}

		$x = $tag;
		if ( $pred ) {
			$x .= '[' . implode( ' and ', $pred ) . ']';
		}

		return $x;
	}

	/**
	 * Detect variable products and extract variants.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param DOMXPath    $xpath    XPath instance.
	 * @param string      $html     Raw HTML.
	 * @param array       $variable Profile "variable" section.
	 * @param array       $data     Data (by reference).
	 */
	private static function apply_variable( $doc, $xpath, $html, $variable, &$data ) {
		$detection = isset( $variable['detection'] ) && is_array( $variable['detection'] ) ? $variable['detection'] : array();
		$is_var    = false;

		if ( ! empty( $detection ) ) {
			$strategy = isset( $detection['strategy'] ) ? (string) $detection['strategy'] : '';

			if ( 'jsonld_offers' === $strategy ) {
				// schema.org Product with an `offers` array of >1 (each offer
				// is a variant). Robust — no fragile CSS selector needed.
				$is_var = self::jsonld_has_offers_array( $xpath, 2 );
			} elseif ( 'selector' === $strategy && ! empty( $detection['selector'] ) ) {
				// Presence check: the selector matches at least one node.
				$found = $xpath->query( self::css_to_xpath( $detection['selector'] ) );
				$is_var = ( $found && $found->length > 0 );
			} else {
				$is_var = ( null !== self::extract_field( $doc, $xpath, $html, $detection ) );
			}
		} else {
			$is_var = ! empty( $variable['variants'] );
		}

		if ( ! $is_var ) {
			return;
		}

		$variants  = isset( $variable['variants'] ) && is_array( $variable['variants'] ) ? $variable['variants'] : array();
		$extracted = self::extract_variants( $doc, $xpath, $html, $variants );

		if ( ! empty( $extracted ) ) {
			$data['type']     = 'variable';
			$data['variants'] = $extracted;

			if ( '' === $data['price'] && ! empty( $extracted[0]['price'] ) ) {
				$data['price'] = $extracted[0]['price'];
			}
			if ( '' === $data['sku'] && ! empty( $extracted[0]['sku'] ) ) {
				$data['sku'] = $extracted[0]['sku'];
			}
		}
	}

	/**
	 * Extract a normalized variant list.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param DOMXPath    $xpath    XPath instance.
	 * @param string      $html     Raw HTML.
	 * @param array       $variants Variants rule map.
	 * @return array
	 */
	private static function extract_variants( $doc, $xpath, $html, $variants ) {
		$strategy = isset( $variants['strategy'] ) ? (string) $variants['strategy'] : '';

		if ( 'embedded_json' === $strategy ) {
			return self::variants_from_embedded_json( $xpath, $variants );
		}
		if ( 'dom_options' === $strategy ) {
			return self::variants_from_options( $xpath, $variants );
		}
		if ( 'productgroup' === $strategy ) {
			return self::variants_from_productgroup( $xpath, $variants );
		}

		return array();
	}

	/**
	 * Extract variants from schema.org JSON-LD ProductGroup.hasVariant.
	 *
	 * Many custom e-commerce platforms (and Google Shopping feed generators)
	 * emit a `ProductGroup` node whose `hasVariant` array holds one `Product`
	 * per variation — each with sku, image, offers (price/currency/availability)
	 * and often custom properties like "color"/"size". This reads them directly,
	 * so the AI doesn't need fragile CSS selectors for these sites.
	 *
	 * @param DOMXPath $xpath    XPath instance.
	 * @param array    $variants Variants rule map.
	 * @return array
	 */
	private static function variants_from_productgroup( $xpath, $variants ) {
		$nodes = self::flatten_jsonld( $xpath );

		$group = null;
		foreach ( $nodes as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( in_array( 'ProductGroup', $type, true ) && isset( $node['hasVariant'] ) && is_array( $node['hasVariant'] ) ) {
				$group = $node;
				break;
			}
		}
		if ( null === $group ) {
			return array();
		}

		$list = $group['hasVariant'];
		if ( ! isset( $list[0] ) ) {
			$list = array( $list );
		}

		// Attribute names vs JSON paths. When the profile provides explicit
		// attribute_names (+ optional option_paths), those win. Otherwise
		// auto-discover non-standard string props (color/size/...) and use the
		// JSON key as both path and (humanized) label.
		$names  = isset( $variants['attribute_names'] ) && is_array( $variants['attribute_names'] ) ? $variants['attribute_names'] : array();
		$paths  = isset( $variants['option_paths'] ) && is_array( $variants['option_paths'] ) ? $variants['option_paths'] : array();
		$labels = array();

		if ( empty( $names ) ) {
			$keys = self::discover_variant_props( $list );
			foreach ( $keys as $key ) {
				$names[]  = self::humanize_key( $key );
				$paths[]  = $key;
				$labels[] = self::humanize_key( $key );
			}
		} else {
			foreach ( $names as $i => $name ) {
				$labels[] = (string) $name;
			}
		}

		$divide = isset( $variants['divide_by'] ) && (float) $variants['divide_by'] > 0 ? (float) $variants['divide_by'] : 1;

		$out = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$attrs = array();
			foreach ( $names as $i => $name ) {
				$path = ! empty( $paths[ $i ] ) ? (string) $paths[ $i ] : (string) $name;
				$v    = self::dot_get( $item, $path );
				if ( is_scalar( $v ) && '' !== (string) $v ) {
					$attrs[ (string) $labels[ $i ] ] = (string) $v;
				}
			}

			$price    = self::variant_scalar( $item, $variants, 'price_path', 'offers.price' );
			$regular  = self::variant_scalar( $item, $variants, 'regular_price_path', '' );
			$sale     = self::variant_scalar( $item, $variants, 'sale_price_path', '' );
			$sku      = self::variant_scalar( $item, $variants, 'sku_path', 'sku' );
			$image    = self::variant_scalar( $item, $variants, 'image_path', 'image' );

			$price   = is_numeric( $price ) && '' !== $price ? (string) round( (float) $price / $divide, 6 ) : $price;
			$regular = is_numeric( $regular ) && '' !== $regular ? (string) round( (float) $regular / $divide, 6 ) : $regular;
			$sale    = is_numeric( $sale ) && '' !== $sale ? (string) round( (float) $sale / $divide, 6 ) : $sale;

			// Stock from offers.availability (schema.org) or an explicit stock_path.
			$stock = null;
			if ( isset( $variants['stock_path'] ) && '' !== (string) $variants['stock_path'] ) {
				$stock = self::variant_stock( self::dot_get( $item, (string) $variants['stock_path'] ) );
			}
			if ( null === $stock ) {
				$avail = self::dot_get( $item, 'offers.availability' );
				if ( is_string( $avail ) ) {
					$l = strtolower( $avail );
					if ( false !== strpos( $l, 'outofstock' ) || false !== strpos( $l, 'soldout' ) || false !== strpos( $l, 'preorder' ) ) {
						$stock = 0;
					} elseif ( false !== strpos( $l, 'instock' ) || false !== strpos( $l, 'limitedavailability' ) ) {
						$stock = null;
					}
				}
			}

			$out[] = array(
				'attributes' => $attrs,
				'price'      => (string) $price,
				'sale_price' => (string) $sale,
				'sku'        => (string) $sku,
				'image'      => (string) $image,
				'stock'      => $stock,
			);
		}

		return $out;
	}

	/**
	 * Auto-discover variant attribute properties (non-standard string fields
	 * like color/size) present across the variant items.
	 *
	 * Returns the raw JSON keys (not humanized), preserving first-appearance
	 * order, so callers can read each key as the dot path.
	 *
	 * @param array $list Variant items.
	 * @return array
	 */
	private static function discover_variant_props( $list ) {
		$standard = array( '@type', 'sku', 'name', 'image', 'description', 'url', 'offers', 'mpn', 'gtin', 'brand', 'weight', 'height', 'width', 'depth', 'material', 'additionalProperty', 'itemCondition', 'productID', 'identifier', 'releaseDate', 'isRelatedTo', 'isSimilarTo' );
		$keys     = array();

		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $item as $key => $value ) {
				if ( '@' === $key[0] || in_array( $key, $standard, true ) ) {
					continue;
				}
				if ( is_scalar( $value ) && ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
		}

		return $keys;
	}

	/**
	 * Humanize a JSON property key into a display label ("color" → "Color",
	 * "shade_name" → "Shade Name").
	 *
	 * @param string $key JSON key.
	 * @return string
	 */
	private static function humanize_key( $key ) {
		return ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) );
	}

	/**
	 * Strip promotional / stock / placeholder suffixes from a variant name.
	 *
	 * "112 American Woman - 5% Off - Out of Stock" → "112 American Woman".
	 * Returns '' for placeholder labels ("View All Options", "Select …") that
	 * aren't real variants.
	 *
	 * @param string $name Raw variant/option name.
	 * @return string Cleaned name ('' when it's a placeholder).
	 */
	private static function clean_variant_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}

		// Placeholder labels — not real variants.
		if ( preg_match( '/^(view all options?|select an? option|choose an? option|select size|select color|select shade|please select|choose)\b/i', $name ) ) {
			return '';
		}

		// Split on " - " and drop promotional/stock segments.
		$parts = preg_split( '/\s*-\s*/', $name );
		$kept  = array();
		foreach ( $parts as $part ) {
			if ( preg_match( '/^\d+(?:[.,]\d+)?\s*%\s*(?:off|sale)?$/i', $part ) ) {
				continue; // "5% Off", "25%"
			}
			if ( preg_match( '/^(out\s*of\s*stock|sold\s*out|back\s*order(?:ed)?|backorder)$/i', $part ) ) {
				continue; // "Out of Stock", "Sold out"
			}
			if ( preg_match( '/^(off|sale|new|best\s*seller|clearance)$/i', $part ) ) {
				continue; // "Off", "Sale"
			}
			$kept[] = $part;
		}

		return trim( implode( ' - ', $kept ) );
	}

	/**
	 * Extract variants from an embedded JSON blob (script or attribute).
	 *
	 * @param DOMXPath $xpath    XPath instance.
	 * @param array    $variants Variants rule map.
	 * @return array
	 */
	private static function variants_from_embedded_json( $xpath, $variants ) {
		$json     = '';
		$selector = isset( $variants['selector'] ) ? (string) $variants['selector'] : '';

		if ( '' !== $selector ) {
			$nodes = $xpath->query( self::css_to_xpath( $selector ) );
			$node  = $nodes->length ? $nodes->item( 0 ) : null;

			if ( $node ) {
				$attribute = isset( $variants['attribute'] ) ? (string) $variants['attribute'] : '';
				if ( '' !== $attribute ) {
					$json = trim( $node->getAttribute( $attribute ) );
				} else {
					$json = trim( $node->nodeValue );
				}
			}
		}

		if ( '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}

		$path = isset( $variants['json_path'] ) ? (string) $variants['json_path'] : '';
		$list = '' !== $path ? self::dot_get( $decoded, $path ) : $decoded;

		if ( ! is_array( $list ) ) {
			return array();
		}
		if ( ! isset( $list[0] ) ) {
			$list = array( $list );
		}

		$attr_mode = isset( $variants['attribute_mode'] ) ? (string) $variants['attribute_mode'] : 'title_split';
		$names     = isset( $variants['attribute_names'] ) && is_array( $variants['attribute_names'] ) ? $variants['attribute_names'] : array();
		$divide    = isset( $variants['divide_by'] ) && (float) $variants['divide_by'] > 0 ? (float) $variants['divide_by'] : 1;

		$out = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$attrs = array();
			$skip  = false;
			if ( 'attributes_map' === $attr_mode ) {
				// WooCommerce-style: each item has attributes => { "attribute_pa_color": "slug" }.
				$map = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
				foreach ( $map as $key => $value ) {
					$label = (string) $key;
					$label = preg_replace( '/^attribute_pa_/', '', $label );
					$label = preg_replace( '/^attribute_/', '', $label );
					$label = preg_replace( '/^pa_/', '', $label );
					$label = ucwords( str_replace( array( '_', '-' ), ' ', $label ) );
					$attrs[ $label ] = (string) $value;
				}
			} elseif ( 'title_split' === $attr_mode ) {
				$title = isset( $item['title'] ) ? (string) $item['title'] : '';
				foreach ( explode( ' / ', $title ) as $i => $part ) {
					$name             = isset( $names[ $i ] ) ? (string) $names[ $i ] : ( 'Option ' . ( $i + 1 ) );
					$attrs[ $name ]   = trim( $part );
				}
			} elseif ( 'name_attr' === $attr_mode ) {
				// schema.org `offers` arrays (Notino & similar): each offer is a
				// variant distinguished by its `name`, e.g. "DIOR Sauvage 200 ml"
				// or "Avène Sun … shade Sable - Beige 10 g".
				$parent_name = isset( $decoded['name'] ) ? (string) $decoded['name'] : '';
				$title       = isset( $item['name'] ) ? (string) $item['name'] : ( isset( $item['title'] ) ? (string) $item['title'] : '' );
				$rest        = trim( $title );
				if ( '' !== $parent_name && 0 === stripos( $rest, $parent_name ) ) {
					$rest = trim( (string) substr( $rest, strlen( $parent_name ) ) );
				}

				$label = isset( $names[0] ) && '' !== (string) $names[0] ? (string) $names[0] : 'Option';

				// When the AI supplied an explicit `attribute_regex`, use its
				// capture group directly on the offer name (allows arbitrary
				// per-site transforms, e.g. keeping "10 g" in the shade name).
				if ( ! empty( $variants['attribute_regex'] ) ) {
					$pattern = (string) $variants['attribute_regex'];
					// LLMs often double-escape JSON regex ("shade\\\\s+") — the
					// JSON already decoded to a single backslash; collapse any
					// doubled backslashes so \s matches whitespace.
					$pattern = str_replace( '\\\\', '\\', $pattern );
					$value   = '';
					if ( false !== @preg_match( $pattern, $title, $m ) ) {
						$value = isset( $m[1] ) ? trim( (string) $m[1] ) : trim( (string) $m[0] );
					}
					if ( '' === $value ) {
						// Fallback: strip the parent name and a leading
						// "shade/color/…" descriptor word.
						$value = '' !== $rest ? $rest : $title;
						$value = trim( (string) preg_replace( '/^(shade|shades|color|colour|colors|colours|scent|fragrance|flavor|flavour|tone|finish)\b[\s:\-\x{00A0}]*/iu', '', $value ) );
					}
					$attrs[ $label ] = $value;
					// Fall through to price/sku/image extraction below.
				} else {
					// Built-in heuristic (no regex supplied).
					$value = '' !== $rest ? $rest : $title;

					if ( preg_match( '/^(shade|shades|color|colour|colors|colours|scent|fragrance|flavor|flavour|tone|finish)\b/u', $value, $dm ) ) {
						// Descriptor-led: "<shade> <value> [<volume>]".
						$label = isset( $names[0] ) && '' !== (string) $names[0] ? (string) $names[0] : ucfirst( strtolower( $dm[1] ) );
						$value = trim( (string) preg_replace( '/^(shade|shades|color|colour|colors|colours|scent|fragrance|flavor|flavour|tone|finish)\b[\s:\-\x{00A0}]*/iu', '', $value ) );
						// Drop a trailing volume/size ("10 g", "200 ml") — NBSP-safe.
						$value = trim( (string) preg_replace( '/[\s\x{00A0}]*\d+(?:[.,]\d+)?[\s\x{00A0}]*(ml|g|mg|l|kg|oz|cl)[\s\x{00A0}]*$/iu', '', $value ) );
					} elseif ( preg_match( '/^\d+(?:[.,]\d+)?[\s\x{00A0}]*(ml|g|mg|l|kg|oz|cl)$/iu', $value ) ) {
						// Pure volume/size: "200 ml", "10 g".
						$label = 'Size';
					}

					// Strip promo/stock/placeholder suffixes (" - 5% Off",
					// " - Out of Stock", "View All Options", …).
					$value = self::clean_variant_name( $value );
					if ( '' === $value ) {
						$skip = true; // placeholder ("View All Options", etc.).
					} else {
						$attrs[ $label ] = $value;
					}
				}
			} else {
				// option_fields mode. If "option_paths" is provided, each name
				// maps to a dot path on the item (e.g. "color", "size").
				$option_paths = isset( $variants['option_paths'] ) && is_array( $variants['option_paths'] ) ? $variants['option_paths'] : array();

				foreach ( $names as $i => $name ) {
					$label = (string) $name;

					if ( ! empty( $option_paths[ $i ] ) ) {
						$v = self::dot_get( $item, (string) $option_paths[ $i ] );
						if ( is_scalar( $v ) && '' !== (string) $v ) {
							$attrs[ $label ] = (string) $v;
						}
						continue;
					}

					$key = 'option' . ( $i + 1 );
					if ( array_key_exists( $key, $item ) ) {
						$attrs[ $label ] = (string) $item[ $key ];
					} elseif ( isset( $item['options'] ) && is_array( $item['options'] ) ) {
						$opts = $item['options'];
						if ( isset( $opts[0] ) ) {
							$attrs[ $label ] = isset( $opts[ $i ] ) ? (string) $opts[ $i ] : '';
						} elseif ( array_key_exists( $label, $opts ) ) {
							$attrs[ $label ] = (string) $opts[ $label ];
						}
					}
				}
			}

			$price   = self::variant_scalar( $item, $variants, 'price_path' );
			$regular = self::variant_scalar( $item, $variants, 'regular_price_path' );
			$sale    = self::variant_scalar( $item, $variants, 'sale_price_path' );
			$sku     = self::variant_scalar( $item, $variants, 'sku_path' );
			$image   = self::variant_scalar( $item, $variants, 'image_path' );

			$price   = is_numeric( $price ) && '' !== $price ? (string) round( (float) $price / $divide, 6 ) : $price;
			$regular = is_numeric( $regular ) && '' !== $regular ? (string) round( (float) $regular / $divide, 6 ) : $regular;
			$sale    = is_numeric( $sale ) && '' !== $sale ? (string) round( (float) $sale / $divide, 6 ) : $sale;

			// Stock: an "available" boolean → true = in stock (null), false = out (0).
			// Also accept numeric quantities and WooCommerce "instock"/"outofstock" strings.
			$stock      = null;
			$stock_path = isset( $variants['stock_path'] ) ? (string) $variants['stock_path'] : '';
			if ( '' !== $stock_path ) {
				$raw = self::dot_get( $item, $stock_path );
				if ( is_bool( $raw ) ) {
					$stock = $raw ? null : 0;
				} elseif ( is_numeric( $raw ) ) {
					$stock = (int) $raw;
				} elseif ( is_string( $raw ) ) {
					$l = strtolower( $raw );
					if ( in_array( $l, array( 'outofstock', 'out-of-stock', 'out of stock', 'false', 'no', '0' ), true ) ) {
						$stock = 0;
					}
				}
			}

			if ( $skip ) {
				continue; // placeholder option ("View All Options", etc.).
			}

			$out[] = array(
				'attributes' => $attrs,
				'price'      => (string) $price,
				'sale_price' => (string) $sale,
				'sku'        => (string) $sku,
				'image'      => (string) $image,
				'stock'      => $stock,
			);
		}

		return $out;
	}

	/**
	 * Extract variants from <select> options.
	 *
	 * @param DOMXPath $xpath    XPath instance.
	 * @param array    $variants Variants rule map.
	 * @return array
	 */
	private static function variants_from_options( $xpath, $variants ) {
		$selector = isset( $variants['selector'] ) ? (string) $variants['selector'] : 'select';
		$nodes    = $xpath->query( self::css_to_xpath( $selector ) . '/option' );

		$name = isset( $variants['attribute_names'][0] ) ? (string) $variants['attribute_names'][0] : 'Option';

		$out = array();
		foreach ( $nodes as $option ) {
			$label = trim( $option->textContent );
			if ( '' === $label ) {
				continue;
			}

			$out[] = array(
				'attributes' => array( $name => $label ),
				'price'      => '',
				'sale_price' => '',
				'sku'        => '',
				'image'      => '',
				'stock'      => null,
			);
		}

		return $out;
	}

	/**
	 * Read a scalar value from a variant item at a configured path.
	 *
	 * @param array  $item     Variant item.
	 * @param array  $variants Variants rule map.
	 * @param string $path_key Rule key holding the dot path.
	 * @param string $default  Fallback dot path when the rule key is absent/empty.
	 * @return string
	 */
	private static function variant_scalar( $item, $variants, $path_key, $default = '' ) {
		$path = isset( $variants[ $path_key ] ) ? (string) $variants[ $path_key ] : '';
		if ( '' === $path ) {
			$path = $default;
		}
		if ( '' === $path ) {
			return '';
		}

		$value = self::dot_get( $item, $path );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Normalize a raw stock value into 0 (out of stock) or null (in stock).
	 *
	 * @param mixed $raw Raw stock value.
	 * @return int|null
	 */
	private static function variant_stock( $raw ) {
		if ( is_bool( $raw ) ) {
			return $raw ? null : 0;
		}
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		if ( is_string( $raw ) ) {
			$l = strtolower( $raw );
			if ( in_array( $l, array( 'outofstock', 'out-of-stock', 'out of stock', 'false', 'no', '0', 'soldout' ), true ) ) {
				return 0;
			}
		}
		return null;
	}

	/**
	 * Fallback image extraction: try well-known product-gallery containers.
	 *
	 * Used when the profile's images rule yielded nothing, so product images
	 * are still captured deterministically. Runs the candidate selectors in
	 * priority order and returns the first non-empty result set.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @return array Image URLs.
	 */
	private static function fallback_images( $xpath ) {
		$selectors = array(
			// PrestaShop / fotorama.
			'#product-gallery img',
			'.fotorama img',
			// WooCommerce.
			'.woocommerce-product-gallery img',
			'.woocommerce-product-gallery__image img',
			'.product .images img',
			// Shopify (Dawn/theme gallery).
			'.product__media img',
			'.product-gallery img',
			'[data-product-gallery] img',
			// Generic carousels.
			'.gallery img',
			'.product-images img',
			'.swiper-container img',
			'.owl-carousel img',
		);

		foreach ( $selectors as $selector ) {
			$xpath_expr = self::css_to_xpath( $selector );
			$nodes      = $xpath->query( $xpath_expr );
			if ( ! $nodes || 0 === $nodes->length ) {
				continue;
			}

			$out = array();
			foreach ( $nodes as $node ) {
				$src = trim( $node->getAttribute( 'src' ) );
				if ( '' === $src ) {
					$src = trim( $node->getAttribute( 'data-src' ) );
				}
				if ( '' !== $src ) {
					$out[] = $src;
				}
			}

			if ( ! empty( $out ) ) {
				return array_values( array_unique( $out ) );
			}
		}

		return array();
	}

	/**
	 * Normalize a price string to a bare number ("$39.18" → "39.18"),
	 * stripping currency symbols/letters and normalizing separators. Also
	 * fills $currency (by reference) when the price carried a symbol.
	 *
	 * @param string $price    Raw price.
	 * @param string $currency Currency (by reference).
	 * @return string
	 */
	private static function normalize_price( $price, &$currency ) {
		$raw = trim( (string) $price );
		if ( '' === $raw ) {
			return '';
		}

		// Derive currency from a leading symbol.
		$symbol_map = array(
			'$'    => 'USD',
			'US$'  => 'USD',
			'USD'  => 'USD',
			'€'    => 'EUR',
			'EUR'  => 'EUR',
			'£'    => 'GBP',
			'GBP'  => 'GBP',
			'AED'  => 'AED',
			'SAR'  => 'SAR',
			'JOD'  => 'JOD',
			'EGP'  => 'EGP',
			'KWD'  => 'KWD',
			'QAR'  => 'QAR',
		);
		foreach ( $symbol_map as $sym => $code ) {
			if ( 0 === stripos( $raw, $sym ) || false !== stripos( $raw, ' ' . $sym ) ) {
				if ( '' === (string) $currency ) {
					$currency = $code;
				}
				break;
			}
		}

		// Keep only digits, dot, comma and a leading minus.
		$s = preg_replace( '/[^0-9.,\-]/', '', $raw );
		if ( '' === $s ) {
			return '';
		}

		$last_comma = strrpos( $s, ',' );
		$last_dot   = strrpos( $s, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			// Both present: rightmost is the decimal separator.
			if ( $last_comma > $last_dot ) {
				// "1.299,99" — dots are thousands, comma is decimal.
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				// "1,299.99" — commas are thousands.
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $last_comma && false === $last_dot ) {
			$parts = explode( ',', $s );
			$after = end( $parts );
			if ( count( $parts ) > 1 && 3 === strlen( $after ) ) {
				$s = str_replace( ',', '', $s );
			} else {
				$s = str_replace( ',', '.', $s );
			}
		}

		return (string) $s;
	}

	/**
	 * Read a <meta> value by property= then name=.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param string   $key   Meta key.
	 * @return string
	 */
	private static function meta_value( $xpath, $key ) {
		$node = $xpath->query( '//meta[@property="' . $key . '"]' )->item( 0 );
		if ( ! $node ) {
			$node = $xpath->query( '//meta[@name="' . $key . '"]' )->item( 0 );
		}
		return $node ? trim( $node->getAttribute( 'content' ) ) : '';
	}

	/**
	 * Back-fill still-empty scalar fields from meta tags, JSON-LD and DOM.
	 * Never overwrites a value the profile already extracted.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param DOMXPath    $xpath XPath instance.
	 * @param array       $data Data (by reference).
	 */
	private static function enrich_scalars( $doc, $xpath, &$data ) {
		// Title.
		if ( '' === trim( (string) $data['title'] ) ) {
			$t = self::meta_value( $xpath, 'og:title' );
			if ( '' === $t ) {
				$n = $xpath->query( '//h1' )->item( 0 );
				$t = $n ? trim( $n->textContent ) : '';
			}
			if ( '' === $t ) {
				$n = $xpath->query( '//title' )->item( 0 );
				$t = $n ? trim( $n->textContent ) : '';
			}
			$data['title'] = $t;
		}

		// Short description.
		if ( '' === trim( (string) $data['short_description'] ) ) {
			$data['short_description'] = self::meta_value( $xpath, 'og:description' );
		}

		// Description (fall back to og:description when missing).
		if ( '' === trim( (string) $data['description'] ) ) {
			$data['description'] = self::meta_value( $xpath, 'description' );
			if ( '' === $data['description'] ) {
				$data['description'] = self::meta_value( $xpath, 'og:description' );
			}
		}

		// Price / currency from meta + JSON-LD.
		if ( '' === trim( (string) $data['price'] ) ) {
			$p = self::meta_value( $xpath, 'product:price:amount' );
			if ( '' === $p ) {
				$p = self::meta_value( $xpath, 'og:price:amount' );
			}
			$data['price'] = $p;
		}
		if ( '' === trim( (string) $data['currency'] ) ) {
			$data['currency'] = self::meta_value( $xpath, 'product:price:currency' );
			if ( '' === $data['currency'] ) {
				$data['currency'] = self::meta_value( $xpath, 'og:price:currency' );
			}
		}

		// SKU / description / price / images from schema.org JSON-LD.
		foreach ( self::flatten_jsonld( $xpath ) as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( ! in_array( 'Product', $type, true ) ) {
				continue;
			}
			if ( '' === trim( (string) $data['sku'] ) ) {
				if ( ! empty( $node['sku'] ) ) {
					$data['sku'] = (string) $node['sku'];
				} elseif ( ! empty( $node['mpn'] ) ) {
					$data['sku'] = (string) $node['mpn'];
				} elseif ( ! empty( $node['gtin13'] ) ) {
					$data['sku'] = (string) $node['gtin13'];
				}
			}
			if ( '' === trim( (string) $data['description'] ) && ! empty( $node['description'] ) ) {
				$data['description'] = (string) $node['description'];
			}
			if ( '' === trim( (string) $data['price'] ) && ! empty( $node['offers'] ) ) {
				$offers = isset( $node['offers'][0] ) ? $node['offers'] : array( $node['offers'] );
				if ( isset( $offers[0]['price'] ) ) {
					$data['price'] = (string) $offers[0]['price'];
				}
				if ( '' === trim( (string) $data['currency'] ) && isset( $offers[0]['priceCurrency'] ) ) {
					$data['currency'] = (string) $offers[0]['priceCurrency'];
				}
			}
			break; // first Product node only.
		}

		// Categories from BreadcrumbList JSON-LD when still empty.
		if ( empty( $data['categories'] ) ) {
			foreach ( self::flatten_jsonld( $xpath ) as $node ) {
				$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
				if ( ! in_array( 'BreadcrumbList', $type, true ) || empty( $node['itemListElement'] ) ) {
					continue;
				}
				$cats = array();
				foreach ( $node['itemListElement'] as $item ) {
					$name = '';
					if ( isset( $item['name'] ) && is_scalar( $item['name'] ) ) {
						$name = (string) $item['name'];
					} elseif ( isset( $item['item']['name'] ) && is_scalar( $item['item']['name'] ) ) {
						$name = (string) $item['item']['name'];
					}
					$name = trim( $name );
					if ( '' !== $name && ! in_array( strtolower( $name ), array( 'home', 'home page', 'shop', 'back to products' ), true ) ) {
						$cats[] = $name;
					}
				}
				if ( ! empty( $cats ) ) {
					$data['categories'] = $cats;
				}
				break;
			}
		}
	}

	/**
	 * Collect images from every known source: JSON-LD, OpenGraph meta,
	 * <img srcset>, embedded JSON, and gallery containers.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param DOMXPath    $xpath XPath instance.
	 * @param string      $html Raw HTML.
	 * @return array
	 */
	private static function enrich_images( $doc, $xpath, $html ) {
		$images = array();

		// 1. schema.org JSON-LD Product.image.
		foreach ( self::flatten_jsonld( $xpath ) as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( ! in_array( 'Product', $type, true ) || empty( $node['image'] ) ) {
				continue;
			}
			$imgs = is_array( $node['image'] ) ? $node['image'] : array( $node['image'] );
			foreach ( $imgs as $img ) {
				if ( is_string( $img ) ) {
					$images[] = $img;
				} elseif ( is_array( $img ) && ! empty( $img['url'] ) ) {
					$images[] = (string) $img['url'];
				}
			}
			if ( ! empty( $images ) ) {
				break;
			}
		}

		// 2. OpenGraph / twitter image.
		if ( empty( $images ) ) {
			foreach ( array( 'og:image', 'og:image:secure_url', 'twitter:image' ) as $k ) {
				$v = self::meta_value( $xpath, $k );
				if ( '' !== $v ) {
					$images[] = $v;
				}
			}
		}

		// 3. <img srcset> — pick the largest candidate.
		if ( empty( $images ) ) {
			foreach ( $xpath->query( '//img[@srcset]' ) as $img ) {
				$srcset = $img->getAttribute( 'srcset' );
				$best   = self::best_srcset_url( $srcset );
				if ( '' !== $best ) {
					$images[] = $best;
				}
			}
		}

		// 4. Embedded JSON blobs (data-props / application/json).
		if ( empty( $images ) ) {
			foreach ( $xpath->query( '//*[@data-props]' ) as $node ) {
				$raw = trim( $node->getAttribute( 'data-props' ) );
				if ( '' !== $raw ) {
					$images = array_merge( $images, self::collect_json_images( $raw ) );
				}
			}
			foreach ( $xpath->query( '//script[@type="application/json"]' ) as $node ) {
				$images = array_merge( $images, self::collect_json_images( trim( $node->nodeValue ) ) );
			}
		}

		// 5. DOM gallery containers.
		if ( empty( $images ) ) {
			$images = self::fallback_images( $xpath );
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $images ) ) ) );
	}

	/**
	 * Recursively collect image URLs from a decoded JSON blob.
	 *
	 * @param string $json JSON string.
	 * @return array
	 */
	private static function collect_json_images( $json ) {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		self::walk_json_images( $decoded, $out );
		return $out;
	}

	/**
	 * Recursively walk a JSON structure for URL-looking image values.
	 *
	 * @param mixed $node JSON node.
	 * @param array $out  Accumulator.
	 */
	private static function walk_json_images( $node, &$out ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				self::walk_json_images( $value, $out );
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$v = trim( $value );
			if ( preg_match( '#^https?://[^\s"\']+\.(?:jpe?g|png|webp|gif|avif)(?:\?[^\s"\']*)?$#i', $v ) ) {
				$out[] = $v;
			}
		}
	}

	/**
	 * Pick the highest-resolution URL from an <img srcset> attribute.
	 *
	 * @param string $srcset srcset attribute value.
	 * @return string
	 */
	private static function best_srcset_url( $srcset ) {
		$best_url = '';
		$best_w   = 0;
		foreach ( preg_split( '/\s*,\s*/', (string) $srcset ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$parts = preg_split( '/\s+/', $candidate );
			$url   = $parts[0];
			$w     = 0;
			if ( isset( $parts[1] ) && preg_match( '/^(\d+)w$/', $parts[1], $m ) ) {
				$w = (int) $m[1];
			}
			if ( '' !== $url && $w >= $best_w ) {
				$best_w   = $w;
				$best_url = $url;
			}
		}
		return $best_url;
	}

	/**
	 * Upgrade a low-resolution CDN image URL to its highest-quality variant.
	 *
	 * Notino serves the same image at several sizes — `order`/`order_2k` are
	 * 2–3 KB placeholder thumbnails while `detail_main_hq` is the real photo.
	 * Other CDNs are handled with a conservative width-query bump.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function upgrade_image_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return $url;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		// Notino: order/order_2k/lq/mq → detail_main_hq (real high-res photo).
		if ( false !== strpos( $host, 'notinoimg.com' ) ) {
			return (string) preg_replace( '#/(order_2k|order|detail_main_lq|detail_main_mq|detail_main_hq)/#', '/detail_main_hq/', $url );
		}

		// Generic: bump a small width query param (widely supported, safe).
		if ( preg_match( '#([?&](?:w|width)=\d+)#', $url, $m ) ) {
			$width = (int) preg_replace( '/\D/', '', $m[1] );
			if ( $width > 0 && $width < 800 ) {
				return str_replace( $m[1], preg_replace( '/\d+/', '1200', $m[1] ), $url );
			}
		}

		return $url;
	}

	/**
	 * Extract color swatches from the rendered HTML for the generic/universal
	 * path, in the same shape the importer expects (mirrors the WooCommerce/Rey
	 * scraper):
	 *
	 *   [ 'pa_shade' => [ 'name' => 'Shade', 'type' => 'rey_color',
	 *                     'terms' => [ slug => ['name','slug','color'] ] ] ]
	 *
	 * Signals (in order):
	 *   1. CSS-in-JS "color picker" (Notino): <li data-testid="color-picker-item">
	 *      whose swatch circle carries `--<hash>-2: #RRGGBB`, with the term
	 *      label in the same item ("shade 020N 30 ml" → "020N").
	 *   2. Generic: an element with an inline background hex AND a
	 *      data-value / data-title attribute naming the term.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param DOMXPath    $xpath XPath instance.
	 * @param array       $data Normalized product data (for the attribute label).
	 * @return array
	 */
	private static function extract_swatches( $doc, $xpath, $data ) {
		$label = 'Shade';
		if ( ! empty( $data['variants'] ) && isset( $data['variants'][0]['attributes'] ) && is_array( $data['variants'][0]['attributes'] ) ) {
			$keys = array_keys( $data['variants'][0]['attributes'] );
			if ( ! empty( $keys ) ) {
				$label = (string) $keys[0];
			}
		}

		$terms = array();

		// 1) Notino-style CSS-in-JS color picker. The shade LABEL is rendered
		// in a separate tooltip portal (not inside the swatch <li>), so we
		// join two maps by product ID:
		//   - color-picker-item <a href=".../p-<id>/"> carries the swatch color
		//   - JSON-LD offer "url" .../p-<id>/ + "name" ("... shade 020N ...")
		$pid_to_color = array();
		// Match BOTH "color-picker-item" and "color-picker-item-selected" — the
		// selected shade uses a different data-testid value and would otherwise
		// be skipped, dropping its swatch color.
		$items = $xpath->query( '//*[starts-with(@data-testid, "color-picker-item")]' );
		if ( $items && $items->length ) {
			foreach ( $items as $item ) {
				$frag  = $doc->saveHTML( $item );
				$color = '';
				if ( preg_match( '/--[a-zA-Z0-9]+-2\s*:\s*(#[0-9a-fA-F]{6})/', $frag, $m ) ) {
					$color = $m[1];
				}
				$pid = '';
				if ( preg_match( '/p-(\d+)/', $frag, $m ) ) {
					$pid = $m[1];
				}
				if ( '' !== $pid && '' !== $color ) {
					$pid_to_color[ $pid ] = $color;
				}
			}
		}

		if ( ! empty( $pid_to_color ) ) {
			foreach ( self::flatten_jsonld( $xpath ) as $node ) {
				$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
				if ( ! in_array( 'Product', $type, true ) || empty( $node['offers'] ) ) {
					continue;
				}
				$parent_name = isset( $node['name'] ) ? (string) $node['name'] : '';
				$offers      = isset( $node['offers'][0] ) ? $node['offers'] : array( $node['offers'] );
				foreach ( $offers as $offer ) {
					if ( ! is_array( $offer ) ) {
						continue;
					}
					$name = isset( $offer['name'] ) ? (string) $offer['name'] : '';
					if ( '' === $name ) {
						continue;
					}
					// Reuse the same parser that produces the VARIANT attribute
					// values, so the swatch term name EXACTLY matches the
					// variation value ("Sable - Beige", "Doré - Honey"). A
					// first-word-only regex here would yield "Sable"/"Dor" and
					// break the term↔variation link (no swatch rendered).
					$attr  = self::parse_offer_attribute( $parent_name, $name );
					$shade = trim( (string) $attr['value'] );
					if ( '' === $shade ) {
						continue;
					}
					$url = isset( $offer['url'] ) ? (string) $offer['url'] : ( isset( $offer['@id'] ) ? (string) $offer['@id'] : '' );
					if ( ! preg_match( '/p-(\d+)/', $url, $pm ) ) {
						continue;
					}
					$pid = $pm[1];
					if ( ! isset( $pid_to_color[ $pid ] ) ) {
						continue;
					}
					$slug           = wc_sanitize_taxonomy_name( $shade );
					$terms[ $slug ] = array(
						'name'  => $shade,
						'slug'  => $slug,
						'color' => $pid_to_color[ $pid ],
					);
				}
				if ( ! empty( $terms ) ) {
					break;
				}
			}
		}

		// 2) Generic inline-background swatches (only if #1 found nothing).
		if ( empty( $terms ) ) {
			foreach ( $xpath->query( '//*[@data-value or @data-title]' ) as $node ) {
				$style = $node->getAttribute( 'style' );
				$color = '';
				if ( '' !== $style && preg_match( '/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{6})/', $style, $m ) ) {
					$color = $m[1];
				}
				$name = trim( $node->getAttribute( 'data-value' ) );
				if ( '' === $name ) {
					$name = trim( $node->getAttribute( 'data-title' ) );
				}
				if ( '' === $name || '' === $color ) {
					continue;
				}
				$slug           = wc_sanitize_taxonomy_name( $name );
				$terms[ $slug ] = array(
					'name'  => $name,
					'slug'  => $slug,
					'color' => $color,
				);
			}
		}

		// 3) Headless / Magento-style embedded JSON: swatch colors live inside
		// a `data-props` attribute (or <script type="application/json">) as an
		// options array where each option has a `name` and an `after`/`html`
		// fragment carrying `background-color: #HEX` (or an <img>).
		if ( empty( $terms ) ) {
			$json_candidates = array();
			foreach ( $xpath->query( '//*[@data-props]' ) as $node ) {
				$raw = trim( $node->getAttribute( 'data-props' ) );
				if ( '' !== $raw ) {
					$json_candidates[] = $raw;
				}
			}
			foreach ( $xpath->query( '//script[@type="application/json"]' ) as $node ) {
				$raw = trim( $node->nodeValue );
				if ( '' !== $raw ) {
					$json_candidates[] = $raw;
				}
			}
			foreach ( $json_candidates as $raw ) {
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					continue;
				}
				$found = self::extract_swatches_from_json( $decoded );
				if ( ! empty( $found ) ) {
					$terms = $found;
					break;
				}
			}
		}

		if ( empty( $terms ) ) {
			return array();
		}

		$taxonomy = wc_attribute_taxonomy_name( $label );
		$type     = 'rey_color';
		// If any term has an image (and no color), this attribute is image-typed.
		$any_image = false;
		foreach ( $terms as $t ) {
			if ( ! empty( $t['image'] ) && empty( $t['color'] ) ) {
				$any_image = true;
				break;
			}
		}
		if ( $any_image ) {
			$type = 'rey_image';
		}

		return array(
			$taxonomy => array(
				'name'  => $label,
				'type'  => $type,
				'terms' => $terms,
			),
		);
	}

	/**
	 * Recursively walk a decoded JSON structure looking for a swatch "options"
	 * list (a sequential array of items that each carry a name + a color/image).
	 *
	 * @param mixed $node  Decoded JSON node.
	 * @param array $terms Accumulator (slug => {name,slug,color,image}).
	 */
	private static function walk_swatch_json( $node, &$terms ) {
		if ( ! is_array( $node ) ) {
			return;
		}

		// Sequential array → maybe an options list.
		if ( isset( $node[0] ) && is_array( $node[0] ) ) {
			$has_name  = false;
			$has_visual = false;
			foreach ( $node as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( '' !== self::find_swatch_name( $item ) ) {
					$has_name = true;
				}
				if ( '' !== self::find_swatch_color( $item ) || '' !== self::find_swatch_image( $item ) ) {
					$has_visual = true;
				}
			}

			if ( $has_name && $has_visual ) {
				foreach ( $node as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$clean = self::clean_variant_name( self::find_swatch_name( $item ) );
					if ( '' === $clean ) {
						continue;
					}
					$color = self::find_swatch_color( $item );
					$image = self::find_swatch_image( $item );
					if ( '' === $color && '' === $image ) {
						continue;
					}
					$slug = wc_sanitize_taxonomy_name( $clean );
					if ( ! isset( $terms[ $slug ] ) ) {
						$terms[ $slug ] = array(
							'name'  => $clean,
							'slug'  => $slug,
							'color' => $color,
							'image' => $image,
						);
					}
				}
				return;
			}

			foreach ( $node as $item ) {
				self::walk_swatch_json( $item, $terms );
			}
			return;
		}

		// Associative array → recurse into each value.
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::walk_swatch_json( $value, $terms );
			}
		}
	}

	/**
	 * Extract swatch terms from a decoded JSON blob (headless storefronts).
	 *
	 * @param array $json Decoded JSON.
	 * @return array slug => {name,slug,color,image}.
	 */
	private static function extract_swatches_from_json( $json ) {
		$terms = array();
		self::walk_swatch_json( $json, $terms );
		return $terms;
	}

	/**
	 * Resolve a swatch option's display name from common key names.
	 *
	 * @param array $item Option item.
	 * @return string
	 */
	private static function find_swatch_name( $item ) {
		foreach ( array( 'name', 'label', 'title', 'swatch_name', 'value_name' ) as $k ) {
			if ( isset( $item[ $k ] ) && is_scalar( $item[ $k ] ) && '' !== trim( (string) $item[ $k ] ) ) {
				return (string) $item[ $k ];
			}
		}
		return '';
	}

	/**
	 * Resolve a swatch color from an option item (direct color-ish key, or an
	 * embedded `background-color: #HEX` fragment).
	 *
	 * @param array $item Option item.
	 * @return string Hex color (uppercase) or ''.
	 */
	private static function find_swatch_color( $item ) {
		foreach ( array( 'color', 'swatch_color', 'background_color', 'hex', 'hex_color' ) as $k ) {
			if ( isset( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
				$c = strtolower( trim( $item[ $k ] ) );
				if ( '0x' === substr( $c, 0, 2 ) ) {
					$c = '#' . substr( $c, 2 );
				}
				if ( preg_match( '/^#[0-9a-f]{6}$/', $c ) ) {
					return strtoupper( $c );
				}
			}
		}
		foreach ( $item as $v ) {
			if ( is_scalar( $v ) && preg_match( '/background-color\s*:\s*(#[0-9a-fA-F]{6})/i', (string) $v, $m ) ) {
				return strtoupper( $m[1] );
			}
		}
		return '';
	}

	/**
	 * Resolve a swatch image URL from an option item (direct image key, or an
	 * embedded `background-image:url(...)` / `<img src>` fragment).
	 *
	 * @param array $item Option item.
	 * @return string Image URL or ''.
	 */
	private static function find_swatch_image( $item ) {
		foreach ( array( 'image', 'swatch_image', 'image_url', 'thumbnail', 'thumb' ) as $k ) {
			if ( isset( $item[ $k ] ) && is_string( $item[ $k ] ) && preg_match( '#^https?://#i', trim( $item[ $k ] ) ) ) {
				return trim( $item[ $k ] );
			}
		}
		foreach ( $item as $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$s = (string) $v;
			if ( preg_match( '/background-image\s*:\s*url\([\"\']?([^\"\')]+)[\"\']?\)/i', $s, $m ) ) {
				return $m[1];
			}
			if ( preg_match( '/<img[^>]+src=[\"\']([^\"\']+)[\"\']/i', $s, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/**
	 * Resolve a (possibly relative) URL against a base URL.
	 *
	 * @param string $src  URL value.
	 * @param string $base Base URL.
	 * @return string
	 */
	private static function absolutize_url( $src, $base ) {
		$src = trim( (string) $src );
		if ( '' === $src || '#' === $src[0] || 0 === strpos( $src, 'data:' ) ) {
			return '';
		}

		if ( 0 === strpos( $src, '//' ) ) {
			return 'https:' . $src;
		}
		if ( preg_match( '#^https?://#i', $src ) ) {
			return $src;
		}

		$parts  = wp_parse_url( $base );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		if ( 0 === strpos( $src, '/' ) ) {
			return $scheme . '://' . $host . $src;
		}

		$dir = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
		if ( '.' === $dir ) {
			$dir = '/';
		}

		return $scheme . '://' . $host . rtrim( $dir, '/' ) . '/' . $src;
	}

	/**
	 * Absolutize a list of image URLs.
	 *
	 * @param mixed  $images Image URL(s).
	 * @param string $base   Base URL.
	 * @return array
	 */
	private static function absolutize_images( $images, $base ) {
		$out = array();
		foreach ( (array) $images as $image ) {
			$abs = self::absolutize_url( (string) $image, $base );
			if ( '' !== $abs ) {
				$out[] = $abs;
			}
		}
		return $out;
	}
}
