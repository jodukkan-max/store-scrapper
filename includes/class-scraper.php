<?php
/**
 * Product page scraper: extract product data from HTML using JSON-LD, meta tags
 * and platform-specific selectors, and detect whether a product is simple or
 * variable.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Scraper {

	/**
	 * Lightweight preview for the selection screen (title, image, price).
	 *
	 * @param string $url Product URL.
	 * @return array|WP_Error
	 */
	public static function preview( $url ) {
		// A saved profile for this site overrides the generic meta preview.
		$profile = SWI_Profile::get( $url );
		if ( null !== $profile ) {
			$data = SWI_Generic_Scraper::scrape( $url, $profile );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			return array(
				'title'    => isset( $data['title'] ) ? $data['title'] : '',
				'image'    => isset( $data['images'][0] ) ? $data['images'][0] : '',
				'price'    => isset( $data['price'] ) ? $data['price'] : '',
				'currency' => isset( $data['currency'] ) ? $data['currency'] : '',
			);
		}

		// Previews only need a lightweight title/price/thumbnail. Use a single
		// fast request (no retry loop, shorter timeout) so a large catalog can
		// be previewed quickly without long stalls on slow/404 pages.
		$html = SWI_HTTP::get( $url, 15 );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$doc = self::load_dom( $html );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$xpath = new DOMXPath( $doc );

		$get = function ( $property ) use ( $xpath ) {
			$node = $xpath->query( '//meta[@property="' . $property . '"]' )->item( 0 );
			if ( ! $node ) {
				$node = $xpath->query( '//meta[@name="' . $property . '"]' )->item( 0 );
			}
			return $node ? trim( $node->getAttribute( 'content' ) ) : '';
		};

		return array(
			'title'    => $get( 'og:title' ),
			'image'    => $get( 'og:image' ),
			'price'    => $get( 'product:price:amount' ),
			'currency' => $get( 'product:price:currency' ),
		);
	}

	/**
	 * Fully scrape a product page.
	 *
	 * @param string $url      Product URL.
	 * @param string $platform shopify|woocommerce.
	 * @return array|WP_Error
	 */
	public static function scrape( $url, $platform ) {
		// A saved profile routes this product through the generic engine,
		// regardless of the platform argument.
		$profile = SWI_Profile::get( $url );
		if ( null !== $profile ) {
			return SWI_Generic_Scraper::scrape( $url, $profile );
		}

		$html = SWI_HTTP::get_with_retry( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$doc = self::load_dom( $html );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}

		$data = array(
			'title'       => '',
			'description' => '',
			'price'       => '',
			'sale_price'  => '',
			'currency'    => '',
			'sku'         => '',
			'images'      => array(),
			'categories'  => array(),
			'type'        => 'simple',
			'variants'    => array(),
			'swatches'    => array(),
		);

		// 1. JSON-LD structured data.
		$jsonld = self::parse_jsonld( $doc );
		self::apply_jsonld( $jsonld, $data );

		// 2. Meta / OpenGraph tags.
		self::apply_meta( $doc, $data );

		// 3. Platform-specific selectors as fallback.
		self::apply_selectors( $doc, $html, $platform, $data );

		// 4. Product type (simple vs variable) + variants.
		self::apply_type_and_variants( $doc, $html, $platform, $data );

		// 4b. Shopify: authoritative variant data from the public `.json`
		// endpoint (option names + values + decimal prices). More reliable
		// than HTML and resilient to the page being rate-limited (429).
		if ( 'shopify' === $platform ) {
			self::apply_shopify_json_endpoint( $url, $data );
		}

		// 4c. Rey variation swatches (color/image/button) for WooCommerce.
		self::apply_swatches( $doc, $platform, $data );

		// 5. Absolutize any relative image URLs against the product URL.
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
	 * Extract and flatten all JSON-LD objects from the document.
	 *
	 * @param DOMDocument $doc Document.
	 * @return array
	 */
	private static function parse_jsonld( $doc ) {
		$xpath = new DOMXPath( $doc );
		$nodes = $xpath->query( '//script[@type="application/ld+json"]' );

		$items = array();
		foreach ( $nodes as $node ) {
			$json = trim( $node->nodeValue );
			if ( '' === $json ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				continue;
			}
			$items = array_merge( $items, self::flatten_jsonld( $decoded ) );
		}

		return $items;
	}

	/**
	 * Flatten nested JSON-LD: collect every node carrying an @type key,
	 * recursively descending into any nested array (lists, @graph, hasVariant,
	 * etc.).
	 *
	 * @param mixed $data  Decoded JSON-LD.
	 * @param array $out   Accumulator.
	 * @param int   $depth Recursion depth guard.
	 * @return array
	 */
	private static function flatten_jsonld( $data, &$out = array(), $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 10 ) {
			return $out;
		}

		if ( isset( $data['@type'] ) ) {
			$out[] = $data;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				self::flatten_jsonld( $value, $out, $depth + 1 );
			}
		}

		return $out;
	}

	/**
	 * Apply a JSON-LD Product node to the data array.
	 *
	 * @param array $jsonld Flattened JSON-LD nodes.
	 * @param array $data   Data (by reference).
	 */
	private static function apply_jsonld( $jsonld, &$data ) {
		$names = array();

		foreach ( $jsonld as $node ) {
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();

			if ( ! in_array( 'Product', $type, true ) ) {
				continue;
			}

			if ( ! empty( $node['name'] ) ) {
				$name = wp_strip_all_tags( (string) $node['name'] );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}

			if ( '' === $data['description'] && ! empty( $node['description'] ) ) {
				$data['description'] = (string) $node['description'];
			}

			if ( '' === $data['sku'] && ! empty( $node['sku'] ) ) {
				$data['sku'] = (string) $node['sku'];
			} elseif ( '' === $data['sku'] && ! empty( $node['mpn'] ) ) {
				$data['sku'] = (string) $node['mpn'];
			}

			if ( ! empty( $node['image'] ) && empty( $data['images'] ) ) {
				$data['images'] = self::normalize_images( $node['image'] );
			}

			if ( ! empty( $node['brand'] ) && is_array( $node['brand'] ) && ! empty( $node['brand']['name'] ) ) {
				$data['categories'][] = (string) $node['brand']['name'];
			}

			// Offers: single object = simple; array = variable.
			if ( ! empty( $node['offers'] ) ) {
				$offers = isset( $node['offers'][0] ) ? $node['offers'] : array( $node['offers'] );

				if ( count( $offers ) > 1 && empty( $data['variants'] ) ) {
					$data['type'] = 'variable';
					foreach ( $offers as $offer ) {
						$data['variants'][] = array(
							'attributes' => array(),
							'price'      => isset( $offer['price'] ) ? $offer['price'] : '',
							'sale_price' => '',
							'sku'        => isset( $offer['sku'] ) ? $offer['sku'] : '',
							'image'      => '',
						);
					}
					// Currency is shared across offers; the first one is authoritative.
					if ( '' === $data['currency'] && isset( $offers[0]['priceCurrency'] ) ) {
						$data['currency'] = (string) $offers[0]['priceCurrency'];
					}
				} elseif ( '' === $data['price'] && isset( $offers[0]['price'] ) ) {
					$data['price']    = (string) $offers[0]['price'];
					$data['currency'] = isset( $offers[0]['priceCurrency'] ) ? (string) $offers[0]['priceCurrency'] : $data['currency'];
				}
			}
		}

		// Prefer the shortest product name: parent names are shorter than
		// variant-specific names (e.g. "Men's Wool Runner" vs "... - Size 8").
		if ( '' === $data['title'] && ! empty( $names ) ) {
			usort(
				$names,
				function ( $a, $b ) {
					return strlen( $a ) - strlen( $b );
				}
			);
			$data['title'] = reset( $names );
		}
	}

	/**
	 * Apply OpenGraph / meta tags.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param array       $data Data (by reference).
	 */
	private static function apply_meta( $doc, &$data ) {
		$xpath = new DOMXPath( $doc );

		$get = function ( $property ) use ( $xpath ) {
			$node = $xpath->query( '//meta[@property="' . $property . '"]' )->item( 0 );
			if ( ! $node ) {
				$node = $xpath->query( '//meta[@name="' . $property . '"]' )->item( 0 );
			}
			return $node ? trim( $node->getAttribute( 'content' ) ) : '';
		};

		if ( '' === $data['title'] ) {
			$data['title'] = $get( 'og:title' );
		}

		if ( '' === $data['description'] ) {
			$data['description'] = $get( 'og:description' );
		}

		if ( empty( $data['images'] ) ) {
			$img = $get( 'og:image' );
			if ( $img ) {
				$data['images'][] = $img;
			}
		}

		if ( '' === $data['price'] ) {
			$data['price'] = $get( 'product:price:amount' );
		}

		if ( '' === $data['currency'] ) {
			$data['currency'] = $get( 'product:price:currency' );
		}
	}

	/**
	 * Apply platform-specific CSS selectors as a final fallback.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $html     Raw HTML (for regex-based extraction).
	 * @param string      $platform Platform slug.
	 * @param array       $data     Data (by reference).
	 */
	private static function apply_selectors( $doc, $html, $platform, &$data ) {
		$xpath = new DOMXPath( $doc );

		$first_text = function ( $query ) use ( $xpath ) {
			$nodes = $xpath->query( $query );
			return $nodes->length ? trim( $nodes->item( 0 )->textContent ) : '';
		};

		if ( 'shopify' === $platform ) {
			if ( '' === $data['title'] ) {
				$data['title'] = $first_text( '//h1[contains(concat(" ", normalize-space(@class), " "), "product")]' );
			}
			if ( '' === $data['title'] ) {
				$data['title'] = $first_text( '//h1' );
			}
			if ( '' === $data['description'] ) {
				$data['description'] = $first_text( '//*[contains(concat(" ", normalize-space(@class), " "), "product-single__description")]' );
			}
			if ( '' === $data['price'] ) {
				$data['price'] = $first_text( '//*[contains(concat(" ", normalize-space(@class), " "), "product__price")]' );
			}
			if ( empty( $data['images'] ) ) {
				$data['images'] = self::extract_images( $xpath, '//*[contains(concat(" ", normalize-space(@class), " "), "product__media")]//img' );
			}
			if ( empty( $data['images'] ) ) {
				$data['images'] = self::extract_images( $xpath, '//img[contains(concat(" ", normalize-space(@class), " "), "product")]' );
			}
		} else {
			if ( '' === $data['title'] ) {
				$data['title'] = $first_text( '//h1[contains(concat(" ", normalize-space(@class), " "), "product_title")]' );
			}
			if ( '' === $data['title'] ) {
				$data['title'] = $first_text( '//h1' );
			}
			if ( '' === $data['description'] ) {
				$data['description'] = $first_text( '//*[@id="tab-description"]' );
			}
			if ( '' === $data['description'] ) {
				$data['description'] = $first_text( '//*[contains(concat(" ", normalize-space(@class), " "), "woocommerce-product-details__short-description")]' );
			}
			if ( '' === $data['price'] ) {
				$data['price'] = $first_text( '//*[contains(concat(" ", normalize-space(@class), " "), "price")]//*[contains(concat(" ", normalize-space(@class), " "), "woocommerce-Price-amount")]' );
			}
			if ( empty( $data['images'] ) ) {
				$data['images'] = self::extract_images( $xpath, '//*[contains(concat(" ", normalize-space(@class), " "), "woocommerce-product-gallery")]//img' );
			}
		}

		// Breadcrumb categories (both platforms). Only target real breadcrumb
		// <nav> elements — never a generic wrapper whose class merely contains
		// "breadcrumb(s)" as a substring (which would also match product-nav
		// "back/prev/next" links and unrelated product titles).
		$breadcrumb_links = $xpath->query(
			'//nav[contains(concat(" ", normalize-space(@class), " "), "breadcrumb") or @aria-label="breadcrumb" or @aria-label="Breadcrumb"]//a'
		);

		$seen_categories = array();
		foreach ( $breadcrumb_links as $link ) {
			$text = trim( $link->textContent );
			if ( '' === $text ) {
				continue;
			}
			// Skip the current product title and generic home/shop labels.
			if ( in_array( $text, array( 'Home', 'Shop', 'Back to products' ), true ) ) {
				continue;
			}
			if ( isset( $seen_categories[ $text ] ) ) {
				continue;
			}
			$seen_categories[ $text ] = true;
			$data['categories'][]      = $text;
		}
	}

	/**
	 * Determine simple vs variable and extract variant payload.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $html     Raw HTML.
	 * @param string      $platform Platform slug.
	 * @param array       $data     Data (by reference).
	 */
	private static function apply_type_and_variants( $doc, $html, $platform, &$data ) {
		if ( 'shopify' === $platform ) {
			self::apply_shopify_variants( $doc, $html, $data );
		} else {
			self::apply_woo_variants( $doc, $data );
		}
	}

	/**
	 * Extract Rey variation swatches (color/image/button) from the page.
	 *
	 * Reads the Rey theme's `rey-swatchList` markup so color hex values (and
	 * image URLs) can be imported onto global attribute terms, reproducing the
	 * source store's swatches on the imported product.
	 *
	 * @param DOMDocument $doc      Document.
	 * @param string      $platform Platform slug.
	 * @param array       $data     Data (by reference).
	 */
	private static function apply_swatches( $doc, $platform, &$data ) {
		if ( 'woocommerce' !== $platform ) {
			return;
		}

		$data['swatches'] = self::merge_swatches(
			self::extract_rey_swatches( $doc ),
			self::extract_wvs_swatches( $doc )
		);
	}

	/**
	 * Merge two swatch maps (Rey + WooCommerce Variation Swatches).
	 *
	 * The second map is merged into the first; when a taxonomy appears in both,
	 * terms are unioned with the second map's term data taking precedence
	 * (e.g. WVS dual-tone gradients carry richer color info).
	 *
	 * @param array $primary   Primary swatch map.
	 * @param array $secondary Secondary swatch map.
	 * @return array
	 */
	private static function merge_swatches( $primary, $secondary ) {
		if ( empty( $secondary ) ) {
			return $primary;
		}
		if ( empty( $primary ) ) {
			return $secondary;
		}

		foreach ( $secondary as $taxonomy => $swatch ) {
			if ( ! isset( $primary[ $taxonomy ] ) ) {
				$primary[ $taxonomy ] = $swatch;
				continue;
			}
			$primary[ $taxonomy ]['terms'] = array_merge(
				$primary[ $taxonomy ]['terms'],
				$swatch['terms']
			);
		}

		return $primary;
	}

	/**
	 * Walk `rey-swatchList` blocks and return a swatch map:
	 *   taxonomy => { name, type, terms => { slug => { name, slug, color, secondary_color, image } } }
	 *
	 * @param DOMDocument $doc Document.
	 * @return array
	 */
	private static function extract_rey_swatches( $doc ) {
		$xpath = new DOMXPath( $doc );
		$lists = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " rey-swatchList ")]' );

		$swatches = array();

		foreach ( $lists as $list ) {
			// The list div carries `data-attribute_name="attribute_pa_color"`,
			// while each item carries `data-attribute-name="pa_color"`.
			$taxonomy = trim( $list->getAttribute( 'data-attribute-name' ) );
			if ( '' === $taxonomy ) {
				$taxonomy = trim( $list->getAttribute( 'data-attribute_name' ) );
				$taxonomy = preg_replace( '/^attribute_/', '', $taxonomy );
			}
			if ( '' === $taxonomy || 0 !== strpos( $taxonomy, 'pa_' ) ) {
				continue; // Only global attribute swatches carry term meta.
			}

			$classes = ' ' . $list->getAttribute( 'class' ) . ' ';
			$type    = 'rey_button'; // Rey's default swatch type.
			if ( false !== strpos( $classes, '--type-color' ) ) {
				$type = 'rey_color';
			} elseif ( false !== strpos( $classes, '--type-image' ) ) {
				$type = 'rey_image';
			} elseif ( false !== strpos( $classes, '--type-large-button' ) ) {
				$type = 'rey_large_button';
			} elseif ( false !== strpos( $classes, '--type-radio' ) ) {
				$type = 'rey_radio';
			}

			$terms = array();
			$items = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " rey-swatchList-item ")]', $list );
			foreach ( $items as $item ) {
				$slug  = trim( $item->getAttribute( 'data-term-slug' ) );
				$title = trim( $item->getAttribute( 'data-title' ) );
				if ( '' === $slug ) {
					$slug = sanitize_title( $title );
				}
				if ( '' === $slug && '' === $title ) {
					continue;
				}
				if ( '' === $title ) {
					$title = $slug;
				}

				$color           = '';
				$secondary_color = '';
				$image           = '';

				// The visual lives in one of two spans depending on the type:
				//   - color/image swatches: `.rey-swatchList-itemContent`
				//   - button/large-button swatches: a `__swatch` dot (optional)
				$content = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " rey-swatchList-itemContent ")]', $item )->item( 0 );

				$styles = array();
				if ( $content ) {
					$styles[] = $content->getAttribute( 'style' );
				}
				$swatch_dot = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " __swatch ")]', $item )->item( 0 );
				if ( $swatch_dot ) {
					$styles[] = $swatch_dot->getAttribute( 'style' );
				}

				foreach ( $styles as $style ) {
					if ( '' === $style ) {
						continue;
					}
					$parsed = self::parse_swatch_style( $style );
					if ( '' === $color && '' !== $parsed['color'] ) {
						$color = $parsed['color'];
					}
					if ( '' === $secondary_color && '' !== $parsed['secondary_color'] ) {
						$secondary_color = $parsed['secondary_color'];
					}
					if ( '' === $image && '' !== $parsed['image'] ) {
						$image = $parsed['image'];
					}
				}

				// Image-tag mode (Rey `image_uses_tag`): the itemContent holds an
				// <img> child instead of a background-image style.
				if ( '' === $image && $content ) {
					$img = $xpath->query( './/img', $content )->item( 0 );
					if ( $img ) {
						$src = trim( $img->getAttribute( 'src' ) );
						if ( '' !== $src ) {
							$image = $src;
						}
					}
				}

				$terms[ $slug ] = array(
					'name'            => $title,
					'slug'            => $slug,
					'color'           => $color,
					'secondary_color' => $secondary_color,
					'image'           => $image,
				);
			}

			if ( ! empty( $terms ) ) {
				$swatches[ $taxonomy ] = array(
					'name'  => self::humanize_attribute_name( $taxonomy ),
					'type'  => $type,
					'terms' => $terms,
				);
			}
		}

		return $swatches;
	}

	/**
	 * Extract WooCommerce Variation Swatches (Emran Ahmed) markup into the same
	 * swatch map shape as Rey, so either plugin's source store imports cleanly.
	 *
	 * WVS renders:
	 *   <div class="variable-items-wrapper" data-attribute_name="attribute_pa_color">
	 *     <div class="variable-item color-variable-item color-variable-item-{slug}"
	 *          data-value="{slug}" data-title="{name}">
	 *       <span class="variable-item-span variable-item-span-color" style="background-color:#hex;"></span>
	 *     </div>
	 *   </div>
	 * Types map to Rey: color→rey_color, image→rey_image, button→rey_button, radio→rey_radio.
	 *
	 * @param DOMDocument $doc Document.
	 * @return array
	 */
	private static function extract_wvs_swatches( $doc ) {
		$xpath = new DOMXPath( $doc );
		$lists = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " variable-items-wrapper ")]' );

		$swatches = array();

		foreach ( $lists as $list ) {
			$taxonomy = trim( $list->getAttribute( 'data-attribute_name' ) );
			if ( '' !== $taxonomy ) {
				$taxonomy = preg_replace( '/^attribute_/', '', $taxonomy );
			}
			if ( '' === $taxonomy ) {
				$taxonomy = trim( $list->getAttribute( 'data-attribute-name' ) );
			}
			if ( '' === $taxonomy || 0 !== strpos( $taxonomy, 'pa_' ) ) {
				continue; // Only global attribute swatches carry term meta.
			}

			$terms = array();
			$items = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " variable-item ")]', $list );
			foreach ( $items as $item ) {
				$classes = ' ' . $item->getAttribute( 'class' ) . ' ';

				// Determine the WVS type from the item class.
				$type = 'rey_button';
				if ( false !== strpos( $classes, 'color-variable-item' ) ) {
					$type = 'rey_color';
				} elseif ( false !== strpos( $classes, 'image-variable-item' ) ) {
					$type = 'rey_image';
				} elseif ( false !== strpos( $classes, 'radio-variable-item' ) ) {
					$type = 'rey_radio';
				}

				$slug  = trim( $item->getAttribute( 'data-value' ) );
				$title = trim( $item->getAttribute( 'data-title' ) );
				if ( '' === $title ) {
					$title = trim( $item->getAttribute( 'title' ) );
				}
				if ( '' === $slug ) {
					$slug = sanitize_title( $title );
				}
				if ( '' === $slug && '' === $title ) {
					continue;
				}
				if ( '' === $title ) {
					$title = $slug;
				}

				$color           = '';
				$secondary_color = '';
				$image           = '';

				// Color: `.variable-item-span-color` (or the item itself) carries
				// a background-color / linear-gradient inline style.
				$styles = array();
				$color_span = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " variable-item-span-color ")]', $item )->item( 0 );
				if ( $color_span ) {
					$styles[] = $color_span->getAttribute( 'style' );
				}
				$styles[] = $item->getAttribute( 'style' );

				foreach ( $styles as $style ) {
					if ( '' === $style ) {
						continue;
					}
					$parsed = self::parse_swatch_style( $style );
					if ( '' === $color && '' !== $parsed['color'] ) {
						$color = $parsed['color'];
					}
					if ( '' === $secondary_color && '' !== $parsed['secondary_color'] ) {
						$secondary_color = $parsed['secondary_color'];
					}
					if ( '' === $image && '' !== $parsed['image'] ) {
						$image = $parsed['image'];
					}
				}

				// Image: `.variable-item-image` <img> child.
				if ( '' === $image ) {
					$img = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " variable-item-image ")]', $item )->item( 0 );
					if ( $img && 'img' === strtolower( $img->nodeName ) ) {
						$src = trim( $img->getAttribute( 'src' ) );
						if ( '' !== $src ) {
							$image = $src;
						}
					}
				}

				$terms[ $slug ] = array(
					'name'            => $title,
					'slug'            => $slug,
					'color'           => $color,
					'secondary_color' => $secondary_color,
					'image'           => $image,
				);
			}

			if ( ! empty( $terms ) ) {
				$swatches[ $taxonomy ] = array(
					'name'  => self::humanize_attribute_name( $taxonomy ),
					'type'  => $type,
					'terms' => $terms,
				);
			}
		}

		return $swatches;
	}

	/**
	 * Parse a swatch inline style into its color / secondary-color / image parts.
	 *
	 * Handles the three forms Rey emits:
	 *   - dual-tone gradient: "linear-gradient(90deg, #A 50%, #B 50%)"
	 *   - image:              "background-image:url(...)"
	 *   - single color:       "background: #B78082;"
	 *
	 * @param string $style Inline style attribute value.
	 * @return array { color, secondary_color, image }
	 */
	private static function parse_swatch_style( $style ) {
		$result = array(
			'color'           => '',
			'secondary_color' => '',
			'image'           => '',
		);

		// Dual-tone gradient first (it contains two hex values).
		if ( preg_match( '/linear-gradient\([^)]*?(#[0-9a-fA-F]{3,8})[^)]*?,\s*[^)]*?(#[0-9a-fA-F]{3,8})/', $style, $m ) ) {
			$result['color']           = $m[1];
			$result['secondary_color'] = $m[2];
			return $result;
		}

		// Image.
		if ( preg_match( '/url\(["\']?([^"\')]+)["\']?\)/', $style, $m ) ) {
			$result['image'] = $m[1];
			return $result;
		}

		// Single color.
		if ( preg_match( '/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,8})/', $style, $m ) ) {
			$result['color'] = $m[1];
		}

		return $result;
	}

	/**
	 * Humanize a taxonomy name into a display label ("pa_color" → "Color").
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private static function humanize_attribute_name( $taxonomy ) {
		$name = (string) $taxonomy;
		if ( 0 === strpos( $name, 'pa_' ) ) {
			$name = substr( $name, 3 );
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $name ) );
	}

	/**
	 * Shopify: detect variable products and extract variants.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param string      $html Raw HTML.
	 * @param array       $data Data (by reference).
	 */
	private static function apply_shopify_variants( $doc, $html, &$data ) {
		$xpath = new DOMXPath( $doc );

		$product_json = self::extract_shopify_product_json( $doc, $html );

		if ( $product_json ) {
			$variants = isset( $product_json['variants'] ) ? (array) $product_json['variants'] : array();
			$options  = isset( $product_json['options'] ) ? (array) $product_json['options'] : array();

			if ( count( $variants ) > 1 ) {
				$data['type'] = 'variable';
				$normalized   = array();

				foreach ( $variants as $v ) {
					$attrs = array();
					$title = isset( $v['title'] ) ? (string) $v['title'] : '';
					$parts = explode( ' / ', $title );

					foreach ( $parts as $i => $part ) {
						$name          = isset( $options[ $i ] ) ? (string) $options[ $i ] : 'Option ' . ( $i + 1 );
						$attrs[ $name ] = trim( $part );
					}

					$price   = isset( $v['price'] ) ? (float) $v['price'] / 100 : 0;
					$compare = isset( $v['compare_at_price'] ) && $v['compare_at_price'] ? (float) $v['compare_at_price'] / 100 : 0;

					$regular = ( $compare > 0 && $compare > $price ) ? $compare : $price;
					$sale    = ( $compare > 0 && $compare > $price ) ? $price : '';

					$normalized[] = array(
						'attributes' => $attrs,
						'price'      => $regular,
						'sale_price' => $sale,
						'sku'        => isset( $v['sku'] ) ? (string) $v['sku'] : '',
						'image'      => ! empty( $v['featured_image']['src'] ) ? (string) $v['featured_image']['src'] : '',
						'stock'      => isset( $v['inventory_quantity'] ) ? (int) $v['inventory_quantity'] : null,
					);
				}

				$data['variants'] = $normalized;

				// First variant fills the fallback price/currency fields.
				if ( '' === $data['price'] ) {
					$data['price'] = $normalized[0]['price'];
				}
				if ( '' === $data['sku'] && ! empty( $normalized[0]['sku'] ) ) {
					$data['sku'] = $normalized[0]['sku'];
				}
			} elseif ( count( $variants ) === 1 ) {
				$v = $variants[0];
				if ( '' === $data['price'] ) {
					$data['price'] = isset( $v['price'] ) ? (float) $v['price'] / 100 : '';
				}
				if ( '' === $data['sku'] && ! empty( $v['sku'] ) ) {
					$data['sku'] = (string) $v['sku'];
				}
			}
		}

		// Fallback: multiple options in the variant <select>.
		if ( 'simple' === $data['type'] ) {
			$options_nodes = $xpath->query( '//select[@name="id"]/option' );
			if ( $options_nodes->length > 1 ) {
				$data['type']     = 'variable';
				$data['variants'] = array();

				foreach ( $options_nodes as $option ) {
					$label = trim( $option->textContent );
					if ( '' === $label ) {
						continue;
					}
					$data['variants'][] = array(
						'attributes' => array( 'Option' => $label ),
						'price'      => '',
						'sale_price' => '',
						'sku'        => '',
						'image'      => '',
					);
				}
			}
		}

		// Fallback: custom themes that expose variants via data-variant-* attributes
		// (e.g. <li data-variant-price="11000" data-variant-sku="..." data-variant-title="8">).
		if ( 'simple' === $data['type'] || empty( $data['variants'] ) ) {
			self::extract_data_variant_variants( $html, $data );
		}
	}

	/**
	 * Extract variants from data-variant-* attributes (custom Shopify themes).
	 *
	 * @param string $html Raw HTML.
	 * @param array  $data Data (by reference).
	 */
	private static function extract_data_variant_variants( $html, &$data ) {
		// Match each element tag that carries a data-variant-title attribute.
		if ( ! preg_match_all( '/<([a-z][a-z0-9]*)\b([^>]*\bdata-variant-title\b[^>]*)>/i', $html, $tags, PREG_SET_ORDER ) ) {
			return;
		}

		$variants = array();
		$titles   = array();

		foreach ( $tags as $tag ) {
			$attrs = $tag[2];

			$attr = function ( $name ) use ( $attrs ) {
				if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*[\'"]([^\'"]*)[\'"]/i', $attrs, $m ) ) {
					return trim( $m[1] );
				}
				return '';
			};

			$title = $attr( 'data-variant-title' );
			if ( '' === $title ) {
				continue;
			}

			$price_raw   = $attr( 'data-variant-price' );
			$compare_raw = $attr( 'data-variant-compare-at-price' );
			$sku         = $attr( 'data-variant-sku' );
			$available   = $attr( 'data-variant-available' );

			// Shopify exposes raw money in cents by convention.
			$price   = is_numeric( $price_raw ) && '' !== $price_raw ? (float) $price_raw / 100 : 0;
			$compare = is_numeric( $compare_raw ) && '' !== $compare_raw ? (float) $compare_raw / 100 : 0;

			$regular = ( $compare > 0 && $compare > $price ) ? $compare : $price;
			$sale    = ( $compare > 0 && $compare > $price ) ? $price : '';

			$titles[] = $title;

			$variants[] = array(
				'attributes' => array(), // filled after we determine the option name.
				'price'      => $regular,
				'sale_price' => $sale,
				'sku'        => $sku,
				'image'      => '',
				'stock'      => ( 'false' === strtolower( $available ) ) ? 0 : null,
			);
		}

		if ( count( $variants ) < 2 ) {
			// A single data-variant element still gives us a price/SKU for a simple product.
			if ( count( $variants ) === 1 ) {
				if ( '' === $data['price'] && $variants[0]['price'] > 0 ) {
					$data['price'] = $variants[0]['price'];
				}
				if ( '' === $data['sku'] && '' !== $variants[0]['sku'] ) {
					$data['sku'] = $variants[0]['sku'];
				}
			}
			return;
		}

		// Determine the option name: numeric titles imply "Size", otherwise "Color".
		$all_numeric = true;
		foreach ( $titles as $t ) {
			if ( ! is_numeric( $t ) ) {
				$all_numeric = false;
				break;
			}
		}
		$option_name = $all_numeric ? 'Size' : 'Color';

		foreach ( $variants as $i => $v ) {
			$variants[ $i ]['attributes'] = array( $option_name => $titles[ $i ] );
		}

		$data['type']     = 'variable';
		$data['variants'] = $variants;

		if ( '' === $data['price'] ) {
			$data['price'] = $variants[0]['price'];
		}
		if ( '' === $data['sku'] ) {
			$data['sku'] = $variants[0]['sku'];
		}
	}

	/**
	 * WooCommerce: detect variable products and extract variations.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param array       $data Data (by reference).
	 */
	private static function apply_woo_variants( $doc, &$data ) {
		$xpath = new DOMXPath( $doc );

		// Body class signals.
		$body = $xpath->query( '//body' )->item( 0 );
		if ( $body ) {
			$class = ' ' . $body->getAttribute( 'class' ) . ' ';
			if ( false !== strpos( $class, ' product-type-variable ' ) ) {
				$data['type'] = 'variable';
			}
		}

		// data-product_variations JSON (primary source of truth).
		$forms = $xpath->query( '//form[contains(concat(" ", normalize-space(@class), " "), " variations_form ")]' );
		if ( $forms->length ) {
			$form = $forms->item( 0 );
			if ( $form->hasAttribute( 'data-product_variations' ) ) {
				$raw     = $form->getAttribute( 'data-product_variations' );
				$decoded = json_decode( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ), true );

				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					$data['type']     = 'variable';
					$data['variants'] = self::normalize_woo_variations( $decoded );

					if ( '' === $data['price'] && ! empty( $data['variants'] ) ) {
						$data['price'] = $data['variants'][0]['price'];
					}
					if ( '' === $data['sku'] && ! empty( $data['variants'][0]['sku'] ) ) {
						$data['sku'] = $data['variants'][0]['sku'];
					}
				}
			}
		}
	}

	/**
	 * Normalize WooCommerce data-product_variations entries.
	 *
	 * @param array $variations Raw variations.
	 * @return array
	 */
	private static function normalize_woo_variations( $variations ) {
		$out = array();

		foreach ( $variations as $v ) {
			$attrs = array();
			foreach ( (array) $v['attributes'] as $key => $value ) {
				$label = (string) $key;
				if ( 0 === strpos( $label, 'attribute_pa_' ) ) {
					$label = substr( $label, strlen( 'attribute_pa_' ) );
				} elseif ( 0 === strpos( $label, 'attribute_' ) ) {
					$label = substr( $label, strlen( 'attribute_' ) );
				}
				$label          = ucwords( str_replace( array( '_', '-' ), ' ', $label ) );
				$attrs[ $label ] = (string) $value;
			}

			$price   = isset( $v['display_price'] ) ? (float) $v['display_price'] : 0;
			$regular = isset( $v['display_regular_price'] ) ? (float) $v['display_regular_price'] : $price;

			// Prefer the full-resolution image (full_src) over the sized src
			// so the imported variation carries the best-quality photo.
			$image = '';
			if ( ! empty( $v['image']['full_src'] ) ) {
				$image = (string) $v['image']['full_src'];
			} elseif ( ! empty( $v['image']['src'] ) ) {
				$image = (string) $v['image']['src'];
			}

			$out[] = array(
				'attributes' => $attrs,
				'price'      => $regular,
				'sale_price' => ( $price > 0 && $regular > $price ) ? $price : '',
				'sku'        => isset( $v['sku'] ) ? (string) $v['sku'] : '',
				'image'      => $image,
				'stock'      => ( isset( $v['is_in_stock'] ) && ! $v['is_in_stock'] ) ? 0 : null,
			);
		}

		return $out;
	}

	/**
	 * Extract a Shopify product JSON blob (variants + options) from the page.
	 *
	 * @param DOMDocument $doc  Document.
	 * @param string      $html Raw HTML.
	 * @return array|false
	 */
	private static function extract_shopify_product_json( $doc, $html ) {
		$xpath   = new DOMXPath( $doc );
		$scripts = $xpath->query( '//script' );

		foreach ( $scripts as $script ) {
			$id    = strtolower( $script->getAttribute( 'id' ) );
			$type  = strtolower( $script->getAttribute( 'type' ) );
			$text  = $script->nodeValue;

			if ( ( $id && ( false !== strpos( $id, 'productjson' ) || false !== strpos( $id, 'product-json' ) ) ) || ( $type && false !== strpos( $type, 'json' ) ) ) {
				$decoded = json_decode( $text, true );
				if ( is_array( $decoded ) && isset( $decoded['variants'] ) && is_array( $decoded['variants'] ) ) {
					return $decoded;
				}
			}
		}

		// ShopifyAnalytics.meta.product = { ... };
		if ( preg_match( '/ShopifyAnalytics\.meta\.product\s*=\s*(\{.*?\});/s', $html, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) && isset( $decoded['variants'] ) && is_array( $decoded['variants'] ) ) {
				return $decoded;
			}
		}

		return false;
	}

	/**
	 * Fetch Shopify's authoritative single-product `.json` endpoint and use its
	 * options + variants to (re)populate the normalized variant list.
	 *
	 * The HTML page can be rate-limited (429) and many themes omit the variant
	 * option names from the markup, while `products/<handle>.json` always
	 * returns the full options (names + values) and variants (option1/2/3, sku,
	 * decimal price, image, inventory). This fills in variant attribute names
	 * and the parent price/currency that HTML-only extraction misses.
	 *
	 * @param string $url  Product URL.
	 * @param array  $data Data (by reference).
	 */
	private static function apply_shopify_json_endpoint( $url, &$data ) {
		// Build the .json URL from scheme+host+path, dropping any query string
		// (e.g. "?variant=123" would otherwise break the .json suffix).
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return;
		}
		$base     = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['path'] ) ? $parts['path'] : '' );
		$json_url = rtrim( $base, '/' ) . '.json';

		$body = SWI_HTTP::get_with_retry( $json_url );
		if ( is_wp_error( $body ) ) {
			return;
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return;
		}

		$product = isset( $decoded['product'] ) && is_array( $decoded['product'] ) ? $decoded['product'] : $decoded;
		$options = isset( $product['options'] ) && is_array( $product['options'] ) ? $product['options'] : array();
		$raw     = isset( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : array();

		if ( empty( $raw ) ) {
			return;
		}

		// Option names, in position order.
		$option_names = array();
		foreach ( $options as $o ) {
			if ( ! empty( $o['name'] ) ) {
				$option_names[] = (string) $o['name'];
			}
		}

		$normalized = array();
		foreach ( $raw as $v ) {
			// Prefer option1/2/3 (authoritative); fall back to splitting the
			// title on " / " (older themes).
			$option_values = array();
			for ( $i = 1; $i <= 3; $i++ ) {
				$key = 'option' . $i;
				if ( isset( $v[ $key ] ) && null !== $v[ $key ] && '' !== (string) $v[ $key ] ) {
					$option_values[] = (string) $v[ $key ];
				}
			}
			if ( empty( $option_values ) && isset( $v['title'] ) ) {
				$option_values = explode( ' / ', (string) $v['title'] );
			}

			$attrs = array();
			foreach ( $option_values as $i => $part ) {
				$name           = isset( $option_names[ $i ] ) ? $option_names[ $i ] : 'Option ' . ( $i + 1 );
				$attrs[ $name ] = trim( (string) $part );
			}

			// The .json endpoint returns decimal prices ("39.00"), not cents.
			$price   = isset( $v['price'] ) && is_numeric( $v['price'] ) ? (float) $v['price'] : 0;
			$compare = isset( $v['compare_at_price'] ) && is_numeric( $v['compare_at_price'] ) ? (float) $v['compare_at_price'] : 0;
			$regular = ( $compare > 0 && $compare > $price ) ? $compare : $price;
			$sale    = ( $compare > 0 && $compare > $price ) ? $price : '';

			$normalized[] = array(
				'attributes' => $attrs,
				'price'      => $regular,
				'sale_price' => $sale,
				'sku'        => isset( $v['sku'] ) ? (string) $v['sku'] : '',
				'image'      => ! empty( $v['featured_image']['src'] ) ? (string) $v['featured_image']['src'] : '',
				'stock'      => isset( $v['inventory_quantity'] ) && null !== $v['inventory_quantity'] ? (int) $v['inventory_quantity'] : null,
			);
		}

		if ( empty( $normalized ) ) {
			return;
		}

		$data['variants'] = $normalized;
		if ( count( $normalized ) > 1 ) {
			$data['type'] = 'variable';
		}

		// Fill parent fallbacks.
		if ( '' === $data['price'] && ! empty( $normalized[0]['price'] ) ) {
			$data['price'] = (string) $normalized[0]['price'];
		}
		if ( '' === $data['sku'] && ! empty( $normalized[0]['sku'] ) ) {
			$data['sku'] = $normalized[0]['sku'];
		}

		// The .json endpoint carries the complete, ordered product image
		// gallery — prefer it over the single og:image / JSON-LD image.
		if ( ! empty( $product['images'] ) && is_array( $product['images'] ) ) {
			$imgs = array();
			foreach ( $product['images'] as $img ) {
				if ( ! empty( $img['src'] ) ) {
					$imgs[] = (string) $img['src'];
				}
			}
			if ( ! empty( $imgs ) ) {
				$data['images'] = $imgs;
			}
		}

		// Variant images: the public .json endpoint may expose them under
		// image_id + images (when a variant has its own gallery). Prefer the
		// explicit featured_image.src when present.
		foreach ( $normalized as $i => $v ) {
			if ( '' !== $v['image'] ) {
				continue;
			}
			$raw_var = isset( $raw[ $i ] ) && is_array( $raw[ $i ] ) ? $raw[ $i ] : array();
			if ( ! empty( $raw_var['featured_image']['src'] ) ) {
				$normalized[ $i ]['image'] = (string) $raw_var['featured_image']['src'];
			} elseif ( ! empty( $raw_var['image_id'] ) && ! empty( $product['images'] ) && is_array( $product['images'] ) ) {
				foreach ( $product['images'] as $img ) {
					if ( isset( $img['id'] ) && (int) $img['id'] === (int) $raw_var['image_id'] ) {
						$normalized[ $i ]['image'] = (string) $img['src'];
						break;
					}
				}
			}
		}
		$data['variants'] = $normalized;
	}

	/**
	 * Normalize a JSON-LD image value (string | object | array) to URLs.
	 *
	 * @param mixed $image Image value.
	 * @return array
	 */
	private static function normalize_images( $image ) {
		$out = array();

		if ( is_string( $image ) ) {
			$out[] = $image;
		} elseif ( is_array( $image ) ) {
			foreach ( $image as $entry ) {
				if ( is_string( $entry ) ) {
					$out[] = $entry;
				} elseif ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
					$out[] = (string) $entry['url'];
				}
			}
		}

		return array_values( array_filter( $out ) );
	}

	/**
	 * Resolve a (possibly relative) image URL against a base URL.
	 *
	 * @param string $src  Image src value.
	 * @param string $base Product URL.
	 * @return string
	 */
	private static function absolutize_url( $src, $base ) {
		$src = trim( (string) $src );
		if ( '' === $src || '#' === $src[0] || 0 === strpos( $src, 'data:' ) ) {
			return '';
		}

		// Protocol-relative.
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
	 * @param array  $images Image URLs.
	 * @param string $base   Product URL.
	 * @return array
	 */
	private static function absolutize_images( $images, $base ) {
		$out = array();
		foreach ( (array) $images as $image ) {
			$abs = self::absolutize_url( $image, $base );
			if ( '' !== $abs ) {
				$out[] = $abs;
			}
		}
		return $out;
	}

	/**
	 * Extract image src values from a set of <img> nodes.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param string   $query XPath query.
	 * @return array
	 */
	private static function extract_images( $xpath, $query ) {
		$out = array();

		foreach ( $xpath->query( $query ) as $img ) {
			$src = $img->getAttribute( 'data-src' );
			if ( ! $src ) {
				$src = $img->getAttribute( 'src' );
			}
			if ( $src ) {
				$out[] = $src;
			}
		}

		return array_values( array_filter( $out ) );
	}
}
