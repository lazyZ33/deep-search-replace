<?php
/**
 * Items of a run: the page, option, term, user or comment each backed-up value belongs to,
 * so one item can be listed and restored on its own (History > Restore pages).
 *
 * A post's item covers its own columns, all its meta (Elementor data, ACF fields), its Yoast
 * row and its revisions. Values in other tables form one item per row of that table.
 *
 * Every backup row carries object_type + object_id, indexed together with run_id, so listing
 * and restoring never scan or join the backed-up values.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Objects {

	/** Item type for a value whose row has no numeric key: the backup row is an item of its own. */
	const ROW = 'row';

	const PER_PAGE     = 50;
	const MIN_PER_PAGE = 10;
	const MAX_PER_PAGE = 200;
	const INDEX_BATCH  = 2000;
	const STEP_SECONDS = 8;
	const MAX_FIELDS   = 3000;

	/**
	 * The item each row of a table belongs to.
	 *
	 * @param string   $table
	 * @param string[] $pks Primary key values.
	 * @return array pk => [ object_type, object_id ]
	 */
	public static function resolve( $table, array $pks ) {
		global $wpdb;
		$yoast  = $wpdb->prefix . 'yoast_indexable';
		$direct = array(
			$wpdb->posts    => 'post',
			$wpdb->options  => 'option',
			$wpdb->terms    => 'term',
			$wpdb->users    => 'user',
			$wpdb->comments => 'comment',
		);
		$lookup = array(
			$wpdb->postmeta      => array( 'post', "SELECT meta_id, post_id FROM {$wpdb->postmeta} WHERE meta_id IN (%s)" ),
			$wpdb->termmeta      => array( 'term', "SELECT meta_id, term_id FROM {$wpdb->termmeta} WHERE meta_id IN (%s)" ),
			$wpdb->term_taxonomy => array( 'term', "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN (%s)" ),
			$wpdb->usermeta      => array( 'user', "SELECT umeta_id, user_id FROM {$wpdb->usermeta} WHERE umeta_id IN (%s)" ),
			$wpdb->commentmeta   => array( 'comment', "SELECT meta_id, comment_id FROM {$wpdb->commentmeta} WHERE meta_id IN (%s)" ),
			$yoast               => array( 'post', "SELECT id, object_id FROM $yoast WHERE object_type = 'post' AND id IN (%s)" ),
		);

		$pks    = array_values( array_unique( array_map( 'strval', $pks ) ) );
		$type   = null;
		$owners = array();
		if ( isset( $direct[ $table ] ) ) {
			$type = $direct[ $table ];
			foreach ( $pks as $pk ) {
				if ( ctype_digit( $pk ) ) {
					$owners[ $pk ] = (int) $pk;
				}
			}
		} elseif ( isset( $lookup[ $table ] ) ) {
			$type = $lookup[ $table ][0];
			foreach ( array_chunk( $pks, 1000 ) as $chunk ) {
				foreach ( DSR_Report::fetch( $lookup[ $table ][1], $chunk, ARRAY_N ) as $r ) {
					$owners[ (string) $r[0] ] = (int) $r[1];
				}
			}
		}
		if ( 'post' === $type && $owners ) {
			$owners = self::revisions_to_parents( $owners );
		}

		$out = array();
		foreach ( $pks as $pk ) {
			if ( isset( $owners[ $pk ] ) ) {
				$out[ $pk ] = array( $type, $owners[ $pk ] );
			} elseif ( ctype_digit( $pk ) ) {
				$out[ $pk ] = array( $table, (int) $pk ); // Any other table, or a meta row whose owner is gone.
			} else {
				$out[ $pk ] = array( self::ROW, 0 );
			}
		}
		return $out;
	}

	/** A revision's values belong to the page it is a revision of. */
	private static function revisions_to_parents( array $owners ) {
		global $wpdb;
		$parents = array();
		foreach ( array_chunk( array_values( array_unique( $owners ) ), 1000 ) as $chunk ) {
			foreach ( DSR_Report::fetch( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0 AND ID IN (%s)", $chunk, ARRAY_N ) as $r ) {
				$parents[ (int) $r[0] ] = (int) $r[1];
			}
		}
		foreach ( $owners as $pk => $id ) {
			if ( isset( $parents[ $id ] ) ) {
				$owners[ $pk ] = $parents[ $id ];
			}
		}
		return $owners;
	}

	/**
	 * Fill in the item of backup rows written before version 1.3.0, a batch at a time.
	 * Reads ids and keys only, never the backed-up values.
	 *
	 * @param string $run_id
	 * @return array|WP_Error [ done, pending ]
	 */
	public static function index_step( $run_id ) {
		global $wpdb;
		$bt      = DSR_DB::backup_table();
		$started = microtime( true );
		do {
			$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, table_name, pk_val FROM $bt WHERE run_id = %s AND object_type = '' LIMIT %d", $run_id, self::INDEX_BATCH ), ARRAY_A ); // phpcs:ignore
			$by_table = array();
			foreach ( $rows as $row ) {
				$by_table[ $row['table_name'] ][ (int) $row['id'] ] = $row['pk_val'];
			}

			// One UPDATE per item type: object_id via CASE. All values are integers.
			$groups = array();
			foreach ( $by_table as $table => $pks ) {
				$objects = self::resolve( $table, $pks );
				foreach ( $pks as $id => $pk ) {
					list( $type, $object_id ) = $objects[ $pk ];
					$groups[ $type ][ $id ]   = self::ROW === $type ? $id : $object_id;
				}
			}
			foreach ( $groups as $type => $map ) {
				$case = '';
				foreach ( $map as $id => $object_id ) {
					$case .= ' WHEN ' . (int) $id . ' THEN ' . (int) $object_id;
				}
				$wpdb->query( $wpdb->prepare( "UPDATE $bt SET object_type = %s, object_id = CASE id$case END WHERE id IN (" . implode( ',', array_keys( $map ) ) . ')', $type ) ); // phpcs:ignore
				if ( $wpdb->last_error ) {
					return new WP_Error( 'dsr_index', 'Could not prepare the list: ' . $wpdb->last_error );
				}
			}
		} while ( count( $rows ) === self::INDEX_BATCH && microtime( true ) - $started < self::STEP_SECONDS );

		$pending = count( $rows ) < self::INDEX_BATCH ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $bt WHERE run_id = %s AND object_type = ''", $run_id ) ); // phpcs:ignore
		return array(
			'done'    => 0 === $pending,
			'pending' => $pending,
		);
	}

	/**
	 * Item types present in a run, with the number of items of each.
	 *
	 * @param string $run_id
	 * @return array[] [ type, label, count ]
	 */
	public static function types( $run_id ) {
		global $wpdb;
		$bt     = DSR_DB::backup_table();
		$counts = wp_list_pluck( (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_type, COUNT(DISTINCT object_id) AS n FROM $bt WHERE run_id = %s GROUP BY object_type", $run_id ), ARRAY_A ), 'n', 'object_type' ); // phpcs:ignore
		$labels = array(
			'post'    => 'Pages, posts & templates',
			'option'  => 'Options (settings, ACF options pages, widgets)',
			'term'    => 'Categories, tags & terms',
			'user'    => 'Users',
			'comment' => 'Comments',
		);
		$out    = array();
		foreach ( $labels as $type => $label ) {
			if ( isset( $counts[ $type ] ) ) {
				$out[] = array( 'type' => $type, 'label' => $label, 'count' => (int) $counts[ $type ] );
				unset( $counts[ $type ] );
			}
		}
		unset( $counts[''] );
		ksort( $counts );
		foreach ( $counts as $type => $n ) {
			$out[] = array( 'type' => $type, 'label' => self::ROW === $type ? 'Other values' : 'Table ' . $type, 'count' => (int) $n );
		}
		return $out;
	}

	/**
	 * One page of a run's items of one type, keyset-paginated on object_id.
	 *
	 * @param string $run_id
	 * @param string $type
	 * @param array  $args after (last object_id of the previous page) or offset (page jump), per_page,
	 *                     search (title or ID), post_type, total (bool).
	 * @return array [ items, next (cursor or null), total (when asked for) ]
	 */
	public static function page( $run_id, $type, array $args ) {
		global $wpdb;
		$bt        = DSR_DB::backup_table();
		$search    = trim( (string) $args['search'] );
		$post_type = 'post' === $type ? (string) $args['post_type'] : '';
		$target    = self::search_target( $type );
		$join      = '';
		$where     = array( 'b.run_id = %s', 'b.object_type = %s' );
		$params    = array( $run_id, $type );

		if ( '' !== $search && ctype_digit( $search ) ) {
			$where[]  = 'b.object_id = %d';
			$params[] = (int) $search;
			$search   = '';
		}
		if ( '' !== $search || '' !== $post_type ) {
			if ( ! $target ) {
				return array( 'items' => array(), 'next' => null, 'total' => 0 );
			}
			$join = " JOIN {$target[0]} j ON j.{$target[1]} = b.object_id";
			if ( '' !== $search ) {
				$where[]  = "j.{$target[2]} LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			if ( '' !== $post_type ) {
				$where[]  = 'j.post_type = %s';
				$params[] = $post_type;
			}
		}

		$out = array();
		if ( ! empty( $args['total'] ) ) {
			$out['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT b.object_id) FROM $bt b$join WHERE " . implode( ' AND ', $where ), $params ) ); // phpcs:ignore
		}

		// Next / previous continue after the last ID seen; only a jump to a page number needs an offset.
		$per_page = isset( $args['per_page'] ) ? min( self::MAX_PER_PAGE, max( self::MIN_PER_PAGE, (int) $args['per_page'] ) ) : self::PER_PAGE;
		$offset   = 0;
		if ( ! empty( $args['after'] ) ) {
			$where[]  = 'b.object_id > %d';
			$params[] = (int) $args['after'];
		} elseif ( ! empty( $args['offset'] ) ) {
			$offset = max( 0, (int) $args['offset'] );
		}
		$params[] = $per_page + 1;
		$params[] = $offset;
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.object_id, COUNT(*) AS n FROM $bt b$join WHERE " . implode( ' AND ', $where ) . ' GROUP BY b.object_id ORDER BY b.object_id ASC LIMIT %d OFFSET %d', $params ), ARRAY_A ); // phpcs:ignore

		$more = count( $rows ) > $per_page;
		$rows = array_slice( $rows, 0, $per_page );

		$out['items'] = self::describe( $run_id, $type, wp_list_pluck( $rows, 'n', 'object_id' ) );
		$out['next']  = $more ? (int) end( $rows )['object_id'] : null;
		return $out;
	}

	/** @return array|null [ table, id column, searchable column ] */
	private static function search_target( $type ) {
		global $wpdb;
		$targets = array(
			'post'    => array( $wpdb->posts, 'ID', 'post_title' ),
			'option'  => array( $wpdb->options, 'option_id', 'option_name' ),
			'term'    => array( $wpdb->terms, 'term_id', 'name' ),
			'user'    => array( $wpdb->users, 'ID', 'display_name' ),
			'comment' => array( $wpdb->comments, 'comment_ID', 'comment_author' ),
		);
		return isset( $targets[ $type ] ) ? $targets[ $type ] : null;
	}

	/**
	 * Title, links and changed fields of a page of items.
	 *
	 * @param string $run_id
	 * @param string $type
	 * @param array  $counts object_id => number of changed values.
	 * @return array[]
	 */
	private static function describe( $run_id, $type, array $counts ) {
		global $wpdb;
		if ( ! $counts ) {
			return array();
		}
		$bt    = DSR_DB::backup_table();
		$ids   = array_map( 'intval', array_keys( $counts ) );
		$yoast = $wpdb->prefix . 'yoast_indexable';
		$items = array();
		foreach ( $counts as $id => $n ) {
			$items[ $id ] = array(
				'id'        => (int) $id,
				'title'     => '',
				'kind'      => '',
				'status'    => '',
				'edit'      => '',
				'view'      => '',
				'values'    => (int) $n,
				'fields'    => array(),
				'revisions' => 0,
			);
		}

		// Which fields changed: keys only, never the backed-up values.
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_id, table_name, pk_val, col_name FROM $bt WHERE run_id = %s AND object_type = %s AND object_id IN (" . implode( ',', $ids ) . ') LIMIT %d', $run_id, $type, self::MAX_FIELDS ), ARRAY_A ); // phpcs:ignore
		$meta_ids = array();
		foreach ( $rows as $r ) {
			if ( $r['table_name'] === $wpdb->postmeta ) {
				$meta_ids[] = $r['pk_val'];
			}
		}
		$meta = array();
		foreach ( DSR_Report::fetch( "SELECT meta_id, post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_id IN (%s)", array_unique( $meta_ids ) ) as $m ) {
			$meta[ $m['meta_id'] ] = $m;
		}
		$first = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['object_id'];
			$owner = $id;
			$label = $r['col_name'];
			if ( $r['table_name'] === $wpdb->posts ) {
				$owner = (int) $r['pk_val'];
				$label = preg_replace( '/^post_/', '', $label );
			} elseif ( $r['table_name'] === $wpdb->postmeta && isset( $meta[ $r['pk_val'] ] ) ) {
				$owner = (int) $meta[ $r['pk_val'] ]['post_id'];
				$label = $meta[ $r['pk_val'] ]['meta_key'];
			} elseif ( $r['table_name'] === $yoast ) {
				$label = 'Yoast SEO';
			}
			if ( 'post' === $type && $owner !== $id ) {
				$items[ $id ]['revisions']++;
			} else {
				$items[ $id ]['fields'][ $label ] = true;
			}
			if ( ! isset( $first[ $id ] ) ) {
				$first[ $id ] = $r;
			}
		}

		switch ( $type ) {
			case 'post':
				_prime_post_caches( $ids, false, false );
				foreach ( $ids as $id ) {
					$post = get_post( $id );
					if ( ! $post ) {
						$items[ $id ]['title'] = '(deleted)';
						continue;
					}
					$pto                    = get_post_type_object( $post->post_type );
					$items[ $id ]['title']  = '' !== $post->post_title ? $post->post_title : '(no title)';
					$items[ $id ]['kind']   = $pto ? $pto->labels->singular_name : $post->post_type;
					$items[ $id ]['status'] = $post->post_status;
					$items[ $id ]['edit']   = (string) get_edit_post_link( $id, 'raw' );
					$items[ $id ]['view']   = ( 'publish' === $post->post_status && $pto && $pto->public ) ? get_permalink( $post ) : '';
				}
				break;

			case 'option':
				foreach ( DSR_Report::fetch( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id IN (%s)", $ids ) as $o ) {
					$items[ $o['option_id'] ]['title'] = $o['option_name'];
					$items[ $o['option_id'] ]['kind']  = 'Option';
				}
				break;

			case 'term':
				foreach ( DSR_Report::fetch( "SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id IN (%s)", $ids ) as $t ) {
					$items[ $t['term_id'] ]['title'] = $t['name'];
					$items[ $t['term_id'] ]['kind']  = (string) $t['taxonomy'];
					$items[ $t['term_id'] ]['edit']  = $t['taxonomy'] ? admin_url( 'term.php?taxonomy=' . rawurlencode( $t['taxonomy'] ) . '&tag_ID=' . (int) $t['term_id'] ) : '';
				}
				break;

			case 'user':
				foreach ( DSR_Report::fetch( "SELECT ID, display_name, user_login FROM {$wpdb->users} WHERE ID IN (%s)", $ids ) as $u ) {
					$items[ $u['ID'] ]['title'] = $u['display_name'] . ' (' . $u['user_login'] . ')';
					$items[ $u['ID'] ]['kind']  = 'User';
					$items[ $u['ID'] ]['edit']  = admin_url( 'user-edit.php?user_id=' . (int) $u['ID'] );
				}
				break;

			case 'comment':
				foreach ( DSR_Report::fetch( "SELECT comment_ID, comment_author, comment_post_ID FROM {$wpdb->comments} WHERE comment_ID IN (%s)", $ids ) as $c ) {
					$items[ $c['comment_ID'] ]['title'] = 'Comment by ' . $c['comment_author'] . ' on ' . get_the_title( (int) $c['comment_post_ID'] );
					$items[ $c['comment_ID'] ]['kind']  = 'Comment';
					$items[ $c['comment_ID'] ]['edit']  = admin_url( 'comment.php?action=editcomment&c=' . (int) $c['comment_ID'] );
				}
				break;
		}

		foreach ( $items as $id => $item ) {
			if ( '' === $item['title'] ) {
				$items[ $id ]['title'] = isset( $first[ $id ] ) ? $first[ $id ]['table_name'] . ' #' . $first[ $id ]['pk_val'] : '#' . $id;
			}
			if ( '' === $item['kind'] && isset( $first[ $id ] ) ) {
				$items[ $id ]['kind'] = $first[ $id ]['table_name'];
			}
			$items[ $id ]['fields'] = array_keys( $item['fields'] );
		}
		return array_values( $items );
	}
}
