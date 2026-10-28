<?php
/**
 * Live output filter: rewrites the final front-end HTML. A safety net for text
 * that never lives in the database (hard-coded theme strings, wptexturize turning
 * "--" into an em dash, SEO title separators, plugin output).
 *
 * @package DeepSearchReplace
 */

defined( 'ABSPATH' ) || exit;

class DSR_Output {

	/** @var DSR_Replacer|null */
	private static $replacer = null;

	public static function init() {
		$settings = DSR_Plugin::settings();
		if ( empty( $settings['live_enabled'] ) || empty( $settings['live_rules'] ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'start' ), -1000 );
	}

	public static function start() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return;
		}
		$settings       = DSR_Plugin::settings();
		self::$replacer = new DSR_Replacer( $settings['live_rules'] );
		if ( self::$replacer->has_rules() ) {
			ob_start( array( __CLASS__, 'filter' ) );
		}
	}

	/**
	 * @param string $html
	 * @return string
	 */
	public static function filter( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! self::is_markup_response() || ! self::$replacer->contains_needle( $html ) ) {
			return $html;
		}

		// Leave <script> and <style> alone, except JSON-LD structured data (titles / descriptions).
		$parts = preg_split( '#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}
		$out = '';
		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$out .= self::$replacer->replace_text( $part );
			} elseif ( preg_match( '#^(<script\b[^>]*application/ld\+json[^>]*>)(.*)(</script>)$#is', $part, $m ) ) {
				$out .= $m[1] . self::$replacer->replace_value( trim( $m[2] ) ) . $m[3];
			} else {
				$out .= $part;
			}
		}
		return $out;
	}

	private static function is_markup_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return (bool) preg_match( '#text/html|xml#i', $header );
			}
		}
		return true; // PHP default is text/html.
	}
}
