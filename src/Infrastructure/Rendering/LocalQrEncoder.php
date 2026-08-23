<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\QrEncoderInterface;
use InvalidArgumentException;

/**
 * WordPress-local QR Code Model 2 encoder.
 *
 * This implementation is a PHP adaptation of the QR matrix construction used
 * by the BSD-licensed python-qrcode project (Lincoln Loop; see
 * licenses/python-qrcode-BSD.txt). Certloom keeps the encoder behind its own
 * QrEncoderInterface so public verification, future PDF and image renderers
 * receive the same exact validated module matrix without a network service.
 *
 * Scope: byte-mode QR Model 2, versions 1-40, error correction L/M/Q/H.
 */
final class LocalQrEncoder implements QrEncoderInterface {
	const ENCODER_ID      = 'certloom-local-qr-model2';
	const ENCODER_VERSION = '1.0.0';
	const MAX_DATA_BYTES  = 2048;

	const MODE_BYTE = 4;
	const PAD0      = 236;
	const PAD1      = 17;
	const G15       = 0x537;
	const G18       = 0x1F25;
	const G15_MASK  = 0x5412;

	/** @var array<int,array<int,int>> */
	private static $pattern_positions = array(
		array(),
		array( 6, 18 ),
		array( 6, 22 ),
		array( 6, 26 ),
		array( 6, 30 ),
		array( 6, 34 ),
		array( 6, 22, 38 ),
		array( 6, 24, 42 ),
		array( 6, 26, 46 ),
		array( 6, 28, 50 ),
		array( 6, 30, 54 ),
		array( 6, 32, 58 ),
		array( 6, 34, 62 ),
		array( 6, 26, 46, 66 ),
		array( 6, 26, 48, 70 ),
		array( 6, 26, 50, 74 ),
		array( 6, 30, 54, 78 ),
		array( 6, 30, 56, 82 ),
		array( 6, 30, 58, 86 ),
		array( 6, 34, 62, 90 ),
		array( 6, 28, 50, 72, 94 ),
		array( 6, 26, 50, 74, 98 ),
		array( 6, 30, 54, 78, 102 ),
		array( 6, 28, 54, 80, 106 ),
		array( 6, 32, 58, 84, 110 ),
		array( 6, 30, 58, 86, 114 ),
		array( 6, 34, 62, 90, 118 ),
		array( 6, 26, 50, 74, 98, 122 ),
		array( 6, 30, 54, 78, 102, 126 ),
		array( 6, 26, 52, 78, 104, 130 ),
		array( 6, 30, 56, 82, 108, 134 ),
		array( 6, 34, 60, 86, 112, 138 ),
		array( 6, 30, 58, 86, 114, 142 ),
		array( 6, 34, 62, 90, 118, 146 ),
		array( 6, 30, 54, 78, 102, 126, 150 ),
		array( 6, 24, 50, 76, 102, 128, 154 ),
		array( 6, 28, 54, 80, 106, 132, 158 ),
		array( 6, 32, 58, 84, 110, 136, 162 ),
		array( 6, 26, 54, 82, 110, 138, 166 ),
		array( 6, 30, 58, 86, 114, 142, 170 )
	);

