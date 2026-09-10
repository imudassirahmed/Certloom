<?php
/**
 * Responsive-contract guard.
 *
 * New admin work must use the four canonical widths. Historical breakpoints are
 * temporarily allow-listed so the release does not change layout behavior by
 * blind numeric replacement. The legacy list is expected to shrink, never grow.
 */
$css = file_get_contents( dirname( __DIR__ ) . '/admin/assets/admin.css' );
if ( false === $css ) {
	fwrite( STDERR, "FAIL  admin.css could not be read\n" );
	exit( 1 );
}

preg_match_all( '/@media\s*\([^)]*(?:max|min)-width\s*:\s*(\d+)px[^)]*\)/', $css, $matches );
$widths    = array_map( 'intval', $matches[1] );
$canonical = array( 600, 782, 960, 1180 );
$legacy    = array( 480, 540, 560, 620, 640, 700, 720, 900, 961, 980, 1000, 1050, 1080, 1100, 1380 );
$allowed   = array_merge( $canonical, $legacy );
$unknown   = array_values( array_diff( array_unique( $widths ), $allowed ) );
sort( $unknown );

if ( $unknown ) {
	fwrite( STDERR, 'FAIL  new non-canonical responsive breakpoint(s): ' . implode( ', ', $unknown ) . "px\n" );
	exit( 1 );
}

$legacy_occurrences = count( array_filter( $widths, static function ( $width ) use ( $canonical ) {
	return ! in_array( $width, $canonical, true );
} ) );
if ( $legacy_occurrences > 34 ) {
	fwrite( STDERR, sprintf( "FAIL  legacy breakpoint occurrences grew to %d (baseline ceiling: 34)\n", $legacy_occurrences ) );
	exit( 1 );
}

printf(
	"PASS  responsive contract: %d media-width rules; %d legacy occurrences are frozen for staged reduction; new work uses 600/782/960/1180\n",
	count( $widths ),
	$legacy_occurrences
);
