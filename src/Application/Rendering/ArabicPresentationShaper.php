<?php
namespace Certloom\Application\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use InvalidArgumentException;

/**
 * Deterministic in-process Arabic/Perso-Arabic presentation shaper for the GD
 * candidate. This is deliberately bounded: it converts Unicode Arabic letters
 * with standard Presentation Forms into contextual forms before GD/FreeType
 * drawing, then applies a small deterministic bidi visual-order step.
 *
 * Complex-script coverage outside this declared Arabic/Perso-Arabic set must
 * fail closed instead of silently degrading credential typography.
 */
final class ArabicPresentationShaper {
	/** @var array<int,array<string,int>> */
	private static $forms = array(
		0x0621 => array('isolated' => 0xFE80),
		0x0622 => array('isolated' => 0xFE81, 'final' => 0xFE82),
		0x0623 => array('isolated' => 0xFE83, 'final' => 0xFE84),
		0x0624 => array('isolated' => 0xFE85, 'final' => 0xFE86),
		0x0625 => array('isolated' => 0xFE87, 'final' => 0xFE88),
		0x0626 => array('isolated' => 0xFE89, 'final' => 0xFE8A, 'initial' => 0xFE8B, 'medial' => 0xFE8C),
		0x0627 => array('isolated' => 0xFE8D, 'final' => 0xFE8E),
		0x0628 => array('isolated' => 0xFE8F, 'final' => 0xFE90, 'initial' => 0xFE91, 'medial' => 0xFE92),
		0x0629 => array('isolated' => 0xFE93, 'final' => 0xFE94),
		0x062A => array('isolated' => 0xFE95, 'final' => 0xFE96, 'initial' => 0xFE97, 'medial' => 0xFE98),
		0x062B => array('isolated' => 0xFE99, 'final' => 0xFE9A, 'initial' => 0xFE9B, 'medial' => 0xFE9C),
		0x062C => array('isolated' => 0xFE9D, 'final' => 0xFE9E, 'initial' => 0xFE9F, 'medial' => 0xFEA0),
		0x062D => array('isolated' => 0xFEA1, 'final' => 0xFEA2, 'initial' => 0xFEA3, 'medial' => 0xFEA4),
		0x062E => array('isolated' => 0xFEA5, 'final' => 0xFEA6, 'initial' => 0xFEA7, 'medial' => 0xFEA8),
		0x062F => array('isolated' => 0xFEA9, 'final' => 0xFEAA),
		0x0630 => array('isolated' => 0xFEAB, 'final' => 0xFEAC),
		0x0631 => array('isolated' => 0xFEAD, 'final' => 0xFEAE),
		0x0632 => array('isolated' => 0xFEAF, 'final' => 0xFEB0),
		0x0633 => array('isolated' => 0xFEB1, 'final' => 0xFEB2, 'initial' => 0xFEB3, 'medial' => 0xFEB4),
		0x0634 => array('isolated' => 0xFEB5, 'final' => 0xFEB6, 'initial' => 0xFEB7, 'medial' => 0xFEB8),
		0x0635 => array('isolated' => 0xFEB9, 'final' => 0xFEBA, 'initial' => 0xFEBB, 'medial' => 0xFEBC),
		0x0636 => array('isolated' => 0xFEBD, 'final' => 0xFEBE, 'initial' => 0xFEBF, 'medial' => 0xFEC0),
		0x0637 => array('isolated' => 0xFEC1, 'final' => 0xFEC2, 'initial' => 0xFEC3, 'medial' => 0xFEC4),
		0x0638 => array('isolated' => 0xFEC5, 'final' => 0xFEC6, 'initial' => 0xFEC7, 'medial' => 0xFEC8),
		0x0639 => array('isolated' => 0xFEC9, 'final' => 0xFECA, 'initial' => 0xFECB, 'medial' => 0xFECC),
		0x063A => array('isolated' => 0xFECD, 'final' => 0xFECE, 'initial' => 0xFECF, 'medial' => 0xFED0),
		0x0641 => array('isolated' => 0xFED1, 'final' => 0xFED2, 'initial' => 0xFED3, 'medial' => 0xFED4),
		0x0642 => array('isolated' => 0xFED5, 'final' => 0xFED6, 'initial' => 0xFED7, 'medial' => 0xFED8),
		0x0643 => array('isolated' => 0xFED9, 'final' => 0xFEDA, 'initial' => 0xFEDB, 'medial' => 0xFEDC),
		0x0644 => array('isolated' => 0xFEDD, 'final' => 0xFEDE, 'initial' => 0xFEDF, 'medial' => 0xFEE0),
		0x0645 => array('isolated' => 0xFEE1, 'final' => 0xFEE2, 'initial' => 0xFEE3, 'medial' => 0xFEE4),
		0x0646 => array('isolated' => 0xFEE5, 'final' => 0xFEE6, 'initial' => 0xFEE7, 'medial' => 0xFEE8),
		0x0647 => array('isolated' => 0xFEE9, 'final' => 0xFEEA, 'initial' => 0xFEEB, 'medial' => 0xFEEC),
		0x0648 => array('isolated' => 0xFEED, 'final' => 0xFEEE),
		0x0649 => array('initial' => 0xFBE8, 'medial' => 0xFBE9, 'isolated' => 0xFEEF, 'final' => 0xFEF0),
		0x064A => array('isolated' => 0xFEF1, 'final' => 0xFEF2, 'initial' => 0xFEF3, 'medial' => 0xFEF4),
		0x0671 => array('isolated' => 0xFB50, 'final' => 0xFB51),
		0x0677 => array('isolated' => 0xFBDD),
		0x0679 => array('isolated' => 0xFB66, 'final' => 0xFB67, 'initial' => 0xFB68, 'medial' => 0xFB69),
		0x067A => array('isolated' => 0xFB5E, 'final' => 0xFB5F, 'initial' => 0xFB60, 'medial' => 0xFB61),
		0x067B => array('isolated' => 0xFB52, 'final' => 0xFB53, 'initial' => 0xFB54, 'medial' => 0xFB55),
		0x067E => array('isolated' => 0xFB56, 'final' => 0xFB57, 'initial' => 0xFB58, 'medial' => 0xFB59),
		0x067F => array('isolated' => 0xFB62, 'final' => 0xFB63, 'initial' => 0xFB64, 'medial' => 0xFB65),
		0x0680 => array('isolated' => 0xFB5A, 'final' => 0xFB5B, 'initial' => 0xFB5C, 'medial' => 0xFB5D),
		0x0683 => array('isolated' => 0xFB76, 'final' => 0xFB77, 'initial' => 0xFB78, 'medial' => 0xFB79),
		0x0684 => array('isolated' => 0xFB72, 'final' => 0xFB73, 'initial' => 0xFB74, 'medial' => 0xFB75),
		0x0686 => array('isolated' => 0xFB7A, 'final' => 0xFB7B, 'initial' => 0xFB7C, 'medial' => 0xFB7D),
		0x0687 => array('isolated' => 0xFB7E, 'final' => 0xFB7F, 'initial' => 0xFB80, 'medial' => 0xFB81),
		0x0688 => array('isolated' => 0xFB88, 'final' => 0xFB89),
		0x068C => array('isolated' => 0xFB84, 'final' => 0xFB85),
		0x068D => array('isolated' => 0xFB82, 'final' => 0xFB83),
		0x068E => array('isolated' => 0xFB86, 'final' => 0xFB87),
		0x0691 => array('isolated' => 0xFB8C, 'final' => 0xFB8D),
		0x0698 => array('isolated' => 0xFB8A, 'final' => 0xFB8B),
		0x06A4 => array('isolated' => 0xFB6A, 'final' => 0xFB6B, 'initial' => 0xFB6C, 'medial' => 0xFB6D),
		0x06A6 => array('isolated' => 0xFB6E, 'final' => 0xFB6F, 'initial' => 0xFB70, 'medial' => 0xFB71),
		0x06A9 => array('isolated' => 0xFB8E, 'final' => 0xFB8F, 'initial' => 0xFB90, 'medial' => 0xFB91),
		0x06AD => array('isolated' => 0xFBD3, 'final' => 0xFBD4, 'initial' => 0xFBD5, 'medial' => 0xFBD6),
		0x06AF => array('isolated' => 0xFB92, 'final' => 0xFB93, 'initial' => 0xFB94, 'medial' => 0xFB95),
		0x06B1 => array('isolated' => 0xFB9A, 'final' => 0xFB9B, 'initial' => 0xFB9C, 'medial' => 0xFB9D),
		0x06B3 => array('isolated' => 0xFB96, 'final' => 0xFB97, 'initial' => 0xFB98, 'medial' => 0xFB99),
		0x06BA => array('isolated' => 0xFB9E, 'final' => 0xFB9F),
		0x06BB => array('isolated' => 0xFBA0, 'final' => 0xFBA1, 'initial' => 0xFBA2, 'medial' => 0xFBA3),
		0x06BE => array('isolated' => 0xFBAA, 'final' => 0xFBAB, 'initial' => 0xFBAC, 'medial' => 0xFBAD),
		0x06C0 => array('isolated' => 0xFBA4, 'final' => 0xFBA5),
		0x06C1 => array('isolated' => 0xFBA6, 'final' => 0xFBA7, 'initial' => 0xFBA8, 'medial' => 0xFBA9),
		0x06C5 => array('isolated' => 0xFBE0, 'final' => 0xFBE1),
		0x06C6 => array('isolated' => 0xFBD9, 'final' => 0xFBDA),
		0x06C7 => array('isolated' => 0xFBD7, 'final' => 0xFBD8),
		0x06C8 => array('isolated' => 0xFBDB, 'final' => 0xFBDC),
		0x06C9 => array('isolated' => 0xFBE2, 'final' => 0xFBE3),
		0x06CB => array('isolated' => 0xFBDE, 'final' => 0xFBDF),
		0x06CC => array('isolated' => 0xFBFC, 'final' => 0xFBFD, 'initial' => 0xFBFE, 'medial' => 0xFBFF),
		0x06D0 => array('isolated' => 0xFBE4, 'final' => 0xFBE5, 'initial' => 0xFBE6, 'medial' => 0xFBE7),
		0x06D2 => array('isolated' => 0xFBAE, 'final' => 0xFBAF),
		0x06D3 => array('isolated' => 0xFBB0, 'final' => 0xFBB1),
	);

