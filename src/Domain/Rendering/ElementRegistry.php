<?php
namespace Certloom\Domain\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use InvalidArgumentException;

/**
 * Closed, typed registry for every canonical certloom-layout element.
 *
 * DSN-08 deliberately centralizes element metadata without opening an
 * unversioned WordPress filter or arbitrary HTML/CSS extension point. New
 * persisted element types still require an explicit layout-schema version and
 * renderer/validator support before they can appear here.
 */
final class ElementRegistry {
	const CONTRACT_VERSION = 1;

	/** @return array<string,array<string,mixed>> */
	public static function all() {
		$common = array( 'id', 'type', 'x_mm', 'y_mm', 'width_mm', 'height_mm', 'z', 'rotation_deg', 'opacity' );
		$text_style = array( 'font_family', 'font_size_pt', 'font_weight', 'italic', 'color', 'align', 'vertical_align', 'direction', 'line_height', 'letter_spacing_pt', 'overflow' );
		return array(
			'text' => self::definition( 'text', 1, array( 'text', 'style' ), array( 'text' ), 'text', 'text', 'text', 'text', 'text', array(), array( 'content' => 'text', 'style' => 'text', 'style_properties' => $text_style ), array(
				self::palette_item( 'heading', 'heading', 120, 18, array( 'text_i18n' => 'heading', 'style_preset' => 'heading' ) ),
				self::palette_item( 'text', 'text', 90, 18, array( 'text_i18n' => 'text', 'style_preset' => 'text' ) ),
				self::palette_item( 'field', 'field', 90, 18, array( 'text_i18n' => 'field', 'style_preset' => 'field' ) ),
			), $common ),
			'merge' => self::definition( 'merge', 1, array( 'token', 'style' ), array( 'token' ), 'merge', 'merge', 'merge', 'text', 'merge', array( 'dynamic_token' ), array( 'content' => 'merge', 'style' => 'text', 'style_properties' => $text_style ), array(
				// Legacy standalone merge boxes remain readable forever, but schema 4 moves normal authoring to inline rich-text runs.
				self::palette_item( 'merge', 'mergeField', 80, 15, array( 'token' => 'recipient.name', 'style_preset' => 'text' ), false, 3 ),
			), $common ),
			'rich_text' => self::definition( 'rich_text', 4, array( 'runs', 'style' ), array( 'runs' ), 'rich_text', 'rich_text', 'rich_text', 'rich_text', 'richText', array( 'inline_dynamic_tokens' ), array( 'content' => 'rich_text', 'style' => 'text', 'style_properties' => $text_style ), array(
				self::palette_item( 'dynamic_field', 'dynamicField', 90, 18, array( 'rich_preset' => 'dynamic_field', 'style_preset' => 'text' ) ),
				self::palette_item( 'rich_text', 'richText', 110, 24, array( 'rich_preset' => 'certificate_line', 'style_preset' => 'text' ) ),
			), $common ),
			'image' => self::definition( 'image', 1, array( 'asset', 'source', 'fit' ), array(), 'asset', 'asset', 'asset', 'image', 'asset', array(), array( 'content' => 'asset', 'style' => '', 'media' => true ), array(
				self::palette_item( 'image', 'image', 60, 40, array( 'fit' => 'contain' ), true ),
				self::palette_item( 'seal', 'seal', 40, 40, array( 'fit' => 'contain' ), true ),
			), $common ),
			'logo' => self::definition( 'logo', 1, array( 'asset', 'source', 'fit' ), array(), 'asset', 'asset', 'asset', 'image', 'asset', array(), array( 'content' => 'asset', 'style' => '', 'media' => true ), array(
				self::palette_item( 'logo', 'logo', 60, 40, array( 'fit' => 'contain' ), true ),
			), $common ),
			'signature' => self::definition( 'signature', 1, array( 'asset', 'source', 'fit' ), array(), 'signature_asset', 'signature_asset', 'asset', 'image', 'signature', array(), array( 'content' => 'signature', 'style' => '', 'media' => true ), array(
				self::palette_item( 'signature', 'signature', 70, 25, array( 'fit' => 'contain' ), true ),
			), $common ),
			'shape' => self::definition( 'shape', 1, array( 'shape', 'fill', 'stroke', 'stroke_width_mm' ), array( 'shape' ), 'shape', 'passthrough', 'shape', 'shape', 'shape', array(), array( 'content' => 'shape', 'style' => 'shape', 'style_properties' => array( 'fill', 'stroke', 'stroke_width_mm' ) ), array(
				self::palette_item( 'rectangle', 'rectangle', 60, 30, array( 'shape' => 'rectangle', 'fill' => '#FFFFFF00', 'stroke' => '#000000', 'stroke_width_mm' => 0.3 ) ),
				self::palette_item( 'ellipse', 'ellipse', 60, 30, array( 'shape' => 'ellipse', 'fill' => '#FFFFFF00', 'stroke' => '#000000', 'stroke_width_mm' => 0.3 ) ),
				self::palette_item( 'background', 'background', 0, 0, array( 'shape' => 'rectangle', 'fill' => '#FFFFFF', 'stroke' => '#00000000', 'stroke_width_mm' => 0.0, 'full_page' => true ) ),
			), $common ),
			'line' => self::definition( 'line', 1, array( 'stroke', 'stroke_width_mm' ), array(), 'line', 'passthrough', 'line', 'line', 'line', array(), array( 'content' => 'line', 'style' => 'line', 'style_properties' => array( 'stroke', 'stroke_width_mm' ) ), array(
				self::palette_item( 'line', 'line', 60, 1, array( 'stroke' => '#000000', 'stroke_width_mm' => 0.3 ) ),
			), $common ),
			'qr' => self::definition( 'qr', 1, array( 'source', 'error_correction', 'quiet_zone_modules' ), array( 'source' ), 'qr', 'qr', 'qr', 'qr', 'qr', array( 'verification_url' ), array( 'content' => 'qr', 'style' => '' ), array(
				self::palette_item( 'qr', 'qr', 35, 35, array( 'source' => 'verification_url', 'error_correction' => 'H', 'quiet_zone_modules' => 4 ) ),
			), $common ),
			'barcode' => self::definition( 'barcode', 2, array( 'source', 'symbology', 'quiet_zone_modules' ), array( 'source' ), 'barcode', 'barcode', 'barcode', 'barcode', 'barcode', array( 'credential.display_number' ), array( 'content' => 'barcode', 'style' => '' ), array(
				self::palette_item( 'barcode', 'barcode', 110, 18, array( 'source' => 'credential.display_number', 'symbology' => 'code128', 'quiet_zone_modules' => 10 ) ),
			), $common ),
			'list' => self::definition( 'list', 3, array( 'items', 'list_style', 'style' ), array( 'items' ), 'list', 'list', 'list', 'text', 'list', array(), array( 'content' => 'list', 'style' => 'text', 'style_properties' => $text_style ), array(
				self::palette_item( 'list', 'list', 110, 50, array( 'items_i18n' => array( 'firstListItem', 'secondListItem' ), 'list_style' => 'bullet', 'style_preset' => 'list' ) ),
			), $common ),
			'table' => self::definition( 'table', 4, array( 'columns', 'rows', 'show_header', 'style' ), array( 'columns', 'rows' ), 'table', 'table', 'table', 'table', 'table', array(), array( 'content' => 'table', 'style' => 'table', 'style_properties' => array( 'header_fill', 'header_color', 'header_font_weight', 'row_fill', 'alternate_row_fill', 'border_color', 'border_width_mm', 'cell_padding_mm' ) ), array(
				self::palette_item( 'table', 'table', 150, 60, array( 'table_preset' => 'simple' ) ),
			), $common ),
			'data_table' => self::definition( 'data_table', 4, array( 'source', 'columns', 'max_rows', 'show_header', 'style' ), array( 'source', 'columns' ), 'data_table', 'data_table', 'data_table', 'table', 'dataTable', array(), array( 'content' => 'data_table', 'style' => 'table', 'style_properties' => array( 'header_fill', 'header_color', 'header_font_weight', 'row_fill', 'alternate_row_fill', 'border_color', 'border_width_mm', 'cell_padding_mm' ) ), array(
				self::palette_item( 'data_table', 'dataTable', 150, 72, array( 'source' => 'credential.results', 'max_rows' => 8, 'table_preset' => 'results' ) ),
			), $common ),
		);
	}

