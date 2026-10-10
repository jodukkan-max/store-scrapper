<?php
/**
 * Uninstall handler: remove the custom table and plugin options.
 *
 * @package SWI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'swi_products';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( 'swi_options' );
delete_option( 'swi_db_version' );

// Clean up transients (in case any job is mid-flight).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_swi_%' OR option_name LIKE '%_transient_timeout_swi_%'" );
