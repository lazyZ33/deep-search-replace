<?php
/**
 * Remove plugin data. Backed-up file copies in uploads/dsr-backups are left in place on purpose.
 *
 * @package DeepSearchReplace
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dsr_backup" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dsr\\_%'" ); // phpcs:ignore
