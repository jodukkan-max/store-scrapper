<?php
/**
 * Update-notification mechanism for Store Scrapper.
 *
 * Injects the latest GitHub release into WordPress's native update UI so
 * the site admin sees "update now" on the Plugins screen. Updates install
 * automatically only when the admin enables WordPress auto-updates for the
 * plugin (the toggle is injected below); otherwise they apply on click.
 *
 * The "latest version" source of truth is a version.json file committed to
 * the repository's `main` branch, which a GitHub Action rewrites on every
 * release. This keeps existing installs in sync without a wordpress.org
 * listing.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Updater {

	/**
	 * URL of the version.json file in the GitHub repository (main branch).
	 *
	 * @var string
	 */
	const VERSION_URL = 'https://raw.githubusercontent.com/jodukkan-max/store-scrapper/main/version.json';

	/**
	 * The plugin slug (folder name).
	 *
	 * @var string
	 */
	const PLUGIN_SLUG = 'store-scrapper';

	/**
	 * The plugin basename (store-scrapper/shopify-woo-importer.php).
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Full filesystem path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * The current plugin version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Initialize the updater and register hooks.
	 *
	 * @param string $plugin_file Full path to the main plugin file.
	 * @param string $version     Current plugin version.
	 */
	public function __construct( $plugin_file, $version ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_file     = $plugin_file;
		$this->current_version = $version;

		// Inject into WP's native update UI.
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ), 10, 1 );

		// Show the "Enable auto-updates" toggle (WordPress only shows it for
		// wordpress.org-hosted plugins by default).
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_toggle_html' ), 10, 3 );
	}

	// -----------------------------------------------------------------
	// WP native update UI injection.
	// -----------------------------------------------------------------

	/**
	 * Inject the latest release into WordPress's update transient.
	 *
	 * @param  object $transient WordPress update transient.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$data = $this->fetch_version_data();
		if ( ! $data ) {
			return $transient;
		}

		if ( version_compare( $data['version'], $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => self::PLUGIN_SLUG,
				'plugin'      => $this->plugin_basename,
				'new_version' => $data['version'],
				'package'     => $data['package'],
				'url'         => 'https://github.com/jodukkan-max/store-scrapper',
				'requires'    => isset( $data['requires'] ) ? $data['requires'] : '5.0',
				'tested'      => isset( $data['tested'] ) ? $data['tested'] : '',
			);
		}

		return $transient;
	}

	// -----------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------

	/**
	 * Transient key used to cache the remote version.json payload.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'swi_update_check';

	/**
	 * How long a successful version.json response is cached (12 hours),
	 * mirroring WordPress core's own update cadence.
	 *
	 * @var int
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed fetch is cached (1 hour) so a flaky GitHub/CDN
	 * response doesn't trigger a retry on every single admin page load.
	 *
	 * @var int
	 */
	const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Fetch the latest version.json from GitHub, caching the result in a
	 * site transient so we hit raw.githubusercontent.com at most once per
	 * 12 hours.
	 *
	 * @return array|null Decoded version data, or null on failure.
	 */
	private function fetch_version_data() {
		$cached = get_site_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && ! empty( $cached['version'] ) && ! empty( $cached['package'] ) ) {
			return $cached;
		}

		// Sentinel for a recent failure (we can't use `false` — it's also the
		// "missing transient" return value).
		if ( 'failed' === $cached ) {
			return null;
		}

		$response = wp_remote_get( self::VERSION_URL, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			set_site_transient( self::TRANSIENT_KEY, 'failed', self::FAILURE_TTL );
			return null;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::TRANSIENT_KEY, 'failed', self::FAILURE_TTL );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['version'] ) || empty( $body['package'] ) ) {
			set_site_transient( self::TRANSIENT_KEY, 'failed', self::FAILURE_TTL );
			return null;
		}

		set_site_transient( self::TRANSIENT_KEY, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Show the "Enable auto-updates" / "Disable auto-updates" toggle for this
	 * plugin in the Plugins list screen (WordPress only renders it for
	 * wordpress.org-hosted plugins by default).
	 *
	 * @param  string $html         Existing HTML (empty for self-hosted plugins).
	 * @param  string $plugin_file  Plugin basename.
	 * @param  array  $plugin_data  Plugin header data from get_plugins().
	 * @return string Modified HTML.
	 */
	public function auto_update_toggle_html( $html, $plugin_file, $plugin_data ) {
		if ( $plugin_file !== $this->plugin_basename ) {
			return $html;
		}

		if ( wp_is_auto_update_forced_for_item( 'plugin', false, 'disabled' ) ) {
			return $html;
		}

		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$enabled      = in_array( $plugin_file, $auto_updates, true );

		if ( $enabled ) {
			$action = 'disable-auto-update';
			$label  = __( 'Disable auto-updates' );
			$css    = 'auto-update-disabled';
		} else {
			$action = 'enable-auto-update';
			$label  = __( 'Enable auto-updates' );
			$css    = 'auto-update-enabled';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'plugin' => $plugin_file,
				),
				'plugins.php'
			),
			'updates'
		);

		return sprintf(
			'<a href="%s" class="%s" data-wp-action="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			$css,
			$enabled ? 'disable' : 'enable',
			esc_attr( $label ),
			$label
		);
	}
}
