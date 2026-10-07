<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Parse image attributes through WordPress; never replace URL substrings in HTML. */
final class WholeStateEmailMedia {
	public static function urls( $value ) {
		if ( ! is_string( $value ) ) { return array(); }
		if ( WholeStateReferenceRecord::media_url( $value ) ) { return array( $value ); }
		if ( false === strpos( $value, '<' ) ) { return array(); }
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) { return new \WP_Error( 'certloom_whole_email_media_parser_missing', __( 'WordPress cannot review the images in this email design.', 'certloom' ) ); }
		$parser = new \WP_HTML_Tag_Processor( $value ); $urls = array();
		while ( $parser->next_tag() ) {
			foreach ( array_unique( array( 'background', 'IMG' === $parser->get_tag() ? 'src' : 'background' ) ) as $attribute ) {
				$url = $parser->get_attribute( $attribute ); if ( WholeStateReferenceRecord::media_url( $url ) ) { $urls[$url] = $url; }
			}
			$style = $parser->get_attribute( 'style' );
			if ( is_string( $style ) ) { preg_replace_callback( self::css_pattern(), static function ( $match ) use ( &$urls ) { $url = self::css_url( $match ); if ( WholeStateReferenceRecord::media_url( $url ) ) { $urls[$url] = $url; } return $match[0]; }, $style ); }
			if ( count( $urls ) > WholeStateReferenceRecord::MAX_ASSET_REFERENCES ) { return new \WP_Error( 'certloom_whole_email_media_limit', __( 'This email design contains too many images to recover safely.', 'certloom' ) ); }
		}
		return array_values( $urls );
	}
	public static function rewrite( $value, callable $map ) {
		$urls = self::urls( $value ); if ( is_wp_error( $urls ) ) { return $urls; }
		if ( ! $urls ) { return $value; }
		if ( WholeStateReferenceRecord::media_url( $value ) ) { return $map( $value ); }
		$parser = new \WP_HTML_Tag_Processor( $value );
		while ( $parser->next_tag() ) {
			foreach ( array_unique( array( 'background', 'IMG' === $parser->get_tag() ? 'src' : 'background' ) ) as $attribute ) {
				$url = $parser->get_attribute( $attribute ); if ( ! WholeStateReferenceRecord::media_url( $url ) ) { continue; }
				$target = $map( $url ); if ( is_wp_error( $target ) ) { return $target; }
				if ( $target !== $url ) { $parser->set_attribute( $attribute, $target ); if ( 'src' === $attribute ) { $parser->remove_attribute( 'srcset' ); } }
			}
			$style = $parser->get_attribute( 'style' ); $error = null;
			if ( is_string( $style ) ) {
				$changed = preg_replace_callback( self::css_pattern(), static function ( $match ) use ( $map, &$error ) {
					$url = self::css_url( $match ); if ( ! WholeStateReferenceRecord::media_url( $url ) ) { return $match[0]; }
					$target = $map( $url ); if ( is_wp_error( $target ) ) { $error = $target; return $match[0]; }
					if ( $target === $url ) { return $match[0]; }
					return '' === $target ? 'none' : 'url("' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $target ) . '")';
				}, $style );
				if ( is_wp_error( $error ) ) { return $error; }
				if ( null === $changed ) { return new \WP_Error( 'certloom_whole_email_media_invalid', __( 'This email background could not be reviewed.', 'certloom' ) ); }
				if ( $changed !== $style ) { $parser->set_attribute( 'style', $changed ); }
			}
		}
		return $parser->get_updated_html();
	}
	private static function css_pattern() { return '~url\(\s*(?:"([^"\\\\]*)"|\x27([^\x27\\\\]*)\x27|([^()\s"\x27\\\\]+))\s*\)~i'; }
	private static function css_url( array $match ) { return (string) ( '' !== ( $match[1] ?? '' ) ? $match[1] : ( '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[3] ?? '' ) ) ); }
}
