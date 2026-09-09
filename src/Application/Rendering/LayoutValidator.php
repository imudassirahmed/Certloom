<?php
namespace Certloom\Application\Rendering;

use Certloom\Domain\Rendering\ElementId;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;
use InvalidArgumentException;

/** Strict schema validator/normalizer for immutable certificate layouts. */
final class LayoutValidator {
	const MAX_ELEMENTS = 500;
	const MAX_TEXT_BYTES = 20000;
	const MAX_RICH_TEXT_RUNS = 256;
	const MAX_ROTATION = 360.0;

	/** @return array<string,mixed> */
	public function normalize( array $layout ) {
		$this->assert_keys( $layout, array( '_meta', 'page', 'elements' ), array( '_meta', 'page', 'elements' ), 'layout' );
		$meta = $this->normalize_meta( $layout['_meta'] );
		$schema_version = (int) $meta['schema_version'];
		$page = $this->normalize_page( $layout['page'] );
		if ( ! is_array( $layout['elements'] ) ) {
			throw new InvalidArgumentException( 'Layout elements must be an array.' );
		}
		if ( count( $layout['elements'] ) > self::MAX_ELEMENTS ) {
			throw new InvalidArgumentException( 'Layout contains too many elements.' );
		}

		$elements = array();
		$ids = array();
		$z_orders = array();
		foreach ( array_values( $layout['elements'] ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				throw new InvalidArgumentException( sprintf( 'Element %d must be an object.', $index + 1 ) );
			}
			$normalized = $this->normalize_element( $element, $page, $index, $schema_version );
			if ( isset( $ids[ $normalized['id'] ] ) ) {
				throw new InvalidArgumentException( 'Layout element IDs must be unique.' );
			}
			$ids[ $normalized['id'] ] = true;
			if ( isset( $z_orders[ (string) $normalized['z'] ] ) ) {
				throw new InvalidArgumentException( 'Layout element z-order values must be unique.' );
			}
			$z_orders[ (string) $normalized['z'] ] = true;
			$elements[] = $normalized;
		}

		usort( $elements, function ( $a, $b ) { return $a['z'] <=> $b['z']; } );
		return array( '_meta' => $meta, 'page' => $page, 'elements' => $elements );
	}

	/** @return string */
	public function hash( array $layout ) {
		return ( new CanonicalJson() )->hash( $this->normalize( $layout ) );
	}

