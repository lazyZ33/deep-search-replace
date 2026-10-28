<?php
/**
 * WP-CLI: wp dsr replace / wp dsr undo / wp dsr history
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_CLI {

	/**
	 * Search & replace across the database (and optionally theme files).
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Text to find. Defaults to the em dash.
	 *
	 * [--replace=<text>]
	 * : Replacement. Defaults to ", ".
	 *
	 * [--no-encoded]
	 * : Only match the literal text, not its HTML-entity / JSON-escaped forms.
	 *
	 * [--absorb-spaces]
	 * : Swallow spaces around each match. On by default for the em dash preset.
	 *
	 * [--tables=<list>]
	 * : Comma-separated table names. Defaults to every table with a primary key.
	 *
	 * [--include-revisions]
	 * : Also rewrite post revisions.
	 *
	 * [--files]
	 * : Also replace in the active (and parent) theme files.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * [--no-cache-clear]
	 * : Skip clearing Elementor / page / object caches afterwards.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dsr replace --dry-run
	 *     wp dsr replace
	 *     wp dsr replace --search="Acme Inc" --replace="Acme LLC" --files
	 *
	 * @when after_wp_load
	 */
	public function replace( $args, $assoc ) {
		$preset = ! isset( $assoc['search'] );
		$rules  = $preset ? DSR_Plugin::default_rules() : array(
			array(
				'search'        => $assoc['search'],
				'replace'       => isset( $assoc['replace'] ) ? $assoc['replace'] : '',
				'encoded'       => ! isset( $assoc['encoded'] ) || false !== $assoc['encoded'],
				'absorb_spaces' => ! empty( $assoc['absorb-spaces'] ),
			),
		);
		if ( $preset && isset( $assoc['replace'] ) ) {
			$rules[0]['replace'] = $assoc['replace'];
		}

		$dry    = ! empty( $assoc['dry-run'] );
		$tables = isset( $assoc['tables'] )
			? array_map( 'trim', explode( ',', $assoc['tables'] ) )
			: array_keys( array_filter( DSR_DB::get_tables(), function ( $info, $name ) {
				return (bool) DSR_DB::get_schema( $name );
			}, ARRAY_FILTER_USE_BOTH ) );

		$create = array(
			'rules'       => $rules,
			'mode'        => $dry ? 'dry' : 'apply',
			'tables'      => $tables,
			'clear_cache' => ! isset( $assoc['cache-clear'] ) || false !== $assoc['cache-clear'],
		);
		if ( ! empty( $assoc['include-revisions'] ) ) {
			$create['include_post_types'] = wp_list_pluck( DSR_DB::get_post_types(), 'post_type' );
		}
		$run = DSR_Runner::create_db_run( $create );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}

		WP_CLI::log( sprintf( '%s %s in %d tables...', $dry ? 'Scanning for' : 'Replacing', DSR_Runner::describe_rules( $rules ), count( $run['tables'] ) ) );
		$rows = array();
		foreach ( $run['tables'] as $table ) {
			$cursor = null;
			$sum    = array( 'table' => $table, 'rows' => 0, 'values' => 0, 'replacements' => 0, 'failed' => 0 );
			do {
				$r = DSR_Runner::step( $run['id'], $table, $cursor );
				if ( is_wp_error( $r ) ) {
					WP_CLI::error( $r->get_error_message() );
				}
				$sum['rows']         += $r['rows'];
				$sum['values']       += $r['cells'];
				$sum['replacements'] += $r['replacements'];
				$sum['failed']       += $r['failed'];
				foreach ( $r['errors'] as $error ) {
					WP_CLI::warning( $error );
				}
				$cursor = $r['cursor'];
			} while ( ! $r['done'] );
			if ( $sum['values'] ) {
				$rows[] = $sum;
			}
		}
		$summary = DSR_Runner::finish( $run['id'] );

		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'rows', 'values', 'replacements', 'failed' ) );
		}
		$total = array_sum( wp_list_pluck( $rows, 'replacements' ) );

		if ( ! empty( $assoc['files'] ) ) {
			$roots    = DSR_Files::resolve_roots( array( 'theme', 'parent' ) );
			$replacer = new DSR_Replacer( $rules );
			$found    = DSR_Files::scan( $replacer, $roots, DSR_Files::DEFAULT_EXTS );
			foreach ( $found as $file ) {
				WP_CLI::log( sprintf( '  %s: %d', $file['rel'], $file['count'] ) );
			}
			if ( $found && ! $dry ) {
				$file_run          = DSR_Files::apply( new DSR_Replacer( $rules ), $roots, DSR_Files::DEFAULT_EXTS, wp_list_pluck( $found, 'path' ) );
				$file_run['rules'] = $rules;
				DSR_Runner::save_run( $file_run );
				DSR_Runner::add_to_history( $file_run['id'] );
				WP_CLI::log( sprintf( 'Theme files: %d files updated, run %s', $file_run['stats']['files'], $file_run['id'] ) );
			}
		}

		if ( $dry ) {
			WP_CLI::success( "Dry run: $total replacements would be made. Nothing was changed." );
		} else {
			if ( ! empty( $summary['purged'] ) ) {
				WP_CLI::log( 'Cleared: ' . implode( ', ', $summary['purged'] ) );
			}
			WP_CLI::success( "$total replacements made. Undo with: wp dsr undo {$run['id']}" );
		}
	}

	/**
	 * Undo a run.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Run ID from `wp dsr history`.
	 */
	public function undo( $args ) {
		$restored = 0;
		do {
			$r = DSR_Runner::undo_step( $args[0] );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
			$restored += $r['restored'];
			foreach ( $r['conflicts'] as $conflict ) {
				WP_CLI::warning( $conflict );
			}
		} while ( ! $r['done'] );
		WP_CLI::success( "Restored $restored values." );
	}

	/**
	 * Restore the original version of a single page (or option, term...) from a run.
	 * The rest of the run stays replaced.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Run ID from `wp dsr history`.
	 *
	 * <id>
	 * : Post ID (or option / term / user / comment ID with --type).
	 *
	 * [--type=<type>]
	 * : post, option, term, user, comment or a table name.
	 * ---
	 * default: post
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp dsr restore 20261012-101500-AbC123 42
	 */
	public function restore( $args, $assoc ) {
		do {
			$r = DSR_Runner::index_items( $args[0] );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
		} while ( ! $r['done'] );

		$restored = 0;
		do {
			$r = DSR_Runner::restore_item( $args[0], $assoc['type'], (int) $args[1] );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
			$restored += $r['restored'];
			foreach ( $r['conflicts'] as $conflict ) {
				WP_CLI::warning( $conflict );
			}
		} while ( ! $r['done'] );
		WP_CLI::success( "Restored $restored values." );
	}

	/**
	 * List past runs.
	 */
	public function history() {
		$items = array();
		foreach ( DSR_Runner::history() as $run ) {
			$items[] = array(
				'id'     => $run['id'],
				'date'   => wp_date( 'Y-m-d H:i', $run['created'] ),
				'type'   => $run['type'],
				'rules'  => DSR_Runner::describe_rules( $run['rules'] ),
				'status' => $run['status'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'id', 'date', 'type', 'rules', 'status' ) );
	}
}
