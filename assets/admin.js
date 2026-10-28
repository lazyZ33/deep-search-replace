/* global DSR, jQuery */
( function ( $ ) {
	'use strict';

	var $body = $( '#dsr-rules-body' );

	/* ---------- Rules editor ---------- */

	function visible( s ) {
		return s.replace( / /g, '␣' ).replace( / /g, '⍽' ).replace( /\t/g, '⇥' );
	}

	function addRule( rule ) {
		rule = rule || { search: '', replace: '', encoded: true, absorb_spaces: false };
		var $row = $(
			'<tr>' +
				'<td><input type="text" class="dsr-search regular-text"><div class="dsr-visible"></div></td>' +
				'<td><input type="text" class="dsr-replace regular-text"><div class="dsr-visible"></div></td>' +
				'<td><input type="checkbox" class="dsr-encoded"></td>' +
				'<td><input type="checkbox" class="dsr-absorb"></td>' +
				'<td><input type="checkbox" class="dsr-edges"></td>' +
				'<td><button type="button" class="button-link dsr-remove" aria-label="Remove rule">✕</button></td>' +
			'</tr>'
		);
		$row.find( '.dsr-search' ).val( rule.search );
		$row.find( '.dsr-replace' ).val( rule.replace );
		$row.find( '.dsr-encoded' ).prop( 'checked', !! rule.encoded );
		$row.find( '.dsr-absorb' ).prop( 'checked', !! rule.absorb_spaces );
		$row.find( '.dsr-edges' ).prop( 'checked', !! rule.drop_edges );
		$body.append( $row );
		$row.find( 'input[type=text]' ).trigger( 'input' );
	}

	function getRules() {
		var rules = [];
		var map = $( '#dsr-urlmap' ).val() || '';
		$body.find( 'tr' ).each( function () {
			var $r = $( this );
			var search = $r.find( '.dsr-search' ).val();
			if ( search !== '' ) {
				rules.push( {
					search: search,
					replace: $r.find( '.dsr-replace' ).val(),
					encoded: $r.find( '.dsr-encoded' ).is( ':checked' ),
					absorb_spaces: $r.find( '.dsr-absorb' ).is( ':checked' ),
					drop_edges: $r.find( '.dsr-edges' ).is( ':checked' )
				} );
			}
		} );
		if ( map.trim() ) {
			rules.push( { type: 'urls', search: 'URL map', replace: '', csv: map, domains: $( '#dsr-urlmap-domains' ).val() || '' } );
		}
		return rules;
	}

	$body.on( 'input', 'input[type=text]', function () {
		$( this ).next( '.dsr-visible' ).text( this.value ? '[' + visible( this.value ) + ']' : '' );
	} );
	$body.on( 'click', '.dsr-remove', function () {
		$( this ).closest( 'tr' ).remove();
		if ( ! $body.children().length ) {
			addRule();
		}
	} );
	$( '#dsr-add-rule' ).on( 'click', function () {
		addRule();
	} );
	$( '#dsr-preset' ).on( 'click', function () {
		$body.empty();
		DSR.preset.forEach( addRule );
	} );

	/* ---------- URL map ---------- */

	var saved = DSR.rules && DSR.rules.length ? DSR.rules : DSR.preset;
	var textRules = saved.filter( function ( r ) {
		return r.type !== 'urls';
	} );
	var urlRule = saved.filter( function ( r ) {
		return r.type === 'urls';
	} )[ 0 ];
	( textRules.length || urlRule ? textRules : DSR.preset ).forEach( addRule );
	if ( ! textRules.length ) {
		addRule();
	}
	$( '#dsr-urlmap' ).val( urlRule ? urlRule.csv : '' );
	$( '#dsr-urlmap-domains' ).val( urlRule && urlRule.domains ? urlRule.domains : DSR.siteHost );

	$( '#dsr-urlmap-file' ).on( 'change', function () {
		var file = this.files && this.files[ 0 ];
		if ( ! file ) {
			return;
		}
		var reader = new FileReader();
		reader.onload = function () {
			$( '#dsr-urlmap' ).val( String( reader.result ) );
			$( '#dsr-urlmap-check' ).trigger( 'click' );
		};
		reader.readAsText( file );
		this.value = '';
	} );

	$( '#dsr-urlmap-clear' ).on( 'click', function () {
		$( '#dsr-urlmap' ).val( '' );
		$( '#dsr-urlmap-result' ).empty();
	} );

	$( '#dsr-urlmap-check' ).on( 'click', function () {
		var $out = $( '#dsr-urlmap-result' ).html( '<p>Checking...</p>' );
		api( 'urlmap_check', { csv: $( '#dsr-urlmap' ).val() || '', domains: $( '#dsr-urlmap-domains' ).val() || '' } ).then( function ( r ) {
			var html = '<p><strong>' + r.count + ' links ready.</strong> Domains matched: <code>' + esc( r.hosts.join( ', ' ) || 'none' ) + '</code></p>';
			if ( r.problems.length ) {
				html += '<p class="dsr-bad">' + r.problems.length + ' lines skipped:</p><ul class="dsr-errors">' + r.problems.map( function ( p ) {
					return '<li>' + esc( p ) + '</li>';
				} ).join( '' ) + '</ul>';
			}
			if ( r.chains.length ) {
				html += '<p>' + r.chains.length + ' chains followed to the final URL:</p><ul class="dsr-errors dsr-muted">' + r.chains.map( function ( c ) {
					return '<li>' + esc( c ) + '</li>';
				} ).join( '' ) + '</ul>';
			}
			if ( r.pairs.length ) {
				html += '<details><summary>Show the map as it will be used' + ( r.count > r.pairs.length ? ' (first ' + r.pairs.length + ')' : '' ) + '</summary>' +
					'<table class="widefat striped dsr-report-table"><thead><tr><th>Old (normalized)</th><th>New</th></tr></thead><tbody>' +
					r.pairs.map( function ( p ) {
						return '<tr><td><code>' + esc( p[ 0 ] ) + '</code></td><td><code>' + esc( p[ 1 ] ) + '</code></td></tr>';
					} ).join( '' ) + '</tbody></table></details>';
			}
			$out.html( html );
		}, function ( msg ) {
			$out.html( '<div class="notice notice-error inline"><p>' + esc( msg ) + '</p></div>' );
		} );
	} );

	/* ---------- Helpers ---------- */

	function api( action, data ) {
		data = $.extend( { action: 'dsr_' + action, nonce: DSR.nonce }, data || {} );
		return $.post( DSR.ajax, data ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				return $.Deferred().reject( res && res.data ? res.data : 'Unexpected response' ).promise();
			}
			return res.data;
		}, function ( xhr ) {
			return $.Deferred().reject( 'Request failed (' + xhr.status + '). The server may have timed out; try fewer tables or check the PHP error log.' ).promise();
		} );
	}

	function checked( name ) {
		return $( 'input[name=' + name + ']:checked' ).map( function () {
			return this.value;
		} ).get();
	}

	function esc( s ) {
		return $( '<div>' ).text( s == null ? '' : String( s ) ).html();
	}

	function highlight( text, rules, side ) {
		var html = esc( text );
		rules.forEach( function ( rule ) {
			if ( rule.type === 'urls' ) {
				return;
			}
			var term = esc( side === 'before' ? rule.search : rule.replace );
			if ( term.trim() ) {
				html = html.split( term ).join( '<mark>' + term + '</mark>' );
			}
		} );
		return html;
	}

	$( document ).on( 'click', '[data-check]', function ( e ) {
		e.preventDefault();
		var val = $( this ).data( 'val' );
		$( 'input[name=' + $( this ).data( 'check' ) + ']:not(:disabled)' ).each( function () {
			this.checked = val === 'content' ? $( this ).data( 'content' ) === 1 : val === 1;
		} );
	} );

	$( '.dsr-tabs a' ).on( 'click', function ( e ) {
		e.preventDefault();
		var tab = $( this ).data( 'tab' );
		$( '.dsr-tabs a' ).removeClass( 'nav-tab-active' );
		$( this ).addClass( 'nav-tab-active' );
		$( '.dsr-tab' ).each( function () {
			this.hidden = $( this ).data( 'tab' ) !== tab;
		} );
	} );

	function setBusy( busy ) {
		$( '.dsr button' ).prop( 'disabled', busy );
	}

	/* ---------- Database run ---------- */

	var $progress = $( '#dsr-progress' );
	var $results = $( '#dsr-results' );

	function runDb( mode ) {
		var rules = getRules();
		if ( ! rules.length ) {
			window.alert( 'Add at least one search term.' );
			return;
		}
		var textCount = rules.filter( function ( r ) {
			return r.type !== 'urls';
		} ).length;
		if ( textCount && textCount < rules.length && ! window.confirm( 'This run uses the URL map AND ' + textCount + ' text rule(s) from the Rules table (for example the em dash preset).\n\nOK = run both. Cancel = stop, so you can remove the text rules first.' ) ) {
			return;
		}
		if ( mode === 'apply' && ! window.confirm( 'Replace in the database now?\n\nChanged values are backed up and can be undone from History, but make sure you have a full database backup too.' ) ) {
			return;
		}

		var apply = mode === 'apply';
		var stats = {};
		var samples = [];
		var errors = [];
		var links = {};
		var tables, runId, estimate = {}, scanned = {}, pause = 0, index = 0, cursorNow = '', note = '';
		var started = Date.now();

		setBusy( true );
		keepPageOpen( true );
		$results.empty();
		$progress.prop( 'hidden', false ).html(
			'<div class="dsr-progress-head"><span class="spinner is-active"></span><strong class="dsr-progress-title"></strong> <span class="dsr-muted dsr-progress-time"></span></div>' +
			'<div class="dsr-bar"><span></span></div>' +
			'<div class="dsr-status"></div>' +
			'<p class="dsr-muted">Keep this tab open until it says Done. Closing or reloading it stops the run' +
				( apply ? '; what was already changed stays backed up and can be undone from History.' : '.' ) + '</p>'
		);
		render( 'Starting...' );

		api( 'start', {
			mode: mode,
			rules: JSON.stringify( rules ),
			tables: checked( 'tables' ),
			types: checked( 'types' ).concat( [ '__none__' ] ),
			clear_cache: $( '#dsr-clear-cache' ).is( ':checked' ) ? '1' : '0',
			transients: $( '#dsr-transients' ).is( ':checked' ) ? '1' : '0',
			speed: $( 'input[name=dsr-speed]:checked' ).val() || 'gentle'
		} ).then( function ( data ) {
			runId = data.run_id;
			tables = data.tables;
			pause = data.pause || 0;
			tables.forEach( function ( t ) {
				estimate[ t ] = Math.max( 1, data.rows[ t ] || 0 );
				scanned[ t ] = 0;
			} );
			return nextTable();
		} ).then( function () {
			render( 'Finishing (clearing caches)...' );
			return api( 'finish', { run_id: runId } );
		} ).then( function ( summary ) {
			keepPageOpen( false );
			render( apply ? 'Done.' : 'Dry run complete. Nothing was changed.', true );
			report( mode, stats, samples, errors, rules, summary.purged || [], links );
			refreshHistory();
		} ).fail( function ( msg ) {
			keepPageOpen( false );
			render( 'Stopped: ' + msg + ( apply && runId ? ' What was already changed is backed up: History lists this run as interrupted, with Undo all.' : '' ), true );
			if ( apply ) {
				refreshHistory();
			}
		} ).always( function () {
			setBusy( false );
		} );

		function total( key ) {
			return Object.keys( stats ).reduce( function ( sum, t ) {
				return sum + stats[ t ][ key ];
			}, 0 );
		}

		function fraction() {
			var all = 0, done = 0;
			Object.keys( estimate ).forEach( function ( t ) {
				all += estimate[ t ];
				done += Math.min( scanned[ t ], estimate[ t ] );
			} );
			return all ? done / all : 0;
		}

		function render( title, finished ) {
			var f = finished ? 1 : Math.min( 0.99, fraction() );
			var elapsed = ( Date.now() - started ) / 1000;
			var time = 'elapsed ' + duration( elapsed );
			if ( ! finished && f > 0.03 ) {
				time += ' · about ' + duration( elapsed * ( 1 - f ) / f ) + ' left';
			}
			$progress.find( '.dsr-progress-head .spinner' ).toggleClass( 'is-active', ! finished );
			$progress.find( '.dsr-progress-title' ).text( title + ( finished ? '' : ' ' + Math.round( f * 100 ) + '%' ) );
			$progress.find( '.dsr-progress-time' ).text( time );
			$progress.find( '.dsr-bar span' ).css( 'width', ( f * 100 ) + '%' );

			var lines = [];
			if ( tables && index < tables.length && ! finished ) {
				lines.push( 'Table ' + ( index + 1 ) + ' of ' + tables.length + ': <code>' + esc( tables[ index ] ) + '</code>' +
					( cursorNow ? ' <span class="dsr-muted">(up to row ID ' + esc( cursorNow ) + ')</span>' : '' ) );
			}
			if ( tables ) {
				lines.push( '<strong>' + num( total( 'cells' ) ) + '</strong> values ' + ( apply ? 'updated' : 'would change' ) +
					' · <strong>' + num( total( 'replacements' ) ) + '</strong> ' + ( apply ? 'replacements' : 'matches' ) +
					( total( 'failed' ) ? ' · <span class="dsr-bad">' + num( total( 'failed' ) ) + ' failed</span>' : '' ) );
			}
			if ( note && ! finished ) {
				lines.push( '<span class="dsr-bad">' + esc( note ) + '</span>' );
			}
			$progress.find( '.dsr-status' ).html( lines.join( '<br>' ) );
		}

		function nextTable() {
			if ( index >= tables.length ) {
				return $.Deferred().resolve().promise();
			}
			var table = tables[ index ];
			stats[ table ] = { rows: 0, cells: 0, replacements: 0, failed: 0, skipped: '' };
			cursorNow = '';
			return step( table, '', 0 ).then( function () {
				scanned[ table ] = estimate[ table ];
				index++;
				return nextTable();
			} );
		}

		// A busy server may drop a request: wait and try the same slice again (it is safe to repeat).
		function step( table, cursor, attempt ) {
			render( apply ? 'Replacing...' : 'Scanning...' );
			return api( 'step', { run_id: runId, table: table, cursor: cursor } ).then( function ( r ) {
				var s = stats[ table ];
				note = '';
				s.rows += r.rows;
				s.cells += r.cells;
				s.replacements += r.replacements;
				s.failed += r.failed;
				s.skipped = r.skipped;
				scanned[ table ] += r.scanned || 0;
				cursorNow = r.cursor || '';
				samples = samples.concat( r.samples ).slice( 0, 60 );
				errors = errors.concat( r.errors ).slice( 0, 200 );
				Object.keys( r.links || {} ).forEach( function ( k ) {
					links[ k ] = ( links[ k ] || 0 ) + r.links[ k ];
				} );
				render( apply ? 'Replacing...' : 'Scanning...' );
				return r.done ? null : wait( pause ).then( function () {
					return step( table, r.cursor, 0 );
				} );
			}, function ( msg ) {
				if ( attempt >= 3 || /Unknown or finished run|not part of this run/.test( msg ) ) {
					return $.Deferred().reject( msg ).promise();
				}
				var delay = [ 10, 30, 60 ][ attempt ];
				note = 'The server did not answer (' + msg + '). Retrying in ' + delay + ' s, attempt ' + ( attempt + 2 ) + ' of 4...';
				render( apply ? 'Replacing...' : 'Scanning...' );
				return wait( delay * 1000 ).then( function () {
					return step( table, cursor, attempt + 1 );
				} );
			} );
		}
	}

	function wait( ms ) {
		var d = $.Deferred();
		setTimeout( d.resolve, ms );
		return d.promise();
	}

	function duration( seconds ) {
		seconds = Math.round( seconds );
		var m = Math.floor( seconds / 60 );
		return m ? m + ' min ' + ( seconds % 60 ) + ' s' : seconds + ' s';
	}

	function num( n ) {
		return Number( n ).toLocaleString();
	}

	// While a run, an undo or a restore is going, ask before the tab is closed or reloaded.
	function keepPageOpen( on ) {
		window.onbeforeunload = on ? function ( e ) {
			e.preventDefault();
			e.returnValue = '';
			return '';
		} : null;
	}

	function report( mode, stats, samples, errors, rules, purged, links ) {
		var totals = { rows: 0, cells: 0, replacements: 0 };
		var rows = '';
		Object.keys( stats ).forEach( function ( t ) {
			var s = stats[ t ];
			totals.rows += s.rows;
			totals.cells += s.cells;
			totals.replacements += s.replacements;
			if ( s.cells || s.skipped || s.failed ) {
				rows += '<tr><td>' + esc( t ) + '</td><td>' + s.rows + '</td><td>' + s.cells + '</td><td>' + s.replacements + '</td><td>' +
					( s.failed ? '<span class="dsr-bad">' + s.failed + ' failed</span>' : '' ) + esc( s.skipped ) + '</td></tr>';
			}
		} );

		var verb = mode === 'apply' ? 'Replaced' : 'Would replace';
		var html = '<div class="notice ' + ( mode === 'apply' ? 'notice-success' : 'notice-info' ) + ' inline"><p><strong>' + verb + ' ' +
			totals.replacements + ' occurrences</strong> in ' + totals.cells + ' values across ' + totals.rows + ' rows.' +
			( purged.length ? ' Cleared: ' + esc( purged.join( ', ' ) ) + '.' : '' ) + '</p></div>';

		if ( rows ) {
			html += '<table class="widefat striped dsr-summary"><thead><tr><th>Table</th><th>Rows</th><th>Values</th><th>Replacements</th><th>Notes</th></tr></thead><tbody>' + rows + '</tbody></table>';
		}
		var linkKeys = Object.keys( links || {} ).sort();
		if ( linkKeys.length ) {
			html += '<h3>Links ' + ( mode === 'apply' ? 'replaced' : 'found' ) + ' (' + linkKeys.length + ' distinct) ' +
				'<button type="button" class="button" id="dsr-links-csv">Download CSV</button></h3>' +
				'<div class="dsr-scroll"><table class="widefat striped dsr-report-table"><thead><tr><th>Link as written</th><th>New link</th><th>Times</th></tr></thead><tbody>' +
				linkKeys.map( function ( k ) {
					var p = k.split( '\t' );
					return '<tr><td><code>' + esc( p[ 0 ] ) + '</code></td><td><code>' + esc( p[ 1 ] ) + '</code></td><td>' + links[ k ] + '</td></tr>';
				} ).join( '' ) + '</tbody></table></div>';
		}
		if ( samples.length ) {
			html += '<h3>Examples</h3><table class="widefat dsr-samples"><thead><tr><th>Where</th><th>Before</th><th>After</th></tr></thead><tbody>';
			samples.forEach( function ( s ) {
				html += '<tr><td><code>' + esc( s.table ) + '</code><br>' + esc( s.column ) + ' #' + esc( s.id ) + '</td><td>' +
					highlight( s.before, rules, 'before' ) + '</td><td>' + highlight( s.after, rules, 'after' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}
		if ( errors.length ) {
			html += '<h3>Warnings</h3><ul class="dsr-errors">' + errors.slice( 0, 100 ).map( function ( e ) {
				return '<li>' + esc( e ) + '</li>';
			} ).join( '' ) + '</ul>';
		}
		$results.html( html );
		$results.find( '#dsr-links-csv' ).on( 'click', function () {
			download( 'links-' + mode + '.csv', [ [ 'old_link', 'new_link', 'times' ] ].concat( linkKeys.map( function ( k ) {
				var p = k.split( '\t' );
				return [ p[ 0 ], p[ 1 ], links[ k ] ];
			} ) ) );
		} );
	}

	function download( name, rows ) {
		var csv = rows.map( function ( row ) {
			return row.map( function ( v ) {
				return '"' + String( v == null ? '' : v ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\r\n' );
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( new Blob( [ '\ufeff' + csv ], { type: 'text/csv;charset=utf-8' } ) );
		a.download = name;
		document.body.appendChild( a );
		a.click();
		a.remove();
	}

	$( '#dsr-dry' ).on( 'click', function () {
		runDb( 'dry' );
	} );
	$( '#dsr-apply' ).on( 'click', function () {
		runDb( 'apply' );
	} );

	/* ---------- Theme files ---------- */

	var $files = $( '#dsr-files-results' );

	function filesRequest() {
		return {
			rules: JSON.stringify( getRules() ),
			roots: checked( 'roots' ),
			exts: checked( 'exts' ),
			extra: $( '#dsr-extra' ).val()
		};
	}

	$( '#dsr-files-scan' ).on( 'click', function () {
		setBusy( true );
		$files.html( '<p>Scanning...</p>' );
		api( 'files_scan', filesRequest() ).then( function ( list ) {
			if ( ! list.length ) {
				$files.html( '<div class="notice notice-success inline"><p>No matches in the selected files.</p></div>' );
				return;
			}
			var rules = getRules();
			var total = 0;
			var html = '<table class="widefat striped dsr-files"><thead><tr><td class="check-column"><input type="checkbox" id="dsr-files-all" checked></td><th>File</th><th>Matches</th><th>Changes</th></tr></thead><tbody>';
			list.forEach( function ( f ) {
				total += f.count;
				var lines = f.lines.map( function ( l ) {
					return '<div class="dsr-line"><span>L' + l[ 0 ] + '</span><div>' + highlight( l[ 1 ], rules, 'before' ) + '</div><div>' + highlight( l[ 2 ], rules, 'after' ) + '</div></div>';
				} ).join( '' );
				html += '<tr><th class="check-column"><input type="checkbox" name="dsr-file" value="' + esc( f.path ) + '" checked></th><td><code>' + esc( f.rel ) + '</code></td><td>' + f.count + '</td><td>' + lines + '</td></tr>';
			} );
			html += '</tbody></table><p class="dsr-actions"><strong>' + total + ' matches in ' + list.length + ' files.</strong> <button type="button" class="button button-primary" id="dsr-files-apply">Replace in selected files</button></p>';
			$files.html( html );
		} ).fail( function ( msg ) {
			$files.html( '<div class="notice notice-error inline"><p>' + esc( msg ) + '</p></div>' );
		} ).always( function () {
			setBusy( false );
		} );
	} );

	$files.on( 'change', '#dsr-files-all', function () {
		$files.find( 'input[name=dsr-file]' ).prop( 'checked', this.checked );
	} );

	$files.on( 'click', '#dsr-files-apply', function () {
		var paths = checked( 'dsr-file' );
		if ( ! paths.length || ! window.confirm( 'Rewrite ' + paths.length + ' files? Each one is backed up first and can be restored from History.' ) ) {
			return;
		}
		setBusy( true );
		api( 'files_apply', $.extend( filesRequest(), { files: paths } ) ).then( function ( run ) {
			var html = '<div class="notice notice-success inline"><p><strong>Updated ' + run.stats.files + ' files (' + run.stats.replacements + ' replacements).</strong> Backups: <code>' + esc( run.backup_dir ) + '</code></p></div>';
			if ( run.errors.length ) {
				html += '<ul class="dsr-errors">' + run.errors.map( function ( e ) {
					return '<li>' + esc( e ) + '</li>';
				} ).join( '' ) + '</ul>';
			}
			$files.html( html );
			refreshHistory();
		} ).fail( function ( msg ) {
			$files.prepend( '<div class="notice notice-error inline"><p>' + esc( msg ) + '</p></div>' );
		} ).always( function () {
			setBusy( false );
		} );
	} );

	/* ---------- Live filter ---------- */

	$( '#dsr-live-save' ).on( 'click', function () {
		var $btn = $( this );
		setBusy( true );
		api( 'live_save', {
			rules: JSON.stringify( getRules() ),
			enabled: $( '#dsr-live-enabled' ).is( ':checked' ) ? '1' : '0'
		} ).then( function ( described ) {
			$( '#dsr-live-rules' ).text( described );
			$btn.after( ' <span class="dsr-saved">Saved.</span>' );
			setTimeout( function () {
				$( '.dsr-saved' ).remove();
			}, 2500 );
		} ).fail( function ( msg ) {
			window.alert( msg );
		} ).always( function () {
			setBusy( false );
		} );
	} );

	/* ---------- History / undo ---------- */

	function refreshHistory() {
		return api( 'history' ).then( function ( html ) {
			$( '#dsr-history' ).html( html );
		} );
	}

	/** Revert everything a run changed (whatever was not restored item by item yet). */
	function undoRun( runId, $status ) {
		if ( ! window.confirm( 'Revert ALL changes of this run? Every page, option and field goes back to its original value. Values edited since the run are left alone.' ) ) {
			return;
		}
		var restored = 0;
		var conflicts = [];
		setBusy( true );
		keepPageOpen( true );

		$status.html( '<span class="spinner is-active dsr-inline-spinner"></span> Reverting... keep this tab open' );
		( function next() {
			return api( 'undo', { run_id: runId } ).then( function ( r ) {
				restored += r.restored;
				conflicts = conflicts.concat( r.conflicts );
				$status.html( '<span class="spinner is-active dsr-inline-spinner"></span> Reverting... <strong>' + num( restored ) + '</strong> values restored so far' +
					( conflicts.length ? ', ' + num( conflicts.length ) + ' skipped' : '' ) + '. Keep this tab open.' );
				// A short pause between batches leaves the server room for visitors.
				return r.done ? null : wait( 400 ).then( next );
			} );
		}() ).then( function () {
			$( '#dsr-items' ).empty();
			return refreshHistory();
		} ).then( function () {
			var msg = 'Restored ' + restored + ' values.';
			if ( conflicts.length ) {
				msg += ' ' + conflicts.length + ' were skipped because they changed after the run:\n\n' + conflicts.slice( 0, 20 ).join( '\n' );
			}
			window.alert( msg );
		} ).fail( function ( msg ) {
			window.alert( 'Undo failed: ' + msg );
		} ).always( function () {
			setBusy( false );
			keepPageOpen( false );
		} );
	}

	$( '#dsr-history' ).on( 'click', '.dsr-undo', function () {
		undoRun( $( this ).data( 'run' ), $( this ).parent() );
	} );

	/* ---------- Report: where were the matches? ---------- */

	var $report = $( '#dsr-report' );

	function whereLabel( it ) {
		var col = it.column.replace( /^post_/, '' );
		if ( it.table.match( /_posts$/ ) ) {
			return ( it.post_type || 'post' ) + ': ' + col;
		}
		if ( it.table.match( /_postmeta$/ ) ) {
			return ( it.post_type || 'post' ) + ': field "' + ( it.label || '?' ) + '"';
		}
		if ( it.table.match( /_options$/ ) ) {
			return 'Option "' + ( it.label || it.id ) + '"';
		}
		return it.table + ': ' + it.column;
	}

	function renderReport( items ) {
		var total = 0;
		var groups = {};
		var pages = {};

		items.forEach( function ( it ) {
			total += it.occurrences;
			var g = whereLabel( it );
			groups[ g ] = groups[ g ] || { values: 0, occ: 0 };
			groups[ g ].values++;
			groups[ g ].occ += it.occurrences;

			if ( it.edit && ! /^revision/.test( it.post_type || '' ) ) {
				var p = pages[ it.edit ] = pages[ it.edit ] || { title: it.title, type: it.post_type, edit: it.edit, fields: {}, occ: 0 };
				p.fields[ it.label || it.column.replace( /^post_/, '' ) ] = true;
				p.occ += it.occurrences;
			}
		} );

		var byOcc = function ( a, b ) {
			return b.occ - a.occ;
		};

		var html = '<h2>Report</h2><p><strong>' + total + ' occurrences in ' + items.length + ' values.</strong> ' +
			'<button type="button" class="button" id="dsr-report-csv">Download CSV (every value)</button></p>';

		html += '<h3>By location</h3><table class="widefat striped dsr-report-table"><thead><tr><th>Where</th><th>Values</th><th>Occurrences</th></tr></thead><tbody>';
		Object.keys( groups ).map( function ( k ) {
			return { name: k, values: groups[ k ].values, occ: groups[ k ].occ };
		} ).sort( byOcc ).forEach( function ( g ) {
			html += '<tr><td>' + esc( g.name ) + '</td><td>' + g.values + '</td><td>' + g.occ + '</td></tr>';
		} );
		html += '</tbody></table>';

		var list = Object.keys( pages ).map( function ( k ) {
			return pages[ k ];
		} ).sort( byOcc );
		html += '<h3>Live pages, posts &amp; items changed (' + list.length + ', revisions not included)</h3>' +
			'<table class="widefat striped dsr-report-table"><thead><tr><th>Title</th><th>Type</th><th>Fields</th><th>Occurrences</th></tr></thead><tbody>';
		list.forEach( function ( p ) {
			html += '<tr><td><a href="' + esc( p.edit ) + '" target="_blank" rel="noopener">' + esc( p.title || '(no title)' ) + '</a></td><td>' + esc( p.type ) +
				'</td><td>' + esc( Object.keys( p.fields ).join( ', ' ) ) + '</td><td>' + p.occ + '</td></tr>';
		} );
		html += '</tbody></table>';

		$report.html( html );
		$report.find( '#dsr-report-csv' ).on( 'click', function () {
			var cols = [ 'table', 'id', 'column', 'post_type', 'title', 'label', 'occurrences', 'links', 'edit' ];
			var csv = [ 'table,id,column,post_type,title,field_or_option,occurrences,links_changed,edit_url' ].concat( items.map( function ( it ) {
				return cols.map( function ( c ) {
					var v = it[ c ] == null ? '' : String( it[ c ] );
					return '"' + v.replace( /"/g, '""' ) + '"';
				} ).join( ',' );
			} ) ).join( '\r\n' );
			var a = document.createElement( 'a' );
			a.href = URL.createObjectURL( new Blob( [ '﻿' + csv ], { type: 'text/csv;charset=utf-8' } ) );
			a.download = 'search-replace-report.csv';
			document.body.appendChild( a );
			a.click();
			a.remove();
		} );
	}

	$( '#dsr-history' ).on( 'click', '.dsr-report', function () {
		var runId = $( this ).data( 'run' );
		var items = [];
		setBusy( true );

		( function next( after ) {
			$report.html( '<p>Building report... ' + items.length + ' values so far</p>' );
			return api( 'report', { run_id: runId, after: after } ).then( function ( r ) {
				items = items.concat( r.items );
				return r.done ? null : next( r.next );
			} );
		}( 0 ) ).then( function () {
			renderReport( items );
		} ).fail( function ( msg ) {
			$report.html( '<div class="notice notice-error inline"><p>' + esc( msg ) + '</p></div>' );
		} ).always( function () {
			setBusy( false );
		} );
	} );

	/* ---------- Restore individual pages / items ---------- */

	var $items = $( '#dsr-items' );
	var view = null; // { runId, type, search, postType, page, cursors, total, perPage, seq }
	var PER_PAGE_CHOICES = [ 20, 50, 100, 200 ];
	var searchTimer = null;

	// The chosen page size is remembered per browser; storage may be unavailable.
	function savedPerPage() {
		try {
			var n = parseInt( window.localStorage.getItem( 'dsrItemsPerPage' ), 10 );
			return PER_PAGE_CHOICES.indexOf( n ) > -1 ? n : 50;
		} catch ( e ) {
			return 50;
		}
	}

	function savePerPage( n ) {
		try {
			window.localStorage.setItem( 'dsrItemsPerPage', String( n ) );
		} catch ( e ) {}
	}

	$( '#dsr-history' ).on( 'click', '.dsr-items-open', function () {
		var runId = $( this ).data( 'run' );
		var date = $( this ).data( 'date' );
		$report.empty();
		setBusy( true );
		keepPageOpen( true );

		// Runs made before this version are indexed once, in batches, before the list can be shown.
		( function index() {
			return api( 'items_index', { run_id: runId } ).then( function ( r ) {
				if ( r.done ) {
					return r;
				}
				$items.html( '<p>Preparing the page list for this run (one time only)... ' + num( r.pending ) + ' values left</p>' );
				return index();
			} );
		}() ).then( function ( r ) {
			if ( ! r.types.length ) {
				$items.html( '<div class="notice notice-info inline"><p>Nothing left to restore in this run.</p></div>' );
				return null;
			}
			view = { runId: runId, type: r.types[ 0 ].type, search: '', postType: '', page: 0, cursors: [ 0 ], total: 0, perPage: savedPerPage(), seq: 0 };
			renderItemsPanel( date, r.types );
			return loadItems( true );
		} ).fail( function ( msg ) {
			$items.html( '<div class="notice notice-error inline"><p>' + esc( msg ) + '</p></div>' );
		} ).always( function () {
			setBusy( false );
			keepPageOpen( false );
			updateItemsNav();
		} );
	} );

	function renderItemsPanel( date, types ) {
		var typeOptions = types.map( function ( t ) {
			return '<option value="' + esc( t.type ) + '">' + esc( t.label ) + ' (' + num( t.count ) + ')</option>';
		} ).join( '' );
		var postTypeOptions = '<option value="">All post types</option>' + DSR.postTypes.filter( function ( t ) {
			return t !== 'revision';
		} ).map( function ( t ) {
			return '<option value="' + esc( t ) + '">' + esc( t ) + '</option>';
		} ).join( '' );

		$items.html(
			'<div class="dsr-card">' +
				'<h2>Pages changed by this run <small>' + esc( date ) + '</small></h2>' +
				'<p class="description"><strong>Restore original</strong> on a row puts back that one page only: title, content, Elementor / ACF fields, SEO fields and any revisions this run changed; everything else stays replaced. To put back everything, use <strong>Undo all</strong> above. Fields edited after the run are left alone either way.</p>' +
				'<div class="dsr-items-filters">' +
					'<select class="dsr-items-type">' + typeOptions + '</select>' +
					'<select class="dsr-items-ptype">' + postTypeOptions + '</select>' +
					'<input type="search" class="dsr-items-search" placeholder="Search by title or ID..." autocomplete="off">' +
					'<span class="spinner dsr-items-spinner"></span>' +
				'</div>' +
				'<div class="dsr-items-list"></div>' +
				navHtml() +
			'</div>'
		);
	}

	function navHtml() {
		var perPage = PER_PAGE_CHOICES.map( function ( n ) {
			return '<option value="' + n + '"' + ( n === view.perPage ? ' selected' : '' ) + '>' + n + '</option>';
		} ).join( '' );
		return '<div class="dsr-items-nav">' +
			'<span class="dsr-items-count"></span>' +
			'<span class="dsr-items-pager">' +
				'<button type="button" class="button dsr-items-first" title="First page">&laquo;</button>' +
				'<button type="button" class="button dsr-items-prev" title="Previous page">&lsaquo;</button>' +
				'<span>Page <input type="number" class="small-text dsr-items-pagenum" min="1" value="1"> of <span class="dsr-items-pages">1</span></span>' +
				'<button type="button" class="button dsr-items-next" title="Next page">&rsaquo;</button>' +
				'<button type="button" class="button dsr-items-last" title="Last page">&raquo;</button>' +
			'</span>' +
			'<label class="dsr-items-perpage">Show <select class="dsr-items-perpage-select">' + perPage + '</select> per page</label>' +
		'</div>';
	}

	function pageCount() {
		return Math.max( 1, Math.ceil( view.total / view.perPage ) );
	}

	/**
	 * Load view.page. Next / previous continue after the last ID of the neighbouring page
	 * (cheap at any depth); a page jumped to directly is fetched by offset.
	 * Only the latest request is rendered, so fast typing never shows stale results.
	 */
	function loadItems( withTotal ) {
		var seq = ++view.seq;
		var cursor = view.cursors[ view.page ];
		$items.find( '.dsr-items-list' ).addClass( 'dsr-loading' );
		$items.find( '.dsr-items-spinner' ).addClass( 'is-active' );
		return api( 'items', {
			run_id: view.runId,
			type: view.type,
			after: cursor || 0,
			offset: cursor == null ? view.page * view.perPage : 0,
			per_page: view.perPage,
			search: view.search,
			post_type: view.postType,
			total: withTotal ? '1' : '0'
		} ).then( function ( r ) {
			if ( seq !== view.seq ) {
				return;
			}
			if ( withTotal ) {
				view.total = r.total;
			}
			view.cursors[ view.page + 1 ] = r.next;
			renderItems( r.items );
		} ).fail( function ( msg ) {
			if ( seq === view.seq ) {
				$items.find( '.dsr-items-list' ).html( '<div class="notice notice-error inline"><p>' + esc( msg ) + '</p></div>' );
			}
		} ).always( function () {
			if ( seq === view.seq ) {
				$items.find( '.dsr-items-list' ).removeClass( 'dsr-loading' );
				$items.find( '.dsr-items-spinner' ).removeClass( 'is-active' );
				updateItemsNav();
			}
		} );
	}

	function goToPage( page ) {
		page = Math.min( pageCount() - 1, Math.max( 0, page ) );
		if ( page === view.page ) {
			updateItemsNav();
			return;
		}
		view.page = page;
		loadItems( false );
	}

	function renderItems( list ) {
		if ( ! list.length ) {
			$items.find( '.dsr-items-list' ).html( '<p>No items found.</p>' );
			return;
		}
		var html = '<table class="widefat striped dsr-items-table"><thead><tr><th>Item</th><th>Changed</th><th>Values</th><th></th></tr></thead><tbody>';
		list.forEach( function ( it ) {
			var title = it.edit ? '<a href="' + esc( it.edit ) + '" target="_blank" rel="noopener">' + esc( it.title ) + '</a>' : esc( it.title );
			var meta = [ esc( it.kind ), esc( it.status ), 'ID ' + it.id ].filter( Boolean );
			if ( it.view ) {
				meta.push( '<a href="' + esc( it.view ) + '" target="_blank" rel="noopener">View</a>' );
			}
			var fields = esc( it.fields.join( ', ' ) );
			if ( it.revisions ) {
				fields += ( fields ? '<br>' : '' ) + '<span class="dsr-muted">+ ' + it.revisions + ' in revisions</span>';
			}
			html += '<tr data-id="' + it.id + '" data-title="' + esc( it.title ) + '">' +
				'<td><strong>' + title + '</strong><div class="dsr-muted">' + meta.join( ' · ' ) + '</div></td>' +
				'<td>' + fields + '</td><td>' + it.values + '</td>' +
				'<td class="dsr-items-action"><button type="button" class="button dsr-restore-item">Restore original</button></td></tr>';
		} );
		$items.find( '.dsr-items-list' ).html( html + '</tbody></table>' );
	}

	function updateItemsNav() {
		if ( ! view ) {
			return;
		}
		var pages = pageCount();
		var first = view.total ? view.page * view.perPage + 1 : 0;
		var last = Math.min( view.total, ( view.page + 1 ) * view.perPage );
		$items.find( '.dsr-items-count' ).text( view.total ? num( first ) + '–' + num( last ) + ' of ' + num( view.total ) + ' items' : 'No items' );
		$items.find( '.dsr-items-pages' ).text( num( pages ) );
		$items.find( '.dsr-items-pagenum' ).val( view.page + 1 ).attr( 'max', pages );
		$items.find( '.dsr-items-first, .dsr-items-prev' ).prop( 'disabled', view.page === 0 );
		$items.find( '.dsr-items-next, .dsr-items-last' ).prop( 'disabled', view.page >= pages - 1 );
		$items.find( '.dsr-items-perpage-select' ).val( view.perPage );
		$items.find( '.dsr-items-ptype' ).toggle( view.type === 'post' );
	}

	/** Filters changed: back to page 1, recount. */
	function applyItemsFilter() {
		clearTimeout( searchTimer );
		view.type = $items.find( '.dsr-items-type' ).val();
		view.postType = view.type === 'post' ? $items.find( '.dsr-items-ptype' ).val() : '';
		view.search = $.trim( $items.find( '.dsr-items-search' ).val() );
		view.page = 0;
		view.cursors = [ 0 ];
		loadItems( true );
	}

	$items.on( 'change', '.dsr-items-type, .dsr-items-ptype', applyItemsFilter );

	// Live search: wait until typing pauses; a title search needs 2+ characters (an ID any length).
	$items.on( 'input', '.dsr-items-search', function () {
		var q = $.trim( this.value );
		clearTimeout( searchTimer );
		if ( q === view.search || ( q.length === 1 && ! /^\d$/.test( q ) ) ) {
			return;
		}
		searchTimer = setTimeout( applyItemsFilter, 350 );
	} );
	$items.on( 'keydown', '.dsr-items-search', function ( e ) {
		if ( e.which === 13 ) {
			e.preventDefault();
			applyItemsFilter();
		}
	} );

	$items.on( 'click', '.dsr-items-first', function () {
		goToPage( 0 );
	} );
	$items.on( 'click', '.dsr-items-prev', function () {
		goToPage( view.page - 1 );
	} );
	$items.on( 'click', '.dsr-items-next', function () {
		goToPage( view.page + 1 );
	} );
	$items.on( 'click', '.dsr-items-last', function () {
		goToPage( pageCount() - 1 );
	} );
	$items.on( 'change', '.dsr-items-pagenum', function () {
		goToPage( ( parseInt( this.value, 10 ) || 1 ) - 1 );
	} );
	$items.on( 'keydown', '.dsr-items-pagenum', function ( e ) {
		if ( e.which === 13 ) {
			e.preventDefault();
			$( this ).trigger( 'change' );
		}
	} );
	$items.on( 'change', '.dsr-items-perpage-select', function () {
		view.perPage = parseInt( this.value, 10 ) || 50;
		savePerPage( view.perPage );
		view.page = 0;
		view.cursors = [ 0 ];
		loadItems( false );
	} );

	$items.on( 'click', '.dsr-restore-item', function () {
		var $row = $( this ).closest( 'tr' );
		var $cell = $row.find( '.dsr-items-action' );
		var id = $row.data( 'id' );
		if ( ! window.confirm( 'Restore the original version of "' + $row.data( 'title' ) + '"?\n\nOnly this item is reverted; the rest of the run stays replaced. Fields edited since the run are left alone.' ) ) {
			return;
		}
		var restored = 0;
		var conflicts = [];
		var status = 'done';
		setBusy( true );
		keepPageOpen( true );
		$cell.text( 'Restoring...' );

		( function next() {
			return api( 'restore_item', { run_id: view.runId, type: view.type, id: id } ).then( function ( r ) {
				restored += r.restored;
				conflicts = conflicts.concat( r.conflicts );
				status = r.status;
				return r.done ? null : next();
			} );
		}() ).then( function () {
			var html = '<span class="dsr-ok">Restored ' + restored + ' values.</span>';
			if ( conflicts.length ) {
				html += '<details><summary>' + conflicts.length + ' skipped (edited after the run)</summary><ul class="dsr-errors">' +
					conflicts.map( function ( c ) {
						return '<li>' + esc( c ) + '</li>';
					} ).join( '' ) + '</ul></details>';
			}
			$row.addClass( 'dsr-restored' );
			$cell.html( html );
			view.total = Math.max( 0, view.total - 1 );
			if ( status !== 'done' ) {
				refreshHistory(); // That was the last item: the run is fully undone.
			}
		} ).fail( function ( msg ) {
			$cell.html( '<span class="dsr-bad">' + esc( msg ) + '</span>' );
		} ).always( function () {
			setBusy( false );
			keepPageOpen( false );
			updateItemsNav();
		} );
	} );

	$( '#dsr-history' ).on( 'click', '.dsr-delete-backup', function () {
		if ( window.confirm( 'Delete the backup for this run? It can no longer be undone afterwards.' ) ) {
			$items.empty();
			api( 'delete_backup', { run_id: $( this ).data( 'run' ) } ).then( refreshHistory );
		}
	} );
}( jQuery ) );