	/** @return array<string,mixed> */
	private function normalize_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			throw new InvalidArgumentException( 'Layout _meta must be an object.' );
		}
		$this->assert_keys( $meta, array( 'schema', 'schema_version' ), array( 'schema', 'schema_version' ), 'layout _meta' );
		if ( LayoutSchema::NAME !== (string) $meta['schema'] ) {
			throw new InvalidArgumentException( 'Unsupported layout schema.' );
		}
		$version = $this->integer( $meta['schema_version'], 'layout schema version' );
		if ( ! LayoutSchema::supports_version( $version ) ) {
			throw new InvalidArgumentException( 'Unsupported layout schema version.' );
		}
		return array( 'schema' => LayoutSchema::NAME, 'schema_version' => $version );
	}

	/** @return array<string,mixed> */
	private function normalize_page( $page ) {
		if ( ! is_array( $page ) ) {
			throw new InvalidArgumentException( 'Layout page must be an object.' );
		}
		$this->assert_keys( $page, array( 'size', 'orientation', 'width_mm', 'height_mm' ), array( 'size', 'orientation', 'width_mm', 'height_mm' ), 'layout page' );
		$size = (string) $page['size'];
		$orientation = strtolower( (string) $page['orientation'] );
		$dimensions = LayoutSchema::page_dimensions();
		if ( ! isset( $dimensions[ $size ][ $orientation ] ) ) {
			throw new InvalidArgumentException( 'Unsupported page size or orientation.' );
		}
		$width = $this->number( $page['width_mm'], 'page width' );
		$height = $this->number( $page['height_mm'], 'page height' );
		list( $expected_width, $expected_height ) = $dimensions[ $size ][ $orientation ];
		if ( abs( $width - $expected_width ) > 0.01 || abs( $height - $expected_height ) > 0.01 ) {
			throw new InvalidArgumentException( 'Page dimensions do not match the selected standard size/orientation.' );
		}
		return array( 'size' => $size, 'orientation' => $orientation, 'width_mm' => $expected_width, 'height_mm' => $expected_height );
	}

	/** @return array<string,mixed> */
	private function normalize_element( array $element, array $page, $index, $schema_version ) {
		$type = isset( $element['type'] ) ? strtolower( (string) $element['type'] ) : '';
		$definition = ElementRegistry::get( $type );
		if ( ! is_array( $definition ) || ! in_array( $type, LayoutSchema::element_types( $schema_version ), true ) ) {
			throw new InvalidArgumentException( sprintf( 'Element %d has an unsupported type.', $index + 1 ) );
		}
		$this->assert_keys( $element, $definition['allowed_properties'], $definition['required_properties'], 'layout element' );

		$id = strtolower( trim( (string) $element['id'] ) );
		if ( ! ( new ElementId() )->is_valid( $id ) ) {
			throw new InvalidArgumentException( 'Each layout element ID must be a UUIDv4.' );
		}
		$x = $this->number( $element['x_mm'], 'element x' );
		$y = $this->number( $element['y_mm'], 'element y' );
		$width = $this->number( $element['width_mm'], 'element width' );
		$height = $this->number( $element['height_mm'], 'element height' );
		if ( $x < 0 || $y < 0 || $width <= 0 || $height <= 0 || $x + $width > $page['width_mm'] + 0.001 || $y + $height > $page['height_mm'] + 0.001 ) {
			throw new InvalidArgumentException( 'Layout element geometry must remain inside the page bounds.' );
		}
		$rotation = isset( $element['rotation_deg'] ) ? $this->number( $element['rotation_deg'], 'element rotation' ) : 0.0;
		if ( $rotation < -self::MAX_ROTATION || $rotation > self::MAX_ROTATION ) {
			throw new InvalidArgumentException( 'Element rotation must be between -360 and 360 degrees.' );
		}
		$rotation = fmod( $rotation, 360.0 );
		if ( $rotation >= 180.0 ) { $rotation -= 360.0; }
		if ( $rotation < -180.0 ) { $rotation += 360.0; }
		if ( 0.0 == $rotation ) { $rotation = 0.0; }
		$this->assert_rotated_bounds( $x, $y, $width, $height, $rotation, $page );
		$opacity = isset( $element['opacity'] ) ? $this->number( $element['opacity'], 'element opacity' ) : 1.0;
		if ( $opacity < 0 || $opacity > 1 ) {
			throw new InvalidArgumentException( 'Element opacity must be between 0 and 1.' );
		}

		$out = array(
			'id' => $id, 'type' => $type, 'x_mm' => $x, 'y_mm' => $y,
			'width_mm' => $width, 'height_mm' => $height,
			'z' => isset( $element['z'] ) ? $this->integer( $element['z'], 'element z-order' ) : (int) $index,
			'rotation_deg' => $rotation, 'opacity' => $opacity,
		);
		if ( $out['z'] < -100000 || $out['z'] > 100000 ) {
			throw new InvalidArgumentException( 'Element z-order is outside the supported range.' );
		}

		switch ( (string) $definition['validation'] ) {
			case 'text':
				$out['text'] = $this->text( $element['text'], 'text element' );
				$out['style'] = $this->style( isset( $element['style'] ) ? $element['style'] : array() );
				break;
			case 'merge':
				$out['token'] = $this->token( $element['token'] );
				$out['style'] = $this->style( isset( $element['style'] ) ? $element['style'] : array() );
				break;
			case 'rich_text':
				if ( (int) $schema_version < LayoutSchema::VERSION_V4 ) {
					throw new InvalidArgumentException( 'Rich text elements require certloom-layout v4 or newer.' );
				}
				$out['runs'] = $this->rich_text_runs( $element['runs'] );
				$out['style'] = $this->style( isset( $element['style'] ) ? $element['style'] : array() );
				break;
			case 'asset':
				$out['asset'] = $this->asset_reference( $element['asset'] );
				$out['fit'] = isset( $element['fit'] ) ? strtolower( (string) $element['fit'] ) : 'contain';
				if ( ! in_array( $out['fit'], array( 'contain', 'cover', 'fill' ), true ) ) {
					throw new InvalidArgumentException( 'Unsupported image fit mode.' );
				}
				break;
			case 'signature_asset':
				$source = isset( $element['source'] ) ? strtolower( trim( (string) $element['source'] ) ) : '';
				if ( '' !== $source ) {
					if ( (int) $schema_version < LayoutSchema::VERSION_V4 ) {
						throw new InvalidArgumentException( 'Dynamic signer signatures require certloom-layout v4 or newer.' );
					}
					if ( 'signer.signature' !== $source ) {
						throw new InvalidArgumentException( 'Unsupported signature source.' );
					}
					if ( isset( $element['asset'] ) && '' !== trim( (string) $element['asset'] ) ) {
						throw new InvalidArgumentException( 'A signature must use either a fixed local asset or the immutable credential signer source, not both.' );
					}
					$out['source'] = $source;
				} else {
					if ( empty( $element['asset'] ) ) {
						throw new InvalidArgumentException( 'A fixed signature requires a local asset reference.' );
					}
					$out['asset'] = $this->asset_reference( $element['asset'] );
				}
				$out['fit'] = isset( $element['fit'] ) ? strtolower( (string) $element['fit'] ) : 'contain';
				if ( ! in_array( $out['fit'], array( 'contain', 'cover', 'fill' ), true ) ) {
					throw new InvalidArgumentException( 'Unsupported image fit mode.' );
				}
				break;
			case 'shape':
				$out['shape'] = strtolower( (string) $element['shape'] );
				if ( ! in_array( $out['shape'], array( 'rectangle', 'ellipse' ), true ) ) {
					throw new InvalidArgumentException( 'Unsupported shape type.' );
				}
				$out['fill'] = $this->color( isset( $element['fill'] ) ? $element['fill'] : '#00000000' );
				$out['stroke'] = $this->color( isset( $element['stroke'] ) ? $element['stroke'] : '#000000' );
				$out['stroke_width_mm'] = isset( $element['stroke_width_mm'] ) ? $this->positive_number( $element['stroke_width_mm'], 'shape stroke width', true ) : 0.0;
				if ( $out['stroke_width_mm'] > 20 ) { throw new InvalidArgumentException( 'Shape stroke width is outside the supported range.' ); }
				break;
			case 'line':
				$out['stroke'] = $this->color( isset( $element['stroke'] ) ? $element['stroke'] : '#000000' );
				$out['stroke_width_mm'] = isset( $element['stroke_width_mm'] ) ? $this->positive_number( $element['stroke_width_mm'], 'line stroke width', false ) : 0.3;
				if ( $out['stroke_width_mm'] > 20 ) { throw new InvalidArgumentException( 'Line stroke width is outside the supported range.' ); }
				break;
			case 'qr':
				if ( abs( $width - $height ) > 0.001 ) {
					throw new InvalidArgumentException( 'QR layout boxes must be square.' );
				}
				$out['source'] = strtolower( trim( (string) $element['source'] ) );
				if ( 'verification_url' !== $out['source'] ) {
					throw new InvalidArgumentException( 'Layout schema v1 QR source must be verification_url.' );
				}
				$out['error_correction'] = isset( $element['error_correction'] ) ? strtoupper( (string) $element['error_correction'] ) : 'M';
				if ( ! in_array( $out['error_correction'], array( 'L', 'M', 'Q', 'H' ), true ) ) {
					throw new InvalidArgumentException( 'Unsupported QR error correction level.' );
				}
				$out['quiet_zone_modules'] = isset( $element['quiet_zone_modules'] ) ? $this->integer( $element['quiet_zone_modules'], 'QR quiet zone' ) : 4;
				if ( $out['quiet_zone_modules'] < 4 || $out['quiet_zone_modules'] > 16 ) {
					throw new InvalidArgumentException( 'QR quiet zone must be between 4 and 16 modules.' );
				}
				break;
			case 'barcode':
				if ( (int) $schema_version < LayoutSchema::VERSION_V2 ) {
					throw new InvalidArgumentException( 'Barcode elements require certloom-layout v2 or newer.' );
				}
				$out['source'] = strtolower( trim( (string) $element['source'] ) );
				if ( 'credential.display_number' !== $out['source'] ) {
					throw new InvalidArgumentException( 'Code 128 barcode source must be credential.display_number.' );
				}
				$out['symbology'] = isset( $element['symbology'] ) ? strtolower( trim( (string) $element['symbology'] ) ) : 'code128';
				if ( 'code128' !== $out['symbology'] ) {
					throw new InvalidArgumentException( 'certloom-layout v2 currently supports Code 128 barcodes only.' );
				}
				$out['quiet_zone_modules'] = isset( $element['quiet_zone_modules'] ) ? $this->integer( $element['quiet_zone_modules'], 'Code 128 quiet zone' ) : 10;
				if ( $out['quiet_zone_modules'] < 10 || $out['quiet_zone_modules'] > 50 ) {
					throw new InvalidArgumentException( 'Code 128 quiet zone must be between 10 and 50 modules.' );
				}
				break;
			case 'list':
				if ( (int) $schema_version < LayoutSchema::VERSION_V3 ) {
					throw new InvalidArgumentException( 'List elements require certloom-layout v3 or newer.' );
				}
				$out['items'] = $this->list_items( $element['items'] );
				$out['list_style'] = isset( $element['list_style'] ) ? strtolower( trim( (string) $element['list_style'] ) ) : 'bullet';
				if ( ! in_array( $out['list_style'], array( 'bullet', 'decimal' ), true ) ) {
					throw new InvalidArgumentException( 'Unsupported list marker style.' );
				}
				$out['style'] = $this->style( isset( $element['style'] ) ? $element['style'] : array() );
				break;
		}
		return $out;
	}


	/** @return void */
	private function assert_rotated_bounds( $x, $y, $width, $height, $rotation, array $page ) {
		if ( 0.0 == $rotation ) { return; }
		$cx = $x + ( $width / 2 );
		$cy = $y + ( $height / 2 );
		$radians = deg2rad( $rotation );
		$cos = cos( $radians );
		$sin = sin( $radians );
		$half_width = $width / 2;
		$half_height = $height / 2;
		$points = array(
			array( -$half_width, -$half_height ), array( $half_width, -$half_height ),
			array( $half_width, $half_height ), array( -$half_width, $half_height ),
		);
		foreach ( $points as $point ) {
			$rx = $cx + ( $point[0] * $cos ) - ( $point[1] * $sin );
			$ry = $cy + ( $point[0] * $sin ) + ( $point[1] * $cos );
			if ( $rx < -0.001 || $ry < -0.001 || $rx > $page['width_mm'] + 0.001 || $ry > $page['height_mm'] + 0.001 ) {
				throw new InvalidArgumentException( 'Rotated layout element geometry must remain inside the page bounds.' );
			}
		}
	}

	/** @return array<string,mixed> */
	private function style( $style ) {
		if ( ! is_array( $style ) ) {
			throw new InvalidArgumentException( 'Text style must be an object.' );
		}
		$allowed = array( 'font_family', 'font_size_pt', 'font_weight', 'italic', 'color', 'align', 'vertical_align', 'direction', 'line_height', 'letter_spacing_pt', 'overflow' );
		$this->assert_keys( $style, $allowed, array(), 'text style' );
		$out = array(
			'font_family' => isset( $style['font_family'] ) ? trim( (string) $style['font_family'] ) : 'sans-serif',
			'font_size_pt' => isset( $style['font_size_pt'] ) ? $this->positive_number( $style['font_size_pt'], 'font size', false ) : 18.0,
			'font_weight' => isset( $style['font_weight'] ) ? $this->integer( $style['font_weight'], 'font weight' ) : 400,
			'italic' => array_key_exists( 'italic', $style ) ? $this->boolean( $style['italic'], 'italic' ) : false,
			'color' => $this->color( isset( $style['color'] ) ? $style['color'] : '#000000' ),
			'align' => isset( $style['align'] ) ? strtolower( (string) $style['align'] ) : 'center',
			'vertical_align' => isset( $style['vertical_align'] ) ? strtolower( (string) $style['vertical_align'] ) : 'middle',
			'direction' => isset( $style['direction'] ) ? strtolower( (string) $style['direction'] ) : LayoutSchema::DIRECTION_AUTO,
			'line_height' => isset( $style['line_height'] ) ? $this->positive_number( $style['line_height'], 'line height', false ) : 1.2,
			'letter_spacing_pt' => isset( $style['letter_spacing_pt'] ) ? $this->number( $style['letter_spacing_pt'], 'letter spacing' ) : 0.0,
			'overflow' => isset( $style['overflow'] ) ? strtolower( (string) $style['overflow'] ) : LayoutSchema::OVERFLOW_SHRINK,
		);
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $out['font_family'] ) ) {
			throw new InvalidArgumentException( 'Font family must be a registered Certloom font key.' );
		}
		if ( $out['font_size_pt'] < 1 || $out['font_size_pt'] > 300 ) {
			throw new InvalidArgumentException( 'Font size is outside the supported range.' );
		}
		if ( $out['line_height'] < 0.5 || $out['line_height'] > 5 ) {
			throw new InvalidArgumentException( 'Line height is outside the supported range.' );
		}
		if ( $out['letter_spacing_pt'] < -20 || $out['letter_spacing_pt'] > 50 ) {
			throw new InvalidArgumentException( 'Letter spacing is outside the supported range.' );
		}
		if ( $out['font_weight'] < 100 || $out['font_weight'] > 900 || 0 !== $out['font_weight'] % 100 ) {
			throw new InvalidArgumentException( 'Font weight must be 100 through 900 in 100-point increments.' );
		}
		if ( ! in_array( $out['align'], array( 'left', 'center', 'right', 'start', 'end' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported text alignment.' );
		}
		if ( ! in_array( $out['vertical_align'], array( 'top', 'middle', 'bottom' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported vertical alignment.' );
		}
		if ( ! in_array( $out['direction'], array( LayoutSchema::DIRECTION_LTR, LayoutSchema::DIRECTION_RTL, LayoutSchema::DIRECTION_AUTO ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported text direction.' );
		}
		if ( ! in_array( $out['overflow'], array( LayoutSchema::OVERFLOW_WRAP, LayoutSchema::OVERFLOW_SHRINK, LayoutSchema::OVERFLOW_CLIP ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported text overflow policy.' );
		}
		return $out;
	}



	/** @return array<int,array<string,mixed>> */
	private function rich_text_runs( $runs ) {
		if ( ! is_array( $runs ) || empty( $runs ) || count( $runs ) > self::MAX_RICH_TEXT_RUNS ) {
			throw new InvalidArgumentException( 'Rich text requires between 1 and 256 inline runs.' );
		}
		$out = array();
		$total = 0;
		foreach ( array_values( $runs ) as $run ) {
			if ( ! is_array( $run ) ) {
				throw new InvalidArgumentException( 'Rich text runs must be objects.' );
			}
			$kind = isset( $run['kind'] ) ? strtolower( trim( (string) $run['kind'] ) ) : '';
			if ( 'text' === $kind ) {
				$this->assert_keys( $run, array( 'kind', 'text', 'style' ), array( 'kind', 'text' ), 'rich text run' );
				$value = $this->text( $run['text'], 'rich text run' );
				if ( '' === $value ) {
					throw new InvalidArgumentException( 'Rich text literal runs may not be empty.' );
				}
				$total += strlen( $value );
				$normalized = array( 'kind' => 'text', 'text' => $value );
			} elseif ( 'token' === $kind ) {
				$this->assert_keys( $run, array( 'kind', 'token', 'style' ), array( 'kind', 'token' ), 'rich text run' );
				$value = $this->token( $run['token'] );
				$total += strlen( $value );
				$normalized = array( 'kind' => 'token', 'token' => $value );
			} else {
				throw new InvalidArgumentException( 'Rich text run kind must be text or token.' );
			}
			if ( $total > self::MAX_TEXT_BYTES ) {
				throw new InvalidArgumentException( 'Rich text source exceeds the canonical text budget.' );
			}
			if ( array_key_exists( 'style', $run ) ) {
				$style = $this->rich_run_style( $run['style'] );
				if ( ! empty( $style ) ) { $normalized['style'] = $style; }
			}
			$out[] = $normalized;
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function rich_run_style( $style ) {
		if ( ! is_array( $style ) ) {
			throw new InvalidArgumentException( 'Rich text run style must be an object.' );
		}
		$allowed = array( 'font_family', 'font_size_pt', 'font_weight', 'italic', 'color', 'letter_spacing_pt' );
		$this->assert_keys( $style, $allowed, array(), 'rich text run style' );
		$out = array();
		if ( array_key_exists( 'font_family', $style ) ) {
			$value = trim( (string) $style['font_family'] );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $value ) ) {
				throw new InvalidArgumentException( 'Rich text run font family must be a registered Certloom font key.' );
			}
			$out['font_family'] = $value;
		}
		if ( array_key_exists( 'font_size_pt', $style ) ) {
			$value = $this->positive_number( $style['font_size_pt'], 'rich text run font size', false );
			if ( $value < 1 || $value > 300 ) { throw new InvalidArgumentException( 'Rich text run font size is outside the supported range.' ); }
			$out['font_size_pt'] = $value;
		}
		if ( array_key_exists( 'font_weight', $style ) ) {
			$value = $this->integer( $style['font_weight'], 'rich text run font weight' );
			if ( $value < 100 || $value > 900 || 0 !== $value % 100 ) { throw new InvalidArgumentException( 'Rich text run font weight must be 100 through 900 in 100-point increments.' ); }
			$out['font_weight'] = $value;
		}
		if ( array_key_exists( 'italic', $style ) ) { $out['italic'] = $this->boolean( $style['italic'], 'rich text run italic' ); }
		if ( array_key_exists( 'color', $style ) ) { $out['color'] = $this->color( $style['color'] ); }
		if ( array_key_exists( 'letter_spacing_pt', $style ) ) {
			$value = $this->number( $style['letter_spacing_pt'], 'rich text run letter spacing' );
			if ( $value < -20 || $value > 50 ) { throw new InvalidArgumentException( 'Rich text run letter spacing is outside the supported range.' ); }
			$out['letter_spacing_pt'] = $value;
		}
		return $out;
	}

	/** @return string[] */
	private function list_items( $items ) {
		if ( ! is_array( $items ) || empty( $items ) || count( $items ) > 100 ) {
			throw new InvalidArgumentException( 'List elements require between 1 and 100 items.' );
		}
		$out = array();
		$total = 0;
		foreach ( array_values( $items ) as $item ) {
			$value = $this->text( $item, 'list item' );
			if ( '' === trim( $value ) ) { throw new InvalidArgumentException( 'List items may not be empty.' ); }
			if ( strlen( $value ) > 2000 ) { throw new InvalidArgumentException( 'A list item is too large.' ); }
			$total += strlen( $value );
			if ( $total > self::MAX_TEXT_BYTES ) { throw new InvalidArgumentException( 'List text exceeds the canonical text budget.' ); }
			$out[] = $value;
		}
		return $out;
	}

	/** @return string */
	private function token( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^[a-z][a-z0-9_.:-]{0,127}$/', $value ) ) {
			throw new InvalidArgumentException( 'Merge token has an invalid machine-key format.' );
		}
		return $value;
	}

	/** @return string */
	private function asset_reference( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(?:attachment:[1-9][0-9]*|asset:[a-z0-9][a-z0-9_-]{0,127})$/', $value ) ) {
			throw new InvalidArgumentException( 'Layout assets must use a local attachment:ID or asset:key reference.' );
		}
		return $value;
	}

	/** @return string */
	private function color( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		if ( ! preg_match( '/^#[0-9A-F]{6}(?:[0-9A-F]{2})?$/', $value ) ) {
			throw new InvalidArgumentException( 'Colors must use #RRGGBB or #RRGGBBAA.' );
		}
		return $value;
	}

	/** @return string */
	private function text( $value, $label ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new InvalidArgumentException( $label . ' must be scalar text.' );
		}
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_TEXT_BYTES || preg_match( '/\x00/', $value ) ) {
			throw new InvalidArgumentException( $label . ' is too large or contains NUL bytes.' );
		}
		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( $label . ' must be valid UTF-8.' );
		}
		return $value;
	}

	/** @return int */
	private function integer( $value, $label ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/', $value ) ) {
			$int = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false !== $int ) { return (int) $int; }
		}
		throw new InvalidArgumentException( $label . ' must be an integer.' );
	}

	/** @return bool */
	private function boolean( $value, $label ) {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( $label . ' must be a boolean.' );
		}
		return $value;
	}

	/** @return float */
	private function number( $value, $label ) {
		if ( ! is_numeric( $value ) ) {
			throw new InvalidArgumentException( $label . ' must be numeric.' );
		}
		$value = (float) $value;
		if ( is_nan( $value ) || is_infinite( $value ) ) {
			throw new InvalidArgumentException( $label . ' must be finite.' );
		}
		return $value;
	}

	/** @return float */
	private function positive_number( $value, $label, $allow_zero ) {
		$value = $this->number( $value, $label );
		if ( $allow_zero ? $value < 0 : $value <= 0 ) {
			throw new InvalidArgumentException( $label . ' is outside its allowed range.' );
		}
		return $value;
	}

	/** @return void */
	private function assert_keys( array $value, array $allowed, array $required, $label ) {
		$unknown = array_diff( array_keys( $value ), $allowed );
		if ( ! empty( $unknown ) ) {
			throw new InvalidArgumentException( sprintf( '%s contains unknown property: %s', $label, reset( $unknown ) ) );
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				throw new InvalidArgumentException( sprintf( '%s is missing required property: %s', $label, $key ) );
			}
		}
	}
}
