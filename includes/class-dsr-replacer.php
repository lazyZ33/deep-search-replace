<?php
/**
 * Context-aware replacement engine.
 *
 * Knows how a string may be stored (plain text / HTML, Gutenberg block markup,
 * JSON such as Elementor's _elementor_data, PHP-serialized data such as ACF
 * options, widgets and theme mods) and replaces inside each without breaking it.
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Replacer {

	const MAX_DEPTH = 12;
	const JSON_RAW  = 320; // JSON_UNESCAPED_UNICODE (256) | JSON_UNESCAPED_SLASHES (64).

	/** @var array[] Compiled rules: [ 'pattern' => regex, 'variants' => find => [ 'text' => .., 'json' => .. ] ]. */
	private $compiled = array();

	/** @var string[] Every literal that can start a match. Used for fast pre-checks and SQL LIKE filters. */
	private $needles = array();

	/** @var int Replacements made by this instance. */
	public $count = 0;

	/** @var string[] Values that were left untouched because replacing would have corrupted them. */
	public $errors = array();

	/** @var DSR_Url_Map|null Bulk URL map (rule type "urls"). */
	private $url_map = null;

	/** @var array "old URL\tnew URL" => occurrences, for URL map replacements made by this instance. */
	public $links = array();

	/**
	 * @param array[] $rules Each rule: search, replace, encoded (bool), absorb_spaces (bool).
	 */
	public function __construct( array $rules ) {
		foreach ( $rules as $rule ) {
			if ( isset( $rule['type'] ) && 'urls' === $rule['type'] ) {
				$map = new DSR_Url_Map( isset( $rule['csv'] ) ? $rule['csv'] : '', isset( $rule['domains'] ) ? $rule['domains'] : '' );
				if ( $map->count() ) {
					$this->url_map = $map;
				}
				continue;
			}
			$search = isset( $rule['search'] ) ? (string) $rule['search'] : '';
			if ( '' === $search ) {
				continue;
			}
			$replace  = isset( $rule['replace'] ) ? (string) $rule['replace'] : '';
			$variants = empty( $rule['encoded'] )
				? array( $search => array( 'text' => $replace, 'json' => self::json_inner( $replace, self::JSON_RAW ) ) )
				: self::build_variants( $search, $replace );

			$finds = array_map( 'strval', array_keys( $variants ) );
			usort(
				$finds,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			$quoted = array();
			foreach ( $finds as $find ) {
				$quoted[] = preg_quote( $find, '/' );
			}
			$alt     = implode( '|', $quoted );
			$ws      = empty( $rule['absorb_spaces'] ) ? '' : self::ws();
			$pattern = $ws . '(' . $alt . ')' . $ws;

			// A match at the very start or end of a piece of text (attribution lines such as
			// "— The Wall Street Journal", a quote ending in a dash) is removed instead: a
			// leading or trailing comma would be wrong. Text boundaries: string start/end, a
			// block-level tag (paragraph, heading, list item, cell, <br>...), a line break (raw
			// or JSON-escaped), or a JSON / attribute quote. Inline tags (<strong>, <a>, <span>...)
			// are not boundaries: "itself <strong>— our firm" is mid-sentence and gets the comma,
			// moved outside the tag ("itself, <strong>our firm").
			$edge   = null;
			$inline = array();
			if ( ! empty( $rule['drop_edges'] ) ) {
				$block  = '(?:p|h[1-6]|li|ul|ol|dl|dt|dd|div|td|th|tr|table|thead|tbody|blockquote|figure|figcaption|section|article|header|footer|nav|aside|main|title|option|label|button|summary|details|br|hr)\b';
				$names  = '(?:strong|b|em|i|a|span|u|mark|small|sup|sub|cite|q|abbr|font|code|s|del|ins)\b';
				$slash  = '(?:\\\\)?\/'; // "/" or JSON-escaped "\/".
				$open   = '<' . $names . '[^<>]*>';
				$close  = '<' . $slash . $names . '\s*>';
				$btag   = '<(?:' . $slash . ')?' . $block;
				$before = '(^|\n|\r|:"|\["|,"|="|\\\\n|\\\\r|' . $btag . '[^<>]*>)((?:' . $open . ')*)';
				$after  = '(?=(?:' . $close . ')*(?:$|\n|\r|"|\\\\n|\\\\r|' . $btag . '))';
				$edge   = '/' . $before . $ws . '(?:' . $alt . ')' . $ws . '|' . $ws . '(?:' . $alt . ')' . $ws . $after . '/';
				// regex => true when the replacement goes before the captured tags ("<b>— x" -> ", <b>x").
				$inline = array(
					'/' . $ws . '(?<tags>(?:' . $open . ')+)' . $ws . '(?<find>' . $alt . ')' . $ws . '/'  => true,
					'/' . $ws . '(?<tags>(?:' . $close . ')+)' . $ws . '(?<find>' . $alt . ')' . $ws . '/' => false,
					'/' . $ws . '(?<find>' . $alt . ')' . $ws . '(?<tags>(?:' . $close . ')+)' . $ws . '/' => false,
				);
			}

			$this->compiled[] = array(
				'pattern'  => '/' . $pattern . '/',
				'edge'     => $edge,
				'inline'   => $inline,
				'variants' => $variants,
			);
			$this->needles    = array_merge( $this->needles, $finds );
		}
		$this->needles = array_values( array_unique( $this->needles ) );
	}

	/** @return bool */
	public function has_rules() {
		return ! empty( $this->compiled ) || null !== $this->url_map;
	}

	/** @return string[] Literal needles of the text rules. */
	public function needles() {
		return $this->needles;
	}

	/**
	 * Literals for the SQL LIKE pre-filter, or null when rows can't usefully be pre-filtered
	 * (a large URL map): then every row is checked in PHP.
	 *
	 * @return string[]|null
	 */
	public function sql_needles() {
		if ( null === $this->url_map ) {
			return $this->needles;
		}
		$urls = $this->url_map->sql_needles();
		return null === $urls ? null : array_values( array_unique( array_merge( $this->needles, $urls ) ) );
	}

	/** @return DSR_Url_Map|null */
	public function url_map() {
		return $this->url_map;
	}

	/**
	 * Cheap check: could this string contain a match at all?
	 *
	 * @param string $value
	 * @return bool
	 */
	public function contains_needle( $value ) {
		foreach ( $this->needles as $needle ) {
			if ( false !== strpos( $value, $needle ) ) {
				return true;
			}
		}
		return null !== $this->url_map && $this->url_map->may_match( $value );
	}

	/**
	 * Replace inside a stored value, detecting its format.
	 *
	 * @param mixed $value
	 * @param int   $depth
	 * @return mixed
	 */
	public function replace_value( $value, $depth = 0 ) {
		if ( ! is_string( $value ) || '' === $value || $depth > self::MAX_DEPTH || ! $this->contains_needle( $value ) ) {
			return $value;
		}

		if ( self::is_serialized( $value ) ) {
			try {
				$pos = 0;
				$out = $this->walk_serialized( $value, $pos, false, $depth );
				if ( strlen( $value ) !== $pos ) {
					throw new RuntimeException( 'trailing data' );
				}
				if ( 'b:0;' !== $out && false === @unserialize( $out, array( 'allowed_classes' => false ) ) ) { // phpcs:ignore
					throw new RuntimeException( 'result does not unserialize' );
				}
				return $out;
			} catch ( RuntimeException $e ) {
				$this->errors[] = 'Serialized value skipped (' . $e->getMessage() . ')';
				return $value;
			}
		}

		if ( self::looks_like_json( $value ) ) {
			$out = $this->apply( $value, 'json' );
			if ( $out !== $value && ! self::is_valid_json( $out ) ) {
				$this->errors[] = 'JSON value skipped (replacement would make it invalid)';
				return $value;
			}
			return $out;
		}

		if ( false !== strpos( $value, '<!-- wp:' ) ) {
			return $this->apply_blocks( $value );
		}

		return $this->apply( $value, 'text' );
	}

	/**
	 * Replace in plain text / HTML (no format detection). Used for the live output filter.
	 *
	 * @param string $value
	 * @return string
	 */
	public function replace_text( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->contains_needle( $value ) ) {
			return $value;
		}
		return $this->apply( $value, 'text' );
	}

	/**
	 * Gutenberg markup: block attributes inside <!-- wp:name {...} --> are JSON
	 * (ACF blocks keep their field data there), everything else is HTML.
	 *
	 * @param string $value
	 * @return string
	 */
	private function apply_blocks( $value ) {
		$parts = preg_split( '/(<!--\s+wp:[a-z0-9_\/-]+\s+\{.*?\}\s+\/?-->)/s', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $this->apply( $value, 'text' );
		}
		$out = '';
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$out .= $this->apply( $part, 'text' );
				continue;
			}
			if ( ! preg_match( '/^(<!--\s+wp:[a-z0-9_\/-]+\s+)(\{.*\})(\s+\/?-->)$/s', $part, $m ) || ! self::is_valid_json( $m[2] ) ) {
				$out .= $this->apply( $part, 'text' );
				continue;
			}
			$json = $this->apply( $m[2], 'json' );
			if ( $json !== $m[2] && ! self::is_valid_json( $json ) ) {
				$this->errors[] = 'Block attributes skipped (replacement would make them invalid)';
				$json           = $m[2];
			}
			$out .= $m[1] . $json . $m[3];
		}
		return $out;
	}

	/**
	 * @param string $value
	 * @param string $ctx 'text' or 'json' (inside a JSON document, replacements must be JSON-escaped).
	 * @return string
	 */
	private function apply( $value, $ctx ) {
		foreach ( $this->compiled as $rule ) {
			if ( ! $this->rule_may_match( $value, $rule ) ) {
				continue;
			}
			$variants = $rule['variants'];

			if ( $rule['edge'] ) {
				$count  = 0;
				$result = preg_replace( $rule['edge'], '$1$2', $value, -1, $count ); // Keep the boundary tags.
				if ( null === $result ) {
					$this->errors[] = 'Regex error ' . preg_last_error() . ' (value too large?)';
					continue;
				}
				$value        = $result;
				$this->count += $count;
			}

			// Next to inline tags: the replacement goes outside the tag, the spaces are absorbed.
			foreach ( $rule['inline'] as $regex => $replacement_first ) {
				$count  = 0;
				$result = preg_replace_callback(
					$regex,
					function ( $m ) use ( $variants, $ctx, $replacement_first ) {
						$replacement = $variants[ $m['find'] ][ $ctx ];
						return $replacement_first ? $replacement . $m['tags'] : $m['tags'] . $replacement;
					},
					$value,
					-1,
					$count
				);
				if ( null === $result ) {
					$this->errors[] = 'Regex error ' . preg_last_error() . ' (value too large?)';
					continue 2;
				}
				$value        = $result;
				$this->count += $count;
			}

			$count    = 0;
			$result   = preg_replace_callback(
				$rule['pattern'],
				function ( $m ) use ( $variants, $ctx ) {
					return $variants[ $m[1] ][ $ctx ];
				},
				$value,
				-1,
				$count
			);
			if ( null === $result ) {
				$this->errors[] = 'Regex error ' . preg_last_error() . ' (value too large?)';
				continue;
			}
			$value        = $result;
			$this->count += $count;
		}
		if ( null !== $this->url_map ) {
			// Escaping is copied from each matched URL, so this is safe in text and JSON alike.
			$value = $this->url_map->replace( $value, $this->count, $this->links );
		}
		return $value;
	}

	private function rule_may_match( $value, $rule ) {
		foreach ( $rule['variants'] as $find => $unused ) {
			if ( false !== strpos( $value, (string) $find ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rebuild a serialized string, replacing inside string values (never keys)
	 * and recomputing lengths. Objects are never instantiated.
	 *
	 * @throws RuntimeException On malformed input.
	 */
	private function walk_serialized( $s, &$pos, $is_key, $depth ) {
		if ( ! isset( $s[ $pos ] ) ) {
			throw new RuntimeException( 'unexpected end' );
		}
		switch ( $s[ $pos ] ) {
			case 'N':
				if ( 'N;' !== substr( $s, $pos, 2 ) ) {
					throw new RuntimeException( 'bad null' );
				}
				$pos += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $s, ';', $pos );
				if ( false === $end ) {
					throw new RuntimeException( 'bad scalar' );
				}
				$out = substr( $s, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;

			case 's':
				$payload = $this->read_length_prefixed( $s, $pos, 's', '";' );
				if ( ! $is_key ) {
					$payload = $this->replace_value( $payload, $depth + 1 );
				}
				return 's:' . strlen( $payload ) . ':"' . $payload . '";';

			case 'E':
				$payload = $this->read_length_prefixed( $s, $pos, 'E', '";' );
				return 'E:' . strlen( $payload ) . ':"' . $payload . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
					throw new RuntimeException( 'bad array' );
				}
				$pos += strlen( $m[0] );
				return $m[0] . $this->walk_members( $s, $pos, (int) $m[1], $depth );

			case 'O':
				$class = $this->read_length_prefixed( $s, $pos, 'O', '":' );
				if ( ! preg_match( '/\G(\d+):\{/', $s, $m, 0, $pos ) ) {
					throw new RuntimeException( 'bad object' );
				}
				$pos += strlen( $m[0] );
				return 'O:' . strlen( $class ) . ':"' . $class . '":' . $m[0] . $this->walk_members( $s, $pos, (int) $m[1], $depth );

			case 'C':
				$class = $this->read_length_prefixed( $s, $pos, 'C', '":' );
				if ( ! preg_match( '/\G(\d+):\{/', $s, $m, 0, $pos ) ) {
					throw new RuntimeException( 'bad custom object' );
				}
				$len   = (int) $m[1];
				$start = $pos + strlen( $m[0] );
				if ( '}' !== substr( $s, $start + $len, 1 ) ) {
					throw new RuntimeException( 'bad custom object length' );
				}
				$out = 'C:' . strlen( $class ) . ':"' . $class . '":' . $m[0] . substr( $s, $start, $len ) . '}';
				$pos = $start + $len + 1;
				return $out;
		}
		throw new RuntimeException( 'unknown token' );
	}

	private function walk_members( $s, &$pos, $n, $depth ) {
		$out = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$out .= $this->walk_serialized( $s, $pos, true, $depth );
			$out .= $this->walk_serialized( $s, $pos, false, $depth );
		}
		if ( '}' !== substr( $s, $pos, 1 ) ) {
			throw new RuntimeException( 'unterminated container' );
		}
		$pos++;
		return $out . '}';
	}

	private function read_length_prefixed( $s, &$pos, $type, $terminator ) {
		if ( ! preg_match( '/\G' . $type . ':(\d+):"/', $s, $m, 0, $pos ) ) {
			throw new RuntimeException( esc_html( "bad $type header" ) );
		}
		$len   = (int) $m[1];
		$start = $pos + strlen( $m[0] );
		if ( substr( $s, $start + $len, strlen( $terminator ) ) !== $terminator ) {
			throw new RuntimeException( esc_html( "bad $type length" ) );
		}
		$pos = $start + $len + strlen( $terminator );
		return substr( $s, $start, $len );
	}

	/**
	 * All the forms a search string may be stored in, each with its matching replacement.
	 *
	 * @return array find => [ 'text' => replacement in HTML/text, 'json' => replacement inside JSON ]
	 */
	public static function build_variants( $search, $replace ) {
		$variants = array();
		$add      = function ( $find, $text, $json ) use ( &$variants ) {
			if ( '' !== $find && ! isset( $variants[ $find ] ) ) {
				$variants[ $find ] = array(
					'text' => $text,
					'json' => $json,
				);
			}
		};

		$replace_json = self::json_inner( $replace, self::JSON_RAW );
		$add( $search, $replace, $replace_json );

		// HTML entities: &mdash; &#8212; &#x2014; ...
		foreach ( self::html_forms( $search ) as $form ) {
			$add( $form, $replace, $replace_json );
		}

		// JSON escapes: \u2014 (Elementor, wp_json_encode), plus double-escaped \\u2014 (JSON stored inside JSON).
		foreach ( array( 0, 64, 256, 320 ) as $flags ) {
			$s1 = self::json_inner( $search, $flags );
			$r1 = self::json_inner( $replace, $flags );
			$add( $s1, $r1, $r1 );
			$add( self::upper_unicode_escapes( $s1 ), $r1, $r1 );
			foreach ( array( 320, 256 ) as $flags2 ) {
				$s2 = self::json_inner( $s1, $flags2 );
				$r2 = self::json_inner( $r1, $flags2 );
				$add( $s2, $r2, $r2 );
				$add( self::upper_unicode_escapes( $s2 ), $r2, $r2 );
			}
		}

		return $variants;
	}

	private static function html_forms( $s ) {
		if ( ! preg_match( '/[^\x00-\x7F]|[&<>"\']/', $s ) ) {
			return array();
		}
		$forms = array( htmlentities( $s, ENT_QUOTES | ENT_HTML401, 'UTF-8' ) );
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return $forms;
		}
		$dec    = '';
		$hex    = '';
		$hex_uc = '';
		$hex_xx = '';
		foreach ( $chars as $c ) {
			$cp = self::utf8_ord( $c );
			if ( $cp > 127 || false !== strpbrk( $c, '&<>"\'' ) ) {
				$h       = dechex( $cp );
				$dec    .= '&#' . $cp . ';';
				$hex    .= '&#x' . $h . ';';
				$hex_uc .= '&#x' . strtoupper( $h ) . ';';
				$hex_xx .= '&#X' . strtoupper( $h ) . ';';
			} else {
				$dec    .= $c;
				$hex    .= $c;
				$hex_uc .= $c;
				$hex_xx .= $c;
			}
		}
		$forms[] = $dec;
		$forms[] = $hex;
		$forms[] = $hex_uc;
		$forms[] = $hex_xx;
		return $forms;
	}

	private static function utf8_ord( $c ) {
		$b = array_values( unpack( 'C*', $c ) );
		switch ( count( $b ) ) {
			case 1:
				return $b[0];
			case 2:
				return ( ( $b[0] & 0x1F ) << 6 ) | ( $b[1] & 0x3F );
			case 3:
				return ( ( $b[0] & 0x0F ) << 12 ) | ( ( $b[1] & 0x3F ) << 6 ) | ( $b[2] & 0x3F );
		}
		return ( ( $b[0] & 0x07 ) << 18 ) | ( ( $b[1] & 0x3F ) << 12 ) | ( ( $b[2] & 0x3F ) << 6 ) | ( $b[3] & 0x3F );
	}

	private static function upper_unicode_escapes( $s ) {
		return preg_replace_callback(
			'/\\\\u([0-9a-f]{4})/',
			function ( $m ) {
				return '\\u' . strtoupper( $m[1] );
			},
			$s
		);
	}

	/** JSON-encode a string and strip the surrounding quotes. */
	private static function json_inner( $s, $flags ) {
		$encoded = json_encode( (string) $s, $flags );
		return false === $encoded ? (string) $s : substr( $encoded, 1, -1 );
	}

	/** Regex for horizontal whitespace in any encoding (space, tab, nbsp as byte / entity / JSON escape). */
	private static function ws() {
		static $ws = null;
		if ( null === $ws ) {
			$literals = array( ' ', "\t", "\xC2\xA0", '&nbsp;', '&#160;', '&#xa0;', '&#xA0;', '&#XA0;', '\u00a0', '\u00A0', '\\\\u00a0', '\\\\u00A0' );
			$quoted   = array();
			foreach ( $literals as $literal ) {
				$quoted[] = preg_quote( $literal, '/' );
			}
			$ws = '(?:' . implode( '|', $quoted ) . ')*+';
		}
		return $ws;
	}

	public static function is_serialized( $s ) {
		if ( 'N;' === $s ) {
			return true;
		}
		if ( strlen( $s ) < 4 || ':' !== $s[1] ) {
			return false;
		}
		$last = substr( $s, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		return (bool) preg_match( '/^(?:s:\d+:"|a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:"|[bid]:[0-9.Ee+-]+;)/', $s );
	}

	private static function looks_like_json( $s ) {
		$first = $s[0];
		$last  = substr( $s, -1 );
		return ( ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last ) ) && self::is_valid_json( $s );
	}

	private static function is_valid_json( $s ) {
		json_decode( $s );
		return JSON_ERROR_NONE === json_last_error();
	}
}
