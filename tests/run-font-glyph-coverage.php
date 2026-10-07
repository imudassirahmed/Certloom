<?php
/** Exact font cmap coverage and malformed-font boundaries. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\FontGlyphCoverage;
$checks = 0;
function glyph_check( $value, $message ) { global $checks; ++$checks; if ( ! $value ) { throw new RuntimeException( $message ); } echo "PASS  $message\n"; }
$regular = CERTLOOM_PATH . 'resources/renderer/fonts/57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e.ttf';
$bold = CERTLOOM_PATH . 'resources/renderer/fonts/a4c5bc453ca281d90ea079e596da7ae0dfeb5777497c29ec254e76d97ff6f890.ttf';
$coverage = new FontGlyphCoverage();
foreach ( array( $regular, $bold ) as $font ) {
	glyph_check( $coverage->has_glyph( $font, 65 ), 'Exact bundled face contains Latin A' );
	glyph_check( $coverage->has_glyph( $font, 0x0627 ), 'Exact bundled face contains Arabic alef' );
	glyph_check( ! $coverage->has_glyph( $font, 0x7530 ), 'Broad manifest ranges cannot invent a CJK glyph' );
	glyph_check( ! $coverage->has_glyph( $font, 0x10ffff ), 'Unassigned scalar does not map to a real glyph' );
}
function glyph_font( $subtable, $encoding = 1 ) {
	$cmap = pack( 'nnnnN', 0, 1, 3, $encoding, 12 ) . $subtable;
	return pack( 'Nnnnn', 0x00010000, 1, 16, 0, 0 ) . 'cmap' . pack( 'NNN', 0, 28, strlen( $cmap ) ) . $cmap;
}
$path = tempnam( sys_get_temp_dir(), 'certloom-glyph-' );
try {
	// Two format-4 segments: A -> glyph 1, sentinel -> missing glyph.
	$map4 = pack( 'n*', 4, 32, 0, 4, 4, 1, 0, 65, 65535, 0, 65, 65535, 65472, 1, 0, 0 );
	file_put_contents( $path, glyph_font( $map4 ) ); clearstatcache( true, $path ); $coverage = new FontGlyphCoverage();
	glyph_check( $coverage->has_glyph( $path, 65 ) && ! $coverage->has_glyph( $path, 66 ), 'Format 4 delta lookup and absent segment' );
	glyph_check( ! $coverage->has_glyph( $path, 65535 ), 'Format 4 glyph zero remains missing' );
	$map4range = pack( 'n*', 4, 34, 0, 4, 4, 1, 0, 65, 65535, 0, 65, 65535, 1, 1, 4, 0, 2 );
	file_put_contents( $path, glyph_font( $map4range ) ); clearstatcache( true, $path ); $coverage = new FontGlyphCoverage();
	glyph_check( $coverage->has_glyph( $path, 65 ), 'Format 4 indirect glyph-array lookup' );
	$map12 = pack( 'nnNNNNNN', 12, 0, 28, 0, 1, 0x1f600, 0x1f600, 7 );
	file_put_contents( $path, glyph_font( $map12, 10 ) ); clearstatcache( true, $path ); $coverage = new FontGlyphCoverage();
	glyph_check( $coverage->has_glyph( $path, 0x1f600 ) && ! $coverage->has_glyph( $path, 0x1f601 ), 'Format 12 supplementary scalar lookup' );
	foreach ( array( 'bad', glyph_font( substr( $map4, 0, 20 ) ), glyph_font( pack( 'nnNNN', 12, 0, 16, 0, 100001 ), 10 ), glyph_font( pack( 'n*', 4, 16, 0, 65534, 0, 0, 0, 0 ) ) ) as $bad ) {
		file_put_contents( $path, $bad ); clearstatcache( true, $path ); $failed = false;
		try { ( new FontGlyphCoverage() )->has_glyph( $path, 65 ); } catch ( RuntimeException $error ) { $failed = true; }
		glyph_check( $failed, 'Truncated or oversized glyph-map data fails closed' );
	}
} finally { unlink( $path ); }
echo "\n{$checks} glyph coverage checks, 0 failures\n";
