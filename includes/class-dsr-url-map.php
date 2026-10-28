<?php
/**
 * Bulk URL replacement: a map of old URL => new URL, matched as whole URLs.
 *
 * Built for fixing internal links that point at redirected URLs. A source only
 * matches a complete URL: "/old-page/" matches href="/old-page/", "/old-page",
 * "https://www.example.com/old-page/?utm=x" and Elementor's "https:\/\/example.com\/old-page\/",
 * but never "/old-page/child/" or "/old-page-2/". Query strings and fragments in the
 * content are kept. The escaping of the original (plain, JSON "\/", double-escaped "\\\/")
 * is reproduced in the replacement, so JSON and serialized data stay valid.
 *
 * Map format: one pair per line, "old,new" (comma, tab or semicolon). Quotes around
 * values are fine. A header row is skipped. Sources may be paths ("/old/", "old/") or
 * absolute URLs on one of the site domains.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Url_Map {

	/** Above this many distinct sources, rows are not pre-filtered in SQL (too many LIKEs). */
	const SQL_NEEDLE_LIMIT = 40;

	/** Hops followed when a destination is itself a source (A -> B -> C becomes A -> C). */
	const MAX_HOPS = 10;

	/** Hard cap on map size, to keep a run's stored settings reasonable. */
	const MAX_PAIRS = 20000;

	/** URL path characters that can continue a path. Anything else ends it. */
	const PATH_CHARS = 'A-Za-z0-9\-._~%+@!$*';

	/** @var string[] normalized source key => destination as written in the map */
	private $map = array();

	/** @var string[] site hosts, lowercase, without "www." */
	private $hosts = array();

	/** @var string[] literal pieces worth an SQL LIKE pre-filter */
	private $segments = array();

	/** @var string|null */
	private $regex = null;

	/** @var string[] Lines that were skipped, with the reason. */
	public $problems = array();

	/** @var string[] Sources whose destination was another source (chain resolved). */
	public $chains = array();

	/**
	 * @param string          $csv     The map text.
	 * @param string|string[] $domains Extra domains that count as this site (comma / space / newline separated).
	 */
	public function __construct( $csv, $domains = '' ) {
		foreach ( self::split_list( $domains ) as $domain ) {
			$this->add_host( $domain );
		}
		if ( function_exists( 'home_url' ) ) {
			$this->add_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$this->add_host( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
		}

		// Absolute sources on any other domain are skipped (reported), never trusted as "this site".
		$pairs = $this->parse( (string) $csv );

		$raw = array();
		foreach ( $pairs as $pair ) {
			list( $from, $to, $line ) = $pair;
			$key = $this->key_of( $from );
			if ( null === $key ) {
				$this->problems[] = "Line $line: \"$from\" is on another domain, skipped (add the domain under Site domains if it is this site)";
				continue;
			}
			if ( '/' === $key ) {
				$this->problems[] = "Line $line: the home page cannot be a source, skipped";
				continue;
			}
			if ( isset( $raw[ $key ] ) ) {
				if ( $raw[ $key ]['to'] !== $to ) {
					$this->problems[] = "Line $line: \"$from\" is listed twice with different destinations, the first one is used";
				}
				continue;
			}
			$raw[ $key ] = array(
				'to'   => $to,
				'from' => $from,
				'line' => $line,
			);
		}

		// Follow chains to the final destination; drop loops and self-redirects.
		foreach ( $raw as $key => $entry ) {
			$to   = $entry['to'];
			$seen = array( $key => true );
			$hops = 0;
			$bad  = '';
			while ( true ) {
				$next = $this->key_of( $to );
				if ( null === $next || ! isset( $raw[ $next ] ) ) {
					break;
				}
				if ( isset( $seen[ $next ] ) ) {
					$bad = 'redirect loop';
					break;
				}
				if ( ++$hops > self::MAX_HOPS ) {
					$bad = 'chain longer than ' . self::MAX_HOPS . ' hops';
					break;
				}
				$seen[ $next ] = true;
				$to            = $raw[ $next ]['to'];
			}
			if ( '' !== $bad ) {
				$this->problems[] = "Line {$entry['line']}: \"{$entry['from']}\" is part of a $bad, skipped";
				continue;
			}
			if ( $this->key_of( $to ) === $key ) {
				$this->problems[] = "Line {$entry['line']}: \"{$entry['from']}\" points to itself, skipped";
				continue;
			}
			if ( $hops > 0 ) {
				$this->chains[] = "{$entry['from']} -> {$entry['to']} -> ... -> $to";
			}
			$this->map[ $key ] = $to;

			$parts = array_values( array_filter( explode( '/', self::path_of( $entry['from'] ) ), 'strlen' ) );
			if ( $parts ) {
				$this->segments[ end( $parts ) ] = true;
			}
		}
		$this->segments = array_keys( $this->segments );
	}

	/** @return int Usable pairs. */
	public function count() {
		return count( $this->map );
	}

	/** @return string[] */
	public function hosts() {
		return $this->hosts;
	}

	/** @return array normalized source => destination */
	public function pairs() {
		return $this->map;
	}

	/**
	 * Literal strings for an SQL pre-filter, or null when there are too many to be worth it
	 * (then every row is checked in PHP, which is still fast).
	 *
	 * @return string[]|null
	 */
	public function sql_needles() {
		if ( ! $this->map ) {
			return array();
		}
		return count( $this->segments ) > self::SQL_NEEDLE_LIMIT ? null : $this->segments;
	}

	/** Cheap check before the regex runs. */
	public function may_match( $value ) {
		if ( ! $this->map || false === strpos( $value, '/' ) ) {
			return false;
		}
		if ( count( $this->segments ) > self::SQL_NEEDLE_LIMIT ) {
			return true;
		}
		foreach ( $this->segments as $segment ) {
			if ( false !== stripos( $value, $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace every mapped URL in a string.
	 *
	 * @param string $value
	 * @param int    $count Incremented per replacement.
	 * @param array  $log   "old\tnew" => occurrences.
	 * @return string
	 */
	public function replace( $value, &$count, array &$log ) {
		if ( ! $this->may_match( $value ) ) {
			return $value;
		}
		$result = preg_replace_callback(
			$this->regex(),
			function ( $m ) use ( &$count, &$log ) {
				$pre  = isset( $m['pre'] ) ? $m['pre'] : '';
				$path = $m['path'];
				$key  = self::normalize_path( $path );
				if ( ! isset( $this->map[ $key ] ) ) {
					return $m[0];
				}
				$target = $this->map[ $key ];
				$esc    = preg_match( '#^(\\\\*)/#', $path, $e ) ? $e[1] : '';
				$out    = $this->render( $pre, $target, $esc );
				if ( $out === $m[0] ) {
					return $m[0];
				}
				$count++;
				$from         = str_replace( '\\', '', $pre . $path );
				$entry        = $from . "\t" . str_replace( '\\', '', $out );
				$log[ $entry ] = isset( $log[ $entry ] ) ? $log[ $entry ] + 1 : 1;
				return $out;
			},
			$value
		);
		return null === $result ? $value : $result;
	}

	/**
	 * The replacement, in the same form as the original: absolute stays absolute (same
	 * scheme and host as written, unless the destination is a full URL), relative stays
	 * relative, and slashes are escaped the same way.
	 */
	private function render( $pre, $target, $esc ) {
		$target_host = self::host_of( $target );
		if ( null !== $target_host ) {
			if ( '' === $pre && in_array( $target_host, $this->hosts, true ) ) {
				$target = self::path_of( $target, true ); // Relative link to this site stays relative.
			}
			return str_replace( '/', $esc . '/', $target );
		}
		return $pre . str_replace( '/', $esc . '/', $target );
	}

	private function regex() {
		if ( null !== $this->regex ) {
			return $this->regex;
		}
		$slash = '\\\\*/'; // "/", "\/" (JSON), "\\\/" (JSON inside JSON).
		$hosts = array();
		foreach ( $this->hosts as $host ) {
			$hosts[] = preg_quote( $host, '#' );
		}
		usort(
			$hosts,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$chars = '[' . self::PATH_CHARS . ']';
		$abs   = $hosts
			? '(?<pre>(?:https?:)?' . $slash . $slash . '(?:www\.)?(?:' . implode( '|', $hosts ) . ')(?::\d+)?)(?=' . $slash . ')'
			: '(?!)';
		// Relative links only right after a quote (attribute, JSON string) or at the very start
		// of a stored value (menu item URL, ACF link field), never "//other-host".
		$rel = '(?:(?<=["\'])|(?<=&quot;)|(?<=&\#039;)|(?<=&\#34;)|(?<=&\#x27;)|^)(?!' . $slash . $slash . ')';

		$this->regex = '#(?:' . $abs . '|' . $rel . ')(?<path>(?:' . $slash . $chars . '*+)++)(?!' . $chars . ')#i';
		return $this->regex;
	}

	/* ---------- Parsing & normalizing ---------- */

	/** @return array[] [ from, to, line number ] */
	private function parse( $csv ) {
		$pairs  = array();
		$lines  = preg_split( '/\r\n|\r|\n/', $csv );
		$header = true;
		foreach ( $lines as $i => $line ) {
			$n    = $i + 1;
			$line = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $line ) );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$cells = self::cells( $line );
			if ( count( $cells ) < 2 || '' === $cells[0] || '' === $cells[1] ) {
				if ( $header && ! self::looks_like_url( isset( $cells[0] ) ? $cells[0] : '' ) ) {
					$header = false;
					continue;
				}
				$this->problems[] = "Line $n: needs two values (old, new), skipped";
				continue;
			}
			if ( $header ) {
				$header = false;
				if ( ! self::looks_like_url( $cells[0] ) ) {
					continue; // "Source,Destination" header row.
				}
			}
			list( $from, $to ) = $cells;
			foreach ( array( $from, $to ) as $url ) {
				if ( preg_match( '/\s|["<>]/', $url ) ) {
					$this->problems[] = "Line $n: \"$url\" contains spaces, quotes or brackets, skipped";
					continue 2;
				}
			}
			if ( preg_match( '/[?#]/', self::strip_host( $from ) ) ) {
				$this->problems[] = "Line $n: \"$from\" has a query string or #fragment, skipped (only plain URLs are matched)";
				continue;
			}
			if ( preg_match( '/[\^$*()\[\]{}|\\\\]/', $from ) ) {
				$this->problems[] = "Line $n: \"$from\" looks like a regex or wildcard pattern, skipped";
				continue;
			}
			$pairs[] = array( $from, self::normalize_target( $to ), $n );
			if ( count( $pairs ) >= self::MAX_PAIRS ) {
				$this->problems[] = 'Only the first ' . self::MAX_PAIRS . ' lines are used';
				break;
			}
		}
		return $pairs;
	}

	private static function cells( $line ) {
		$sep = "\t";
		if ( false === strpos( $line, "\t" ) ) {
			$sep = false !== strpos( $line, ',' ) ? ',' : ';';
		}
		$cells = str_getcsv( $line, $sep, '"', '\\' );
		return array_values( array_map( 'trim', array_slice( $cells, 0, 2 ) ) );
	}

	private static function looks_like_url( $s ) {
		return (bool) preg_match( '#^(?:https?:)?//|^/|^[A-Za-z0-9][A-Za-z0-9\-._~%]*/#', $s );
	}

	private static function normalize_target( $to ) {
		if ( null === self::host_of( $to ) && '/' !== $to[0] ) {
			$to = '/' . $to;
		}
		return $to;
	}

	/** Normalized key: lowercase, decoded, no escaping, one trailing slash. Null for another domain. */
	private function key_of( $url ) {
		$host = self::host_of( $url );
		if ( null !== $host && ! in_array( $host, $this->hosts, true ) ) {
			return null;
		}
		return self::normalize_path( self::path_of( $url ) );
	}

	private static function normalize_path( $path ) {
		$path = strtolower( rawurldecode( str_replace( '\\', '', $path ) ) );
		$path = '/' . trim( $path, '/' );
		return '/' === $path ? '/' : $path . '/';
	}

	/** Lowercase host without "www." and port, or null for a relative URL. */
	private static function host_of( $url ) {
		if ( ! preg_match( '#^(?:https?:)?//([^/?\#]+)#i', $url, $m ) ) {
			return null;
		}
		return preg_replace( '/^www\.|:\d+$/', '', strtolower( $m[1] ) );
	}

	/** Path of a URL (without query/fragment unless asked). */
	private static function path_of( $url, $keep_query = false ) {
		$rest = self::strip_host( $url );
		if ( ! $keep_query ) {
			$rest = preg_replace( '/[?#].*$/s', '', $rest );
		}
		if ( '' === $rest || '/' !== $rest[0] ) {
			$rest = '/' . $rest;
		}
		return $rest;
	}

	private static function strip_host( $url ) {
		return preg_replace( '#^(?:https?:)?//[^/?\#]+#i', '', $url );
	}

	private function add_host( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^(?:https?:)?//#', '', $domain );
		$domain = preg_replace( '#[/?\#].*$#', '', $domain );
		$domain = preg_replace( '/^www\.|:\d+$/', '', $domain );
		if ( '' !== $domain && preg_match( '/^[a-z0-9.\-]+$/', $domain ) && ! in_array( $domain, $this->hosts, true ) ) {
			$this->hosts[] = $domain;
			$this->regex   = null;
		}
	}

	private static function split_list( $list ) {
		if ( is_array( $list ) ) {
			return $list;
		}
		return array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $list ) ), 'strlen' );
	}

	/** Number of non-empty lines, for describing a rule without parsing it. */
	public static function line_count( $csv ) {
		return count( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $csv ) ), 'strlen' ) );
	}
}