	/** @var array<int,array<int,int>> */
	private static $rs_block_table = array(
		array( 1, 26, 19 ),
		array( 1, 26, 16 ),
		array( 1, 26, 13 ),
		array( 1, 26, 9 ),
		array( 1, 44, 34 ),
		array( 1, 44, 28 ),
		array( 1, 44, 22 ),
		array( 1, 44, 16 ),
		array( 1, 70, 55 ),
		array( 1, 70, 44 ),
		array( 2, 35, 17 ),
		array( 2, 35, 13 ),
		array( 1, 100, 80 ),
		array( 2, 50, 32 ),
		array( 2, 50, 24 ),
		array( 4, 25, 9 ),
		array( 1, 134, 108 ),
		array( 2, 67, 43 ),
		array( 2, 33, 15, 2, 34, 16 ),
		array( 2, 33, 11, 2, 34, 12 ),
		array( 2, 86, 68 ),
		array( 4, 43, 27 ),
		array( 4, 43, 19 ),
		array( 4, 43, 15 ),
		array( 2, 98, 78 ),
		array( 4, 49, 31 ),
		array( 2, 32, 14, 4, 33, 15 ),
		array( 4, 39, 13, 1, 40, 14 ),
		array( 2, 121, 97 ),
		array( 2, 60, 38, 2, 61, 39 ),
		array( 4, 40, 18, 2, 41, 19 ),
		array( 4, 40, 14, 2, 41, 15 ),
		array( 2, 146, 116 ),
		array( 3, 58, 36, 2, 59, 37 ),
		array( 4, 36, 16, 4, 37, 17 ),
		array( 4, 36, 12, 4, 37, 13 ),
		array( 2, 86, 68, 2, 87, 69 ),
		array( 4, 69, 43, 1, 70, 44 ),
		array( 6, 43, 19, 2, 44, 20 ),
		array( 6, 43, 15, 2, 44, 16 ),
		array( 4, 101, 81 ),
		array( 1, 80, 50, 4, 81, 51 ),
		array( 4, 50, 22, 4, 51, 23 ),
		array( 3, 36, 12, 8, 37, 13 ),
		array( 2, 116, 92, 2, 117, 93 ),
		array( 6, 58, 36, 2, 59, 37 ),
		array( 4, 46, 20, 6, 47, 21 ),
		array( 7, 42, 14, 4, 43, 15 ),
		array( 4, 133, 107 ),
		array( 8, 59, 37, 1, 60, 38 ),
		array( 8, 44, 20, 4, 45, 21 ),
		array( 12, 33, 11, 4, 34, 12 ),
		array( 3, 145, 115, 1, 146, 116 ),
		array( 4, 64, 40, 5, 65, 41 ),
		array( 11, 36, 16, 5, 37, 17 ),
		array( 11, 36, 12, 5, 37, 13 ),
		array( 5, 109, 87, 1, 110, 88 ),
		array( 5, 65, 41, 5, 66, 42 ),
		array( 5, 54, 24, 7, 55, 25 ),
		array( 11, 36, 12, 7, 37, 13 ),
		array( 5, 122, 98, 1, 123, 99 ),
		array( 7, 73, 45, 3, 74, 46 ),
		array( 15, 43, 19, 2, 44, 20 ),
		array( 3, 45, 15, 13, 46, 16 ),
		array( 1, 135, 107, 5, 136, 108 ),
		array( 10, 74, 46, 1, 75, 47 ),
		array( 1, 50, 22, 15, 51, 23 ),
		array( 2, 42, 14, 17, 43, 15 ),
		array( 5, 150, 120, 1, 151, 121 ),
		array( 9, 69, 43, 4, 70, 44 ),
		array( 17, 50, 22, 1, 51, 23 ),
		array( 2, 42, 14, 19, 43, 15 ),
		array( 3, 141, 113, 4, 142, 114 ),
		array( 3, 70, 44, 11, 71, 45 ),
		array( 17, 47, 21, 4, 48, 22 ),
		array( 9, 39, 13, 16, 40, 14 ),
		array( 3, 135, 107, 5, 136, 108 ),
		array( 3, 67, 41, 13, 68, 42 ),
		array( 15, 54, 24, 5, 55, 25 ),
		array( 15, 43, 15, 10, 44, 16 ),
		array( 4, 144, 116, 4, 145, 117 ),
		array( 17, 68, 42 ),
		array( 17, 50, 22, 6, 51, 23 ),
		array( 19, 46, 16, 6, 47, 17 ),
		array( 2, 139, 111, 7, 140, 112 ),
		array( 17, 74, 46 ),
		array( 7, 54, 24, 16, 55, 25 ),
		array( 34, 37, 13 ),
		array( 4, 151, 121, 5, 152, 122 ),
		array( 4, 75, 47, 14, 76, 48 ),
		array( 11, 54, 24, 14, 55, 25 ),
		array( 16, 45, 15, 14, 46, 16 ),
		array( 6, 147, 117, 4, 148, 118 ),
		array( 6, 73, 45, 14, 74, 46 ),
		array( 11, 54, 24, 16, 55, 25 ),
		array( 30, 46, 16, 2, 47, 17 ),
		array( 8, 132, 106, 4, 133, 107 ),
		array( 8, 75, 47, 13, 76, 48 ),
		array( 7, 54, 24, 22, 55, 25 ),
		array( 22, 45, 15, 13, 46, 16 ),
		array( 10, 142, 114, 2, 143, 115 ),
		array( 19, 74, 46, 4, 75, 47 ),
		array( 28, 50, 22, 6, 51, 23 ),
		array( 33, 46, 16, 4, 47, 17 ),
		array( 8, 152, 122, 4, 153, 123 ),
		array( 22, 73, 45, 3, 74, 46 ),
		array( 8, 53, 23, 26, 54, 24 ),
		array( 12, 45, 15, 28, 46, 16 ),
		array( 3, 147, 117, 10, 148, 118 ),
		array( 3, 73, 45, 23, 74, 46 ),
		array( 4, 54, 24, 31, 55, 25 ),
		array( 11, 45, 15, 31, 46, 16 ),
		array( 7, 146, 116, 7, 147, 117 ),
		array( 21, 73, 45, 7, 74, 46 ),
		array( 1, 53, 23, 37, 54, 24 ),
		array( 19, 45, 15, 26, 46, 16 ),
		array( 5, 145, 115, 10, 146, 116 ),
		array( 19, 75, 47, 10, 76, 48 ),
		array( 15, 54, 24, 25, 55, 25 ),
		array( 23, 45, 15, 25, 46, 16 ),
		array( 13, 145, 115, 3, 146, 116 ),
		array( 2, 74, 46, 29, 75, 47 ),
		array( 42, 54, 24, 1, 55, 25 ),
		array( 23, 45, 15, 28, 46, 16 ),
		array( 17, 145, 115 ),
		array( 10, 74, 46, 23, 75, 47 ),
		array( 10, 54, 24, 35, 55, 25 ),
		array( 19, 45, 15, 35, 46, 16 ),
		array( 17, 145, 115, 1, 146, 116 ),
		array( 14, 74, 46, 21, 75, 47 ),
		array( 29, 54, 24, 19, 55, 25 ),
		array( 11, 45, 15, 46, 46, 16 ),
		array( 13, 145, 115, 6, 146, 116 ),
		array( 14, 74, 46, 23, 75, 47 ),
		array( 44, 54, 24, 7, 55, 25 ),
		array( 59, 46, 16, 1, 47, 17 ),
		array( 12, 151, 121, 7, 152, 122 ),
		array( 12, 75, 47, 26, 76, 48 ),
		array( 39, 54, 24, 14, 55, 25 ),
		array( 22, 45, 15, 41, 46, 16 ),
		array( 6, 151, 121, 14, 152, 122 ),
		array( 6, 75, 47, 34, 76, 48 ),
		array( 46, 54, 24, 10, 55, 25 ),
		array( 2, 45, 15, 64, 46, 16 ),
		array( 17, 152, 122, 4, 153, 123 ),
		array( 29, 74, 46, 14, 75, 47 ),
		array( 49, 54, 24, 10, 55, 25 ),
		array( 24, 45, 15, 46, 46, 16 ),
		array( 4, 152, 122, 18, 153, 123 ),
		array( 13, 74, 46, 32, 75, 47 ),
		array( 48, 54, 24, 14, 55, 25 ),
		array( 42, 45, 15, 32, 46, 16 ),
		array( 20, 147, 117, 4, 148, 118 ),
		array( 40, 75, 47, 7, 76, 48 ),
		array( 43, 54, 24, 22, 55, 25 ),
		array( 10, 45, 15, 67, 46, 16 ),
		array( 19, 148, 118, 6, 149, 119 ),
		array( 18, 75, 47, 31, 76, 48 ),
		array( 34, 54, 24, 34, 55, 25 ),
		array( 20, 45, 15, 61, 46, 16 )
	);

