<?php
/** Exercise the real GD extension: ellipse borders must retain their requested width. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/bootstrap-autoload.php';
if ( ! extension_loaded( 'gd' ) ) { fwrite( STDERR, "Real GD extension required.\n" ); exit( 1 ); }
$driver = new \Certloom\Infrastructure\Rendering\NativeGdDriver();
$counts = array();
foreach ( array( 1, 3, 6 ) as $thickness ) {
 $image = $driver->create_canvas( 200, 150 );
 $color = $driver->allocate_color( $image, array( 'r' => 0, 'g' => 0, 'b' => 0, 'alpha_unit' => 1 ) );
 $driver->ellipse( $image, 100, 75, 140, 90, $color, $thickness );
 $count = 0;
 for ( $y = 0; $y < 150; $y++ ) { for ( $x = 0; $x < 200; $x++ ) { if ( 0 === imagecolorat( $image, $x, $y ) ) { $count++; } } }
 $counts[ $thickness ] = $count;
 $driver->destroy( $image );
}
if ( $counts[1] < 300 || $counts[3] < 2.5 * $counts[1] || $counts[6] < 5 * $counts[1] ) { throw new RuntimeException( 'Raster ellipse borders lost their requested thickness: ' . json_encode( $counts ) ); }
// A thick ellipse must not alter the next operation's default line thickness.
$image = $driver->create_canvas( 200, 150 ); $black = $driver->allocate_color( $image, array( 'r' => 0, 'g' => 0, 'b' => 0, 'alpha_unit' => 1 ) );
$driver->ellipse( $image, 100, 75, 140, 90, $black, 6 ); imageline( $image, 10, 5, 100, 5, $black );
if ( 0 !== imagecolorat( $image, 50, 5 ) || 0 === imagecolorat( $image, 50, 4 ) || 0 === imagecolorat( $image, 50, 6 ) ) { throw new RuntimeException( 'Ellipse stroke width leaked into the next drawing operation.' ); }
$driver->destroy( $image );
echo 'PASS  real GD ellipse stroke widths and default-thickness restoration: ' . json_encode( $counts ) . "\n2 checks, 0 failures\n";
