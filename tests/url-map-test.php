<?php
/**
 * Standalone tests for the URL map (no WordPress needed): php tests/url-map-test.php
 *
 * @package DeepSearchReplace
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}
require __DIR__ . '/../includes/class-dsr-url-map.php';
require __DIR__ . '/../includes/class-dsr-replacer.php';

$failures = 0;
$checks   = 0;
function check( $name, $expected, $actual ) {
	global $failures, $checks;
	$checks++;
	if ( $expected !== $actual ) {
		$failures++;
		echo "FAIL: $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

$csv = "Source,Destination\n"
	. "/heroin/,/heroin-addiction/\n"
	. "old-page,/new-page/\n"
	. "https://www.example.com/abs-old/,https://example.com/abs-new/\n"
	. "/chain-a/,/chain-b/\n"
	. "/chain-b/,/chain-c/\n"
	. "/loop-1/,/loop-2/\n"
	. "/loop-2/,/loop-1/\n"
	. "/,/home/\n"
	. "/self/,/self\n"
	. "/q/?x=1,/y/\n"
	. "/regex/(.*),/z/\n"
	. "https://other.com/x/,/y/\n"
	. "/California/Orange-County/Huntington-Beach/heroin/,/california/orange-county/heroin/\n";

$rules = array(
	array(
		'type'    => 'urls',
		'csv'     => $csv,
		'domains' => 'example.com',
	),
);
$r     = new DSR_Replacer( $rules );
$map   = $r->url_map();

check( 'usable pairs', 6, $map->count() );
check( 'problems', 7, count( $map->problems ) );
check( 'chain resolved', '/chain-c/', $map->pairs()['/chain-a/'] );
check( 'sql needles small map', true, is_array( $r->sql_needles() ) );

$t = function ( $in ) use ( $r ) {
	return $r->replace_value( $in );
};

// Plain HTML.
check( 'relative href', '<a href="/heroin-addiction/">x</a>', $t( '<a href="/heroin/">x</a>' ) );
check( 'no trailing slash', '<a href="/heroin-addiction/">x</a>', $t( '<a href="/heroin">x</a>' ) );
check( 'single quotes', "<a href='/heroin-addiction/'>x</a>", $t( "<a href='/heroin/'>x</a>" ) );
check( 'child untouched', '<a href="/heroin/detox/">x</a>', $t( '<a href="/heroin/detox/">x</a>' ) );
check( 'longer slug untouched', '<a href="/heroin-rehab/">x</a>', $t( '<a href="/heroin-rehab/">x</a>' ) );
check( 'absolute keeps host as written', '<a href="https://www.example.com/heroin-addiction/">x</a>', $t( '<a href="https://www.example.com/heroin/">x</a>' ) );
check( 'http and no www', '<a href="http://example.com/heroin-addiction/">x</a>', $t( '<a href="http://example.com/heroin/">x</a>' ) );
check( 'query kept', '<a href="/heroin-addiction/?utm=1#top">x</a>', $t( '<a href="/heroin/?utm=1#top">x</a>' ) );
check( 'other domain untouched', '<a href="https://other.com/heroin/">x</a>', $t( '<a href="https://other.com/heroin/">x</a>' ) );
check( 'look-alike domain untouched', '<a href="https://example.com.evil.net/heroin/">x</a>', $t( '<a href="https://example.com.evil.net/heroin/">x</a>' ) );
check( 'protocol relative other host', '<a href="//cdn.net/heroin/">x</a>', $t( '<a href="//cdn.net/heroin/">x</a>' ) );
check( 'path in prose untouched', 'see /heroin/ today', $t( 'see /heroin/ today' ) );
check( 'chain', '<a href="/chain-c/">x</a>', $t( '<a href="/chain-a/">x</a>' ) );
check( 'loop untouched', '<a href="/loop-1/">x</a>', $t( '<a href="/loop-1/">x</a>' ) );
check( 'absolute destination, absolute link', '<a href="https://example.com/abs-new/">x</a>', $t( '<a href="https://example.com/abs-old/">x</a>' ) );
check( 'absolute destination, relative link stays relative', '<a href="/abs-new/">x</a>', $t( '<a href="/abs-old/">x</a>' ) );
check( 'source without slash', '<a href="/new-page/">x</a>', $t( '<a href="/old-page/">x</a>' ) );
check( 'case insensitive source', '<a href="/california/orange-county/heroin/">x</a>', $t( '<a href="/california/orange-county/huntington-beach/heroin/">x</a>' ) );
check( 'entity quoted shortcode', 'link=&quot;/heroin-addiction/&quot;', $t( 'link=&quot;/heroin/&quot;' ) );
check( 'whole value (menu item url)', '/heroin-addiction/', $t( '/heroin/' ) );
check( 'whole value absolute', 'https://example.com/heroin-addiction/', $t( 'https://example.com/heroin/' ) );
check( 'two links', '<a href="/heroin-addiction/">a</a><a href="/new-page/">b</a>', $t( '<a href="/heroin/">a</a><a href="/old-page/">b</a>' ) );

// Elementor JSON (escaped slashes) and JSON inside JSON.
$json = json_encode( array( array( 'settings' => array( 'link' => array( 'url' => 'https://example.com/heroin/' ), 'editor' => '<a href="/heroin/">x</a> <a href="/heroin/detox/">y</a>' ) ) ) );
$out  = $t( $json );
check( 'elementor json valid', true, null !== json_decode( $out ) );
$dec = json_decode( $out, true );
check( 'elementor url', 'https://example.com/heroin-addiction/', $dec[0]['settings']['link']['url'] );
check( 'elementor editor', '<a href="/heroin-addiction/">x</a> <a href="/heroin/detox/">y</a>', $dec[0]['settings']['editor'] );
$double = json_encode( array( 'inner' => $json ) );
$dec2   = json_decode( $t( $double ), true );
check( 'json in json', 'https://example.com/heroin-addiction/', json_decode( $dec2['inner'], true )[0]['settings']['link']['url'] );

// Serialized (ACF / options) with JSON-ish content.
$ser = serialize( array( 'link' => array( 'url' => '/heroin/', 'title' => 'Heroin' ), 'html' => '<a href="https://example.com/heroin/">x</a>' ) );
$u   = unserialize( $t( $ser ) );
check( 'serialized url', '/heroin-addiction/', $u['link']['url'] );
check( 'serialized html', '<a href="https://example.com/heroin-addiction/">x</a>', $u['html'] );

// Gutenberg block attributes.
$block = '<!-- wp:button {"url":"\/heroin\/"} --><a href="/heroin/">x</a><!-- /wp:button -->';
check( 'block attrs and html', '<!-- wp:button {"url":"\/heroin-addiction\/"} --><a href="/heroin-addiction/">x</a><!-- /wp:button -->', $t( $block ) );

// Link log.
$r->links = array();
$t( '<a href="/heroin/">a</a><a href="/heroin">b</a>' );
check( 'link log', array( "/heroin/\t/heroin-addiction/" => 1, "/heroin\t/heroin-addiction/" => 1 ), $r->links );

// Large map: no SQL pre-filter.
$big = '';
for ( $i = 0; $i < 100; $i++ ) {
	$big .= "/old-$i/,/new-$i/\n";
}
$r2 = new DSR_Replacer( array( array( 'type' => 'urls', 'csv' => $big ) ) );
check( 'big map no sql filter', null, $r2->sql_needles() );
check( 'big map replace', '<a href="/new-57/">x</a>', $r2->replace_value( '<a href="/old-57/">x</a>' ) );

// Text rules still work alongside.
$r3 = new DSR_Replacer(
	array(
		array( 'search' => 'Acme', 'replace' => 'Beta' ),
		array( 'type' => 'urls', 'csv' => "/heroin/,/h/\n" ),
	)
);
check( 'text + url', '<a href="/h/">Beta</a>', $r3->replace_value( '<a href="/heroin/">Acme</a>' ) );

// Tab separated (pasted from a spreadsheet) with quotes.
$r4 = new DSR_Replacer( array( array( 'type' => 'urls', 'csv' => "\"/a/\"\t\"/b/\"\r\n" ) ) );
check( 'tsv', '<a href="/b/">x</a>', $r4->replace_value( '<a href="/a/">x</a>' ) );

echo $failures ? "\n$failures of $checks checks failed\n" : "All $checks checks passed\n";
exit( $failures ? 1 : 0 );
