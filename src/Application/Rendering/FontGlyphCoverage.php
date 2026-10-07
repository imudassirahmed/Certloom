<?php
namespace Certloom\Application\Rendering;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checks actual Unicode cmap glyphs instead of trusting broad manifest ranges. */
final class FontGlyphCoverage {
	private $maps = array();

	public function has_glyph( $path, $codepoint ) {
		if ( ! isset( $this->maps[ $path ] ) ) { $this->maps[ $path ] = $this->load( $path ); }
		$map = $this->maps[ $path ]; $bytes = $map['bytes']; $offset = $map['offset'];
		if ( 12 === $map['format'] ) {
			$low = 0; $high = $this->u32( $bytes, $offset + 12 ) - 1;
			while ( $low <= $high ) {
				$middle = (int) floor( ( $low + $high ) / 2 ); $group = $offset + 16 + 12 * $middle;
				$start = $this->u32( $bytes, $group ); $end = $this->u32( $bytes, $group + 4 );
				if ( $codepoint < $start ) { $high = $middle - 1; }
				elseif ( $codepoint > $end ) { $low = $middle + 1; }
				else { return 0 !== $this->u32( $bytes, $group + 8 ) + $codepoint - $start; }
			}
			return false;
		}
		if ( $codepoint > 0xffff ) { return false; }
		$count = (int) ( $this->u16( $bytes, $offset + 6 ) / 2 );
		for ( $index = 0; $index < $count; ++$index ) {
			$end = $this->u16( $bytes, $offset + 14 + 2 * $index );
			if ( $codepoint > $end ) { continue; }
			$start = $this->u16( $bytes, $offset + 16 + 2 * $count + 2 * $index );
			if ( $codepoint < $start ) { return false; }
			$delta = $this->u16( $bytes, $offset + 16 + 4 * $count + 2 * $index );
			$word = $offset + 16 + 6 * $count + 2 * $index; $range = $this->u16( $bytes, $word );
			if ( 0 === $range ) { return 0 !== ( ( $codepoint + $delta ) & 0xffff ); }
			$address = $word + $range + 2 * ( $codepoint - $start );
			if ( $address < $offset || $address + 2 > $offset + $map['length'] ) { throw new \RuntimeException( 'Font glyph offset is invalid.' ); }
			$glyph = $this->u16( $bytes, $address );
			return 0 !== $glyph && 0 !== ( ( $glyph + $delta ) & 0xffff );
		}
		return false;
	}

	private function load( $path ) {
		$size = @filesize( $path );
		if ( ! is_int( $size ) || $size < 12 || $size > 20971520 ) { throw new \RuntimeException( 'Font glyph data is unavailable or oversized.' ); }
		$bytes = @file_get_contents( $path );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $size ) { throw new \RuntimeException( 'Font glyph data could not be read.' ); }
		$count = $this->u16( $bytes, 4 );
		if ( $count < 1 || $count > 128 || 12 + 16 * $count > $size ) { throw new \RuntimeException( 'Font table directory is invalid.' ); }
		$cmap = null; $length = 0;
		for ( $index = 0; $index < $count; ++$index ) {
			$record = 12 + 16 * $index;
			if ( 'cmap' === substr( $bytes, $record, 4 ) ) { $cmap = $this->u32( $bytes, $record + 8 ); $length = $this->u32( $bytes, $record + 12 ); break; }
		}
		if ( null === $cmap || $length < 4 || $cmap + $length > $size ) { throw new \RuntimeException( 'Font Unicode table is invalid.' ); }
		$records = $this->u16( $bytes, $cmap + 2 ); $best = null;
		if ( $records > 128 || 4 + 8 * $records > $length ) { throw new \RuntimeException( 'Font Unicode table directory is invalid.' ); }
		for ( $index = 0; $index < $records; ++$index ) {
			$record = $cmap + 4 + 8 * $index; $platform = $this->u16( $bytes, $record ); $encoding = $this->u16( $bytes, $record + 2 );
			if ( 0 !== $platform && ! ( 3 === $platform && in_array( $encoding, array( 1, 10 ), true ) ) ) { continue; }
			$offset = $cmap + $this->u32( $bytes, $record + 4 );
			if ( $offset < $cmap || $offset + 4 > $cmap + $length ) { throw new \RuntimeException( 'Font Unicode subtable offset is invalid.' ); }
			$format = $this->u16( $bytes, $offset );
			if ( ! in_array( $format, array( 4, 12 ), true ) ) { continue; }
			$sub_length = 12 === $format ? $this->u32( $bytes, $offset + 4 ) : $this->u16( $bytes, $offset + 2 );
			if ( $sub_length < 16 || $offset + $sub_length > $cmap + $length ) { throw new \RuntimeException( 'Font Unicode subtable length is invalid.' ); }
			if ( 12 === $format ) {
				$groups = $this->u32( $bytes, $offset + 12 );
				if ( $groups > 100000 || 16 + 12 * $groups > $sub_length ) { throw new \RuntimeException( 'Font Unicode groups are invalid.' ); }
				$previous = -1;
				for ( $group = 0; $group < $groups; ++$group ) {
					$start = $this->u32( $bytes, $offset + 16 + 12 * $group ); $end = $this->u32( $bytes, $offset + 20 + 12 * $group );
					if ( $start <= $previous || $end < $start || $end > 0x10ffff ) { throw new \RuntimeException( 'Font Unicode groups are unordered.' ); } $previous = $end;
				}
			} else {
				$segments = $this->u16( $bytes, $offset + 6 );
				if ( 0 === $segments || $segments % 2 || $segments > 16384 || 16 + 4 * $segments > $sub_length ) { throw new \RuntimeException( 'Font Unicode segments are invalid.' ); }
			}
			$priority = ( 12 === $format ? 20 : 10 ) + ( 3 === $platform ? 1 : 0 );
			if ( null === $best || $priority > $best['priority'] ) { $best = array( 'bytes' => $bytes, 'offset' => $offset, 'length' => $sub_length, 'format' => $format, 'priority' => $priority ); }
		}
		if ( null === $best ) { throw new \RuntimeException( 'Font has no supported Unicode glyph map.' ); }
		return $best;
	}
	private function u16( $bytes, $offset ) { if ( $offset < 0 || $offset + 2 > strlen( $bytes ) ) { throw new \RuntimeException( 'Truncated font data.' ); } return unpack( 'n', substr( $bytes, $offset, 2 ) )[1]; }
	private function u32( $bytes, $offset ) { if ( $offset < 0 || $offset + 4 > strlen( $bytes ) ) { throw new \RuntimeException( 'Truncated font data.' ); } return unpack( 'N', substr( $bytes, $offset, 4 ) )[1]; }
}
