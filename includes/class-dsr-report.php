<?php
/**
 * Per-item report of an applied run, built from its backup rows:
 * which post / field / option changed and how many occurrences.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Report {

	const BATCH        = 200;
	const STEP_SECONDS = 8;

	/**
	 * Describe the next batch of changed values of a run.
	 *
	 * @param string $run_id
	 * @param int    $after  Last backup row id already reported.
	 * @return array|WP_Error [ done, next, items ]
	 */
	public static function batch( $run_id, $after ) {
		global $wpdb;
		$run = DSR_Runner::get_run( $run_id );
		if ( ! $run || 'db' !== $run['type'] ) {
			return new WP_Error( 'dsr_bad_run', 'Unknown run.' );
		}
		if ( ! DSR_Runner::can_restore( $run ) ) {
			return new WP_Error( 'dsr_no_backup', 'This run has no backup left to report on (it was undone or its backup was deleted).' );
		}

		$bt       = DSR_DB::backup_table();
		$replacer = new DSR_Replacer( $run['rules'] );
		$started  = microtime( true );
		$items    = array();
		$done     = false;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, table_name, pk_val, col_name, old_value FROM $bt WHERE run_id = %s AND id > %d ORDER BY id ASC LIMIT %d", $run_id, $after, self::BATCH ), ARRAY_A ); // phpcs:ignore
			if ( count( $rows ) < self::BATCH ) {
				$done = true;
			}
			$described = self::describe( $rows );
			foreach ( $rows as $row ) {
				$before = $replacer->count;
				$replacer->replace_value( $row['old_value'] );
				$key     = $row['table_name'] . ':' . $row['pk_val'];
				$items[] = array_merge(
					array(
						'table'       => $row['table_name'],
						'id'          => $row['pk_val'],
						'column'      => $row['col_name'],
						'occurrences' => $replacer->count - $before,
					),
					isset( $described[ $key ] ) ? $described[ $key ] : array()
				);
				$after = (int) $row['id'];
			}
		} while ( ! $done && microtime( true ) - $started < self::STEP_SECONDS );

		return array(
			'done'  => $done,
			'next'  => $after,
			'items' => $items,
		);
	}

	/**
	 * Look up human-readable context for a batch of changed rows.
	 *
	 * @return array "table:id" => [ group, label, post_type, title, edit ]
	 */
	private static function describe( array $rows ) {
		global $wpdb;
		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ $row['table_name'] ][] = $row['pk_val'];
		}

		$out   = array();
		$posts = array();

		// Post meta: which field on which post.
		if ( ! empty( $ids[ $wpdb->postmeta ] ) ) {
			foreach ( self::fetch( "SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_id IN (%s)", $ids[ $wpdb->postmeta ] ) as $m ) {
				$out[ $wpdb->postmeta . ':' . $m['meta_id'] ] = array(
					'label'   => $m['meta_key'],
					'post_id' => (int) $m['post_id'],
				);
				$posts[] = (int) $m['post_id'];
			}
		}
		if ( ! empty( $ids[ $wpdb->posts ] ) ) {
			foreach ( $ids[ $wpdb->posts ] as $id ) {
				$out[ $wpdb->posts . ':' . $id ] = array( 'post_id' => (int) $id );
				$posts[]                         = (int) $id;
			}
		}

		// Posts (and the live page a revision belongs to).
		$info = array();
		if ( $posts ) {
			$found   = self::fetch( "SELECT ID, post_title, post_type, post_parent FROM {$wpdb->posts} WHERE ID IN (%s)", array_unique( $posts ) );
			$parents = array();
			foreach ( $found as $p ) {
				$info[ $p['ID'] ] = $p;
				if ( 'revision' === $p['post_type'] && $p['post_parent'] ) {
					$parents[] = (int) $p['post_parent'];
				}
			}
			if ( $parents ) {
				foreach ( self::fetch( "SELECT ID, post_title, post_type, post_parent FROM {$wpdb->posts} WHERE ID IN (%s)", array_unique( $parents ) ) as $p ) {
					$info[ $p['ID'] ] = $p;
				}
			}
		}
		foreach ( $out as $key => $entry ) {
			if ( empty( $entry['post_id'] ) || ! isset( $info[ $entry['post_id'] ] ) ) {
				continue;
			}
			$p      = $info[ $entry['post_id'] ];
			$is_rev = 'revision' === $p['post_type'];
			$live   = $is_rev && isset( $info[ $p['post_parent'] ] ) ? $info[ $p['post_parent'] ] : $p;

			$out[ $key ]['post_type'] = $is_rev ? 'revision (' . $live['post_type'] . ')' : $p['post_type'];
			$out[ $key ]['title']     = $live['post_title'];
			$out[ $key ]['edit']      = admin_url( 'post.php?post=' . (int) $live['ID'] . '&action=edit' );
		}

		// Options: option name.
		if ( ! empty( $ids[ $wpdb->options ] ) ) {
			foreach ( self::fetch( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id IN (%s)", $ids[ $wpdb->options ] ) as $o ) {
				$out[ $wpdb->options . ':' . $o['option_id'] ] = array( 'label' => $o['option_name'] );
			}
		}

		// Terms: term name.
		if ( ! empty( $ids[ $wpdb->term_taxonomy ] ) ) {
			foreach ( self::fetch( "SELECT tt.term_taxonomy_id, tt.taxonomy, t.name FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.term_taxonomy_id IN (%s)", $ids[ $wpdb->term_taxonomy ] ) as $t ) {
				$out[ $wpdb->term_taxonomy . ':' . $t['term_taxonomy_id'] ] = array( 'label' => $t['taxonomy'] . ': ' . $t['name'] );
			}
		}

		// Yoast SEO: the URL the title/description belongs to.
		$yoast = $wpdb->prefix . 'yoast_indexable';
		if ( ! empty( $ids[ $yoast ] ) ) {
			foreach ( self::fetch( "SELECT id, permalink, object_type, object_sub_type FROM $yoast WHERE id IN (%s)", $ids[ $yoast ] ) as $y ) {
				$out[ $yoast . ':' . $y['id'] ] = array( 'label' => trim( $y['object_type'] . ' ' . $y['object_sub_type'] ) . ': ' . $y['permalink'] );
			}
		}

		return $out;
	}

	/** Run a "... IN (%s)" query with the given ids. */
	public static function fetch( $sql, array $ids, $output = ARRAY_A ) {
		global $wpdb;
		$ids = array_values( $ids );
		if ( ! $ids ) {
			return array();
		}
		$sql = str_replace( '%s', implode( ', ', array_fill( 0, count( $ids ), '%s' ) ), $sql );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $ids ), $output ); // phpcs:ignore
	}
}