	/** @return string */
	public function visual_text( $text, $direction ) {
		$text = (string) $text;
		$direction = strtolower( trim( (string) $direction ) );
		if ( ! in_array( $direction, array( 'ltr', 'rtl' ), true ) || 1 !== preg_match( '//u', $text ) ) {
			throw new InvalidArgumentException( 'Arabic shaping requires valid UTF-8 and an explicit ltr/rtl direction.' );
		}
		$arabic = '[\\x{0600}-\\x{06FF}\\x{0750}-\\x{077F}\\x{08A0}-\\x{08FF}]';
		$pattern = '/' . $arabic . '+(?:[ \\t]+' . $arabic . '+)*/u';
		$result = preg_replace_callback( $pattern, function ( $match ) {
			$chars = preg_split( '//u', $match[0], -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $chars ) { throw new InvalidArgumentException( 'Arabic shaping could not split a visual run.' ); }
			return implode( '', array_reverse( $this->shape_arabic_run( $chars ) ) );
		}, $text );
		if ( null === $result ) { throw new InvalidArgumentException( 'Arabic shaping could not segment bidi runs.' ); }
		return $result;
	}

	/** @return array<int,string> */
	private function shape_arabic_run( array $chars ) {
		$out = $chars;
		$count = count( $chars );
		for ( $i = 0; $i < $count; $i++ ) {
			$cp = $this->codepoint( $chars[ $i ] );
			if ( ! isset( self::$forms[ $cp ] ) ) { continue; }
			$prev = $this->neighbor_form_index( $chars, $i, -1 );
			$next = $this->neighbor_form_index( $chars, $i, 1 );
			$forms = self::$forms[ $cp ];
			$connect_prev = null !== $prev && $this->can_connect_next( $this->codepoint( $chars[ $prev ] ) ) && $this->can_connect_prev( $cp );
			$connect_next = null !== $next && $this->can_connect_next( $cp ) && $this->can_connect_prev( $this->codepoint( $chars[ $next ] ) );
			$key = $connect_prev && $connect_next && isset( $forms['medial'] ) ? 'medial' : ( $connect_prev && isset( $forms['final'] ) ? 'final' : ( $connect_next && isset( $forms['initial'] ) ? 'initial' : 'isolated' ) );
			if ( isset( $forms[ $key ] ) ) { $out[ $i ] = $this->char_from_codepoint( $forms[ $key ] ); }
		}
		return $out;
	}

