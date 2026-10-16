<?php
/**
 * HTTP helper: safe fetching with SSRF protection, timeouts, retries and
 * browser-like user agent.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_HTTP {

	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

	/**
	 * Normalize a URL into an absolute http(s) URL.
	 *
	 * @param string $url Raw URL.
	 * @return string|WP_Error
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'swi_empty_url', __( 'Please enter a store URL.', 'swi' ) );
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'swi_invalid_url', __( 'The URL is not valid.', 'swi' ) );
		}

		return $url;
	}

	/**
	 * Fetch a URL and return its body.
	 *
	 * Redirects are followed manually (up to 5 hops) so that every hop —
	 * including the final destination — is re-validated against the SSRF
	 * allow-list. This closes the "open redirect to an internal address"
	 * bypass that wp_remote_get's built-in redirection would otherwise allow.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Request timeout in seconds.
	 * @return string|WP_Error
	 */
	public static function get( $url, $timeout = 30 ) {
		$normalized = self::normalize_url( $url );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$max_redirects = 5;
		$current       = $normalized;

		for ( $i = 0; $i <= $max_redirects; $i++ ) {
			if ( ! self::is_url_allowed( $current ) ) {
				return new WP_Error( 'swi_ssrf', __( 'The URL could not be validated (unresolvable host or a blocked/private address).', 'swi' ) );
			}

			// Prefer a direct cURL request (HTTP/2) — Shopify throttles
			// HTTP/1.1 requests from datacenter IPs ("429 local_rate_limited")
			// but serves HTTP/2 normally. Fall back to wp_remote_get when the
			// cURL extension is unavailable.
			$response = self::curl_fetch( $current, $timeout );
			if ( is_wp_error( $response ) && 'swi_curl_missing' === $response->get_error_code() ) {
				$response = self::wp_fetch( $current, $timeout );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) $response['code'];

			// Bot-protection challenge (Cloudflare "Just a moment…", DataDome,
			// etc.)? When a JS-render proxy is configured, ask it to execute
			// the page and return the rendered HTML instead of giving up.
			if ( self::is_bot_challenge( $code, $response['headers'], $response['body'] ) ) {
				$rendered = self::render_get( $current, $timeout );
				if ( ! is_wp_error( $rendered ) ) {
					return $rendered;
				}
			}

			// Redirect: resolve and re-validate the Location header, then loop.
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = self::header_value( $response['headers'], 'Location' );
				if ( '' !== (string) $location ) {
					$next = self::resolve_redirect( $current, (string) $location );
					if ( is_wp_error( $next ) ) {
						return $next;
					}
					$current = $next;
					continue;
				}
			}

			if ( $code >= 400 ) {
				return new WP_Error(
					'swi_http_' . $code,
					/* translators: %d: HTTP status code */
					sprintf( __( 'The remote server returned HTTP %d.', 'swi' ), $code )
				);
			}

			return $response['body'];
		}

		return new WP_Error( 'swi_too_many_redirects', __( 'Too many redirects while fetching the URL.', 'swi' ) );
	}

	/**
	 * Fetch one URL (a single hop) with cURL, negotiating HTTP/2.
	 *
	 * Returns the raw response header block (normalized to HTTP/1.1-style
	 * lines by cURL) and the decompressed body, or a WP_Error.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|WP_Error Array( 'code' => int, 'headers' => string, 'body' => string ).
	 */
	private static function curl_fetch( $url, $timeout = 30 ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'swi_curl_missing', __( 'cURL is not available.', 'swi' ) );
		}

		$sslverify = (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 );

		$ch = curl_init();
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_FOLLOWLOCATION => false, // Redirects are handled manually for SSRF re-validation.
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_USERAGENT      => self::USER_AGENT,
				CURLOPT_SSL_VERIFYPEER => $sslverify,
				CURLOPT_SSL_VERIFYHOST => $sslverify ? 2 : 0,
				CURLOPT_ENCODING       => '', // Accept gzip/brotli; cURL decompresses the body.
				CURLOPT_HTTPHEADER     => array(
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language: en-US,en;q=0.9',
				),
			)
		);

		// Prefer HTTP/2 — Shopify serves it while throttling HTTP/1.1.
		if ( defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
			curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS );
		} elseif ( defined( 'CURL_HTTP_VERSION_2_0' ) ) {
			curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0 );
		}

		$raw = curl_exec( $ch );

		if ( false === $raw ) {
			$errn = curl_errno( $ch );
			$err  = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'swi_curl_' . $errn, ( '' !== $err ? $err : __( 'The cURL request failed.', 'swi' ) ) );
		}

		$code        = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		curl_close( $ch );

		return array(
			'code'    => $code,
			'headers' => substr( $raw, 0, $header_size ),
			'body'    => substr( $raw, $header_size ),
		);
	}

	/**
	 * Fallback fetch via WordPress's HTTP API (used only when cURL is missing).
	 *
	 * WordPress's cURL transport forces HTTP/1.1, so this may be rate-limited
	 * by Shopify; it exists purely for hosts without the cURL extension.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|WP_Error Array( 'code' => int, 'headers' => string, 'body' => string ).
	 */
	private static function wp_fetch( $url, $timeout = 30 ) {
		$args = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => self::USER_AGENT,
			'sslverify'   => (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 ),
			'headers'     => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		);

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_headers = '';
		$headers_obj = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers_obj ) || is_array( $headers_obj ) ) {
			foreach ( $headers_obj as $key => $value ) {
				$raw_headers .= $key . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : (string) $value ) . "\r\n";
			}
		}

		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $raw_headers,
			'body'    => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Read a single header value from a raw header block (case-insensitive).
	 *
	 * @param string $header_block Raw response headers.
	 * @param string $name         Header name (e.g. "Location").
	 * @return string Header value, or ''.
	 */
	private static function header_value( $header_block, $name ) {
		$lines = preg_split( '/\r\n|\n|\r/', (string) $header_block );
		foreach ( $lines as $line ) {
			if ( preg_match( '/^' . preg_quote( $name, '/' ) . '\s*:\s*(.*)$/i', trim( $line ), $m ) ) {
				return trim( $m[1] );
			}
		}
		return '';
	}

	/**
	 * Resolve a redirect Location header against the current URL.
	 *
	 * @param string $current  Current absolute URL.
	 * @param string $location Raw Location header value.
	 * @return string|WP_Error
	 */
	private static function resolve_redirect( $current, $location ) {
		$location = trim( (string) $location );
		if ( '' === $location ) {
			return new WP_Error( 'swi_redirect', __( 'Redirect response missing a Location header.', 'swi' ) );
		}

		// Absolutize a relative Location against the current URL.
		if ( ! preg_match( '#^https?://#i', $location ) ) {
			$parts  = wp_parse_url( $current );
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
			$host   = isset( $parts['host'] ) ? $parts['host'] : '';

			if ( 0 === strpos( $location, '//' ) ) {
				$location = $scheme . ':' . $location;
			} elseif ( 0 === strpos( $location, '/' ) ) {
				$location = $scheme . '://' . $host . $location;
			} else {
				$dir = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
				if ( '.' === $dir ) {
					$dir = '/';
				}
				$location = $scheme . '://' . $host . rtrim( $dir, '/' ) . '/' . $location;
			}
		}

		return self::normalize_url( $location );
	}

	/**
	 * Whether a JS-rendering proxy is configured.
	 *
	 * @return bool
	 */
	public static function render_enabled() {
		return '' !== trim( (string) SWI_Plugin::get_option( 'render_url', '' ) );
	}

	/**
	 * Whether a response looks like a bot-protection challenge.
	 *
	 * Cloudflare's "managed challenge" returns 403/503 with a
	 * `cf-mitigated: challenge` header and a "Just a moment…" body; DataDome
	 * returns 403 with a `#challenge` page. When detected we can fall back to
	 * a JS renderer instead of erroring out with HTTP 403.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $headers Raw header block.
	 * @param string $body    Response body.
	 * @return bool
	 */
	public static function is_bot_challenge( $code, $headers, $body ) {
		if ( ! self::render_enabled() ) {
			return false;
		}

		if ( ! in_array( (int) $code, array( 403, 503 ), true ) ) {
			return false;
		}

		$headers = strtolower( (string) $headers );
		if ( false !== strpos( $headers, 'cf-mitigated: challenge' ) ) {
			return true;
		}

		$markers = array(
			'__cf_chl',
			'challenges.cloudflare.com',
			'just a moment',
			'performing security verification',
			'datadome',
			'captcha-delivery.com',
		);

		$body = (string) $body;
		foreach ( $markers as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetch a URL through the configured JS-rendering proxy.
	 *
	 * Speaks the FlareSolverr-compatible JSON API (POST /v1,
	 * `cmd: "request.get"`), which executes the page in a real browser and
	 * solves bot challenges before returning the rendered HTML.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $timeout Per-request timeout (seconds).
	 * @return string|WP_Error Rendered HTML, or WP_Error.
	 */
	public static function render_get( $url, $timeout = 60 ) {
		$endpoint = rtrim( (string) SWI_Plugin::get_option( 'render_url', '' ), '/' );
		if ( '' === $endpoint ) {
			return new WP_Error( 'swi_no_render', __( 'No render proxy is configured.', 'swi' ) );
		}

		$payload = array(
			'cmd'        => 'request.get',
			'url'        => $url,
			'maxTimeout' => max( 20000, (int) $timeout * 1000 ),
		);

		$response = wp_remote_post(
			$endpoint . '/v1',
			array(
				'timeout'     => (int) $timeout + 20,
				'redirection' => 0,
				'sslverify'   => (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 ),
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'swi_render_' . $code,
				/* translators: %d: HTTP status code */
				sprintf( __( 'The render proxy returned HTTP %d.', 'swi' ), $code )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['solution'] ) ) {
			return new WP_Error( 'swi_render_invalid', __( 'The render proxy returned an invalid response.', 'swi' ) );
		}

		$solution = $decoded['solution'];
		if ( isset( $solution['status'] ) && (int) $solution['status'] >= 400 ) {
			return new WP_Error(
				'swi_render_http',
				/* translators: %d: HTTP status code */
				sprintf( __( 'The render proxy could not load the page (HTTP %d).', 'swi' ), (int) $solution['status'] )
			);
		}

		if ( empty( $solution['response'] ) ) {
			return new WP_Error( 'swi_render_empty', __( 'The render proxy returned an empty page.', 'swi' ) );
		}

		// Binary resources (sitemaps, .xml.gz) come back base64-encoded from the
		// render proxy — decode so the caller gets the raw bytes.
		if ( ! empty( $solution['encoding'] ) && 'base64' === $solution['encoding'] ) {
			$raw = base64_decode( (string) $solution['response'], true );
			return ( false === $raw ) ? '' : $raw;
		}

		return (string) $solution['response'];
	}

	/**
	 * Fetch with a small retry/backoff loop.
	 *
	 * @param string $url      Absolute URL.
	 * @param int    $attempts Number of attempts.
	 * @return string|WP_Error
	 */
	public static function get_with_retry( $url, $attempts = 3 ) {
		$last_error = null;

		for ( $i = 0; $i < $attempts; $i++ ) {
			$result = self::get( $url );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			if ( in_array( $result->get_error_code(), array( 'swi_http_429', 'swi_http_403' ), true ) ) {
				sleep( min( 10, (int) pow( 2, $i ) ) );
			} else {
				sleep( 1 );
			}
		}

		return $last_error;
	}

	/**
	 * Fetch a URL and decode JSON.
	 *
	 * @param string $url Absolute URL.
	 * @return array|WP_Error
	 */
	public static function get_json( $url ) {
		$body = self::get_with_retry( $url );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'swi_invalid_json', __( 'The response was not valid JSON.', 'swi' ) );
		}

		return $decoded;
	}

	/**
	 * SSRF protection: allow only public http(s) hosts.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_url_allowed( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		if ( 'localhost' === $host || '.local' === substr( $host, -6 ) ) {
			return false;
		}

		// Resolve the hostname and reject private/reserved IPs (DNS rebinding protection).
		$ips = @gethostbynamel( $host );
		if ( false === $ips ) {
			$ip = @gethostbyname( $host );
			if ( $ip === $host ) {
				return false; // Unresolvable host.
			}
			$ips = array( $ip );
		}

		foreach ( $ips as $ip ) {
			if ( self::is_private_ip( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an IP is private, loopback, link-local or reserved.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_private_ip( $ip ) {
		$ip = trim( (string) $ip );

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long   = ip2long( $ip );
			$ranges = array(
				array( ip2long( '0.0.0.0' ), ip2long( '0.255.255.255' ) ),
				array( ip2long( '10.0.0.0' ), ip2long( '10.255.255.255' ) ),
				array( ip2long( '100.64.0.0' ), ip2long( '100.127.255.255' ) ),
				array( ip2long( '127.0.0.0' ), ip2long( '127.255.255.255' ) ),
				array( ip2long( '169.254.0.0' ), ip2long( '169.254.255.255' ) ),
				array( ip2long( '172.16.0.0' ), ip2long( '172.31.255.255' ) ),
				array( ip2long( '192.168.0.0' ), ip2long( '192.168.255.255' ) ),
				array( ip2long( '224.0.0.0' ), ip2long( '239.255.255.255' ) ),
				array( ip2long( '240.0.0.0' ), ip2long( '255.255.255.255' ) ),
			);

			foreach ( $ranges as $range ) {
				if ( $long >= $range[0] && $long <= $range[1] ) {
					return true;
				}
			}

			return false;
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$ip     = strtolower( $ip );
			$packed = @inet_pton( $ip );

			if ( false === $packed ) {
				return false;
			}

			$first = ord( $packed[0] );

			// Unique local address fc00::/7.
			if ( ( $first & 0xFE ) === 0xFC ) {
				return true;
			}

			// Link-local fe80::/10.
			if ( 0xFE === $first && ( ord( $packed[1] ) & 0xC0 ) === 0x80 ) {
				return true;
			}

			// Loopback ::1 and unspecified ::.
			$all_zero = true;
			for ( $i = 0; $i < 15; $i++ ) {
				if ( 0 !== ord( $packed[ $i ] ) ) {
					$all_zero = false;
					break;
				}
			}

			if ( $all_zero && ( 0 === ord( $packed[15] ) || 1 === ord( $packed[15] ) ) ) {
				return true;
			}

			return false;
		}

		return false;
	}
}
