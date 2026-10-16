<?php
/**
 * Import engine: turns scraped product data into local WooCommerce products
 * (simple or variable), sideloads images, and dedupes on re-import.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Importer {

	/**
	 * Import a single scraped product (dispatch by type).
	 *
	 * @param array  $data       Scraped product data.
	 * @param string $source_url Source product URL.
	 * @param array  $options    Image options (white_bg, padding).
	 * @return int Product ID.
	 */
	public static function import( $data, $source_url, $options = array() ) {
		if ( 'variable' === $data['type'] && ! empty( $data['variants'] ) ) {
			return self::import_variable( $data, $source_url, $options );
		}

		return self::import_simple( $data, $source_url, $options );
	}

	/**
	 * Find an existing product by source URL.
	 *
	 * @param string $source_url Source URL.
	 * @return int
	 */
	public static function find_existing( $source_url ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => '_swi_source_url',
				'meta_value'     => $source_url,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $query->posts ? (int) $query->posts[0] : 0;
	}

	/**
	 * Resolve the product object to import into, replacing a type-mismatched
	 * product so a re-import that changes simple↔variable doesn't orphan the
	 * old product (which would keep holding its SKU and cause duplicate
	 * products + dropped variant SKUs).
	 *
	 * @param int    $product_id Existing product ID (0 if none).
	 * @param string $target_class Expected WC_Product class ('WC_Product_Simple'|'WC_Product_Variable').
	 * @return WC_Product
	 */
	private static function prepare_product( $product_id, $target_class ) {
		$product = $product_id ? wc_get_product( $product_id ) : false;

		if ( $product && $product instanceof $target_class ) {
			return $product; // Correct type — reuse (idempotent re-import).
		}

		if ( $product ) {
			// Wrong type (e.g. first imported as simple, now scraped as
			// variable). Force-delete the old product so its SKU and
			// _swi_source_url are released and no duplicate is left behind.
			$product->delete( true );
		}

		$class = 'WC_Product_Variable' === $target_class ? 'WC_Product_Variable' : 'WC_Product_Simple';
		return new $class();
	}

	/**
	 * Build or update a simple product.
	 *
	 * @param array  $data       Scraped product data.
	 * @param string $source_url Source URL.
	 * @return int
	 */
	private static function import_simple( $data, $source_url, $options = array() ) {
		$product = self::prepare_product( self::find_existing( $source_url ), 'WC_Product_Simple' );

		$product->set_name( self::clean_title( $data['title'] ) );
		$product->set_description( wp_kses_post( $data['description'] ) );
		if ( ! empty( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $data['short_description'] ) );
		}
		$product->set_status( self::import_status() );
		$product->set_catalog_visibility( 'visible' );

		if ( '' !== $data['sku'] ) {
			// Guard against duplicate SKUs (skip if owned by another product).
			$owner = wc_get_product_id_by_sku( (string) $data['sku'] );
			if ( ! $owner || (int) $owner === (int) $product->get_id() ) {
				$product->set_sku( (string) $data['sku'] );
			}
		}

		self::apply_prices( $product, $data );

		$category_ids = self::ensure_categories( $data['categories'] );
		if ( $category_ids ) {
			$product->set_category_ids( $category_ids );
		}

		$product->update_meta_data( '_swi_source_url', $source_url );
		if ( '' !== $data['currency'] ) {
			$product->update_meta_data( '_swi_currency', $data['currency'] );
		}

		$product_id = $product->save();

		self::attach_images( $product_id, $data['images'], $options );

		return $product_id;
	}

	/**
	 * Build or update a variable product and its variations.
	 *
	 * @param array  $data       Scraped product data.
	 * @param string $source_url Source URL.
	 * @return int
	 */
	private static function import_variable( $data, $source_url, $options = array() ) {
		$product = self::prepare_product( self::find_existing( $source_url ), 'WC_Product_Variable' );

		$product->set_name( self::clean_title( $data['title'] ) );
		$product->set_description( wp_kses_post( $data['description'] ) );
		if ( ! empty( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $data['short_description'] ) );
		}
		$product->set_status( self::import_status() );
		$product->set_catalog_visibility( 'visible' );

		// NOTE: a variable parent does not carry a variant SKU; each variation
		// owns its own SKU (set in upsert_variation). Setting the parent SKU to
		// the first variant's SKU would collide with that variation.

		$category_ids = self::ensure_categories( $data['categories'] );
		if ( $category_ids ) {
			$product->set_category_ids( $category_ids );
		}

		// Build attributes. When the source page exposes Rey swatches, create
		// GLOBAL (pa_*) attributes with the matching swatch type + per-term
		// swatch meta so the imported store renders color/image swatches like
		// the source. Otherwise fall back to custom attributes.
		$wc_attributes = self::build_product_attributes( $data );

		$product->set_attributes( $wc_attributes );

		$product->update_meta_data( '_swi_source_url', $source_url );
		if ( '' !== $data['currency'] ) {
			$product->update_meta_data( '_swi_currency', $data['currency'] );
		}

		$product_id = $product->save();

		// Create/update each variation.
		$swatches = isset( $data['swatches'] ) && is_array( $data['swatches'] ) ? $data['swatches'] : array();
		foreach ( $data['variants'] as $variant ) {
			self::upsert_variation( $product_id, $variant, $swatches, $options );
		}

		self::attach_images( $product_id, $data['images'], $options );

		return $product_id;
	}

	/**
	 * Set regular/sale price on a product (simple or variable parent).
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $data    Scraped data.
	 */
	private static function apply_prices( $product, $data ) {
		$price = self::to_float( $data['price'] );
		if ( $price > 0 ) {
			$product->set_regular_price( wc_format_decimal( $price ) );
		}

		$sale = self::to_float( $data['sale_price'] );
		if ( $sale > 0 && $sale < $price ) {
			$product->set_sale_price( wc_format_decimal( $sale ) );
		} else {
			$product->set_sale_price( '' );
		}
	}

	/**
	 * Create or update a single variation.
	 *
	 * @param int   $parent_id Parent product ID.
	 * @param array $variant   Normalized variant data.
	 * @param array $swatches  Swatch map (taxonomy => { name, ... }).
	 * @return int Variation ID.
	 */
	private static function upsert_variation( $parent_id, $variant, $swatches = array(), $options = array() ) {
		$variation = null;
		$sku       = ! empty( $variant['sku'] ) ? (string) $variant['sku'] : '';

		// Match an existing variation by SKU (for re-import update).
		if ( '' !== $sku ) {
			$existing_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_id ) {
				$existing = wc_get_product( $existing_id );
				if ( $existing && 'variation' === $existing->get_type() && (int) $existing->get_parent_id() === (int) $parent_id ) {
					$variation = $existing;
				}
			}
		}

		if ( ! $variation ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
		}

		// Map swatch human labels back to their global taxonomy, so variation
		// attributes are keyed by taxonomy (pa_color) with the term slug.
		// Also build a term-name → term-slug map: global taxonomy variations
		// must reference the term SLUG, not the human label (a label like
		// "020N" must become "020n").
		$label_to_tax  = array();
		$value_to_slug = array();
		foreach ( (array) $swatches as $taxonomy => $swatch ) {
			if ( ! empty( $swatch['name'] ) ) {
				$label_to_tax[ (string) $swatch['name'] ] = (string) $taxonomy;
			}
			foreach ( (array) $swatch['terms'] as $slug => $term ) {
				$slug = (string) $slug;
				$value_to_slug[ $slug ] = $slug;
				if ( ! empty( $term['name'] ) ) {
					$value_to_slug[ (string) $term['name'] ] = $slug;
				}
			}
		}

		$attributes = array();
		foreach ( (array) $variant['attributes'] as $name => $value ) {
			$name  = (string) $name;
			$value = (string) $value;
			if ( isset( $label_to_tax[ $name ] ) ) {
				$tax = $label_to_tax[ $name ];
				// Use the term slug when the value is a known term name/slug.
				$attributes[ $tax ] = isset( $value_to_slug[ $value ] ) ? $value_to_slug[ $value ] : $value;
			} else {
				$attributes[ sanitize_title( $name ) ] = $value; // Custom attribute.
			}
		}
		$variation->set_attributes( $attributes );

		if ( '' !== $sku ) {
			// Only apply the SKU if it is free or already owned by this variation,
			// avoiding WooCommerce "duplicated SKU" fatal errors.
			$owner = wc_get_product_id_by_sku( $sku );
			if ( ! $owner || (int) $owner === (int) $variation->get_id() ) {
				$variation->set_sku( $sku );
			}
		}

		$price = self::to_float( $variant['price'] );
		if ( $price > 0 ) {
			$variation->set_regular_price( wc_format_decimal( $price ) );
		}

		$sale = self::to_float( $variant['sale_price'] );
		if ( $sale > 0 && $sale < $price ) {
			$variation->set_sale_price( wc_format_decimal( $sale ) );
		} else {
			$variation->set_sale_price( '' );
		}

		// Stock.
		$stock = isset( $variant['stock'] ) && null !== $variant['stock'] ? (int) $variant['stock'] : null;
		if ( null !== $stock ) {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $stock );
			$variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		} else {
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
		}

		$variation_id = $variation->save();

		// Variation-specific image: set as the variation's featured thumbnail
		// AND register it in Rey's `rey_extra_variation_images` meta so the
		// "Extra Variation Images" module swaps the gallery correctly when this
		// variation is selected on the imported store.
		if ( ! empty( $variant['image'] ) ) {
			$attachment_id = self::sideload_image( $variant['image'], $parent_id, $options );
			if ( $attachment_id ) {
				set_post_thumbnail( $variation_id, $attachment_id );
				update_post_meta( $variation_id, 'rey_extra_variation_images', (string) $attachment_id );
			}
		}

		return $variation_id;
	}

	/**
	 * Collect unique attribute labels + values from variants.
	 *
	 * @param array $variants Normalized variants.
	 * @return array [ label => [ values ] ]
	 */
	private static function build_attributes( $variants ) {
		$attributes = array();

		foreach ( $variants as $variant ) {
			foreach ( (array) $variant['attributes'] as $name => $value ) {
				$name = trim( (string) $name );
				$value = trim( (string) $value );
				if ( '' === $name || '' === $value ) {
					continue;
				}
				if ( ! isset( $attributes[ $name ] ) ) {
					$attributes[ $name ] = array();
				}
				$attributes[ $name ][ $value ] = true;
			}
		}

		foreach ( $attributes as $name => $values ) {
			$attributes[ $name ] = array_keys( $values );
		}

		return $attributes;
	}

	/**
	 * Build the WC_Product_Attribute list for a variable product.
	 *
	 * When Rey swatches were extracted from the source page, attributes are
	 * created as GLOBAL taxonomies (pa_*) with the swatch type and per-term
	 * swatch meta. Non-swatch attributes fall back to custom attributes.
	 *
	 * @param array $data Scraped product data.
	 * @return WC_Product_Attribute[]
	 */
	private static function build_product_attributes( $data ) {
		$swatches = isset( $data['swatches'] ) && is_array( $data['swatches'] ) ? $data['swatches'] : array();

		if ( empty( $swatches ) ) {
			// No swatches — legacy custom-attribute path.
			$wc_attributes = array();
			foreach ( self::build_attributes( $data['variants'] ) as $label => $values ) {
				$attribute = new WC_Product_Attribute();
				$attribute->set_name( $label );
				$attribute->set_options( array_values( $values ) );
				$attribute->set_position( count( $wc_attributes ) );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$wc_attributes[] = $attribute;
			}
			return $wc_attributes;
		}

		$wc_attributes = array();
		$position      = 0;

		// 1. Swatch attributes (global pa_* taxonomies + term meta).
		foreach ( $swatches as $taxonomy => $swatch ) {
			$attribute = self::build_swatch_attribute( $taxonomy, $swatch, $position );
			if ( $attribute ) {
				$wc_attributes[] = $attribute;
				$position++;
			}
		}

		// 2. Remaining variant attributes not represented by swatches → custom.
		foreach ( self::build_remaining_custom_attributes( $data['variants'], $swatches ) as $attribute ) {
			$attribute->set_position( $position );
			$wc_attributes[] = $attribute;
			$position++;
		}

		return $wc_attributes;
	}

	/**
	 * Ensure a global swatch attribute + terms + term meta exist, and return a
	 * global WC_Product_Attribute for it.
	 *
	 * @param string $taxonomy Taxonomy slug (e.g. "pa_color").
	 * @param array  $swatch   { name, type, terms: { slug => {name, color, image} } }.
	 * @param int    $position Attribute position.
	 * @return WC_Product_Attribute|null
	 */
	private static function build_swatch_attribute( $taxonomy, $swatch, $position ) {
		$name = ! empty( $swatch['name'] ) ? (string) $swatch['name'] : self::humanize_attribute( $taxonomy );
		$type = ! empty( $swatch['type'] ) ? (string) $swatch['type'] : 'rey_color';

		$tax = self::ensure_global_attribute( $name, $type );
		if ( '' === $tax ) {
			return null;
		}

		// Collect TERM IDs (not slugs). WooCommerce's WC_Product_Attribute::get_terms()
		// treats string options as term NAMES and re-creates terms that don't match
		// by name (a slug like "01-light-beige" won't match the term's display name
		// "01 LIGHT BEIGE", so it spawns a duplicate "01-light-beige-2" term). Passing
		// integer term IDs makes get_terms() resolve them by ID — no duplicates.
		$term_ids = array();
		foreach ( (array) $swatch['terms'] as $slug => $term ) {
			$term_id = self::ensure_swatch_term( $tax, (array) $term );
			if ( $term_id > 0 ) {
				$term_ids[] = (int) $term_id;
			}
		}

		$attr_id = (int) wc_attribute_taxonomy_id_by_name( substr( $tax, 3 ) );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( $attr_id );
		$attribute->set_name( $tax );
		$attribute->set_options( $term_ids );
		$attribute->set_position( $position );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		return $attribute;
	}

	/**
	 * Ensure a global WooCommerce attribute exists with the given swatch type.
	 *
	 * @param string $name Attribute display name (e.g. "Color").
	 * @param string $type Attribute type (e.g. "rey_color").
	 * @return string Taxonomy name (e.g. "pa_color"), or '' on failure.
	 */
	private static function ensure_global_attribute( $name, $type ) {
		$taxonomy = wc_attribute_taxonomy_name( $name );
		$args     = array(
			'name'         => $name,
			'slug'         => wc_sanitize_taxonomy_name( $name ),
			'type'         => $type,
			'order_by'     => 'menu_order',
			'has_archives' => false,
		);

		// Persistent object caches (Redis) can serve a STALE attribute list, so
		// wc_create_attribute wrongly reports "slug already in use" for an
		// attribute that no longer exists in the DB. Clear that cache first.
		self::flush_attribute_cache();

		if ( taxonomy_exists( $taxonomy ) ) {
			self::update_attribute_type( $taxonomy, $type );
			return $taxonomy;
		}

		$id = wc_create_attribute( $args );

		if ( is_wp_error( $id ) ) {
			// Retry once after a full flush (the slug check may have read a
			// stale list); then fall back to an existing registration.
			self::flush_attribute_cache();
			if ( taxonomy_exists( $taxonomy ) ) {
				self::update_attribute_type( $taxonomy, $type );
				return $taxonomy;
			}
			$id = wc_create_attribute( $args );
			if ( is_wp_error( $id ) ) {
				return ( taxonomy_exists( $taxonomy ) ? $taxonomy : '' );
			}
		}

		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		\WC_Post_Types::register_taxonomies();
		self::flush_attribute_cache();

		return $taxonomy;
	}

	/**
	 * Invalidate the attribute/taxonomy/term object cache so a newly created
	 * global attribute is immediately visible to wp_insert_term() even with a
	 * persistent object cache (Redis).
	 */
	private static function flush_attribute_cache() {
		delete_transient( 'wc_attribute_taxonomies' );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	/**
	 * Update the attribute_type of an existing attribute if it differs.
	 *
	 * @param string $taxonomy Taxonomy name (e.g. "pa_color").
	 * @param string $type     Desired type (e.g. "rey_color").
	 */
	private static function update_attribute_type( $taxonomy, $type ) {
		$attr_name = substr( $taxonomy, 3 );
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			if ( $a->attribute_name === $attr_name && ( isset( $a->attribute_type ) ? $a->attribute_type : 'select' ) !== $type ) {
				global $wpdb;
				$wpdb->update(
					$wpdb->prefix . 'woocommerce_attribute_taxonomies',
					array( 'attribute_type' => $type ),
					array( 'attribute_id' => $a->attribute_id )
				);
				delete_transient( 'wc_attribute_taxonomies' );
				break;
			}
		}
	}

	/**
	 * Ensure a swatch term exists and set its Rey swatch term meta.
	 *
	 * @param string $taxonomy Taxonomy name (e.g. "pa_color").
	 * @param array  $term     { name, slug, color, image, secondary_color }.
	 * @return int Term ID or 0.
	 */
	private static function ensure_swatch_term( $taxonomy, $term ) {
		$name = ! empty( $term['name'] ) ? (string) $term['name'] : '';
		$slug = ! empty( $term['slug'] ) ? (string) $term['slug'] : ( '' !== $name ? wc_sanitize_taxonomy_name( $name ) : '' );
		if ( '' === $slug ) {
			return 0;
		}
		if ( '' === $name ) {
			$name = $slug;
		}

		$t = get_term_by( 'name', $name, $taxonomy );
		if ( ! $t || is_wp_error( $t ) ) {
			$t = get_term_by( 'slug', $slug, $taxonomy );
		}

		if ( ! $t || is_wp_error( $t ) ) {
			$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			if ( is_wp_error( $inserted ) ) {
				return 0;
			}
			$term_id = (int) $inserted['term_id'];
		} else {
			$term_id = (int) $t->term_id;
		}

		// Rey swatch term meta (Rey reads these via ACF get_field on the term).
		$color = ! empty( $term['color'] ) ? trim( (string) $term['color'] ) : '';
		if ( '' !== $color ) {
			update_term_meta( $term_id, 'rey_attribute_color', $color );
		}

		if ( ! empty( $term['secondary_color'] ) ) {
			update_term_meta( $term_id, 'rey_attribute_color_secondary', trim( (string) $term['secondary_color'] ) );
		}

		if ( ! empty( $term['image'] ) ) {
			$attachment_id = self::sideload_image( (string) $term['image'], 0 );
			if ( $attachment_id ) {
				update_term_meta( $term_id, 'rey_attribute_image', $attachment_id );
			}
		}

		return $term_id;
	}

	/**
	 * Build custom attributes for variant attributes NOT represented in the
	 * swatches map (so no options are dropped).
	 *
	 * @param array $variants Normalized variants.
	 * @param array $swatches Swatch map (taxonomy => { name, ... }).
	 * @return WC_Product_Attribute[]
	 */
	private static function build_remaining_custom_attributes( $variants, $swatches ) {
		$swatch_labels = array();
		foreach ( $swatches as $swatch ) {
			if ( ! empty( $swatch['name'] ) ) {
				$swatch_labels[ (string) $swatch['name'] ] = true;
			}
		}

		$labels = array();
		$values = array();
		foreach ( (array) $variants as $variant ) {
			foreach ( (array) $variant['attributes'] as $name => $value ) {
				$name  = trim( (string) $name );
				$value = trim( (string) $value );
				if ( '' === $name || '' === $value || isset( $swatch_labels[ $name ] ) ) {
					continue;
				}
				if ( ! isset( $labels[ $name ] ) ) {
					$labels[ $name ] = true;
					$values[ $name ] = array();
				}
				$values[ $name ][ $value ] = true;
			}
		}

		$out = array();
		foreach ( $labels as $label => $_ ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $label );
			$attribute->set_options( array_keys( $values[ $label ] ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$out[] = $attribute;
		}

		return $out;
	}

	/**
	 * Humanize an attribute slug into a display label ("pa_color" → "Color").
	 *
	 * @param string $slug Attribute slug (with or without "pa_").
	 * @return string
	 */
	private static function humanize_attribute( $slug ) {
		$slug = (string) $slug;
		if ( 0 === strpos( $slug, 'pa_' ) ) {
			$slug = substr( $slug, 3 );
		}
		return ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Ensure product categories exist and return their term IDs.
	 *
	 * @param array $categories Category names.
	 * @return array
	 */
	private static function ensure_categories( $categories ) {
		$ids = array();

		foreach ( (array) $categories as $category ) {
			$category = trim( (string) $category );
			if ( '' === $category ) {
				continue;
			}

			$term = term_exists( $category, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $category, 'product_cat' );
			}

			if ( ! is_wp_error( $term ) ) {
				$ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
			}
		}

		return $ids;
	}

	/**
	 * Download images and set featured image + gallery.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $images     Image URLs.
	 * @param array $options    Image options (white_bg, padding).
	 */
	private static function attach_images( $product_id, $images, $options = array() ) {
		$gallery = array();
		$featured = 0;

		foreach ( (array) $images as $index => $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				continue;
			}

			$attachment_id = self::sideload_image( $url, $product_id, $options );
			if ( ! $attachment_id ) {
				continue;
			}

			if ( 0 === $index ) {
				$featured = $attachment_id;
			} else {
				$gallery[] = $attachment_id;
			}
		}

		if ( $featured ) {
			set_post_thumbnail( $product_id, $featured );
		}

		if ( $gallery ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
		}
	}

	/**
	 * Download an image into the media library, deduplicating by source URL.
	 *
	 * @param string $url       Image URL.
	 * @param int    $parent_id Parent post ID.
	 * @param array  $options   Image options (white_bg, padding).
	 * @return int Attachment ID or 0.
	 */
	private static function sideload_image( $url, $parent_id, $options = array() ) {
		// Block private/internal image URLs (SSRF guard for scraped content).
		if ( ! SWI_HTTP::is_url_allowed( $url ) ) {
			return 0;
		}

		// Dedup key must include the processing signature, otherwise a
		// previously-imported *unprocessed* copy of the same URL would be
		// returned even when white-bg/padding are now requested.
		$sig = self::image_signature( $options );

		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_swi_source_image',
				'meta_value'     => $url,
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		foreach ( (array) $existing as $candidate_id ) {
			$candidate_sig = (string) get_post_meta( $candidate_id, '_swi_img_sig', true );
			if ( $candidate_sig === $sig ) {
				return (int) $candidate_id;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		// Optionally composite onto a white square canvas with padding.
		$processed = self::process_image_file( $tmp, $options );

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name || '.' === $name ) {
			$name = 'imported-image-' . time() . '.jpg';
		}
		// A processed image is always re-encoded to JPEG (flattened on white),
		// so force a .jpg extension.
		if ( $processed ) {
			$info = pathinfo( $name );
			$name = ( isset( $info['filename'] ) && '' !== $info['filename'] ? $info['filename'] : 'image' ) . '.jpg';
		}

		$file_array = array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $parent_id, '' );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return 0;
		}

		update_post_meta( $attachment_id, '_swi_source_image', $url );
		update_post_meta( $attachment_id, '_swi_img_sig', $sig );

		return $attachment_id;
	}

	/**
	 * Derive a processing signature for the given image options ('' when no
	 * processing). Used as a dedup key so processed + unprocessed copies of the
	 * same source URL are distinct attachments.
	 *
	 * @param array $options Image options.
	 * @return string
	 */
	private static function image_signature( $options ) {
		$white_bg = isset( $options['white_bg'] ) ? (int) $options['white_bg'] : 0;
		$padding  = isset( $options['padding'] ) ? (int) $options['padding'] : 0;

		if ( $white_bg <= 0 ) {
			return '';
		}

		return 'bg' . $white_bg . '_p' . $padding;
	}

	/**
	 * Composite a downloaded image onto a white square canvas with optional
	 * padding (aspect-ratio-preserving, centered). Re-encodes to JPEG.
	 *
	 * Returns true if the file was rewritten (the `$tmp` path now holds the
	 * processed JPEG), false when no processing was requested or it failed.
	 *
	 * @param string $tmp     Path to the downloaded file (modified in place).
	 * @param array  $options Image options.
	 * @return bool
	 */
	private static function process_image_file( $tmp, $options ) {
		$white_bg = isset( $options['white_bg'] ) ? (int) $options['white_bg'] : 0;
		$padding  = isset( $options['padding'] ) ? (int) $options['padding'] : 0;

		if ( $white_bg <= 0 || ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}

		$src = self::gd_load( $tmp );
		if ( ! $src ) {
			return false;
		}

		$sw = imagesx( $src );
		$sh = imagesy( $src );
		if ( $sw <= 0 || $sh <= 0 ) {
			imagedestroy( $src );
			return false;
		}

		$canvas = imagecreatetruecolor( $white_bg, $white_bg );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $white_bg, $white_bg, $white );

		// Inner bounds after padding.
		$inner_w = max( 1, $white_bg - ( 2 * $padding ) );
		$inner_h = max( 1, $white_bg - ( 2 * $padding ) );

		// Fit source into the inner box, preserving aspect ratio.
		$scale  = min( $inner_w / $sw, $inner_h / $sh );
		$dst_w  = max( 1, (int) round( $sw * $scale ) );
		$dst_h  = max( 1, (int) round( $sh * $scale ) );
		$dst_x  = (int) round( ( $white_bg - $dst_w ) / 2 );
		$dst_y  = (int) round( ( $white_bg - $dst_h ) / 2 );

		imagecopyresampled( $canvas, $src, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $sw, $sh );

		// Re-encode to JPEG (flattens any transparency onto the white canvas).
		imagejpeg( $canvas, $tmp, 90 );
		imagedestroy( $canvas );
		imagedestroy( $src );

		return true;
	}

	/**
	 * Load an image file into a GD resource, supporting jpg/png/gif/webp.
	 *
	 * @param string $path File path.
	 * @return resource|false
	 */
	private static function gd_load( $path ) {
		$mime = function_exists( 'mime_content_type' ) ? @mime_content_type( $path ) : '';
		$mime = is_string( $mime ) ? $mime : '';

		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/jpg':
				return @imagecreatefromjpeg( $path );
			case 'image/png':
				return @imagecreatefrompng( $path );
			case 'image/gif':
				return @imagecreatefromgif( $path );
			case 'image/webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
		}

		// Fallback: try by extension.
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'jpg':
			case 'jpeg':
				return @imagecreatefromjpeg( $path );
			case 'png':
				return @imagecreatefrompng( $path );
			case 'gif':
				return @imagecreatefromgif( $path );
			case 'webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
		}

		return false;
	}

	/**
	 * Convert a price string to float.
	 *
	 * Handles common currency formats: "29.50", "1,299.99", "1.299,99",
	 * "1 299,99", "1299". Currency symbols/letters and whitespace are
	 * stripped, then the decimal separator is normalized to a dot before
	 * casting, so comma-thousands and comma-decimals don't corrupt the value.
	 *
	 * @param mixed $value Price value.
	 * @return float
	 */
	private static function to_float( $value ) {
		if ( '' === $value || null === $value ) {
			return 0.0;
		}

		$s = (string) $value;

		// Keep only digits, dot, comma and a leading minus sign.
		$s = preg_replace( '/[^0-9.,\-]/', '', $s );
		if ( '' === $s ) {
			return 0.0;
		}

		$last_comma = strrpos( $s, ',' );
		$last_dot   = strrpos( $s, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			// Both separators present: the rightmost is the decimal separator.
			if ( $last_comma > $last_dot ) {
				// "1.299,99" — dots are thousands, comma is decimal.
				$s = str_replace( '.', '', $s );
				$s = str_replace( ',', '.', $s );
			} else {
				// "1,299.99" — commas are thousands.
				$s = str_replace( ',', '', $s );
			}
		} elseif ( false !== $last_comma && false === $last_dot ) {
			// Only commas. Heuristic: a 3-digit trailing group is thousands,
			// otherwise the comma is a decimal separator ("29,99").
			$parts = explode( ',', $s );
			$after = end( $parts );
			if ( count( $parts ) > 1 && 3 === strlen( $after ) ) {
				$s = str_replace( ',', '', $s );
			} else {
				$s = str_replace( ',', '.', $s );
			}
		}

		return (float) $s;
	}

	/**
	 * Clean a title.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private static function clean_title( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			$title = __( 'Imported product', 'swi' );
		}
		return sanitize_text_field( $title );
	}

	/**
	 * Resolve the post status for newly/updated imported products.
	 *
	 * Products are always published.
	 *
	 * @return string
	 */
	private static function import_status() {
		return 'publish';
	}
}