	/** @var array<int,int>|null */
	private static $exp_table = null;
	/** @var array<int,int>|null */
	private static $log_table = null;
	/** @var array<int,array<int,int>> */
	private static $generator_cache = array();

	/** @return string */
	public function id() { return self::ENCODER_ID; }
	/** @return string */
	public function version() { return self::ENCODER_VERSION; }

	/**
	 * @param string $data Raw byte payload, normally the canonical verification URL.
	 * @param string $error_correction L, M, Q or H.
	 * @return array<int,array<int,int>>
	 */
	public function encode( $data, $error_correction ) {
		$data = (string) $data;
		$length = strlen( $data );
		if ( 0 === $length || $length > self::MAX_DATA_BYTES || false !== strpos( $data, "\0" ) ) {
			throw new InvalidArgumentException( 'QR payload must contain between 1 and 2048 non-NUL bytes.' );
		}
		$level = strtoupper( trim( (string) $error_correction ) );
		if ( ! in_array( $level, array( 'L', 'M', 'Q', 'H' ), true ) ) {
			throw new InvalidArgumentException( 'QR error correction must be L, M, Q or H.' );
		}
		$version = $this->fit_version( $length, $level );
		$bytes   = $this->create_codewords( $data, $version, $level );
		$best_mask = 0;
		$best_penalty = null;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$matrix = $this->make_matrix( $version, $level, $bytes, $mask, true );
			$penalty = $this->lost_point( $matrix );
			if ( null === $best_penalty || $penalty < $best_penalty ) {
				$best_penalty = $penalty;
				$best_mask = $mask;
			}
		}
		$matrix = $this->make_matrix( $version, $level, $bytes, $best_mask, false );
		$out = array();
		foreach ( $matrix as $row ) {
			$out[] = array_map( static function ( $value ) { return $value ? 1 : 0; }, $row );
		}
		return $out;
	}

	private function fit_version( $byte_length, $level ) {
		for ( $version = 1; $version <= 40; $version++ ) {
			$count_bits = $version <= 9 ? 8 : 16;
			if ( $byte_length >= ( 1 << $count_bits ) ) { continue; }
			$needed = 4 + $count_bits + ( 8 * $byte_length );
			$capacity = 0;
			foreach ( $this->rs_blocks( $version, $level ) as $block ) {
				$capacity += 8 * $block['data'];
			}
			if ( $needed <= $capacity ) { return $version; }
		}
		throw new InvalidArgumentException( 'QR payload is too large for the requested error-correction level.' );
	}

	/** @return array<int,int> */
	private function create_codewords( $data, $version, $level ) {
		$buffer = array();
		$bit_length = 0;
		$this->put_bits( self::MODE_BYTE, 4, $buffer, $bit_length );
		$this->put_bits( strlen( $data ), $version <= 9 ? 8 : 16, $buffer, $bit_length );
		$raw = unpack( 'C*', $data );
		if ( false === $raw ) { throw new InvalidArgumentException( 'QR payload could not be converted to bytes.' ); }
		foreach ( $raw as $byte ) { $this->put_bits( $byte, 8, $buffer, $bit_length ); }

		$blocks = $this->rs_blocks( $version, $level );
		$bit_limit = 0;
		foreach ( $blocks as $block ) { $bit_limit += $block['data'] * 8; }
		if ( $bit_length > $bit_limit ) { throw new InvalidArgumentException( 'QR payload exceeded selected version capacity.' ); }
		$terminator = min( 4, $bit_limit - $bit_length );
		if ( $terminator > 0 ) { $this->put_bits( 0, $terminator, $buffer, $bit_length ); }
		while ( 0 !== $bit_length % 8 ) { $this->put_bits( 0, 1, $buffer, $bit_length ); }
		$pad_index = 0;
		while ( $bit_length < $bit_limit ) {
			$this->put_bits( 0 === $pad_index % 2 ? self::PAD0 : self::PAD1, 8, $buffer, $bit_length );
			$pad_index++;
		}
		return $this->interleave_with_error_correction( $buffer, $blocks );
	}

	private function put_bits( $number, $length, array &$buffer, &$bit_length ) {
		for ( $i = 0; $i < $length; $i++ ) {
			$bit = ( $number >> ( $length - $i - 1 ) ) & 1;
			$index = intdiv( $bit_length, 8 );
			if ( ! isset( $buffer[ $index ] ) ) { $buffer[ $index ] = 0; }
			if ( $bit ) { $buffer[ $index ] |= 0x80 >> ( $bit_length % 8 ); }
			$bit_length++;
		}
	}

	/** @return array<int,array{total:int,data:int}> */
	private function rs_blocks( $version, $level ) {
		$offsets = array( 'L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3 );
		$index = ( (int) $version - 1 ) * 4 + $offsets[ $level ];
		if ( ! isset( self::$rs_block_table[ $index ] ) ) { throw new InvalidArgumentException( 'QR Reed-Solomon block table lookup failed.' ); }
		$entry = self::$rs_block_table[ $index ];
		$blocks = array();
		for ( $i = 0; $i < count( $entry ); $i += 3 ) {
			$count = $entry[ $i ]; $total = $entry[ $i + 1 ]; $data = $entry[ $i + 2 ];
			for ( $j = 0; $j < $count; $j++ ) { $blocks[] = array( 'total' => $total, 'data' => $data ); }
		}
		return $blocks;
	}

	/** @return array<int,int> */
	private function interleave_with_error_correction( array $buffer, array $blocks ) {
		$offset = 0; $dc = array(); $ec = array(); $max_dc = 0; $max_ec = 0;
		foreach ( $blocks as $block ) {
			$data_count = $block['data']; $ec_count = $block['total'] - $data_count;
			$current = array_slice( $buffer, $offset, $data_count );
			$offset += $data_count;
			$current_ec = $this->reed_solomon_remainder( $current, $ec_count );
			$dc[] = $current; $ec[] = $current_ec;
			$max_dc = max( $max_dc, count( $current ) );
			$max_ec = max( $max_ec, count( $current_ec ) );
		}
		$out = array();
		for ( $i = 0; $i < $max_dc; $i++ ) { foreach ( $dc as $row ) { if ( isset( $row[ $i ] ) ) { $out[] = $row[ $i ]; } } }
		for ( $i = 0; $i < $max_ec; $i++ ) { foreach ( $ec as $row ) { if ( isset( $row[ $i ] ) ) { $out[] = $row[ $i ]; } } }
		return $out;
	}

	/** @return array<int,int> */
	private function reed_solomon_remainder( array $data, $degree ) {
		$generator = $this->rs_generator( $degree );
		$message = array_merge( array_values( $data ), array_fill( 0, $degree, 0 ) );
		for ( $i = 0; $i < count( $data ); $i++ ) {
			$factor = $message[ $i ];
			if ( 0 === $factor ) { continue; }
			for ( $j = 0; $j < count( $generator ); $j++ ) {
				$message[ $i + $j ] ^= $this->gf_multiply( $generator[ $j ], $factor );
			}
		}
		return array_slice( $message, -$degree );
	}

	/** @return array<int,int> */
	private function rs_generator( $degree ) {
		if ( isset( self::$generator_cache[ $degree ] ) ) { return self::$generator_cache[ $degree ]; }
		$poly = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$poly = $this->poly_multiply( $poly, array( 1, $this->gf_exp( $i ) ) );
		}
		self::$generator_cache[ $degree ] = $poly;
		return $poly;
	}

	/** @return array<int,int> */
	private function poly_multiply( array $a, array $b ) {
		$out = array_fill( 0, count( $a ) + count( $b ) - 1, 0 );
		foreach ( $a as $i => $av ) { foreach ( $b as $j => $bv ) { if ( $av && $bv ) { $out[ $i + $j ] ^= $this->gf_multiply( $av, $bv ); } } }
		return $out;
	}

	private function gf_multiply( $a, $b ) {
		if ( 0 === $a || 0 === $b ) { return 0; }
		$this->init_galois();
		return self::$exp_table[ ( self::$log_table[ $a ] + self::$log_table[ $b ] ) % 255 ];
	}
	private function gf_exp( $power ) { $this->init_galois(); return self::$exp_table[ $power % 255 ]; }
	private function init_galois() {
		if ( null !== self::$exp_table ) { return; }
		$exp = array_fill( 0, 256, 0 ); $log = array_fill( 0, 256, 0 );
		for ( $i = 0; $i < 8; $i++ ) { $exp[ $i ] = 1 << $i; }
		for ( $i = 8; $i < 256; $i++ ) { $exp[ $i ] = $exp[ $i - 4 ] ^ $exp[ $i - 5 ] ^ $exp[ $i - 6 ] ^ $exp[ $i - 8 ]; }
		for ( $i = 0; $i < 255; $i++ ) { $log[ $exp[ $i ] ] = $i; }
		self::$exp_table = $exp; self::$log_table = $log;
	}

	/** @return array<int,array<int,bool|null>> */
	private function make_matrix( $version, $level, array $data, $mask, $test ) {
		$size = 17 + 4 * $version;
		$modules = array_fill( 0, $size, array_fill( 0, $size, null ) );
		$this->setup_position_probe( $modules, 0, 0 );
		$this->setup_position_probe( $modules, $size - 7, 0 );
		$this->setup_position_probe( $modules, 0, $size - 7 );
		$this->setup_alignment( $modules, $version );
		$this->setup_timing( $modules );
		$this->setup_type_info( $modules, $level, $mask, $test );
		if ( $version >= 7 ) { $this->setup_type_number( $modules, $version, $test ); }
		$this->map_data( $modules, $data, $mask );
		return $modules;
	}

	private function setup_position_probe( array &$modules, $row, $col ) {
		$size = count( $modules );
		for ( $r = -1; $r <= 7; $r++ ) {
			if ( $row + $r < 0 || $row + $r >= $size ) { continue; }
			for ( $c = -1; $c <= 7; $c++ ) {
				if ( $col + $c < 0 || $col + $c >= $size ) { continue; }
				$dark = ( $r >= 0 && $r <= 6 && ( 0 === $c || 6 === $c ) ) || ( $c >= 0 && $c <= 6 && ( 0 === $r || 6 === $r ) ) || ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 );
				$modules[ $row + $r ][ $col + $c ] = $dark;
			}
		}
	}

	private function setup_alignment( array &$modules, $version ) {
		$positions = self::$pattern_positions[ $version - 1 ];
		foreach ( $positions as $row ) { foreach ( $positions as $col ) {
			if ( null !== $modules[ $row ][ $col ] ) { continue; }
			for ( $r = -2; $r <= 2; $r++ ) { for ( $c = -2; $c <= 2; $c++ ) {
				$modules[ $row + $r ][ $col + $c ] = -2 === $r || 2 === $r || -2 === $c || 2 === $c || ( 0 === $r && 0 === $c );
			} }
		} }
	}

	private function setup_timing( array &$modules ) {
		$size = count( $modules );
		for ( $r = 8; $r < $size - 8; $r++ ) { if ( null === $modules[ $r ][6] ) { $modules[ $r ][6] = 0 === $r % 2; } }
		for ( $c = 8; $c < $size - 8; $c++ ) { if ( null === $modules[6][ $c ] ) { $modules[6][ $c ] = 0 === $c % 2; } }
	}

	private function setup_type_info( array &$modules, $level, $mask, $test ) {
		$format_levels = array( 'L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2 );
		$data = ( $format_levels[ $level ] << 3 ) | $mask;
		$bits = $this->bch_type_info( $data );
		$size = count( $modules );
		for ( $i = 0; $i < 15; $i++ ) {
			$dark = ! $test && 1 === ( ( $bits >> $i ) & 1 );
			if ( $i < 6 ) { $modules[ $i ][8] = $dark; }
			elseif ( $i < 8 ) { $modules[ $i + 1 ][8] = $dark; }
			else { $modules[ $size - 15 + $i ][8] = $dark; }
		}
		for ( $i = 0; $i < 15; $i++ ) {
			$dark = ! $test && 1 === ( ( $bits >> $i ) & 1 );
			if ( $i < 8 ) { $modules[8][ $size - $i - 1 ] = $dark; }
			elseif ( $i < 9 ) { $modules[8][15 - $i] = $dark; }
			else { $modules[8][15 - $i - 1] = $dark; }
		}
		$modules[ $size - 8 ][8] = ! $test;
	}

	private function setup_type_number( array &$modules, $version, $test ) {
		$bits = $this->bch_type_number( $version ); $size = count( $modules );
		for ( $i = 0; $i < 18; $i++ ) {
			$dark = ! $test && 1 === ( ( $bits >> $i ) & 1 );
			$modules[ intdiv( $i, 3 ) ][ $i % 3 + $size - 11 ] = $dark;
			$modules[ $i % 3 + $size - 11 ][ intdiv( $i, 3 ) ] = $dark;
		}
	}

	private function map_data( array &$modules, array $data, $mask ) {
		$size = count( $modules ); $inc = -1; $row = $size - 1; $bit = 7; $byte = 0;
		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			if ( $col <= 6 ) { $col--; }
			while ( true ) {
				foreach ( array( $col, $col - 1 ) as $c ) {
					if ( null !== $modules[ $row ][ $c ] ) { continue; }
					$dark = $byte < count( $data ) ? 1 === ( ( $data[ $byte ] >> $bit ) & 1 ) : false;
					if ( $this->mask( $mask, $row, $c ) ) { $dark = ! $dark; }
					$modules[ $row ][ $c ] = $dark;
					$bit--;
					if ( $bit < 0 ) { $byte++; $bit = 7; }
				}
				$row += $inc;
				if ( $row < 0 || $row >= $size ) { $row -= $inc; $inc = -$inc; break; }
			}
		}
	}

	private function mask( $mask, $i, $j ) {
		switch ( $mask ) {
			case 0: return 0 === ( $i + $j ) % 2;
			case 1: return 0 === $i % 2;
			case 2: return 0 === $j % 3;
			case 3: return 0 === ( $i + $j ) % 3;
			case 4: return 0 === ( intdiv( $i, 2 ) + intdiv( $j, 3 ) ) % 2;
			case 5: return 0 === ( ( $i * $j ) % 2 + ( $i * $j ) % 3 );
			case 6: return 0 === ( ( ( $i * $j ) % 2 + ( $i * $j ) % 3 ) % 2 );
			case 7: return 0 === ( ( ( $i * $j ) % 3 + ( $i + $j ) % 2 ) % 2 );
		}
		throw new InvalidArgumentException( 'Invalid QR mask.' );
	}

	private function bch_type_info( $data ) {
		$d = $data << 10;
		while ( $this->bch_digit( $d ) - $this->bch_digit( self::G15 ) >= 0 ) { $d ^= self::G15 << ( $this->bch_digit( $d ) - $this->bch_digit( self::G15 ) ); }
		return ( ( $data << 10 ) | $d ) ^ self::G15_MASK;
	}
	private function bch_type_number( $data ) {
		$d = $data << 12;
		while ( $this->bch_digit( $d ) - $this->bch_digit( self::G18 ) >= 0 ) { $d ^= self::G18 << ( $this->bch_digit( $d ) - $this->bch_digit( self::G18 ) ); }
		return ( $data << 12 ) | $d;
	}
	private function bch_digit( $data ) { $digit = 0; while ( 0 !== $data ) { $digit++; $data >>= 1; } return $digit; }

	private function lost_point( array $modules ) {
		$size = count( $modules ); $penalty = 0;
		for ( $row = 0; $row < $size; $row++ ) {
			$run_color = $modules[ $row ][0]; $run = 1;
			for ( $col = 1; $col < $size; $col++ ) { if ( $modules[ $row ][ $col ] === $run_color ) { $run++; } else { if ( $run >= 5 ) { $penalty += $run - 2; } $run_color = $modules[ $row ][ $col ]; $run = 1; } }
			if ( $run >= 5 ) { $penalty += $run - 2; }
		}
		for ( $col = 0; $col < $size; $col++ ) {
			$run_color = $modules[0][ $col ]; $run = 1;
			for ( $row = 1; $row < $size; $row++ ) { if ( $modules[ $row ][ $col ] === $run_color ) { $run++; } else { if ( $run >= 5 ) { $penalty += $run - 2; } $run_color = $modules[ $row ][ $col ]; $run = 1; } }
			if ( $run >= 5 ) { $penalty += $run - 2; }
		}
		for ( $row = 0; $row < $size - 1; $row++ ) { for ( $col = 0; $col < $size - 1; $col++ ) {
			$color = $modules[ $row ][ $col ]; if ( $modules[ $row ][ $col + 1 ] === $color && $modules[ $row + 1 ][ $col ] === $color && $modules[ $row + 1 ][ $col + 1 ] === $color ) { $penalty += 3; }
		} }
		$pattern_a = '10111010000'; $pattern_b = '00001011101';
		for ( $row = 0; $row < $size; $row++ ) {
			$bits = ''; for ( $col = 0; $col < $size; $col++ ) { $bits .= $modules[ $row ][ $col ] ? '1' : '0'; }
			for ( $i = 0; $i <= $size - 11; $i++ ) { $slice = substr( $bits, $i, 11 ); if ( $pattern_a === $slice || $pattern_b === $slice ) { $penalty += 40; } }
		}
		for ( $col = 0; $col < $size; $col++ ) {
			$bits = ''; for ( $row = 0; $row < $size; $row++ ) { $bits .= $modules[ $row ][ $col ] ? '1' : '0'; }
			for ( $i = 0; $i <= $size - 11; $i++ ) { $slice = substr( $bits, $i, 11 ); if ( $pattern_a === $slice || $pattern_b === $slice ) { $penalty += 40; } }
		}
		$dark = 0; foreach ( $modules as $row ) { foreach ( $row as $module ) { if ( $module ) { $dark++; } } }
		$percent = ( 100.0 * $dark ) / ( $size * $size );
		$penalty += (int) floor( abs( $percent - 50.0 ) / 5.0 ) * 10;
		return $penalty;
	}
}
