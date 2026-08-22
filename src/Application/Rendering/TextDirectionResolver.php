<?php
namespace Certloom\Application\Rendering;

use Certloom\Domain\Rendering\LayoutSchema;
use InvalidArgumentException;

/** Shared first-strong-character direction resolution for every output engine. */
final class TextDirectionResolver {
	/** @return string */
	public function resolve( $text, $requested = LayoutSchema::DIRECTION_AUTO ) {
		$requested = strtolower( (string) $requested );
		if ( in_array( $requested, array( LayoutSchema::DIRECTION_LTR, LayoutSchema::DIRECTION_RTL ), true ) ) {
			return $requested;
		}
		if ( LayoutSchema::DIRECTION_AUTO !== $requested ) {
			throw new InvalidArgumentException( 'Unknown text direction.' );
		}
		$text = (string) $text;
		$characters = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $characters ) {
			throw new InvalidArgumentException( 'Direction detection requires valid UTF-8.' );
		}
		foreach ( $characters as $character ) {
			if ( preg_match( '/[\p{Arabic}\p{Hebrew}]/u', $character ) ) {
				return LayoutSchema::DIRECTION_RTL;
			}
			if ( preg_match( '/[\p{Latin}\p{Greek}\p{Cyrillic}]/u', $character ) ) {
				return LayoutSchema::DIRECTION_LTR;
			}
		}
		return LayoutSchema::DIRECTION_LTR;
	}
}
