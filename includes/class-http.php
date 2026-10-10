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

			$args = array(
				'timeout'     => $timeout,
				'redirection' => 0, // Handled manually so each hop is re-validated.
				'user-agent'  => self::USER_AGENT,
				'sslverify'   => (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 ),
				'headers'     => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
			);

			$response = wp_remote_get( $current, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// Redirect: resolve and re-validate the Location header, then loop.
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
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

			return wp_remote_retrieve_body( $response );
		}

		return new WP_Error( 'swi_too_many_redirects', __( 'Too many redirects while fetching the URL.', 'swi' ) );
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
