<?php
/**
 * Run lifecycle: create -> step through tables -> finish -> (optional) undo.
 * Shared by the admin AJAX screen and WP-CLI.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Runner {

	const RUNS_OPTION = 'dsr_runs';
	const MAX_HISTORY = 50;

	/** Seconds of work per AJAX request (well under typical 30-60 s host limits). */
	const STEP_SECONDS = 10;

	/**
	 * How hard a run works the server: rows per window, seconds of work per request, and the
	 * pause (ms) the browser leaves between requests so visitors' requests get the CPU too.
	 */
	const SPEEDS = array(
		'gentle' => array( 'window' => 500, 'seconds' => 3, 'pause' => 2000 ),
		'normal' => array( 'window' => 1000, 'seconds' => 5, 'pause' => 700 ),
		'fast'   => array( 'window' => 2000, 'seconds' => 10, 'pause' => 0 ),
	);

	/**
	 * Normalise rules coming from the UI / CLI. Search & replace strings are kept byte-exact.
	 *
	 * @param mixed $raw
	 * @return array[]
	 */
	public static function sanitize_rules( $raw ) {
		$rules = array();
		foreach ( (array) $raw as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['search'] ) || '' === (string) $rule['search'] ) {
				continue;
			}
			$rules[] = array(
				'search'        => (string) $rule['search'],
				'replace'       => isset( $rule['replace'] ) ? (string) $rule['replace'] : '',
				'encoded'       => ! empty( $rule['encoded'] ),
				'absorb_spaces' => ! empty( $rule['absorb_spaces'] ),
				'drop_edges'    => ! empty( $rule['drop_edges'] ),
			);
			if ( count( $rules ) >= 20 ) {
				break;
			}
		}
		return $rules;
	}

	/**
	 * @param array $args rules, mode (dry|apply), tables, include_post_types (null = all but revisions), clear_cache, delete_transients,
	 *                    speed (gentle|normal|fast, see SPEEDS; default normal).
	 * @return array|WP_Error
	 */
	public static function create_db_run( array $args ) {
		$speed = isset( $args['speed'] ) && array_key_exists( (string) $args['speed'], self::SPEEDS ) ? (string) $args['speed'] : 'normal';
		$rules = self::sanitize_rules( isset( $args['rules'] ) ? $args['rules'] : array() );
		if ( ! $rules ) {
			return new WP_Error( 'dsr_no_rules', 'Add at least one search term.' );
		}

		$available = DSR_DB::get_tables();
		$tables    = array();
		foreach ( (array) $args['tables'] as $table ) {
			if ( isset( $available[ $table ] ) ) {
				$tables[] = $table;
			}
		}
		if ( ! $tables ) {
			return new WP_Error( 'dsr_no_tables', 'Select at least one table.' );
		}

		$all_types = wp_list_pluck( DSR_DB::get_post_types(), 'post_type' );
		if ( isset( $args['include_post_types'] ) && is_array( $args['include_post_types'] ) ) {
			$exclude = array_values( array_diff( $all_types, $args['include_post_types'] ) );
		} else {
			$exclude = array_values( array_intersect( $all_types, array( 'revision' ) ) );
		}

		$run = array(
			'id'                 => gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ),
			'type'               => 'db',
			'mode'               => 'apply' === $args['mode'] ? 'apply' : 'dry',
			'created'            => time(),
			'user'               => get_current_user_id(),
			'status'             => 'running',
			'rules'              => $rules,
			'tables'             => $tables,
			'exclude_post_types' => $exclude,
			'clear_cache'        => ! empty( $args['clear_cache'] ),
			'delete_transients'  => ! empty( $args['delete_transients'] ),
			'indexed'            => true, // Backup rows record their item as they are written.
			'speed'              => $speed,
			'window'             => self::SPEEDS[ $speed ]['window'],
			'step_seconds'       => self::SPEEDS[ $speed ]['seconds'],
			'pause'              => self::SPEEDS[ $speed ]['pause'],
			'stats'              => array(),
			'errors'             => array(),
		);
		self::save_run( $run );
		if ( 'apply' === $run['mode'] ) {
			// Listed from the start: if the run is interrupted (page reloaded, request timed out),
			// what it already changed can still be undone from History.
			self::add_to_history( $run['id'] );
		}
		return $run;
	}

	/**
	 * Whether a run has a backup to restore from: a finished run, or a database run that was
	 * interrupted (each value is backed up before it is changed, so a partial run is safe to undo).
	 *
	 * @param array|null $run
	 * @return bool
	 */
	public static function can_restore( $run ) {
		if ( ! $run ) {
			return false;
		}
		return 'done' === $run['status'] || ( 'db' === $run['type'] && 'apply' === $run['mode'] && 'running' === $run['status'] );
	}

	/**
	 * @return array|WP_Error Window result.
	 */
	public static function step( $run_id, $table, $cursor ) {
		$run = self::get_run( $run_id );
		if ( ! $run || 'running' !== $run['status'] ) {
			return new WP_Error( 'dsr_bad_run', 'Unknown or finished run.' );
		}
		if ( ! in_array( $table, $run['tables'], true ) ) {
			return new WP_Error( 'dsr_bad_table', 'Table not part of this run.' );
		}

		// Keep processing windows until the time budget is used up: one HTTP request per
		// small window is far too slow on sites with millions of meta rows.
		$replacer = new DSR_Replacer( $run['rules'] );
		$budget   = ! empty( $run['step_seconds'] ) ? (int) $run['step_seconds'] : self::STEP_SECONDS;
		$started  = microtime( true );
		$result   = null;
		do {
			$window = DSR_DB::process_window( $run, $table, $cursor, $replacer );
			if ( null === $result ) {
				$result = $window;
			} else {
				foreach ( array( 'rows', 'cells', 'replacements', 'failed', 'scanned' ) as $key ) {
					$result[ $key ] += $window[ $key ];
				}
				$result['samples'] = array_slice( array_merge( $result['samples'], $window['samples'] ), 0, DSR_DB::SAMPLES_PER_TBL );
				$result['errors']  = array_merge( $result['errors'], $window['errors'] );
				$result['done']    = $window['done'];
				$result['cursor']  = $window['cursor'];
			}
			$cursor = $window['cursor'];
		} while ( ! $window['done'] && microtime( true ) - $started < $budget );

		if ( ! isset( $run['stats'][ $table ] ) ) {
			$run['stats'][ $table ] = array(
				'rows'         => 0,
				'cells'        => 0,
				'replacements' => 0,
				'failed'       => 0,
			);
		}
		foreach ( array( 'rows', 'cells', 'replacements', 'failed' ) as $key ) {
			$run['stats'][ $table ][ $key ] += $result[ $key ];
		}
		$run['errors'] = array_slice( array_merge( $run['errors'], $result['errors'] ), 0, 200 );
		self::save_run( $run );

		return $result;
	}

	/**
	 * @return array|WP_Error Summary.
	 */
	public static function finish( $run_id ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'dsr_bad_run', 'Unknown run.' );
		}
		$run['status']   = 'done';
		$run['finished'] = time();
		$purged          = array();

		if ( 'apply' === $run['mode'] ) {
			if ( $run['clear_cache'] ) {
				$purged = DSR_Cache::purge_all( $run['delete_transients'] );
			}
			$run['purged'] = $purged;
			self::save_run( $run );
			self::add_to_history( $run['id'] );
		} else {
			delete_option( 'dsr_run_' . $run['id'] );
		}

		return array(
			'run'    => $run,
			'purged' => $purged,
		);
	}

	/**
	 * Undo one batch of a run (DB runs need several calls; file runs finish in one).
	 *
	 * @return array|WP_Error [ done, restored, conflicts ]
	 */
	public static function undo_step( $run_id ) {
		$run = self::get_run( $run_id );
		if ( ! self::can_restore( $run ) ) {
			return new WP_Error( 'dsr_bad_run', 'This run cannot be undone.' );
		}

		if ( 'files' === $run['type'] ) {
			$result = DSR_Files::undo( $run );
		} else {
			$result = DSR_DB::undo_batch( $run_id );
		}

		if ( $result['done'] ) {
			$run['status'] = 'undone';
			$run['undone'] = time();
			self::save_run( $run );
			DSR_Cache::purge_all( false );
		}
		return $result;
	}

	/**
	 * Make sure every backup row of a run knows its item (runs made before 1.3.0 don't).
	 * Call until done; then the item types are returned.
	 *
	 * @return array|WP_Error [ done, pending, types ]
	 */
	public static function index_items( $run_id ) {
		$run = self::get_run( $run_id );
		if ( ! self::can_restore( $run ) || 'db' !== $run['type'] ) {
			return new WP_Error( 'dsr_bad_run', 'This run has no backup left to restore from.' );
		}
		if ( empty( $run['indexed'] ) ) {
			$result = DSR_Objects::index_step( $run_id );
			if ( is_wp_error( $result ) || ! $result['done'] ) {
				return $result;
			}
			$run['indexed'] = true;
			self::save_run( $run );
		}
		return array(
			'done'    => true,
			'pending' => 0,
			'types'   => DSR_Objects::types( $run_id ),
		);
	}

	/**
	 * Restore one item of a run (a page with all its fields, an option, a term...), one batch per call.
	 * The rest of the run stays replaced and can still be undone or restored item by item.
	 *
	 * @return array|WP_Error [ done, restored, conflicts, status ]
	 */
	public static function restore_item( $run_id, $object_type, $object_id ) {
		$run = self::get_run( $run_id );
		if ( ! self::can_restore( $run ) || 'db' !== $run['type'] ) {
			return new WP_Error( 'dsr_bad_run', 'This run has no backup left to restore from.' );
		}
		if ( empty( $run['indexed'] ) ) {
			return new WP_Error( 'dsr_not_indexed', 'Open the page list of this run first.' );
		}

		$result = DSR_DB::undo_batch( $run_id, $object_type, $object_id );
		if ( $result['done'] ) {
			if ( 'post' === $object_type ) {
				DSR_Cache::purge_post( $object_id );
			} else {
				DSR_Cache::purge_all( false ); // Options and terms show up site-wide.
			}
			$run['restored_items'] = ( isset( $run['restored_items'] ) ? (int) $run['restored_items'] : 0 ) + 1;
			if ( ! DSR_DB::has_backup( $run_id ) ) {
				$run['status'] = 'undone';
				$run['undone'] = time();
			}
			self::save_run( $run );
		}
		$result['status'] = $run['status'];
		return $result;
	}

	public static function delete_backup( $run_id ) {
		$run = self::get_run( $run_id );
		if ( ! $run ) {
			return;
		}
		if ( 'files' === $run['type'] ) {
			DSR_Files::delete_backup( $run );
		} else {
			DSR_DB::delete_backup( $run_id );
		}
		$run['status'] = 'backup-deleted';
		self::save_run( $run );
	}

	public static function get_run( $run_id ) {
		if ( ! is_string( $run_id ) || ! preg_match( '/^[0-9A-Za-z-]+$/', $run_id ) ) {
			return null;
		}
		$run = get_option( 'dsr_run_' . $run_id );
		return is_array( $run ) ? $run : null;
	}

	public static function save_run( array $run ) {
		update_option( 'dsr_run_' . $run['id'], $run, false );
	}

	public static function add_to_history( $run_id ) {
		$ids = (array) get_option( self::RUNS_OPTION, array() );
		array_unshift( $ids, $run_id );
		$ids = array_values( array_unique( $ids ) );
		foreach ( array_slice( $ids, self::MAX_HISTORY ) as $old ) {
			self::delete_backup( $old );
			delete_option( 'dsr_run_' . $old );
		}
		update_option( self::RUNS_OPTION, array_slice( $ids, 0, self::MAX_HISTORY ), false );
	}

	public static function describe_rules( $rules ) {
		if ( ! $rules ) {
			return 'none';
		}
		$parts = array();
		foreach ( $rules as $rule ) {
			$parts[] = '"' . $rule['search'] . '" → "' . $rule['replace'] . '"';
		}
		return implode( ', ', $parts );
	}

	/** @return array[] Runs, newest first. */
	public static function history() {
		global $wpdb;
		$ids = (array) get_option( self::RUNS_OPTION, array() );

		// Runs interrupted before version 1.3.4 were never listed: pick them up so they can be undone.
		foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'dsr\\_run\\_%'" ) as $name ) {
			$id  = substr( $name, strlen( 'dsr_run_' ) );
			$run = in_array( $id, $ids, true ) ? null : self::get_run( $id );
			if ( $run && 'running' === $run['status'] && 'db' === $run['type'] && 'apply' === $run['mode'] ) {
				self::add_to_history( $id );
				$ids = (array) get_option( self::RUNS_OPTION, array() );
			}
		}

		$runs = array();
		foreach ( $ids as $id ) {
			$run = self::get_run( $id );
			if ( $run ) {
				$runs[] = $run;
			}
		}
		return $runs;
	}
}
