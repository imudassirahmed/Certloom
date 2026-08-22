<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Pure-PHP Unicode scalar/range helper used by the font contract.
 * Does not require mbstring so Core can validate manifests on PHP 7.4+.
 */
final class UnicodeCoverage {
	/** @return string[] */
	public function normalize_ranges( array $ranges ) {
		if ( empty( $ranges ) ) {
			throw new InvalidArgumentException( 'Font face coverage must declare at least one Unicode range.' );
		}
		$parsed = array();
		foreach ( $ranges as $range ) {
			if ( ! is_string( $range ) ) {
				throw new InvalidArgumentException( 'Unicode coverage ranges must be strings.' );
			}
			$value = strtoupper( trim( $range ) );
			if ( ! preg_match( '/^U\+([0-9A-F]{4,6})(?:-([0-9A-F]{4,6}))?$/', $value, $matches ) ) {
				throw new InvalidArgumentException( 'Unicode coverage must use U+XXXX or U+XXXX-YYYY notation.' );
			}
			$start = hexdec( $matches[1] );
			$end = isset( $matches[2] ) && '' !== $matches[2] ? hexdec( $matches[2] ) : $start;
			if ( $start > $end || $end > 0x10FFFF || $this->intersects_surrogates( $start, $end ) ) {
				throw new InvalidArgumentException( 'Unicode coverage contains an invalid scalar range.' );
			}
			$parsed[] = array( $start, $end );
		}
		usort( $parsed, function ( $a, $b ) { return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]; } );

		$merged = array();
		foreach ( $parsed as $range ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $range[0] <= $merged[ $last ][1] + 1 ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
			} else {
				$merged[] = $range;
			}
		}
		$out = array();
		foreach ( $merged as $range ) {
			$start = $this->format_codepoint( $range[0] );
			$end = $this->format_codepoint( $range[1] );
			$out[] = $range[0] === $range[1] ? 'U+' . $start : 'U+' . $start . '-' . $end;
		}
		return $out;
	}

	/** @return void */
	public function assert_text_covered( $text, array $faces ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
			throw new InvalidArgumentException( 'Font coverage can only be checked against valid UTF-8 text.' );
		}
		$coverage = array();
		foreach ( $faces as $face ) {
			if ( ! is_array( $face ) || empty( $face['coverage'] ) || ! is_array( $face['coverage'] ) ) {
				throw new InvalidArgumentException( 'Font face coverage descriptor is missing.' );
			}
			$coverage[] = $this->parse_normalized_ranges( $face['coverage'] );
		}
		foreach ( $this->codepoints( $text ) as $codepoint ) {
			if ( in_array( $codepoint, array( 0x09, 0x0A, 0x0D ), true ) ) {
				continue;
			}
			$covered = false;
			foreach ( $coverage as $ranges ) {
				if ( $this->contains( $codepoint, $ranges ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				throw new InvalidArgumentException( sprintf( 'Renderer font manifest does not cover Unicode scalar U+%s required by the resolved document.', $this->format_codepoint( $codepoint ) ) );
			}
		}
	}

	/** @return int[] */
	public function codepoints( $text ) {
		if ( ! is_string( $text ) || 1 !== preg_match( '//u', $text ) ) {
			throw new InvalidArgumentException( 'Unicode input must be valid UTF-8.' );
		}
		$out = array();
		$length = strlen( $text );
		for ( $i = 0; $i < $length; ) {
			$b1 = ord( $text[ $i ] );
			if ( $b1 <= 0x7F ) {
				$out[] = $b1;
				$i++;
				continue;
			}
			if ( ( $b1 & 0xE0 ) === 0xC0 ) {
				$b2 = ord( $text[ $i + 1 ] );
				$out[] = ( ( $b1 & 0x1F ) << 6 ) | ( $b2 & 0x3F );
				$i += 2;
				continue;
			}
			if ( ( $b1 & 0xF0 ) === 0xE0 ) {
				$b2 = ord( $text[ $i + 1 ] );
				$b3 = ord( $text[ $i + 2 ] );
				$out[] = ( ( $b1 & 0x0F ) << 12 ) | ( ( $b2 & 0x3F ) << 6 ) | ( $b3 & 0x3F );
				$i += 3;
				continue;
			}
			$b2 = ord( $text[ $i + 1 ] );
			$b3 = ord( $text[ $i + 2 ] );
			$b4 = ord( $text[ $i + 3 ] );
			$out[] = ( ( $b1 & 0x07 ) << 18 ) | ( ( $b2 & 0x3F ) << 12 ) | ( ( $b3 & 0x3F ) << 6 ) | ( $b4 & 0x3F );
			$i += 4;
		}
		return $out;
	}

	/** @return array<int,array{0:int,1:int}> */
	private function parse_normalized_ranges( array $ranges ) {
		$out = array();
		foreach ( $this->normalize_ranges( $ranges ) as $range ) {
			preg_match( '/^U\+([0-9A-F]+)(?:-([0-9A-F]+))?$/', $range, $matches );
			$start = hexdec( $matches[1] );
			$end = isset( $matches[2] ) && '' !== $matches[2] ? hexdec( $matches[2] ) : $start;
			$out[] = array( $start, $end );
		}
		return $out;
	}

	/** @return bool */
	private function contains( $codepoint, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $codepoint < $range[0] ) { return false; }
			if ( $codepoint <= $range[1] ) { return true; }
		}
		return false;
	}

	/** @return bool */
	private function intersects_surrogates( $start, $end ) {
		return $start <= 0xDFFF && $end >= 0xD800;
	}

	/** @return string */
	private function format_codepoint( $codepoint ) {
		return strtoupper( str_pad( dechex( (int) $codepoint ), 4, '0', STR_PAD_LEFT ) );
	}
}
