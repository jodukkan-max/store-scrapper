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

		// Image fallback: if the profile's images rule yielded nothing (or the
		// AI guessed a wrong selector), try known gallery containers so product
		// images are still captured deterministically (PrestaShop/fotorama,
		// Shopify, WooCommerce, generic swiper/owl carousels, etc.).
		if ( empty( $data['images'] ) ) {
			$data['images'] = self::fallback_images( $xpath );
		}

		// Absolutize image URLs against the product URL.
		$data['images'] = self::absolutize_images( $data['images'], $url );
		foreach ( $data['variants'] as $i => $variant ) {
			if ( ! empty( $variant['image'] ) ) {
				$data['variants'][ $i ]['image'] = self::absolutize_url( $variant['image'], $url );
			}
		}

		return $data;
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
			$values = array_values( array_filter( array_map( 'strval', $values ), function ( $v ) {
				return '' !== trim( $v );
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
		preg_match_all( '/\.?([^.\[\]]+)|\[(\d+)\]/', (string) $path, $m, PREG_SET_ORDER );
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

			if ( 'selector' === $strategy && ! empty( $detection['selector'] ) ) {
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
