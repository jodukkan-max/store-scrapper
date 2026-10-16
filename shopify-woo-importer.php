<?php
/**
 * Plugin Name:       Shopify/WooCommerce Scraper Importer
 * Plugin URI:        https://github.com/jodukkan-max/store-scrapper
 * Description:       Scrapes a remote Shopify or WooCommerce store by reading its HTML, lets you select which products to import, and imports them into your WooCommerce store with images downloaded into the media library. Discovered products are kept in memory only (never saved to the database).
 * Version:           1.6.10
 * Author:            Dukkan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       swi
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWI_VERSION', '1.6.10' );
define( 'SWI_FILE', __FILE__ );
define( 'SWI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWI_URL', plugin_dir_url( __FILE__ ) );

require_once SWI_DIR . 'includes/class-http.php';
require_once SWI_DIR . 'includes/class-detector.php';
require_once SWI_DIR . 'includes/class-crawler.php';
require_once SWI_DIR . 'includes/class-scraper.php';
require_once SWI_DIR . 'includes/class-profile.php';
require_once SWI_DIR . 'includes/class-generic-scraper.php';
require_once SWI_DIR . 'includes/class-ai.php';
require_once SWI_DIR . 'includes/class-ai-server.php';
require_once SWI_DIR . 'includes/class-importer.php';
require_once SWI_DIR . 'includes/class-debug.php';
require_once SWI_DIR . 'includes/class-admin.php';
require_once SWI_DIR . 'includes/class-updater.php';

register_activation_hook( __FILE__, array( 'SWI_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'SWI_Plugin', 'init' ) );

/**
 * Main plugin bootstrap and shared helpers (options only — no product table).
 *
 * Discovered products are intentionally NOT persisted. They live in the
 * browser's memory (JS state) for the lifetime of the admin screen only, so
 * leaving or refreshing the screen discards everything without leaving
 * anything in the database.
 */
final class SWI_Plugin {

	/**
	 * Activation: drop any legacy product table left over from older versions.
	 */
	public static function activate() {
		global $wpdb;
		$table = $wpdb->prefix . 'swi_products';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Bootstrap the admin side (menu, AJAX, assets).
	 */
	public static function init() {
		load_plugin_textdomain( 'swi', false, dirname( plugin_basename( SWI_FILE ) ) . '/languages' );

		// Self-hosted update checks (GitHub releases).
		new SWI_Updater( SWI_FILE, SWI_VERSION );

		// AI analysis server (REST endpoint for the "thinking" proxy).
		SWI_AI_Server::register();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woo_missing_notice' ) );
			return;
		}

		SWI_Admin::instance();
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 */
	public static function woo_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Shopify/WooCommerce Scraper Importer requires WooCommerce to be installed and active.', 'swi' );
		echo '</p></div>';
	}

	/**
	 * Read a single plugin option.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_option( $key, $default = '' ) {
		$options = get_option( 'swi_options', array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Write a single plugin option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 */
	public static function set_option( $key, $value ) {
		$options          = get_option( 'swi_options', array() );
		$options[ $key ]  = $value;
		update_option( 'swi_options', $options, false );
	}

	/**
	 * Truncate a string to a byte length without breaking on missing mbstring.
	 *
	 * @param string $str   Input string.
	 * @param int    $limit Max length.
	 * @return string
	 */
	public static function truncate( $str, $limit = 500 ) {
		$str = trim( (string) $str );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $str, 0, $limit );
		}
		return substr( $str, 0, $limit );
	}
}
