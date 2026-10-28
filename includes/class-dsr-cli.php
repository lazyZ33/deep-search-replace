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
	 * [--url-map=<file>]
	 * : CSV of "old URL,new URL" lines. Each old URL is matched as a whole URL only (never
	 * a longer URL that starts the same way), chains are followed, redirect tables are never changed.
	 * Without --search, only the URL map runs. Without --tables, only content tables are scanned.
	 *
	 * [--domains=<list>]
	 * : Comma-separated domains that count as this site for --url-map (the site's own domain is always included).
	 *
	 * [--links-csv=<file>]
	 * : With --url-map: write every link found or replaced (old, new, times) to this CSV file.
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
	 *     wp dsr replace --url-map=redirects.csv --domains=example.com --dry-run --links-csv=links.csv
	 *
	 * @when after_wp_load
	 */
	public function replace( $args, $assoc ) {
		$url_rule = null;
		if ( isset( $assoc['url-map'] ) ) {
			$file = (string) $assoc['url-map'];
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( "Cannot read $file" );
			}
			$url_rule = array(
				'type'    => 'urls',
				'search'  => 'URL map',
				'replace' => '',
				'csv'     => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'domains' => isset( $assoc['domains'] ) ? (string) $assoc['domains'] : '',
			);
			$map = new DSR_Url_Map( $url_rule['csv'], $url_rule['domains'] );
			foreach ( $map->problems as $problem ) {
				WP_CLI::warning( $problem );
			}
			foreach ( $map->chains as $chain ) {
				WP_CLI::log( "Chain followed: $chain" );
			}
			if ( ! $map->count() ) {
				WP_CLI::error( 'The URL map has no usable lines.' );
			}
			WP_CLI::log( sprintf( 'URL map: %d links, domains: %s', $map->count(), implode( ', ', $map->hosts() ) ) );
		}

		$preset = ! isset( $assoc['search'] ) && null === $url_rule;
		$rules  = ! isset( $assoc['search'] ) ? ( $preset ? DSR_Plugin::default_rules() : array() ) : array(
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
		if ( null !== $url_rule ) {
			$rules[] = $url_rule;
		}

		$dry    = ! empty( $assoc['dry-run'] );
		if ( null !== $url_rule && ! isset( $assoc['tables'] ) ) {
			// Links live in content tables; logs and form entries keep the URLs they recorded.
			require_once DSR_DIR . 'includes/class-dsr-admin.php';
		}
		$tables = isset( $assoc['tables'] )
			? array_map( 'trim', explode( ',', $assoc['tables'] ) )
			: array_keys( array_filter( DSR_DB::get_tables(), function ( $info, $name ) use ( $url_rule ) {
				return (bool) DSR_DB::get_schema( $name ) && ( null === $url_rule || DSR_Admin::is_content_table( $name ) );
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
		$rows  = array();
		$links = array();
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
				foreach ( $r['links'] as $link => $n ) {
					$links[ $link ] = ( isset( $links[ $link ] ) ? $links[ $link ] : 0 ) + $n;
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

		if ( $links ) {
			WP_CLI::log( sprintf( '%d distinct links %s.', count( $links ), $dry ? 'found' : 'replaced' ) );
			if ( ! empty( $assoc['links-csv'] ) ) {
				$fh = fopen( (string) $assoc['links-csv'], 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $fh ) {
					WP_CLI::warning( 'Cannot write ' . $assoc['links-csv'] );
				} else {
					fputcsv( $fh, array( 'old_link', 'new_link', 'times' ), ',', '"', '\\' );
					ksort( $links );
					foreach ( $links as $link => $n ) {
						$parts = explode( "\t", $link );
						fputcsv( $fh, array( $parts[0], $parts[1], $n ), ',', '"', '\\' );
					}
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					WP_CLI::log( 'Links written to ' . $assoc['links-csv'] );
				}
			}
		}

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
