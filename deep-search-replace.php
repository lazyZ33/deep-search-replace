<?php
/**
 * Plugin Name:       Deep Search & Replace
 * Description:       Site-wide search & replace that understands where content really lives: all post types, Gutenberg blocks, ACF fields, options & blocks, Elementor data, serialized and JSON data, plus theme template files and a live output filter. Dry run, backups and one-click undo.
 * Version:           1.3.5
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * License:           GPL-2.0-or-later
 * Text Domain:       deep-search-replace
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

define( 'DSR_VERSION', '1.3.5' );
define( 'DSR_FILE', __FILE__ );
define( 'DSR_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSR_URL', plugin_dir_url( __FILE__ ) );

require_once DSR_DIR . 'includes/class-dsr-replacer.php';
require_once DSR_DIR . 'includes/class-dsr-db.php';
require_once DSR_DIR . 'includes/class-dsr-files.php';
require_once DSR_DIR . 'includes/class-dsr-cache.php';
require_once DSR_DIR . 'includes/class-dsr-runner.php';
require_once DSR_DIR . 'includes/class-dsr-output.php';
require_once DSR_DIR . 'includes/class-dsr-report.php';
require_once DSR_DIR . 'includes/class-dsr-objects.php';

class DSR_Plugin {

	const SETTINGS = 'dsr_settings';

	public static function init() {
		// Upgrading the backup table can take a while on big sites: never on a visitor's request.
		if ( get_option( 'dsr_db_version' ) !== DSR_VERSION && ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			self::activate();
		}
		DSR_Output::init();

		if ( is_admin() ) {
			require_once DSR_DIR . 'includes/class-dsr-admin.php';
			DSR_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once DSR_DIR . 'includes/class-dsr-cli.php';
			WP_CLI::add_command( 'dsr', 'DSR_CLI' );
		}
	}

	public static function activate() {
		// One upgrade at a time: concurrent admin requests must not run the same ALTER TABLE.
		$lock = (int) get_option( 'dsr_upgrade_lock', 0 );
		if ( $lock && time() - $lock < 600 ) {
			return;
		}
		update_option( 'dsr_upgrade_lock', time(), false );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		ignore_user_abort( true );
		if ( DSR_DB::install() ) {
			update_option( 'dsr_db_version', DSR_VERSION, false );
		}
		delete_option( 'dsr_upgrade_lock' );
	}

	/** The em dash preset that started this plugin. */
	public static function default_rules() {
		return array(
			array(
				'search'        => "\xE2\x80\x94",
				'replace'       => ', ',
				'encoded'       => true,
				'absorb_spaces' => true,
				'drop_edges'    => true,
			),
		);
	}

	public static function settings() {
		$settings = get_option( self::SETTINGS, array() );
		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			array(
				'rules'        => self::default_rules(),
				'live_enabled' => false,
				'live_rules'   => array(),
			)
		);
	}

	public static function update_settings( array $changes ) {
		update_option( self::SETTINGS, array_merge( self::settings(), $changes ), false );
	}
}

register_activation_hook( __FILE__, array( 'DSR_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'DSR_Plugin', 'init' ) );
