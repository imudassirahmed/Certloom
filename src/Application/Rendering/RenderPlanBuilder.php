<?php
namespace Certloom\Application\Rendering;

use Certloom\Domain\Rendering\ElementId;
use Certloom\Domain\Rendering\LayoutSchema;
use InvalidArgumentException;

/**
 * Builds one renderer-independent drawing plan from a fully resolved document.
 *
 * PDF/image adapters consume this plan instead of re-interpreting canonical
 * layout geometry independently. Text shaping/line breaking remains an adapter
 * acceptance concern until Certloom can prove or centralize it further.
 */
final class RenderPlanBuilder {
	const SCHEMA = 'certloom-render-plan';
	const VERSION = 1;
	const POINT_TO_MM = 25.4 / 72.0;
	private $canonical;
	private $fonts;

	public function __construct( ?CanonicalJson $canonical = null, ?FontManifest $fonts = null ) {
		$this->canonical = $canonical ?: new CanonicalJson();
		$this->fonts = $fonts ?: new FontManifest();
	}

	/** @return array<string,mixed> */
	public function build( array $resolved_document, array $renderer_context = array() ) {
		if ( isset( $resolved_document['_meta'] ) && is_array( $resolved_document['_meta'] ) && isset( $resolved_document['_meta']['schema'] ) && 'certloom-layout' !== (string) $resolved_document['_meta']['schema'] ) {
			throw new InvalidArgumentException( 'Resolved render document has an unsupported layout schema.' );
		}
		$page = isset( $resolved_document['page'] ) && is_array( $resolved_document['page'] ) ? $resolved_document['page'] : null;
		if ( ! is_array( $page ) || ! isset( $page['size'], $page['orientation'], $page['width_mm'], $page['height_mm'] ) ) {
			throw new InvalidArgumentException( 'Resolved render document is missing canonical page geometry.' );
		}
		$size = (string) $page['size'];
		$orientation = strtolower( (string) $page['orientation'] );
		$dimensions = LayoutSchema::page_dimensions();
		if ( ! isset( $dimensions[ $size ][ $orientation ] ) ) {
			throw new InvalidArgumentException( 'Render plan page size/orientation is unsupported.' );
		}
		$width = $this->positive_number( $page['width_mm'], 'page width' );
		$height = $this->positive_number( $page['height_mm'], 'page height' );
		list( $expected_width, $expected_height ) = $dimensions[ $size ][ $orientation ];
		if ( abs( $width - $expected_width ) > 0.01 || abs( $height - $expected_height ) > 0.01 ) {
			throw new InvalidArgumentException( 'Render plan page geometry does not match its canonical standard size/orientation.' );
		}
		$width = (float) $expected_width;
		$height = (float) $expected_height;
		$elements = isset( $resolved_document['elements'] ) && is_array( $resolved_document['elements'] ) ? array_values( $resolved_document['elements'] ) : array();
		$operations = array();
		$seen_ids = array();
		$seen_z = array();
		$runtime = isset( $resolved_document['_runtime'] ) && is_array( $resolved_document['_runtime'] ) ? $resolved_document['_runtime'] : array();

		$font_manifest = array();
		if ( $this->fonts->document_uses_text( $resolved_document ) ) {
			if ( empty( $renderer_context['font_manifest'] ) || ! is_array( $renderer_context['font_manifest'] ) ) {
				throw new InvalidArgumentException( 'Text render plans require the renderer exact-font manifest.' );
			}
			$font_manifest = $this->fonts->normalize( $renderer_context['font_manifest'] );
			$this->fonts->assert_document_supported( $resolved_document, $font_manifest );
		}

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				throw new InvalidArgumentException( 'Resolved render elements must be objects.' );
			}
			$type = strtolower( trim( (string) ( $element['type'] ?? '' ) ) );
			if ( 'merge' === $type || '' === $type ) {
				throw new InvalidArgumentException( 'Render plan accepts only fully resolved element types.' );
			}
			$id = strtolower( trim( (string) ( $element['id'] ?? '' ) ) );
			$z = $this->integer( $element['z'] ?? $index, 'element z-order' );
			if ( ! ( new ElementId() )->is_valid( $id ) || $z < -100000 || $z > 100000 || isset( $seen_ids[ $id ] ) || isset( $seen_z[ (string) $z ] ) ) {
				throw new InvalidArgumentException( 'Render plan requires unique element IDs and z-order values.' );
			}
			$seen_ids[ $id ] = true;
			$seen_z[ (string) $z ] = true;
			$geometry = $this->geometry( $element, $width, $height );
			$operation = array(
				'id' => $id,
				'type' => $type,
				'z' => $z,
				'opacity' => $this->unit_number( $element['opacity'] ?? 1.0, 'element opacity' ),
				'geometry' => $geometry,
			);

