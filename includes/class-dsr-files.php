<?php
/**
 * Theme (and other code folder) file scanning / replacing, for text hard-coded
 * in templates, block-theme templates/parts/patterns and theme.json.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Files {

	const MAX_SIZE      = 3145728;
	const SKIP_DIRS     = array( 'node_modules', 'vendor', '.git', '.svn', '.idea', 'bower_components' );
	const DEFAULT_EXTS  = array( 'php', 'html', 'json', 'twig' );
	const ALLOWED_EXTS  = array( 'php', 'html', 'htm', 'json', 'twig', 'txt', 'js', 'css', 'svg' );
	const LINES_PER_FILE = 8;

	/** @return array key => [ label, path ] */
	public static function available_roots() {
		$roots = array(
			'theme' => array(
				'label' => 'Active theme: ' . wp_get_theme()->get( 'Name' ),
				'path'  => get_stylesheet_directory(),
			),
		);
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$roots['parent'] = array(
				'label' => 'Parent theme: ' . wp_get_theme( get_template() )->get( 'Name' ),
				'path'  => get_template_directory(),
			);
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			$roots['mu'] = array(
				'label' => 'Must-use plugins',
				'path'  => WPMU_PLUGIN_DIR,
			);
		}
		return $roots;
	}

	/**
	 * Resolve the roots requested by the user to real, allowed directories.
	 *
	 * @param string[] $keys
	 * @param string   $extra Folder relative to wp-content (e.g. "plugins/my-site-plugin").
	 * @return string[] Normalised absolute paths.
	 */
	public static function resolve_roots( array $keys, $extra = '' ) {
		$available = self::available_roots();
		$paths     = array();
		foreach ( $keys as $key ) {
			if ( isset( $available[ $key ] ) ) {
				$paths[] = wp_normalize_path( realpath( $available[ $key ]['path'] ) );
			}
		}
		$extra = trim( (string) $extra, "/\\ \t" );
		if ( '' !== $extra ) {
			$content = wp_normalize_path( realpath( WP_CONTENT_DIR ) );
			$real    = realpath( WP_CONTENT_DIR . '/' . $extra );
			if ( $real && is_dir( $real ) && 0 === strpos( wp_normalize_path( $real ) . '/', $content . '/' ) ) {
				$paths[] = wp_normalize_path( $real );
			}
		}
		return array_values( array_unique( array_filter( $paths ) ) );
	}

	public static function sanitize_exts( $exts ) {
		$exts = array_intersect( array_map( 'strtolower', (array) $exts ), self::ALLOWED_EXTS );
		return $exts ? array_values( $exts ) : self::DEFAULT_EXTS;
	}

	/**
	 * @return array[] [ path, rel, count, lines => [ [ line, before, after ] ] ]
	 */
	public static function scan( DSR_Replacer $replacer, array $roots, array $exts ) {
		$results = array();
		foreach ( $roots as $root ) {
			foreach ( self::list_files( $root, $exts ) as $path ) {
				$content = file_get_contents( $path ); // phpcs:ignore
				if ( false === $content || ! $replacer->contains_needle( $content ) ) {
					continue;
				}
				$before = $replacer->count;
				$new    = $replacer->replace_value( $content );
				if ( $new === $content ) {
					continue;
				}
				$results[] = array(
					'path'  => $path,
					'rel'   => basename( $root ) . '/' . ltrim( substr( $path, strlen( $root ) ), '/' ),
					'count' => $replacer->count - $before,
					'lines' => self::changed_lines( $content, $new ),
				);
			}
		}
		return $results;
	}

	/**
	 * Replace in the given files (must sit inside the given roots), backing each one up first.
	 *
	 * @return array|WP_Error The saved run.
	 */
	public static function apply( DSR_Replacer $replacer, array $roots, array $exts, array $paths ) {
		$backup_dir = self::backup_base() . '/' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 16, false );
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return new WP_Error( 'dsr_backup_dir', 'Could not create the backup folder in uploads.' );
		}

		$run = array(
			'id'         => gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ),
			'type'       => 'files',
			'mode'       => 'apply',
			'created'    => time(),
			'user'       => get_current_user_id(),
			'status'     => 'done',
			'rules'      => array(), // Set by the caller.
			'backup_dir' => $backup_dir,
			'manifest'   => array(),
			'stats'      => array( 'files' => 0, 'replacements' => 0 ),
			'errors'     => array(),
		);

		foreach ( array_unique( $paths ) as $path ) {
			$real = realpath( $path );
			if ( ! $real || ! self::is_allowed( wp_normalize_path( $real ), $roots, $exts ) ) {
				$run['errors'][] = "Skipped (not inside the selected folders): $path";
				continue;
			}
			$real = wp_normalize_path( $real );
			if ( ! is_writable( $real ) ) {
				$run['errors'][] = "Not writable: $real";
				continue;
			}
			$content = file_get_contents( $real ); // phpcs:ignore
			$before  = $replacer->count;
			$new     = $replacer->replace_value( $content );
			if ( false === $content || $new === $content ) {
				continue;
			}
			$backup = $backup_dir . '/' . md5( $real ) . '.bak';
			if ( false === file_put_contents( $backup, $content ) ) { // phpcs:ignore
				$run['errors'][] = "Backup failed, file left untouched: $real";
				continue;
			}
			if ( false === file_put_contents( $real, $new, LOCK_EX ) ) { // phpcs:ignore
				$run['errors'][] = "Write failed: $real";
				continue;
			}
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $real, true );
			}
			$run['manifest'][] = array(
				'path'   => $real,
				'backup' => $backup,
				'hash'   => md5( $new ),
			);
			$run['stats']['files']++;
			$run['stats']['replacements'] += $replacer->count - $before;
		}

		foreach ( array_unique( $replacer->errors ) as $error ) {
			$run['errors'][] = $error;
		}
		return $run;
	}

	/** @return array [ done, restored, conflicts ] */
	public static function undo( array $run ) {
		$out = array(
			'done'      => true,
			'restored'  => 0,
			'conflicts' => array(),
		);
		foreach ( $run['manifest'] as $item ) {
			if ( ! is_file( $item['backup'] ) ) {
				$out['conflicts'][] = "Backup missing for {$item['path']}";
				continue;
			}
			if ( is_file( $item['path'] ) && md5_file( $item['path'] ) !== $item['hash'] ) {
				$out['conflicts'][] = "{$item['path']} changed since the run, left as is (backup kept at {$item['backup']})";
				continue;
			}
			if ( copy( $item['backup'], $item['path'] ) ) {
				if ( function_exists( 'opcache_invalidate' ) ) {
					opcache_invalidate( $item['path'], true );
				}
				$out['restored']++;
			}
		}
		return $out;
	}

	public static function delete_backup( array $run ) {
		if ( empty( $run['backup_dir'] ) || 0 !== strpos( wp_normalize_path( $run['backup_dir'] ), wp_normalize_path( self::backup_base() ) . '/' ) ) {
			return;
		}
		foreach ( (array) glob( $run['backup_dir'] . '/*.bak' ) as $file ) {
			@unlink( $file ); // phpcs:ignore
		}
		@rmdir( $run['backup_dir'] ); // phpcs:ignore
	}

	private static function backup_base() {
		$uploads = wp_upload_dir( null, false );
		$base    = wp_normalize_path( $uploads['basedir'] ) . '/dsr-backups';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
			file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		return $base;
	}

	private static function is_allowed( $real, array $roots, array $exts ) {
		if ( ! in_array( strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ), $exts, true ) ) {
			return false;
		}
		foreach ( $roots as $root ) {
			if ( 0 === strpos( $real, rtrim( $root, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function list_files( $root, array $exts ) {
		$files = array();
		if ( ! is_dir( $root ) ) {
			return $files;
		}
		$dir = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS );
		$it  = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				$dir,
				function ( $file ) {
					return ! ( $file->isDir() && in_array( $file->getFilename(), DSR_Files::SKIP_DIRS, true ) );
				}
			)
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() && $file->getSize() <= self::MAX_SIZE && in_array( strtolower( $file->getExtension() ), $exts, true ) ) {
				$files[] = wp_normalize_path( $file->getPathname() );
			}
		}
		sort( $files );
		return $files;
	}

	private static function changed_lines( $old, $new ) {
		$a     = explode( "\n", $old );
		$b     = explode( "\n", $new );
		$lines = array();
		if ( count( $a ) !== count( $b ) ) {
			return $lines;
		}
		foreach ( $a as $i => $line ) {
			if ( $line !== $b[ $i ] ) {
				$lines[] = array( $i + 1, self::clip( $line ), self::clip( $b[ $i ] ) );
				if ( count( $lines ) >= self::LINES_PER_FILE ) {
					break;
				}
			}
		}
		return $lines;
	}

	private static function clip( $line ) {
		$line = trim( $line );
		return strlen( $line ) > 220 ? wp_check_invalid_utf8( substr( $line, 0, 220 ), true ) . '...' : $line;
	}
}