	/** @return array<string,mixed>|null */
	public static function get( $type ) {
		$type = strtolower( trim( (string) $type ) );
		$all = self::all();
		return isset( $all[ $type ] ) ? $all[ $type ] : null;
	}

	/** @return array<string,mixed> */
	public static function require_type( $type ) {
		$definition = self::get( $type );
		if ( ! is_array( $definition ) ) {
			throw new InvalidArgumentException( 'Unknown canonical layout element type.' );
		}
		return $definition;
	}

	/** @return string[] */
	public static function types_for_schema( $schema_version ) {
		$schema_version = (int) $schema_version;
		$out = array();
		foreach ( self::all() as $type => $definition ) {
			if ( $schema_version >= (int) $definition['min_schema_version'] ) { $out[] = $type; }
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public static function palette() {
		$out = array();
		foreach ( self::all() as $definition ) {
			foreach ( $definition['palette'] as $item ) {
				$item['type'] = $definition['type'];
				$item['min_schema_version'] = $definition['min_schema_version'];
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Portable metadata safe to expose to the Designer browser runtime.
	 * No callbacks, HTML or executable code can enter this contract.
	 *
	 * @return array<string,mixed>
	 */
	public static function browser_contract() {
		$types = array();
		foreach ( self::all() as $type => $definition ) {
			$types[ $type ] = array(
				'type' => $type,
				'label_key' => (string) $definition['inspector_key'],
				'min_schema_version' => (int) $definition['min_schema_version'],
				'content_group' => (string) $definition['inspector']['content'],
				'style_group' => (string) $definition['inspector']['style'],
				'media' => ! empty( $definition['inspector']['media'] ),
				'preview' => (string) $definition['preview'],
				'render_operation' => (string) $definition['render_operation'],
				'merge_requirements' => array_values( $definition['merge_requirements'] ),
			);
		}
		return array( 'contract_version' => self::CONTRACT_VERSION, 'types' => $types, 'palette' => self::palette() );
	}

	/** @return array<string,mixed> */
	private static function definition( $type, $min_version, array $type_properties, array $required, $validation, $resolver, $preview, $render_operation, $inspector_key, array $merge_requirements, array $inspector, array $palette, array $common ) {
		return array(
			'type' => $type,
			'min_schema_version' => (int) $min_version,
			'allowed_properties' => array_values( array_merge( $common, $type_properties ) ),
			'required_properties' => array_values( array_merge( array( 'id', 'type', 'x_mm', 'y_mm', 'width_mm', 'height_mm' ), $required ) ),
			'validation' => $validation,
			'resolver' => $resolver,
			'preview' => $preview,
			'render_operation' => $render_operation,
			'inspector_key' => $inspector_key,
			'merge_requirements' => array_values( $merge_requirements ),
			'inspector' => array_merge( array( 'content' => '', 'style' => '', 'media' => false, 'style_properties' => array() ), $inspector ),
			'palette' => array_values( $palette ),
		);
	}

	/** @return array<string,mixed> */
	private static function palette_item( $kind, $label_key, $width_mm, $height_mm, array $defaults, $requires_media = false, $max_schema_version = null ) {
		$item = array(
			'kind' => (string) $kind,
			'label_key' => (string) $label_key,
			'width_mm' => (float) $width_mm,
			'height_mm' => (float) $height_mm,
			'requires_media' => (bool) $requires_media,
			'defaults' => $defaults,
		);
		if ( null !== $max_schema_version ) { $item['max_schema_version'] = (int) $max_schema_version; }
		return $item;
	}
}