			switch ( $type ) {
				case 'text':
					$operation['text'] = $this->text_operation( $element, $font_manifest );
					break;
				case 'image':
				case 'logo':
				case 'signature':
					$image = $this->image_operation( $element, $geometry );
					$asset_hash = $image['asset']['sha256'];
					$runtime['assets'][ $asset_hash ] = array( 'path' => $image['_runtime_path'] );
					unset( $image['_runtime_path'] );
					$operation['image'] = $image;
					break;
				case 'shape':
					$operation['shape'] = $this->shape_operation( $element );
					break;
				case 'line':
					$operation['line'] = $this->line_operation( $element, $geometry );
					break;
				case 'qr':
					$operation['qr'] = $this->qr_operation( $element, $geometry );
					break;
				default:
					throw new InvalidArgumentException( 'Resolved render document contains an unsupported operation type.' );
			}
			$operations[] = $operation;
		}

		usort( $operations, static function ( $a, $b ) { return $a['z'] <=> $b['z']; } );
		$plan = array(
			'_meta' => array( 'schema' => self::SCHEMA, 'version' => self::VERSION ),
			'page' => array(
				'size' => $size,
				'orientation' => $orientation,
				'width_mm' => $width,
				'height_mm' => $height,
			),
			'operations' => $operations,
		);
		if ( isset( $resolved_document['_runtime'] ) && ! is_array( $resolved_document['_runtime'] ) ) {
			throw new InvalidArgumentException( 'Renderer runtime state must be an object.' );
		}
		if ( ! empty( $runtime ) ) {
			if ( isset( $runtime['assets'] ) && is_array( $runtime['assets'] ) ) { ksort( $runtime['assets'], SORT_STRING ); }
			$plan['_runtime'] = $runtime;
		}
		return $plan;
	}

	/** @return string */
	public function hash( array $plan ) {
		$portable = $plan;
		unset( $portable['_runtime'] );
		return $this->canonical->hash( $portable );
	}

	/** @return array<string,mixed> */
	private function geometry( array $element, $page_width, $page_height ) {
		$x = $this->number( $element['x_mm'] ?? null, 'element x' );
		$y = $this->number( $element['y_mm'] ?? null, 'element y' );
		$width = $this->positive_number( $element['width_mm'] ?? null, 'element width' );
		$height = $this->positive_number( $element['height_mm'] ?? null, 'element height' );
		$rotation = $this->number( $element['rotation_deg'] ?? 0.0, 'element rotation' );
		if ( $rotation < -180.0 || $rotation > 180.0 ) { throw new InvalidArgumentException( 'Render plan rotation must already be canonicalized to -180..180 degrees.' ); }
		$cx = $x + ( $width / 2.0 );
		$cy = $y + ( $height / 2.0 );
		$radians = deg2rad( $rotation );
		$cos = cos( $radians );
		$sin = sin( $radians );
		$corners = array();
		foreach ( array( array( $x, $y ), array( $x + $width, $y ), array( $x + $width, $y + $height ), array( $x, $y + $height ) ) as $point ) {
			$dx = $point[0] - $cx;
			$dy = $point[1] - $cy;
			$corners[] = array(
				'x_mm' => $this->clean_float( $cx + ( $dx * $cos ) - ( $dy * $sin ) ),
				'y_mm' => $this->clean_float( $cy + ( $dx * $sin ) + ( $dy * $cos ) ),
			);
		}
		$xs = array_column( $corners, 'x_mm' );
		$ys = array_column( $corners, 'y_mm' );
		$bounds = array(
			'x_mm' => $this->clean_float( min( $xs ) ),
			'y_mm' => $this->clean_float( min( $ys ) ),
			'width_mm' => $this->clean_float( max( $xs ) - min( $xs ) ),
			'height_mm' => $this->clean_float( max( $ys ) - min( $ys ) ),
		);
		if ( $bounds['x_mm'] < -0.001 || $bounds['y_mm'] < -0.001 || $bounds['x_mm'] + $bounds['width_mm'] > $page_width + 0.001 || $bounds['y_mm'] + $bounds['height_mm'] > $page_height + 0.001 ) {
			throw new InvalidArgumentException( 'Render plan operation geometry exceeds the canonical page bounds.' );
		}
		return array(
			'box' => array( 'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $width, 'height_mm' => $height ),
			'center' => array( 'x_mm' => $this->clean_float( $cx ), 'y_mm' => $this->clean_float( $cy ) ),
			'rotation_deg' => $this->clean_float( $rotation ),
			'rotated_corners' => $corners,
			'rotated_bounds' => $bounds,
		);
	}

	/** @return array<string,mixed> */
	private function text_operation( array $element, array $manifest ) {
		$text = (string) ( $element['text'] ?? '' );
		if ( strlen( $text ) > LayoutValidator::MAX_TEXT_BYTES || false !== strpos( $text, "\0" ) || 1 !== preg_match( '//u', $text ) ) {
			throw new InvalidArgumentException( 'Render plan text must be bounded valid UTF-8 without NUL bytes.' );
		}
		$style = isset( $element['style'] ) && is_array( $element['style'] ) ? $element['style'] : array();
		$direction = strtolower( (string) ( $style['direction'] ?? '' ) );
		if ( ! in_array( $direction, array( 'ltr', 'rtl' ), true ) ) {
			throw new InvalidArgumentException( 'Render plan requires text direction to be resolved before adapter execution.' );
		}
		$align = strtolower( (string) ( $style['align'] ?? 'center' ) );
		if ( 'start' === $align ) { $align = 'rtl' === $direction ? 'right' : 'left'; }
		if ( 'end' === $align ) { $align = 'rtl' === $direction ? 'left' : 'right'; }
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			throw new InvalidArgumentException( 'Render plan could not resolve physical text alignment.' );
		}
		$vertical = strtolower( (string) ( $style['vertical_align'] ?? 'middle' ) );
		if ( ! in_array( $vertical, array( 'top', 'middle', 'bottom' ), true ) ) {
			throw new InvalidArgumentException( 'Render plan contains an invalid vertical text alignment.' );
		}
		$family = strtolower( trim( (string) ( $style['font_family'] ?? '' ) ) );
		$weight = $this->integer( $style['font_weight'] ?? 400, 'font weight' );
		$italic = true === ( $style['italic'] ?? false );
		$variant = $weight . '-' . ( $italic ? 'italic' : 'normal' );
		if ( empty( $manifest['families'][ $family ][ $variant ]['faces'] ) ) {
			throw new InvalidArgumentException( 'Render plan font stack is unavailable for resolved text.' );
		}
		$faces = array();
		foreach ( $manifest['families'][ $family ][ $variant ]['faces'] as $face ) {
			$faces[] = array( 'sha256' => (string) $face['sha256'], 'format' => (string) $face['format'] );
		}
		$size_pt = $this->positive_number( $style['font_size_pt'] ?? 18.0, 'font size' );
		$line_height = $this->positive_number( $style['line_height'] ?? 1.2, 'line height' );
		$letter_spacing_pt = $this->number( $style['letter_spacing_pt'] ?? 0.0, 'letter spacing' );
		$overflow = strtolower( (string) ( $style['overflow'] ?? 'shrink' ) );
		if ( $size_pt < 1 || $size_pt > 300 || $line_height < 0.5 || $line_height > 5 || $letter_spacing_pt < -20 || $letter_spacing_pt > 50 || $weight < 100 || $weight > 900 || 0 !== $weight % 100 || ! in_array( $overflow, array( 'wrap', 'shrink', 'clip' ), true ) ) {
			throw new InvalidArgumentException( 'Render plan text style is outside the canonical supported range.' );
		}
		return array(
			'value' => $text,
			'direction' => $direction,
			'align' => $align,
			'vertical_align' => $vertical,
			'overflow' => $overflow,
			'font' => array(
				'family' => $family,
				'weight' => $weight,
				'italic' => $italic,
				'faces' => $faces,
				'size_pt' => $size_pt,
				'size_mm' => $this->clean_float( $size_pt * self::POINT_TO_MM ),
				'line_height' => $line_height,
				'line_height_mm' => $this->clean_float( $size_pt * self::POINT_TO_MM * $line_height ),
				'letter_spacing_pt' => $letter_spacing_pt,
				'letter_spacing_mm' => $this->clean_float( $letter_spacing_pt * self::POINT_TO_MM ),
			),
			'color' => $this->color( $style['color'] ?? '#000000' ),
		);
	}

	/** @return array<string,mixed> */
	private function image_operation( array $element, array $geometry ) {
		$asset = isset( $element['asset'] ) && is_array( $element['asset'] ) ? $element['asset'] : null;
		if ( ! is_array( $asset ) || ! isset( $asset['path'], $asset['sha256'], $asset['mime'], $asset['size'], $asset['width_px'], $asset['height_px'] ) ) {
			throw new InvalidArgumentException( 'Render plan image operations require verified local asset facts.' );
		}
		if ( ! is_int( $asset['width_px'] ) || ! is_int( $asset['height_px'] ) || ! is_int( $asset['size'] ) || $asset['width_px'] < 1 || $asset['height_px'] < 1 || $asset['size'] < 1 || ! preg_match( '/^[0-9a-f]{64}$/', strtolower( (string) $asset['sha256'] ) ) || ! in_array( strtolower( (string) $asset['mime'] ), array( 'image/png', 'image/jpeg' ), true ) ) {
			throw new InvalidArgumentException( 'Render plan image facts must retain verified integer geometry and content identity.' );
		}
		$source_width = (float) $asset['width_px'];
		$source_height = (float) $asset['height_px'];
		$box = $geometry['box'];
		$fit = strtolower( (string) ( $element['fit'] ?? 'contain' ) );
		$placement = array( 'mode' => $fit, 'destination' => $box, 'source_crop' => array( 'x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0 ) );
		if ( 'fill' === $fit ) {
			// Destination box already represents the exact stretched placement.
		} elseif ( 'contain' === $fit ) {
			$scale = min( $box['width_mm'] / $source_width, $box['height_mm'] / $source_height );
			$draw_width = $source_width * $scale;
			$draw_height = $source_height * $scale;
			$placement['destination'] = array(
				'x_mm' => $this->clean_float( $box['x_mm'] + ( $box['width_mm'] - $draw_width ) / 2.0 ),
				'y_mm' => $this->clean_float( $box['y_mm'] + ( $box['height_mm'] - $draw_height ) / 2.0 ),
				'width_mm' => $this->clean_float( $draw_width ),
				'height_mm' => $this->clean_float( $draw_height ),
			);
		} elseif ( 'cover' === $fit ) {
			$source_ratio = $source_width / $source_height;
			$target_ratio = $box['width_mm'] / $box['height_mm'];
			if ( $source_ratio > $target_ratio ) {
				$visible = $target_ratio / $source_ratio;
				$placement['source_crop'] = array( 'x' => $this->clean_float( ( 1.0 - $visible ) / 2.0 ), 'y' => 0.0, 'width' => $this->clean_float( $visible ), 'height' => 1.0 );
			} elseif ( $source_ratio < $target_ratio ) {
				$visible = $source_ratio / $target_ratio;
				$placement['source_crop'] = array( 'x' => 0.0, 'y' => $this->clean_float( ( 1.0 - $visible ) / 2.0 ), 'width' => 1.0, 'height' => $this->clean_float( $visible ) );
			}
		} else {
			throw new InvalidArgumentException( 'Render plan contains an unsupported image fit mode.' );
		}
		return array(
			'_runtime_path' => (string) $asset['path'],
			'asset' => array(
				'sha256' => strtolower( (string) $asset['sha256'] ),
				'mime' => strtolower( (string) $asset['mime'] ),
				'size' => (int) $asset['size'],
				'width_px' => (int) $asset['width_px'],
				'height_px' => (int) $asset['height_px'],
			),
			'placement' => $placement,
		);
	}

	/** @return array<string,mixed> */
	private function shape_operation( array $element ) {
		$shape = strtolower( (string) ( $element['shape'] ?? '' ) );
		if ( ! in_array( $shape, array( 'rectangle', 'ellipse' ), true ) ) {
			throw new InvalidArgumentException( 'Render plan contains an unsupported shape.' );
		}
		$stroke_width = $this->number( $element['stroke_width_mm'] ?? 0.0, 'shape stroke width' );
		if ( $stroke_width < 0 || $stroke_width > 20 ) { throw new InvalidArgumentException( 'Render plan shape stroke width is outside the canonical range.' ); }
		return array(
			'kind' => $shape,
			'fill' => $this->color( $element['fill'] ?? '#00000000' ),
			'stroke' => $this->color( $element['stroke'] ?? '#000000' ),
			'stroke_width_mm' => $stroke_width,
		);
	}

	/** @return array<string,mixed> */
	private function line_operation( array $element, array $geometry ) {
		$box = $geometry['box'];
		$stroke_width = $this->positive_number( $element['stroke_width_mm'] ?? 0.3, 'line stroke width' );
		if ( $stroke_width > 20 ) { throw new InvalidArgumentException( 'Render plan line stroke width is outside the canonical range.' ); }
		return array(
			'from' => array( 'x_mm' => $box['x_mm'], 'y_mm' => $box['y_mm'] ),
			'to' => array( 'x_mm' => $this->clean_float( $box['x_mm'] + $box['width_mm'] ), 'y_mm' => $this->clean_float( $box['y_mm'] + $box['height_mm'] ) ),
			'stroke' => $this->color( $element['stroke'] ?? '#000000' ),
			'stroke_width_mm' => $stroke_width,
		);
	}

	/** @return array<string,mixed> */
	private function qr_operation( array $element, array $geometry ) {
		$matrix = $element['matrix'] ?? null;
		if ( ! is_array( $matrix ) || empty( $matrix ) ) {
			throw new InvalidArgumentException( 'Render plan QR operations require the central resolved module matrix.' );
		}
		$modules = count( $matrix );
		if ( $modules < 21 || $modules > 177 || 0 !== ( $modules - 21 ) % 4 ) {
			throw new InvalidArgumentException( 'Render plan QR matrix size must be a valid Model 2 version.' );
		}
		foreach ( $matrix as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $modules ) {
				throw new InvalidArgumentException( 'Render plan QR matrix must be square.' );
			}
			foreach ( $row as $module ) {
				if ( 0 !== $module && 1 !== $module && false !== $module && true !== $module ) {
					throw new InvalidArgumentException( 'Render plan QR matrix may contain only binary modules.' );
				}
			}
		}
		$quiet = $this->integer( $element['quiet_zone_modules'] ?? 4, 'QR quiet zone' );
		if ( $quiet < 4 || $quiet > 16 ) { throw new InvalidArgumentException( 'Render plan QR quiet zone is outside the canonical range.' ); }
		$error_correction = strtoupper( (string) ( $element['error_correction'] ?? 'M' ) );
		if ( ! in_array( $error_correction, array( 'L', 'M', 'Q', 'H' ), true ) ) { throw new InvalidArgumentException( 'Render plan QR error-correction level is invalid.' ); }
		$encoder = isset( $element['encoder'] ) && is_array( $element['encoder'] ) ? $element['encoder'] : array();
		$encoder_id = strtolower( trim( (string) ( $encoder['id'] ?? '' ) ) );
		$encoder_version = trim( (string) ( $encoder['version'] ?? '' ) );
		if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $encoder_id ) || '' === $encoder_version || strlen( $encoder_version ) > 64 || '' === (string) ( $element['data'] ?? '' ) ) {
			throw new InvalidArgumentException( 'Render plan QR identity/data is incomplete.' );
		}
		$total = $modules + ( 2 * $quiet );
		$box = $geometry['box'];
		if ( abs( $box['width_mm'] - $box['height_mm'] ) > 0.001 ) {
			throw new InvalidArgumentException( 'Render plan QR box must remain square.' );
		}
		$module_mm = $box['width_mm'] / $total;
		$runs = array();
		foreach ( array_values( $matrix ) as $row_index => $row ) {
			$start = null;
			$length = 0;
			foreach ( array_values( $row ) as $column => $value ) {
				$dark = 1 === $value || true === $value;
				if ( $dark && null === $start ) { $start = $column; $length = 1; }
				elseif ( $dark ) { $length++; }
				if ( null !== $start && ( ! $dark || $column === $modules - 1 ) ) {
					$end_after = $dark && $column === $modules - 1 ? $column + 1 : $column;
					$run_length = $end_after - $start;
					$runs[] = array(
						'x_mm' => $this->clean_float( $box['x_mm'] + ( $quiet + $start ) * $module_mm ),
						'y_mm' => $this->clean_float( $box['y_mm'] + ( $quiet + $row_index ) * $module_mm ),
						'width_mm' => $this->clean_float( $run_length * $module_mm ),
						'height_mm' => $this->clean_float( $module_mm ),
					);
					$start = null; $length = 0;
				}
			}
		}
		return array(
			'data' => (string) ( $element['data'] ?? '' ),
			'encoder' => array( 'id' => $encoder_id, 'version' => $encoder_version ),
			'error_correction' => $error_correction,
			'matrix_size' => $modules,
			'quiet_zone_modules' => $quiet,
			'total_modules' => $total,
			'module_size_mm' => $this->clean_float( $module_mm ),
			'dark_runs' => $runs,
		);
	}

	/** @return array<string,mixed> */
	private function color( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		if ( ! preg_match( '/^#([0-9A-F]{6})([0-9A-F]{2})?$/', $value, $match ) ) {
			throw new InvalidArgumentException( 'Render plan color is invalid.' );
		}
		$rgb = $match[1];
		$alpha = isset( $match[2] ) && '' !== $match[2] ? hexdec( $match[2] ) : 255;
		return array(
			'hex' => '#' . $rgb . ( isset( $match[2] ) && '' !== $match[2] ? $match[2] : '' ),
			'r' => hexdec( substr( $rgb, 0, 2 ) ),
			'g' => hexdec( substr( $rgb, 2, 2 ) ),
			'b' => hexdec( substr( $rgb, 4, 2 ) ),
			'alpha' => $alpha,
			'alpha_unit' => $this->clean_float( $alpha / 255.0 ),
		);
	}

	/** @return int */
	private function integer( $value, $label ) {
		if ( is_int( $value ) ) { return $value; }
		if ( is_string( $value ) && 1 === preg_match( '/^-?(?:0|[1-9][0-9]*)$/', $value ) ) {
			$int = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false !== $int ) { return (int) $int; }
		}
		throw new InvalidArgumentException( $label . ' must be an integer.' );
	}

	/** @return float */
	private function number( $value, $label ) {
		$is_numeric_string = is_string( $value ) && 1 === preg_match( '/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/', $value );
		if ( ! is_int( $value ) && ! is_float( $value ) && ! $is_numeric_string ) {
			throw new InvalidArgumentException( $label . ' must be numeric.' );
		}
		$value = (float) $value;
		if ( is_nan( $value ) || is_infinite( $value ) ) {
			throw new InvalidArgumentException( $label . ' must be finite.' );
		}
		return $this->clean_float( $value );
	}

	/** @return float */
	private function positive_number( $value, $label ) {
		$value = $this->number( $value, $label );
		if ( $value <= 0 ) { throw new InvalidArgumentException( $label . ' must be positive.' ); }
		return $value;
	}

	/** @return float */
	private function unit_number( $value, $label ) {
		$value = $this->number( $value, $label );
		if ( $value < 0 || $value > 1 ) { throw new InvalidArgumentException( $label . ' must be between zero and one.' ); }
		return $value;
	}

	/** @return float */
	private function clean_float( $value ) {
		$value = (float) $value;
		if ( abs( $value ) < 0.000000000001 ) { return 0.0; }
		return round( $value, 12 );
	}
}