	/** @return int|null */
	private function neighbor_form_index( array $chars, $index, $step ) {
		for ( $i = $index + $step; $i >= 0 && $i < count( $chars ); $i += $step ) {
			$cp = $this->codepoint( $chars[ $i ] );
			if ( $this->is_transparent_mark( $cp ) ) { continue; }
			return isset( self::$forms[ $cp ] ) ? $i : null;
		}
		return null;
	}

	private function can_connect_prev( $cp ) {
		return isset( self::$forms[ $cp ]['final'] ) || isset( self::$forms[ $cp ]['medial'] );
	}

	private function can_connect_next( $cp ) {
		return isset( self::$forms[ $cp ]['initial'] ) || isset( self::$forms[ $cp ]['medial'] );
	}

	private function is_transparent_mark( $cp ) {
		return ( $cp >= 0x0610 && $cp <= 0x061A ) || ( $cp >= 0x064B && $cp <= 0x065F ) || 0x0670 === $cp || ( $cp >= 0x06D6 && $cp <= 0x06ED );
	}

	private function is_arabic_scalar( $cp ) {
		return ( $cp >= 0x0600 && $cp <= 0x06FF ) || ( $cp >= 0x0750 && $cp <= 0x077F ) || ( $cp >= 0x08A0 && $cp <= 0x08FF );
	}

	private function codepoint( $char ) {
		$bytes = array_values( unpack( 'C*', $char ) );
		$b1 = $bytes[0];
		if ( $b1 <= 0x7F ) { return $b1; }
		if ( ( $b1 & 0xE0 ) === 0xC0 ) { return ( ( $b1 & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F ); }
		if ( ( $b1 & 0xF0 ) === 0xE0 ) { return ( ( $b1 & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F ); }
		return ( ( $b1 & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
	}

	private function char_from_codepoint( $cp ) {
		$cp = (int) $cp;
		if ( $cp <= 0x7F ) { return chr( $cp ); }
		if ( $cp <= 0x7FF ) { return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) ); }
		if ( $cp <= 0xFFFF ) { return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) ); }
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}
}
