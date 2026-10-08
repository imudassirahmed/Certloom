<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Presents ordinary email controls without flattening an existing saved layout. */
final class EmailComposerDocument {
	/** A previously generated plain-text body continues to follow message edits. */
	public static function custom_plain_text( array $blocks, array $theme, $saved ) {
		$compiled = ( new EmailDesignCompiler() )->compile( $blocks, $theme );
		return trim( (string) $saved ) === trim( $compiled['text_body'] ) ? '' : (string) $saved;
	}
	public static function defaults() {
		return array(
			array( 'type' => 'heading', 'text' => __( 'Your credential is ready', 'certloom' ) ),
			array( 'type' => 'text', 'html' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p><p>' . esc_html__( 'Congratulations on completing {{program_title}}. Your credential is ready to view, share and verify.', 'certloom' ) . '</p>' ),
			array( 'type' => 'button', 'text' => __( 'View your credential', 'certloom' ), 'url' => '{{verification_url}}' ),
			array( 'type' => 'text', 'html' => '<p>' . esc_html__( 'Issued by {{issuer_name}}', 'certloom' ) . '</p>', 'style' => array( 'font_size' => 12, 'color' => '#607087' ) ),
		);
	}
	/** Paths refer to the original canonical document; styles and other nodes stay intact. */
	public static function controls( array $blocks ) {
		$controls = array( 'heading' => null, 'message' => null, 'action' => null, 'logo' => null, 'footer' => null, 'additional' => array() ); $texts = array();
		$visit = static function ( array $rows, array $prefix ) use ( &$visit, &$controls, &$texts ) {
			foreach ( $rows as $index => $row ) {
				$path = array_merge( $prefix, array( $index ) ); $type = $row['type'] ?? '';
				if ( 'container' === $type ) { $visit( $row['children'] ?? array(), array_merge( $path, array( 'children' ) ) ); continue; }
				$key = array( 'heading' => 'heading', 'text' => 'message', 'button' => 'action', 'image' => 'logo' )[ $type ] ?? '';
				$item = array( 'path' => $path, 'block' => $row );
				if ( 'text' === $type ) { $texts[] = $item; }
				if ( $key && null === $controls[ $key ] ) { $controls[ $key ] = $item; }
				else { $controls['additional'][] = $item; }
			}
		};
		$visit( $blocks, array() );
		if ( count( $texts ) > 1 ) { $controls['footer'] = end( $texts ); $controls['additional'] = array_values( array_filter( $controls['additional'], static function ( $item ) use ( $controls ) { return $item['path'] !== $controls['footer']['path']; } ) ); }
		return $controls;
	}
}
