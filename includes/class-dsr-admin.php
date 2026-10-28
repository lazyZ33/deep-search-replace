<?php
/**
 * Admin screen (Tools > Deep Search & Replace) and its AJAX endpoints.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Admin {

	const SLUG = 'deep-search-replace';

	/** Post types that are never live content: listed but unchecked by default. */
	const SKIP_POST_TYPES = array( 'revision', 'oembed_cache', 'customize_changeset', 'wp_stream_alerts' );

	/**
	 * Tables (without prefix) that hold content shown on the site. Ticked by default;
	 * everything else (form entries, logs, caches, analytics) is listed unticked.
	 */
	const CONTENT_TABLE_PATTERN = '/^(posts|postmeta|options|terms|term_taxonomy|termmeta|comments|users|usermeta|links|yoast_indexable|rank_math_\w+|aioseo_posts|aioseo_terms|revslider_(sliders|slides|static_slides)|wpdatatable_[\d_]+|wpdatatables|wonderplugin_\w+|tablepress\w*|jet_\w+|layerslider\w*|smartslider3_\w+|ninja_table\w*|icl_strings|icl_string_translations)$/';

	public static function is_content_table( $name ) {
		global $wpdb;
		if ( 0 !== strpos( $name, $wpdb->prefix ) ) {
			return false;
		}
		$short = substr( $name, strlen( $wpdb->prefix ) );
		return (bool) preg_match( self::CONTENT_TABLE_PATTERN, $short ) && ! preg_match( '/_bkp$|log|cache|indexer/', $short );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'save_rules', 'start', 'step', 'finish', 'files_scan', 'files_apply', 'undo', 'delete_backup', 'history', 'live_save', 'report', 'items_index', 'items', 'restore_item' ) as $action ) {
			add_action( 'wp_ajax_dsr_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function menu() {
		$hook = add_management_page( 'Deep Search & Replace', 'Deep Search & Replace', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action(
			'admin_enqueue_scripts',
			function ( $current ) use ( $hook ) {
				if ( $current === $hook ) {
					DSR_Admin::assets();
				}
			}
		);
	}

	public static function assets() {
		wp_enqueue_style( 'dsr-admin', DSR_URL . 'assets/admin.css', array(), DSR_VERSION );
		wp_enqueue_script( 'dsr-admin', DSR_URL . 'assets/admin.js', array( 'jquery' ), DSR_VERSION, true );
		$settings = DSR_Plugin::settings();
		wp_add_inline_script(
			'dsr-admin',
			'var DSR = ' . wp_json_encode(
				array(
					'ajax'   => admin_url( 'admin-ajax.php' ),
					'nonce'  => wp_create_nonce( 'dsr' ),
					'rules'     => $settings['rules'],
					'preset'    => DSR_Plugin::default_rules(),
					'postTypes' => wp_list_pluck( DSR_DB::get_post_types(), 'post_type' ),
				)
			) . ';',
			'before'
		);
	}

	public static function render() {
		$settings   = DSR_Plugin::settings();
		$tables     = DSR_DB::get_tables();
		$post_types = DSR_DB::get_post_types();
		$roots      = DSR_Files::available_roots();
		?>
		<div class="wrap dsr">
			<h1>Deep Search &amp; Replace</h1>
			<p class="dsr-env"><strong>Detected:</strong> <?php echo esc_html( implode( ' · ', self::environment() ) ); ?></p>

			<div class="dsr-card">
				<h2>Rules</h2>
				<table class="dsr-rules widefat">
					<thead><tr>
						<th>Search for</th><th>Replace with</th>
						<th title="Also match HTML entities (&amp;mdash; &amp;#8212;) and JSON escapes (—) used by Elementor, Gutenberg and ACF">Encoded forms</th>
						<th title="Swallow spaces / non-breaking spaces on both sides, so 'word — word' becomes 'word, word'">Absorb spaces</th>
						<th title="At the very start or end of a text (e.g. an attribution like '— The Wall Street Journal') remove the match instead of replacing it, so no stray leading comma is left">Remove at start/end</th>
						<th></th>
					</tr></thead>
					<tbody id="dsr-rules-body"></tbody>
				</table>
				<p>
					<button type="button" class="button" id="dsr-add-rule">+ Add rule</button>
					<button type="button" class="button" id="dsr-preset">Em dash &rarr; comma preset</button>
				</p>
				<p class="description">Spaces in the replacement are shown as <code>␣</code> under each field so you can see them. Rules run top to bottom.</p>
			</div>

			<nav class="nav-tab-wrapper dsr-tabs">
				<a href="#db" class="nav-tab nav-tab-active" data-tab="db">Database</a>
				<a href="#files" class="nav-tab" data-tab="files">Theme files</a>
				<a href="#live" class="nav-tab" data-tab="live">Live output filter</a>
				<a href="#history" class="nav-tab" data-tab="history">History &amp; undo</a>
			</nav>

			<section class="dsr-tab" data-tab="db">
				<p>Covers every post type (pages, posts, CPTs, Elementor templates, headers/footers, block templates, template parts, synced patterns, navigation menus), all post meta (ACF fields, Elementor data), options (ACF options pages, widgets, Customizer), terms, users, comments and plugin tables. Serialized and JSON data are rebuilt safely; slugs, GUIDs and keys are never touched.</p>

				<div class="dsr-columns">
					<div class="dsr-card">
						<h3>Post types <small><a href="#" data-check="types" data-val="1">all</a> / <a href="#" data-check="types" data-val="0">none</a></small></h3>
						<div class="dsr-scroll">
							<?php foreach ( $post_types as $type ) : ?>
								<label><input type="checkbox" name="types" value="<?php echo esc_attr( $type['post_type'] ); ?>" <?php checked( ! in_array( $type['post_type'], self::SKIP_POST_TYPES, true ) ); ?>>
									<?php echo esc_html( $type['post_type'] ); ?> <span class="count">(<?php echo (int) $type['n']; ?>)</span></label>
							<?php endforeach; ?>
						</div>
						<p class="description">Off by default: revisions (history), oEmbed cache, Customizer drafts and activity-log alerts. None of them is live content.</p>
					</div>

					<div class="dsr-card">
						<h3>Tables <small><a href="#" data-check="tables" data-val="content">content only</a> / <a href="#" data-check="tables" data-val="1">all</a> / <a href="#" data-check="tables" data-val="0">none</a></small></h3>
						<div class="dsr-scroll">
							<?php
							// Content tables first, then everything else (form entries, logs, caches, analytics).
							uksort(
								$tables,
								function ( $a, $b ) {
									return (int) self::is_content_table( $b ) - (int) self::is_content_table( $a ) ?: strcmp( $a, $b );
								}
							);
							$shown_divider = false;
							foreach ( $tables as $name => $info ) :
								$schema     = DSR_DB::get_schema( $name );
								$is_content = self::is_content_table( $name );
								if ( ! $is_content && ! $shown_divider ) :
									$shown_divider = true;
									?>
									<p class="dsr-divider">Other tables: form entries, logs, caches, analytics. Not shown on the site; tick only if you know they hold page content.</p>
								<?php endif; ?>
								<label class="<?php echo $schema ? '' : 'dsr-disabled'; ?>">
									<input type="checkbox" name="tables" value="<?php echo esc_attr( $name ); ?>" data-content="<?php echo $is_content ? '1' : '0'; ?>" <?php checked( $schema && $is_content ); ?> <?php disabled( ! $schema ); ?>>
									<?php echo esc_html( $name ); ?>
									<span class="count">(<?php echo esc_html( number_format_i18n( $info['rows'] ) ); ?> rows, <?php echo esc_html( size_format( $info['size'] ) ); ?><?php echo $schema ? '' : ', no primary key, skipped'; ?>)</span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="dsr-card">
					<label><input type="checkbox" id="dsr-clear-cache" checked> Clear caches after replacing (Elementor CSS, object cache, page cache plugins)</label><br>
					<label><input type="checkbox" id="dsr-transients"> Also delete transients (cached fragments some themes/plugins keep)</label>
					<p><strong>Speed</strong></p>
					<label><input type="radio" name="dsr-speed" value="gentle" checked> <strong>Gentle</strong>: small batches with a pause between them, so the site stays responsive. Slowest. Use on live sites and small servers.</label><br>
					<label><input type="radio" name="dsr-speed" value="normal"> <strong>Normal</strong>: medium batches, short pauses.</label><br>
					<label><input type="radio" name="dsr-speed" value="fast"> <strong>Fast</strong>: no pauses, uses the server fully. Only for staging or off-hours.</label>
				</div>

				<p class="dsr-actions">
					<button type="button" class="button button-secondary button-hero" id="dsr-dry">Dry run (preview only)</button>
					<button type="button" class="button button-primary button-hero" id="dsr-apply">Replace now</button>
				</p>
				<p class="description">Every changed cell is backed up inside the database first, so a run can be undone from History. A full database backup beforehand is still recommended.</p>

				<div id="dsr-progress" hidden>
					<div class="dsr-bar"><span></span></div>
					<p class="dsr-status"></p>
				</div>
				<div id="dsr-results"></div>
			</section>

			<section class="dsr-tab" data-tab="files" hidden>
				<p>For text hard-coded in theme templates (classic PHP templates, block theme <code>templates/</code>, <code>parts/</code> and <code>patterns/</code>, <code>theme.json</code>). Each file is backed up to <code>uploads/dsr-backups/</code> before it is changed and can be restored from History.</p>
				<div class="dsr-card">
					<h3>Folders</h3>
					<?php foreach ( $roots as $key => $root ) : ?>
						<label><input type="checkbox" name="roots" value="<?php echo esc_attr( $key ); ?>" <?php checked( 'mu' !== $key ); ?>> <?php echo esc_html( $root['label'] ); ?> <code><?php echo esc_html( wp_normalize_path( $root['path'] ) ); ?></code></label><br>
					<?php endforeach; ?>
					<label>Extra folder inside wp-content: <code>wp-content/</code><input type="text" id="dsr-extra" class="regular-text" placeholder="plugins/my-site-functionality"></label>
					<h3>File types</h3>
					<?php foreach ( DSR_Files::ALLOWED_EXTS as $ext ) : ?>
						<label class="dsr-inline"><input type="checkbox" name="exts" value="<?php echo esc_attr( $ext ); ?>" <?php checked( in_array( $ext, DSR_Files::DEFAULT_EXTS, true ) ); ?>> .<?php echo esc_html( $ext ); ?></label>
					<?php endforeach; ?>
					<p class="description">If the theme is updated from its vendor, file changes are overwritten. Use the live output filter for third-party themes.</p>
				</div>
				<p class="dsr-actions"><button type="button" class="button button-secondary" id="dsr-files-scan">Scan files</button></p>
				<div id="dsr-files-results"></div>
			</section>

			<section class="dsr-tab" data-tab="live" hidden>
				<p>Rewrites the final HTML of every front-end page as it is sent to the visitor. It catches what is not stored in the database: hard-coded theme strings, em dashes WordPress generates from <code>--</code> (wptexturize), SEO title separators and plugin output. It does not change any stored data. Scripts and styles are skipped, except JSON-LD structured data.</p>
				<div class="dsr-card">
					<label><input type="checkbox" id="dsr-live-enabled" <?php checked( $settings['live_enabled'] ); ?>> Enable the live output filter</label>
					<p>Active rules: <span id="dsr-live-rules"><?php echo esc_html( self::describe_rules( $settings['live_rules'] ) ); ?></span></p>
					<p><button type="button" class="button button-primary" id="dsr-live-save">Save, using the rules above</button></p>
				</div>
			</section>

			<section class="dsr-tab" data-tab="history" hidden>
				<div id="dsr-history"><?php self::render_history(); ?></div>
				<div id="dsr-items"></div>
				<div id="dsr-report"></div>
			</section>
		</div>
		<?php
	}

	public static function render_history() {
		$runs = DSR_Runner::history();
		if ( ! $runs ) {
			echo '<p>No runs yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Type</th><th>Rules</th><th>Result</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( $runs as $run ) {
			if ( 'files' === $run['type'] ) {
				$result = sprintf( '%d files, %d replacements', $run['stats']['files'], $run['stats']['replacements'] );
			} else {
				$cells = 0;
				$reps  = 0;
				foreach ( $run['stats'] as $stat ) {
					$cells += $stat['cells'];
					$reps  += $stat['replacements'];
				}
				$result = sprintf( '%d values changed, %d replacements', $cells, $reps );
			}
			if ( ! empty( $run['errors'] ) ) {
				$result .= sprintf( ', %d warnings', count( $run['errors'] ) );
			}
			if ( ! empty( $run['restored_items'] ) ) {
				$result .= sprintf( ', %d items restored individually', $run['restored_items'] );
			}
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', $run['created'] ) ) . '</td>';
			echo '<td>' . esc_html( 'files' === $run['type'] ? 'Theme files' : 'Database' ) . '</td>';
			echo '<td>' . esc_html( self::describe_rules( $run['rules'] ) ) . '</td>';
			echo '<td>' . esc_html( $result ) . '</td>';
			if ( 'running' === $run['status'] ) {
				echo '<td><span class="dsr-bad">interrupted</span><br><span class="dsr-muted">Stopped before finishing (page reloaded or request timed out). What it changed so far is backed up. Undo it, or check the result with Report / Restore pages, then run the replacement again.</span></td><td>';
			} else {
				echo '<td>' . esc_html( $run['status'] ) . '</td><td>';
			}
			if ( DSR_Runner::can_restore( $run ) ) {
				if ( 'db' === $run['type'] ) {
					echo '<button type="button" class="button button-primary dsr-report" data-run="' . esc_attr( $run['id'] ) . '">Report</button> ';
					echo '<button type="button" class="button dsr-items-open" data-run="' . esc_attr( $run['id'] ) . '" data-date="' . esc_attr( wp_date( 'Y-m-d H:i', $run['created'] ) ) . '">Restore pages</button> ';
				}
				echo '<button type="button" class="button dsr-undo" data-run="' . esc_attr( $run['id'] ) . '">Undo all</button> ';
				echo '<button type="button" class="button dsr-button-danger dsr-delete-backup" data-run="' . esc_attr( $run['id'] ) . '">Delete backup</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function describe_rules( $rules ) {
		return DSR_Runner::describe_rules( $rules );
	}

	private static function environment() {
		$env   = array();
		$theme = wp_get_theme();
		$env[] = 'Theme: ' . $theme->get( 'Name' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' );
		$env[] = ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ? 'Block theme (FSE)' : 'Classic theme';
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$env[] = 'Elementor ' . ELEMENTOR_VERSION . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ' + Pro' : '' );
		}
		if ( class_exists( 'ACF' ) ) {
			$env[] = 'ACF' . ( defined( 'ACF_PRO' ) ? ' Pro' : '' ) . ( defined( 'ACF_VERSION' ) ? ' ' . ACF_VERSION : '' );
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			$env[] = 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$env[] = 'Rank Math';
		}
		if ( is_multisite() ) {
			$env[] = 'Multisite (this site only)';
		}
		return $env;
	}

	/* ---------- AJAX ---------- */

	private static function guard() {
		check_ajax_referer( 'dsr', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore
	}

	private static function post_rules() {
		$raw = json_decode( (string) self::post( 'rules', '[]' ), true );
		return DSR_Runner::sanitize_rules( is_array( $raw ) ? $raw : array() );
	}

	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_save_rules() {
		self::guard();
		DSR_Plugin::update_settings( array( 'rules' => self::post_rules() ) );
		wp_send_json_success();
	}

	public static function ajax_start() {
		self::guard();
		$rules = self::post_rules();
		DSR_Plugin::update_settings( array( 'rules' => $rules ) );
		$run = DSR_Runner::create_db_run(
			array(
				'rules'              => $rules,
				'mode'               => self::post( 'mode' ),
				'tables'             => (array) self::post( 'tables', array() ),
				'include_post_types' => isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) self::post( 'types' ) ) : null, // phpcs:ignore
				'clear_cache'        => '1' === self::post( 'clear_cache' ),
				'delete_transients'  => '1' === self::post( 'transients' ),
				'speed'              => sanitize_key( (string) self::post( 'speed', 'gentle' ) ),
			)
		);
		if ( is_wp_error( $run ) ) {
			self::respond( $run );
		}
		// Approximate row counts (from table status, no counting), for the progress bar.
		$all  = DSR_DB::get_tables();
		$rows = array();
		foreach ( $run['tables'] as $table ) {
			$rows[ $table ] = isset( $all[ $table ] ) ? $all[ $table ]['rows'] : 0;
		}
		self::respond(
			array(
				'run_id' => $run['id'],
				'tables' => $run['tables'],
				'rows'   => $rows,
				'pause'  => $run['pause'],
			)
		);
	}

	public static function ajax_step() {
		self::guard();
		$cursor = self::post( 'cursor', '' );
		self::respond( DSR_Runner::step( (string) self::post( 'run_id' ), (string) self::post( 'table' ), '' === $cursor ? null : (string) $cursor ) );
	}

	public static function ajax_finish() {
		self::guard();
		self::respond( DSR_Runner::finish( (string) self::post( 'run_id' ) ) );
	}

	private static function files_request() {
		return array(
			DSR_Files::resolve_roots( array_map( 'sanitize_key', (array) self::post( 'roots', array() ) ), (string) self::post( 'extra' ) ),
			DSR_Files::sanitize_exts( self::post( 'exts', array() ) ),
		);
	}

	public static function ajax_files_scan() {
		self::guard();
		$rules = self::post_rules();
		if ( ! $rules ) {
			wp_send_json_error( 'Add at least one search term.' );
		}
		list( $roots, $exts ) = self::files_request();
		if ( ! $roots ) {
			wp_send_json_error( 'Select at least one folder.' );
		}
		wp_send_json_success( DSR_Files::scan( new DSR_Replacer( $rules ), $roots, $exts ) );
	}

	public static function ajax_files_apply() {
		self::guard();
		$rules = self::post_rules();
		list( $roots, $exts ) = self::files_request();
		$paths                = array_map( 'strval', (array) self::post( 'files', array() ) );
		if ( ! $rules || ! $roots || ! $paths ) {
			wp_send_json_error( 'Nothing to do.' );
		}
		$run = DSR_Files::apply( new DSR_Replacer( $rules ), $roots, $exts, $paths );
		if ( is_wp_error( $run ) ) {
			self::respond( $run );
		}
		$run['rules'] = $rules;
		DSR_Runner::save_run( $run );
		DSR_Runner::add_to_history( $run['id'] );
		DSR_Cache::purge_all( false );
		wp_send_json_success( $run );
	}

	public static function ajax_undo() {
		self::guard();
		self::respond( DSR_Runner::undo_step( (string) self::post( 'run_id' ) ) );
	}

	public static function ajax_delete_backup() {
		self::guard();
		DSR_Runner::delete_backup( (string) self::post( 'run_id' ) );
		wp_send_json_success();
	}

	public static function ajax_history() {
		self::guard();
		ob_start();
		self::render_history();
		wp_send_json_success( ob_get_clean() );
	}

	public static function ajax_report() {
		self::guard();
		self::respond( DSR_Report::batch( (string) self::post( 'run_id' ), (int) self::post( 'after', 0 ) ) );
	}

	public static function ajax_items_index() {
		self::guard();
		self::respond( DSR_Runner::index_items( (string) self::post( 'run_id' ) ) );
	}

	public static function ajax_items() {
		self::guard();
		$run_id = (string) self::post( 'run_id' );
		$run    = DSR_Runner::get_run( $run_id );
		if ( ! DSR_Runner::can_restore( $run ) || empty( $run['indexed'] ) ) {
			wp_send_json_error( 'This run has no backup left to restore from.' );
		}
		wp_send_json_success(
			DSR_Objects::page(
				$run_id,
				self::post_object_type(),
				array(
					'after'     => (int) self::post( 'after', 0 ),
					'offset'    => (int) self::post( 'offset', 0 ),
					'per_page'  => (int) self::post( 'per_page', DSR_Objects::PER_PAGE ),
					'search'    => sanitize_text_field( (string) self::post( 'search' ) ),
					'post_type' => sanitize_key( (string) self::post( 'post_type' ) ),
					'total'     => '1' === self::post( 'total' ),
				)
			)
		);
	}

	public static function ajax_restore_item() {
		self::guard();
		self::respond( DSR_Runner::restore_item( (string) self::post( 'run_id' ), self::post_object_type(), (int) self::post( 'id' ) ) );
	}

	/** Item type: post, option, term... or a table name. */
	private static function post_object_type() {
		$type = (string) self::post( 'type' );
		return preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $type ) ? $type : 'post';
	}

	public static function ajax_live_save() {
		self::guard();
		$rules = self::post_rules();
		DSR_Plugin::update_settings(
			array(
				'rules'        => $rules,
				'live_rules'   => $rules,
				'live_enabled' => '1' === self::post( 'enabled' ),
			)
		);
		DSR_Cache::purge_all( false );
		wp_send_json_success( self::describe_rules( $rules ) );
	}
}
