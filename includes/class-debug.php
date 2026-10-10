<?php
/**
 * Debug logging helper.
 *
 * Writes structured diagnostics to a log file in the uploads directory so
 * import failures can be traced. Enabled via the Settings toggle or the
 * SWI_DEBUG constant.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Debug {

	/**
	 * Whether debug logging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( defined( 'SWI_DEBUG' ) && SWI_DEBUG ) {
			return true;
		}
		return (bool) (int) SWI_Plugin::get_option( 'debug', 0 );
	}

	/**
	 * Absolute path to the debug log file.
	 *
	 * The log lives in its own sub-directory under uploads, guarded by an
	 * index.php and an .htaccess deny rule, so it is not directly web-readable
	 * on Apache. On nginx the file is additionally never referenced via a
	 * public URL (see log_url below), so the only way to read it is through
	 * the authenticated AJAX viewer.
	 *
	 * @return string
	 */
	public static function log_path() {
		$upload = wp_get_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'swi-logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Guard the directory from direct web access.
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}

		return trailingslashit( $dir ) . 'swi-debug.log';
	}

	/**
	 * Public URL to the debug log file.
	 *
	 * Always empty: the log is intentionally never exposed via a public URL.
	 * Reading it is only possible through the authenticated AJAX endpoint.
	 *
	 * @return string
	 */
	public static function log_url() {
		return '';
	}

	/**
	 * Append a message to the log.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional structured context.
	 */
	public static function log( $message, $context = array() ) {
		if ( ! self::enabled() ) {
			return;
		}

		$line = self::format( $message, $context );
		$file = self::log_path();

		error_log( 'SWI: ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );

		if ( $file ) {
			@file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
		}
	}

	/**
	 * Format a log line.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return string
	 */
	private static function format( $message, $context ) {
		$prefix = '[' . current_time( 'mysql' ) . ']';
		$prefix .= ' [mem:' . round( memory_get_usage() / 1048576, 1 ) . 'M]';

		if ( function_exists( 'get_current_user_id' ) ) {
			$prefix .= ' [uid:' . get_current_user_id() . ']';
		}

		$line = $prefix . ' ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $context );
		}

		return $line;
	}

	/**
	 * Return the tail of the log file.
	 *
	 * @param int $lines Number of lines.
	 * @return string
	 */
	public static function tail( $lines = 400 ) {
		$file = self::log_path();
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}

		$content = (string) @file_get_contents( $file );
		if ( '' === $content ) {
			return '';
		}

		$parts = explode( "\n", $content );
		$parts = array_slice( $parts, -max( 1, (int) $lines ) );
		return implode( "\n", $parts );
	}

	/**
	 * Clear the log file.
	 */
	public static function clear() {
		$file = self::log_path();
		if ( $file ) {
			@file_put_contents( $file, '' );
		}
	}
}
