<?php
/**
 * Database scanning / replacing, batched by primary-key windows, with per-cell backups.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_DB {

	const WINDOW          = 2000;
	const UNDO_BATCH      = 300;
	const SAMPLES_PER_TBL = 5;

	/** Columns that identify things (slugs, keys, types, credentials) and must never be rewritten. */
	const SKIP_COLUMNS = array(
		'guid', 'post_name', 'post_type', 'post_status', 'post_mime_type', 'post_password', 'ping_status',
		'comment_status', 'to_ping', 'pinged', 'meta_key', 'option_name', 'autoload', 'user_pass', 'user_login',
		'user_email', 'user_activation_key', 'user_nicename', 'user_url', 'slug', 'taxonomy', 'comment_author_email',
		'comment_author_IP', 'comment_agent', 'comment_type', 'comment_approved', 'session_id', 'session_key',
	);

	/**
	 * Redirect storage of SEO / redirect plugins. Rewriting it would change the redirect
	 * sources themselves (breaking the redirects, or making them loop), so it is never touched,
	 * whatever is ticked. Table names are without the prefix.
	 */
	const PROTECTED_TABLES = array(
		'rank_math_redirections',
		'rank_math_redirections_cache',
		'redirection_items',
		'redirection_groups',
		'redirection_404',
		'redirection_logs',
		'eps_redirects',
		'yoast_premium_redirects',
	);

	/** Options holding redirects (Yoast Premium, Simple 301 Redirects). */
	const PROTECTED_OPTIONS = array(
		'wpseo-premium-redirects-base',
		'wpseo-premium-redirects-export-plain',
		'wpseo-premium-redirects-export-regex',
		'wpseo_redirect',
		'301_redirects',
	);

	/** Post types that are redirect rules (Safe Redirect Manager, Rank Math legacy). Their posts and meta are skipped. */
	const PROTECTED_POST_TYPES = array( 'redirect_rule', 'rank_math_redirect' );

	public static function is_protected_table( $table ) {
		global $wpdb;
		if ( 0 !== strpos( $table, $wpdb->prefix ) ) {
			return false;
		}
		return in_array( substr( $table, strlen( $wpdb->prefix ) ), self::PROTECTED_TABLES, true );
	}

	public static function backup_table() {
		global $wpdb;
		return $wpdb->prefix . 'dsr_backup';
	}

	/**
	 * Each backup row also records the item it belongs to (object_type + object_id, see DSR_Objects),
	 * so one page can be listed and restored through the run_object index, without joins.
	 *
	 * @return bool Whether the table is ready.
	 */
	public static function install() {
		global $wpdb;
		$table = self::backup_table();
		if ( self::table_exists( $table ) ) {
			return self::upgrade_schema();
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(40) NOT NULL,
				table_name varchar(191) NOT NULL,
				pk_col varchar(64) NOT NULL,
				pk_val varchar(191) NOT NULL,
				col_name varchar(64) NOT NULL,
				old_value longblob,
				new_hash char(32) NOT NULL,
				object_type varchar(64) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY run_object (run_id,object_type,object_id)
			) {$wpdb->get_charset_collate()};"
		);
		return self::table_exists( $table );
	}

	/**
	 * Add the item columns to a backup table from an older version. That table can be large
	 * (one longblob per changed value), so one statement for the columns (instant on MySQL 8)
	 * and one for the index (built in place, no table copy), where dbDelta would issue one
	 * ALTER per change.
	 *
	 * @return bool
	 */
	private static function upgrade_schema() {
		global $wpdb;
		$qt          = self::quote( self::backup_table() );
		$has_columns = function () use ( $wpdb, $qt ) {
			return in_array( 'object_type', (array) $wpdb->get_col( "SHOW COLUMNS FROM $qt" ), true ); // phpcs:ignore
		};
		$has_index   = function () use ( $wpdb, $qt ) {
			return (bool) $wpdb->get_var( "SHOW INDEX FROM $qt WHERE Key_name = 'run_object'" ); // phpcs:ignore
		};
		if ( ! $has_columns() ) {
			$wpdb->query( "ALTER TABLE $qt ADD COLUMN object_type varchar(64) NOT NULL DEFAULT '', ADD COLUMN object_id bigint(20) unsigned NOT NULL DEFAULT 0" ); // phpcs:ignore
		}
		if ( $has_columns() && ! $has_index() ) {
			$wpdb->query( "ALTER TABLE $qt ADD INDEX run_object (run_id, object_type, object_id)" ); // phpcs:ignore
		}
		return $has_columns() && $has_index();
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Tables belonging to this site, with size info.
	 *
	 * @return array name => [ rows, size ]
	 */
	public static function get_tables() {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = array();
		foreach ( (array) $rows as $row ) {
			$name = $row['Name'];
			if ( self::backup_table() === $name ) {
				continue;
			}
			// On the main site of a multisite, skip the other sites' tables (wp_2_posts...).
			if ( is_multisite() && $wpdb->prefix === $wpdb->base_prefix && preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $name ) ) {
				continue;
			}
			$tables[ $name ] = array(
				'rows' => (int) $row['Rows'],
				'size' => (int) $row['Data_length'] + (int) $row['Index_length'],
			);
		}
		return $tables;
	}

	/**
	 * @return array|null [ 'pk' => col, 'cols' => text columns ] or null when the table can't be processed safely.
	 */
	public static function get_schema( $table ) {
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		$qtable  = self::quote( $table ); // Backtick-escaped identifier; $wpdb->prepare() cannot bind identifiers on WP < 6.2.
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $qtable, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$pks     = array();
		$cols    = array();
		foreach ( (array) $columns as $c ) {
			if ( 'PRI' === $c['Key'] ) {
				$pks[] = $c['Field'];
				continue;
			}
			if ( preg_match( '/char|text|blob|json/i', $c['Type'] ) && ! in_array( $c['Field'], self::SKIP_COLUMNS, true ) ) {
				$cols[] = $c['Field'];
			}
		}
		$cache[ $table ] = ( 1 === count( $pks ) && $cols ) ? array(
			'pk'   => $pks[0],
			'cols' => $cols,
		) : null;
		return $cache[ $table ];
	}

	/** Distinct post types present in the posts table, with counts. */
	public static function get_post_types() {
		global $wpdb;
		static $types = null;
		if ( null === $types ) {
			$types = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type ORDER BY post_type", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $types;
	}

	/**
	 * Scan (and in apply mode, replace) one primary-key window of a table.
	 *
	 * @param array         $run      Run config.
	 * @param string        $table
	 * @param string|null   $cursor   Last primary key processed.
	 * @param DSR_Replacer  $replacer
	 * @return array
	 */
	public static function process_window( array $run, $table, $cursor, DSR_Replacer $replacer ) {
		global $wpdb;

		$result = array(
			'done'         => true,
			'cursor'       => null,
			'rows'         => 0,
			'cells'        => 0,
			'replacements' => 0,
			'failed'       => 0,
			'samples'      => array(),
			'errors'       => array(),
			'skipped'      => '',
			'scanned'      => 0,
			'links'        => array(),
		);

		if ( self::is_protected_table( $table ) ) {
			$result['skipped'] = 'redirect data, never changed';
			return $result;
		}

		$schema = self::get_schema( $table );
		if ( ! $schema ) {
			$result['skipped'] = 'no single-column primary key or no text columns';
			return $result;
		}

		$pk    = $schema['pk'];
		$qpk   = self::quote( $pk );
		$qt    = self::quote( $table );
		$where = array();
		$args  = array();

		if ( null !== $cursor && '' !== $cursor ) {
			$where[] = "$qpk > %s";
			$args[]  = $cursor;
		}
		$window  = ! empty( $run['window'] ) ? (int) $run['window'] : self::WINDOW;
		$end_sql = "SELECT $qpk FROM $qt" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . " ORDER BY $qpk ASC LIMIT 1 OFFSET " . ( $window - 1 );
		$end     = $wpdb->get_var( $args ? $wpdb->prepare( $end_sql, $args ) : $end_sql ); // phpcs:ignore
		if ( null !== $end ) {
			$where[]           = "$qpk <= %s";
			$args[]            = $end;
			$result['done']    = false;
			$result['cursor']  = $end;
			$result['scanned'] = $window;
		}

		self::add_table_filters( $run, $table, $where, $args );

		// Only fetch rows that can contain a match. A large URL map has too many needles for
		// LIKEs to help, so then every row of the window is fetched and checked in PHP.
		$needles = $replacer->sql_needles();
		if ( null !== $needles ) {
			$likes = array();
			foreach ( $schema['cols'] as $col ) {
				foreach ( self::sql_needles( $needles ) as $needle ) {
					$likes[] = 'CAST(' . self::quote( $col ) . ' AS BINARY) LIKE %s';
					$args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
				}
			}
			$where[] = $likes ? '(' . implode( ' OR ', $likes ) . ')' : '0 = 1';
		}

		$select = $qpk . ', ' . implode( ', ', array_map( array( __CLASS__, 'quote' ), $schema['cols'] ) );
		$sql    = "SELECT $select FROM $qt WHERE " . ( $where ? implode( ' AND ', $where ) : '1 = 1' ) . " ORDER BY $qpk ASC";
		$rows   = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore

		if ( $wpdb->last_error ) {
			$result['errors'][] = $wpdb->last_error;
		}

		// Which page / option / term each row belongs to: a few lookups per window, not per cell.
		$objects = ( 'apply' === $run['mode'] && $rows ) ? DSR_Objects::resolve( $table, wp_list_pluck( $rows, $pk ) ) : array();

		foreach ( (array) $rows as $row ) {
			$pk_val      = $row[ $pk ];
			$row_changed = false;
			foreach ( $schema['cols'] as $col ) {
				$old = $row[ $col ];
				if ( null === $old ) {
					continue;
				}
				$before_count = $replacer->count;
				$new          = $replacer->replace_value( $old );
				if ( $new === $old ) {
					continue;
				}
				$row_changed = true;
				$result['cells']++;
				$result['replacements'] += $replacer->count - $before_count;

				if ( count( $result['samples'] ) < self::SAMPLES_PER_TBL ) {
					$result['samples'][] = self::sample( $table, $pk_val, $col, $old, $new );
				}

				if ( 'apply' === $run['mode'] && ! self::write_cell( $run['id'], $table, $pk, $pk_val, $col, $old, $new, $objects[ (string) $pk_val ] ) ) {
					$result['failed']++;
					$result['errors'][] = "Could not update $table.$col where $pk = $pk_val" . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '' );
				}
			}
			if ( $row_changed ) {
				$result['rows']++;
			}
		}

		foreach ( array_unique( $replacer->errors ) as $error ) {
			$result['errors'][] = "$table: $error";
		}
		$replacer->errors = array();

		$result['links']  = $replacer->links;
		$replacer->links  = array();

		return $result;
	}

	/**
	 * The needles worth a LIKE: one that contains another needle ("\\u2014" contains "—")
	 * can only match rows the shorter one matches already. Fewer LIKEs, less database CPU.
	 *
	 * @param string[] $needles
	 * @return string[]
	 */
	private static function sql_needles( array $needles ) {
		$out = array();
		foreach ( $needles as $needle ) {
			foreach ( $needles as $other ) {
				if ( $other !== $needle && false !== strpos( $needle, $other ) ) {
					continue 2;
				}
			}
			$out[] = $needle;
		}
		return $out;
	}

	/** Post-type filtering for the posts and postmeta tables. */
	private static function add_table_filters( array $run, $table, array &$where, array &$args ) {
		global $wpdb;
		if ( $table === $wpdb->options ) {
			// Never touch this plugin's own settings / run state (they contain the search terms),
			// nor redirect plugins' stored redirects.
			$where[] = "option_name NOT LIKE 'dsr\\_%'";
			$where[] = 'option_name NOT IN (' . implode( ', ', array_fill( 0, count( self::PROTECTED_OPTIONS ), '%s' ) ) . ')';
			$args    = array_merge( $args, self::PROTECTED_OPTIONS );
			return;
		}
		$excluded = isset( $run['exclude_post_types'] ) ? array_values( (array) $run['exclude_post_types'] ) : array();
		$excluded = array_values( array_unique( array_merge( $excluded, self::PROTECTED_POST_TYPES ) ) );
		$in = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
		if ( $table === $wpdb->posts ) {
			$where[] = "post_type NOT IN ($in)";
			$args    = array_merge( $args, $excluded );
		} elseif ( $table === $wpdb->postmeta ) {
			$where[] = "post_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in))";
			$args    = array_merge( $args, $excluded );
		}
	}

	/**
	 * @param array $object [ object_type, object_id ] from DSR_Objects::resolve().
	 */
	private static function write_cell( $run_id, $table, $pk, $pk_val, $col, $old, $new, array $object ) {
		global $wpdb;
		$bt    = self::backup_table();
		$saved = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$bt,
			array(
				'run_id'      => $run_id,
				'table_name'  => $table,
				'pk_col'      => $pk,
				'pk_val'      => (string) $pk_val,
				'col_name'    => $col,
				'old_value'   => $old,
				'new_hash'    => md5( $new ),
				'object_type' => $object[0],
				'object_id'   => $object[1],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( ! $saved ) {
			return false; // No backup, no write.
		}
		if ( DSR_Objects::ROW === $object[0] ) {
			// No numeric owner: the backed-up value is an item of its own.
			$wpdb->update( $bt, array( 'object_id' => $wpdb->insert_id ), array( 'id' => $wpdb->insert_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return false !== $wpdb->update( $table, array( $col => $new ), array( $pk => $pk_val ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Restore one batch of backed-up cells (newest first): of the whole run, or of one item.
	 *
	 * @param string      $run_id
	 * @param string|null $object_type Only restore this item (see DSR_Objects).
	 * @param int         $object_id
	 * @return array [ done, restored, conflicts ]
	 */
	public static function undo_batch( $run_id, $object_type = null, $object_id = 0 ) {
		global $wpdb;
		$bt    = self::backup_table();
		$where = 'run_id = %s';
		$args  = array( $run_id );
		if ( null !== $object_type ) {
			$where .= ' AND object_type = %s AND object_id = %d';
			$args[] = $object_type;
			$args[] = $object_id;
		}
		$args[] = self::UNDO_BATCH;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $bt WHERE $where ORDER BY id DESC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore
		$out  = array(
			'done'      => count( $rows ) < self::UNDO_BATCH,
			'restored'  => 0,
			'conflicts' => array(),
		);
		$tables = self::get_tables();
		foreach ( $rows as $row ) {
			$table = $row['table_name'];
			if ( isset( $tables[ $table ] ) ) {
				$current = $wpdb->get_var( $wpdb->prepare( 'SELECT ' . self::quote( $row['col_name'] ) . ' FROM ' . self::quote( $table ) . ' WHERE ' . self::quote( $row['pk_col'] ) . ' = %s', $row['pk_val'] ) ); // phpcs:ignore
				if ( null !== $current && md5( $current ) !== $row['new_hash'] ) {
					// Edited again since the run: don't clobber newer work.
					$out['conflicts'][] = "{$table}.{$row['col_name']} ({$row['pk_col']} = {$row['pk_val']}) changed since the run, left as is";
				} elseif ( false !== $wpdb->update( $table, array( $row['col_name'] => $row['old_value'] ), array( $row['pk_col'] => $row['pk_val'] ), array( '%s' ), array( '%s' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$out['restored']++;
				}
			}
			$wpdb->delete( $bt, array( 'id' => $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $out;
	}

	public static function delete_backup( $run_id ) {
		global $wpdb;
		$wpdb->delete( self::backup_table(), array( 'run_id' => $run_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public static function has_backup( $run_id ) {
		global $wpdb;
		$bt = self::backup_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $bt WHERE run_id = %s LIMIT 1", $run_id ) ); // phpcs:ignore
	}

	public static function backup_count( $run_id ) {
		global $wpdb;
		$bt = self::backup_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $bt WHERE run_id = %s", $run_id ) ); // phpcs:ignore
	}

	/** Before/after excerpt around the first byte that changed. */
	private static function sample( $table, $pk_val, $col, $old, $new ) {
		$first_diff = strspn( $old ^ $new, "\0" );
		$start      = max( 0, $first_diff - 80 );
		$prefix     = $start > 0 ? '...' : '';
		return array(
			'table'  => $table,
			'id'     => (string) $pk_val,
			'column' => $col,
			'before' => $prefix . wp_check_invalid_utf8( substr( $old, $start, 200 ), true ),
			'after'  => $prefix . wp_check_invalid_utf8( substr( $new, $start, 200 + strlen( $new ) - strlen( $old ) ), true ),
		);
	}

	public static function quote( $identifier ) {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
