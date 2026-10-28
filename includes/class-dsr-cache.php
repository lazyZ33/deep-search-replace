<?php
/**
 * Clears every cache layer that could keep serving the old text.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Cache {

	/**
	 * @param bool $delete_transients
	 * @return string[] What was cleared.
	 */
	public static function purge_all( $delete_transients = false ) {
		global $wpdb;
		$done = array();

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'Elementor CSS & data cache';
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			delete_post_meta_by_key( '_elementor_element_cache' );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done[] = 'WP Super Cache';
		}
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_all' );
			$done[] = 'LiteSpeed Cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$done[] = 'SiteGround Optimizer';
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
			$done[] = 'Autoptimize';
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
			$done[] = 'WP Fastest Cache';
		}
		if ( defined( 'BREEZE_VERSION' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$done[] = 'Breeze';
		}
		if ( class_exists( 'WpeCommon' ) ) {
			foreach ( array( 'purge_memcached', 'purge_varnish_cache' ) as $method ) {
				if ( method_exists( 'WpeCommon', $method ) ) {
					call_user_func( array( 'WpeCommon', $method ) );
				}
			}
			$done[] = 'WP Engine';
		}
		if ( function_exists( 'nginx_helper_purge_all' ) || has_action( 'rt_nginx_helper_purge_all' ) ) {
			do_action( 'rt_nginx_helper_purge_all' );
			$done[] = 'Nginx Helper';
		}

		if ( $delete_transients ) {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$done[] = 'Transients';
		}

		wp_cache_flush();
		$done[] = 'Object cache';

		return $done;
	}

	/**
	 * Clear what could keep serving one page: its object cache, its Elementor CSS and
	 * element cache, and its URL in page cache plugins.
	 *
	 * @param int $post_id
	 */
	public static function purge_post( $post_id ) {
		$post_id = (int) $post_id;
		clean_post_cache( $post_id );

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) && method_exists( '\Elementor\Core\Files\CSS\Post', 'create' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			delete_post_meta( $post_id, '_elementor_element_cache' );
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_post', $post_id );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( get_permalink( $post_id ) );
		}
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $post_id );
		}
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			WpeCommon::purge_varnish_cache( $post_id );
		}
	}
}
