<?php
/**
 * Scraping profiles: learned extraction rules for a specific site.
 *
 * A profile is a JSON structure describing how to extract product fields
 * (title, price, images, variants, …) from a given site's HTML. Profiles are
 * created by the "train on a sample simple + variable product" flow and then
 * applied to every other product page on that site during a batch scrape.
 *
 * Unlike discovered products (which are intentionally memory-only), profiles
 * ARE persisted so a trained site can be scraped repeatedly.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Profile {

	const VERSION = 1;

	/**
	 * Supported field-extraction strategies.
	 *
	 * @var string[]
	 */
	private static $field_strategies = array( 'meta', 'jsonld', 'jsonld_all', 'selector', 'selector_all', 'regex' );

	/**
	 * Supported variant-extraction strategies.
	 *
	 * @var string[]
	 */
	private static $variant_strategies = array( 'embedded_json', 'dom_options', 'productgroup' );

	/**
	 * Normalize a site domain for use as a profile key.
	 *
	 * Accepts a bare domain ("example.com") or a full URL. Returns the
	 * lowercased host with a leading "www." stripped, so example.com and
	 * www.example.com share one profile.
	 *
	 * @param string $domain_or_url A bare domain or full URL.
	 * @return string Lowercased host, or '' when unparseable.
	 */
	public static function normalize_domain( $domain_or_url ) {
		$value = trim( (string) $domain_or_url );
		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . $value;
		}

		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * Derive a base URL (scheme://host) from a full URL or domain.
	 *
	 * @param string $domain_or_url Domain or full URL.
	 * @return string scheme://host (no trailing slash), or ''.
	 */
	public static function base_url( $domain_or_url ) {
		$value = trim( (string) $domain_or_url );
		if ( '' === $value ) {
			return '';
		}
		if ( false === strpos( $value, '://' ) ) {
			$value = 'https://' . $value;
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		return $scheme . '://' . $parts['host'];
	}

	/**
	 * Option key holding every saved profile (single option, keyed by domain).
	 *
	 * @return string
	 */
	private static function option_key() {
		return 'swi_profiles';
	}

	/**
	 * Retrieve all saved profiles.
	 *
	 * @return array [ domain => profile ]
	 */
	public static function all() {
		$profiles = get_option( self::option_key(), array() );
		return is_array( $profiles ) ? $profiles : array();
	}

	/**
	 * Retrieve a profile for a domain.
	 *
	 * @param string $domain_or_url Domain or URL.
	 * @return array|null Profile array, or null when none is saved.
	 */
	public static function get( $domain_or_url ) {
		$domain = self::normalize_domain( $domain_or_url );
		if ( '' === $domain ) {
			return null;
		}

		$profiles = self::all();
		return isset( $profiles[ $domain ] ) ? $profiles[ $domain ] : null;
	}

	/**
	 * Save (upsert) a profile for a domain.
	 *
	 * @param string $domain_or_url Domain or URL.
	 * @param array  $profile       Profile array (validated).
	 * @return array|WP_Error Validated profile, or WP_Error on invalid input.
	 */
	public static function save( $domain_or_url, $profile ) {
		$valid = self::validate( $profile );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$domain = self::normalize_domain( $domain_or_url );
		if ( '' === $domain ) {
			return new WP_Error( 'swi_profile_domain', __( 'Could not determine the site domain for this profile.', 'swi' ) );
		}

		$valid['domain']      = $domain;
		$profiles             = self::all();
		$profiles[ $domain ]  = $valid;

		update_option( self::option_key(), $profiles, false );

		return $valid;
	}

	/**
	 * Delete a profile for a domain.
	 *
	 * @param string $domain_or_url Domain or URL.
	 * @return bool Whether a profile existed and was removed.
	 */
	public static function delete( $domain_or_url ) {
		$domain = self::normalize_domain( $domain_or_url );
		if ( '' === $domain ) {
			return false;
		}

		$profiles = self::all();
		if ( ! isset( $profiles[ $domain ] ) ) {
			return false;
		}

		unset( $profiles[ $domain ] );
		update_option( self::option_key(), $profiles, false );

		return true;
	}

	/**
	 * Validate and normalize a profile array.
	 *
	 * @param array $profile Raw profile.
	 * @return array|WP_Error Normalized profile, or WP_Error.
	 */
	public static function validate( $profile ) {
		if ( ! is_array( $profile ) || empty( $profile['fields'] ) || ! is_array( $profile['fields'] ) ) {
			return new WP_Error( 'swi_profile_invalid', __( 'A profile must contain a "fields" map.', 'swi' ) );
		}

		$out = array(
			'version'    => self::VERSION,
			'domain'     => isset( $profile['domain'] ) ? self::normalize_domain( $profile['domain'] ) : '',
			'base_url'   => isset( $profile['base_url'] ) ? (string) $profile['base_url'] : '',
			'trained_at' => isset( $profile['trained_at'] ) ? (string) $profile['trained_at'] : current_time( 'c' ),
			'samples'    => isset( $profile['samples'] ) && is_array( $profile['samples'] ) ? $profile['samples'] : array(),
			'fields'     => array(),
		);

		foreach ( $profile['fields'] as $field => $rule ) {
			// A null rule means "unknown field — leave empty"; drop it.
			if ( null === $rule ) {
				continue;
			}

			$rule     = is_array( $rule ) ? $rule : array();
			$strategy = isset( $rule['strategy'] ) ? (string) $rule['strategy'] : '';

			if ( '' === $strategy ) {
				continue; // empty rule — skip.
			}

			if ( ! in_array( $strategy, self::$field_strategies, true ) ) {
				return new WP_Error(
					'swi_profile_bad_strategy',
					/* translators: 1: strategy, 2: field name */
					sprintf( __( 'Unknown field strategy "%1$s" for "%2$s".', 'swi' ), $strategy, (string) $field )
				);
			}

			$out['fields'][ $field ] = $rule;
		}

		if ( isset( $profile['variable'] ) && is_array( $profile['variable'] ) ) {
			$out['variable'] = array(
				'detection' => isset( $profile['variable']['detection'] ) && is_array( $profile['variable']['detection'] ) ? $profile['variable']['detection'] : array(),
			);

			if ( ! empty( $profile['variable']['variants'] ) && is_array( $profile['variable']['variants'] ) ) {
				$variants  = $profile['variable']['variants'];
				$vstrategy = isset( $variants['strategy'] ) ? strtolower( (string) $variants['strategy'] ) : '';

				// Normalize: the AI sometimes writes an `attribute_mode`
				// ("name_attr", "title_split", "option_fields", "attributes_map")
				// as the top-level strategy. Those are modes, not strategies —
				// the correct strategy is always "embedded_json". Rewrite it so
				// the profile is accepted instead of hard-failing.
				$modes = array( 'name_attr', 'title_split', 'option_fields', 'attributes_map' );
				if ( in_array( $vstrategy, $modes, true ) ) {
					$variants['attribute_mode'] = $vstrategy;
					$variants['strategy']       = 'embedded_json';
					$vstrategy                  = 'embedded_json';
				}

				if ( ! in_array( $vstrategy, self::$variant_strategies, true ) ) {
					return new WP_Error(
						'swi_profile_bad_variant_strategy',
						/* translators: %s: strategy */
						sprintf( __( 'Unknown variant strategy "%s".', 'swi' ), $vstrategy )
					);
				}

				$out['variable']['variants'] = $variants;
			}
		}

		return $out;
	}
}
