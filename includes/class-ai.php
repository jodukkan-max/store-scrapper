<?php
/**
 * AI product-analysis client.
 *
 * Sends sample product HTML to the shared Dukkan AI endpoint (a Gemini proxy
 * hosted on the portal server) and receives back a scraping profile. The Gemini
 * API key itself never leaves the portal server — every store's plugin calls
 * the same endpoint with a dedicated static token.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_AI {

	/**
	 * Default endpoint + token. These can be overridden in plugin options
	 * (swi_options) if the endpoint or token ever rotates.
	 */
	const DEFAULT_ENDPOINT = 'https://portal.dukkan.pro/api/ai/analyze-product';
	const DEFAULT_TOKEN    = 'd98b0f6ecde49ab8c7ac2b5371aabbe19887fe4371616038';

	/**
	 * The shared AI endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$endpoint = SWI_Plugin::get_option( 'ai_endpoint', self::DEFAULT_ENDPOINT );
		return '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * The static token authorizing this plugin against the AI endpoint.
	 *
	 * @return string
	 */
	public static function token() {
		$token = SWI_Plugin::get_option( 'ai_token', self::DEFAULT_TOKEN );
		return '' !== $token ? $token : self::DEFAULT_TOKEN;
	}

	/**
	 * Analyze sample product HTML and return a scraping profile.
	 *
	 * @param string $html_simple   Simple product page HTML (may be '').
	 * @param string $html_variable Variable product page HTML (may be '').
	 * @return array|WP_Error Array with 'profile' on success.
	 */
	public static function analyze( $html_simple, $html_variable ) {
		if ( '' === $html_simple && '' === $html_variable ) {
			return new WP_Error( 'swi_ai_empty', __( 'Provide at least one sample product (simple or variable).', 'swi' ) );
		}

		$payload = array();
		if ( '' !== $html_simple ) {
			$payload['html_simple'] = $html_simple;
		}
		if ( '' !== $html_variable ) {
			$payload['html_variable'] = $html_variable;
		}

		$args = array(
			'timeout'     => 120,
			'redirection' => 5,
			'sslverify'   => (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 ),
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . self::token(),
			),
			'body'        => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( self::endpoint(), $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'swi_ai_invalid', __( 'The AI endpoint returned an unexpected response.', 'swi' ) );
		}

		if ( $code >= 400 || empty( $data['success'] ) ) {
			$message = isset( $data['error'] ) ? (string) $data['error'] : sprintf( __( 'AI request failed (HTTP %d).', 'swi' ), $code );
			return new WP_Error( 'swi_ai_' . $code, $message );
		}

		if ( empty( $data['profile'] ) || ! is_array( $data['profile'] ) ) {
			return new WP_Error( 'swi_ai_profile', __( 'The AI endpoint did not return a profile.', 'swi' ) );
		}

		return $data['profile'];
	}
}
