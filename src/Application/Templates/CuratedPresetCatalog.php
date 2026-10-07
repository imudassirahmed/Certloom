<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\LayoutSchema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * TPLUX-02 additive curated factory-design catalog.
 *
 * The historical PresetCatalog remains immutable at six families x four page
 * variants. This catalog adds genuinely distinct visual concepts under new
 * stable machine identities, so expanding design breadth never rewrites old
 * factory revisions or their permanent reference evidence.
 */
final class CuratedPresetCatalog {
	const CATALOG_VERSION = 2;
	const PRESET_VERSION  = 2;
	const KEY_PREFIX      = 'curated-';

	/** @return array<string,array<string,mixed>> */
	public static function concepts() {
		return array(
			'heritage' => array(
				'name' => 'Heritage', 'description' => 'Traditional archival recognition with a dignified title field, twin rules and restrained seal geometry.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'formal', 'color' => 'navy_gold', 'purposes' => array( 'certification_standards', 'awards_recognition' ),
			),
			'executive' => array(
				'name' => 'Executive', 'description' => 'Boardroom-ready credential with a dark issuer rail, measured whitespace and precise business hierarchy.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'corporate', 'color' => 'blue_teal', 'purposes' => array( 'professional_license_membership', 'training_courses', 'certification_standards' ),
			),
			'summit' => array(
				'name' => 'Summit', 'description' => 'Contemporary achievement layout with rising geometric bands and a strong left-aligned award statement.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'modern', 'color' => 'blue', 'purposes' => array( 'training_courses', 'awards_recognition' ),
			),
			'folio' => array(
				'name' => 'Folio', 'description' => 'Editorial portrait certificate with a narrow folio rail, asymmetric type and quiet document rhythm.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'elegant', 'color' => 'warm_neutral', 'purposes' => array( 'academic_credentials', 'awards_recognition' ),
			),
			'civic' => array(
				'name' => 'Civic', 'description' => 'Institutional public-service credential with a disciplined masthead and accountable information footer.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'formal', 'color' => 'blue_teal', 'purposes' => array( 'certification_standards', 'professional_license_membership' ),
			),
			'ledger' => array(
				'name' => 'Ledger', 'description' => 'Structured portrait credential with a visible information ledger and audit-oriented composition.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'corporate', 'color' => 'neutral_accent', 'purposes' => array( 'certification_standards', 'professional_license_membership' ),
			),
			'ribbon' => array(
				'name' => 'Ribbon', 'description' => 'Recognition-led award certificate with an offset ribbon field and celebratory but restrained framing.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'elegant', 'color' => 'burgundy_gold', 'purposes' => array( 'awards_recognition', 'events_participation' ),
			),
			'crest' => array(
				'name' => 'Crest', 'description' => 'Formal academic portrait with a strong crest medallion, ceremonial rules and centered hierarchy.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'academic', 'color' => 'burgundy_gold', 'purposes' => array( 'academic_credentials' ),
			),
			'atelier' => array(
				'name' => 'Atelier', 'description' => 'Refined asymmetrical portrait with an offset title block and subtle architectural geometry.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'elegant', 'color' => 'warm_neutral', 'purposes' => array( 'awards_recognition', 'events_participation' ),
			),
			'gallery' => array(
				'name' => 'Gallery', 'description' => 'Typography-led portrait certificate using generous margins, a gallery caption rail and almost no ornament.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'minimal', 'color' => 'neutral_accent', 'purposes' => array( 'training_courses', 'academic_credentials', 'custom_other' ),
			),
			'horizon' => array(
				'name' => 'Horizon', 'description' => 'Broad modern layout with a low horizon band, open central field and crisp achievement hierarchy.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'modern', 'color' => 'blue_teal', 'purposes' => array( 'training_courses', 'events_participation' ),
			),
			'keystone' => array(
				'name' => 'Keystone', 'description' => 'Formal vertical achievement design built around a centered keystone mark and framed recipient statement.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'formal', 'color' => 'navy_gold', 'purposes' => array( 'professional_license_membership', 'certification_standards' ),
			),
			'parchment' => array(
				'name' => 'Parchment', 'description' => 'Warm classical credential with double rules, corner ornaments and a restrained archival tone.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'formal', 'color' => 'warm_neutral', 'purposes' => array( 'awards_recognition', 'academic_credentials' ),
			),
			'signal' => array(
				'name' => 'Signal', 'description' => 'High-clarity training certificate with a compact signal bar, modular information blocks and direct hierarchy.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'modern', 'color' => 'blue', 'purposes' => array( 'training_courses', 'certification_standards' ),
			),
			'milestone' => array(
				'name' => 'Milestone', 'description' => 'Achievement-first composition with a central milestone marker and balanced ceremonial whitespace.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'elegant', 'color' => 'blue_teal', 'purposes' => array( 'awards_recognition', 'training_courses' ),
			),
			'conference' => array(
				'name' => 'Conference', 'description' => 'Event and participation certificate with a conference masthead, session-like bands and modern footer.',
				'size' => LayoutSchema::PAGE_LETTER, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'modern', 'color' => 'blue_teal', 'purposes' => array( 'events_participation', 'training_courses' ),
			),
			'license' => array(
				'name' => 'License', 'description' => 'Professional portrait credential with a durable license-information band and authoritative visual structure.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_PORTRAIT,
				'style' => 'corporate', 'color' => 'navy_gold', 'purposes' => array( 'professional_license_membership', 'certification_standards' ),
			),
			'assurance' => array(
				'name' => 'Assurance', 'description' => 'Standards-oriented certificate with a verification grid, strong boundary lines and measured technical character.',
				'size' => LayoutSchema::PAGE_A4, 'orientation' => LayoutSchema::ORIENTATION_LANDSCAPE,
				'style' => 'corporate', 'color' => 'navy_gold', 'purposes' => array( 'certification_standards' ),
			),
		);
	}

	/** Localized presentation label; stored factory identity remains stable English. */
	public static function display_name( $key ) {
		switch ( sanitize_key( (string) $key ) ) {
			case 'heritage': return __( 'Heritage', 'certloom' );
			case 'executive': return __( 'Executive', 'certloom' );
			case 'summit': return __( 'Summit', 'certloom' );
			case 'folio': return __( 'Folio', 'certloom' );
			case 'civic': return __( 'Civic', 'certloom' );
			case 'ledger': return __( 'Ledger', 'certloom' );
			case 'ribbon': return __( 'Ribbon', 'certloom' );
			case 'crest': return __( 'Crest', 'certloom' );
			case 'atelier': return __( 'Atelier', 'certloom' );
			case 'gallery': return __( 'Gallery', 'certloom' );
			case 'horizon': return __( 'Horizon', 'certloom' );
			case 'keystone': return __( 'Keystone', 'certloom' );
			case 'parchment': return __( 'Parchment', 'certloom' );
			case 'signal': return __( 'Signal', 'certloom' );
			case 'milestone': return __( 'Milestone', 'certloom' );
			case 'conference': return __( 'Conference', 'certloom' );
			case 'license': return __( 'License', 'certloom' );
			case 'assurance': return __( 'Assurance', 'certloom' );
		}
		return __( 'Curated design', 'certloom' );
	}

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$out = array();
		foreach ( self::concepts() as $concept_key => $concept ) {
			$out[] = $this->build_definition( $concept_key, $concept );
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	public function get( $preset_key, $version = self::PRESET_VERSION ) {
		$preset_key = sanitize_key( (string) $preset_key );
		$version = absint( $version );
		if ( $version < 1 || $version > self::PRESET_VERSION || 0 !== strpos( $preset_key, self::KEY_PREFIX ) ) { return null; }
		$concept_key = substr( $preset_key, strlen( self::KEY_PREFIX ) );
		$concepts = self::concepts();
		return isset( $concepts[ $concept_key ] ) ? $this->build_definition( $concept_key, $concepts[ $concept_key ], $version ) : null;
	}

	/** @return array<string,mixed> */
	private function build_definition( $concept_key, array $concept, $version = self::PRESET_VERSION ) {
		$dimensions = LayoutSchema::page_dimensions();
		$size = (string) $concept['size'];
		$orientation = (string) $concept['orientation'];
		list( $width, $height ) = $dimensions[ $size ][ $orientation ];
		$preset_key = self::KEY_PREFIX . $concept_key;
		$layout = $this->layout( $concept_key, $preset_key, $size, $orientation, (float) $width, (float) $height, $version );
		$layout = ( new LayoutValidator() )->normalize( $layout );
		return array(
			'preset_key' => $preset_key,
			'preset_version' => $version,
			'family_key' => $preset_key,
			'family_name' => (string) $concept['name'],
			'variant_key' => strtolower( $size ) . '-' . $orientation,
			'page_size' => $size,
			'orientation' => $orientation,
			'machine_key' => 'certloom-preset-' . $preset_key,
			'name' => (string) $concept['name'],
			'description' => (string) $concept['description'],
			'style' => (string) $concept['style'],
			'color' => (string) $concept['color'],
			'purposes' => array_values( (array) $concept['purposes'] ),
			'language' => 'en_base',
			'layout' => $layout,
			'layout_sha256' => ( new CanonicalJson() )->hash( $layout ),
		);
	}

	/** @return array<string,mixed> */
	private function layout( $concept, $seed, $size, $orientation, $w, $h, $version ) {
		$landscape = LayoutSchema::ORIENTATION_LANDSCAPE === $orientation;
		$elements = array(); $z = 0; $p = $this->palette( $concept );
		$margin = $landscape ? 14.0 : 13.0; $content_x = $margin + ( $landscape ? 15.0 : 12.0 ); $content_w = $w - ( 2 * $content_x );
		$add_shape = function( $key, $x, $y, $ww, $hh, $shape, $fill, $stroke = '#00000000', $sw = 0.0, $opacity = 1.0 ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id'=>$this->uuid($seed.':'.$key),'type'=>'shape','x_mm'=>$x,'y_mm'=>$y,'width_mm'=>$ww,'height_mm'=>$hh,'z'=>$z++,'rotation_deg'=>0.0,'opacity'=>$opacity,'shape'=>$shape,'fill'=>$fill,'stroke'=>$stroke,'stroke_width_mm'=>$sw );
		};
		$add_line = function( $key, $x, $y, $ww, $hh, $stroke, $sw = 0.4 ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id'=>$this->uuid($seed.':'.$key),'type'=>'line','x_mm'=>$x,'y_mm'=>$y,'width_mm'=>$ww,'height_mm'=>$hh,'z'=>$z++,'rotation_deg'=>0.0,'opacity'=>1.0,'stroke'=>$stroke,'stroke_width_mm'=>$sw );
		};
		$add_text = function( $key, $x, $y, $ww, $hh, $text, $fs, $weight, $color, $align = 'center', $letter = 0.0 ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id'=>$this->uuid($seed.':'.$key),'type'=>'text','x_mm'=>$x,'y_mm'=>$y,'width_mm'=>$ww,'height_mm'=>$hh,'z'=>$z++,'rotation_deg'=>0.0,'opacity'=>1.0,'text'=>$text,'style'=>$this->style($fs,$weight,$color,$align,$letter) );
		};
		$add_merge = function( $key, $x, $y, $ww, $hh, $token, $fs, $weight, $color, $align = 'center' ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id'=>$this->uuid($seed.':'.$key),'type'=>'merge','x_mm'=>$x,'y_mm'=>$y,'width_mm'=>$ww,'height_mm'=>$hh,'z'=>$z++,'rotation_deg'=>0.0,'opacity'=>1.0,'token'=>$token,'style'=>$this->style($fs,$weight,$color,$align,0.0) );
		};
		$add_qr = function( $key, $x, $y, $side ) use ( &$elements, &$z, $seed ) {
			$elements[] = array( 'id'=>$this->uuid($seed.':'.$key),'type'=>'qr','x_mm'=>$x,'y_mm'=>$y,'width_mm'=>$side,'height_mm'=>$side,'z'=>$z++,'rotation_deg'=>0.0,'opacity'=>1.0,'source'=>'verification_url','error_correction'=>'H','quiet_zone_modules'=>4 );
		};

		// Each concept intentionally uses a different large-scale composition.
		switch ( $concept ) {
			case 'heritage':
				$add_shape('outer',$margin,$margin,$w-2*$margin,$h-2*$margin,'rectangle','#FFFFFF00',$p['primary'],1.0);
				$add_shape('inner',$margin+4,$margin+4,$w-2*$margin-8,$h-2*$margin-8,'rectangle','#FFFFFF00',$p['accent'],0.35);
				$add_shape('medallion',($w-24)/2,22,24,24,'ellipse','#FFFFFF00',$p['accent'],0.8);
				$add_text('monogram',($w-24)/2,27,24,14,'H',15,700,$p['primary']);
				$add_text('eyebrow',$content_x,51,$content_w,7,'CERTIFICATE OF RECOGNITION',8,700,$p['accent'],'center',1.5);
				$add_text('title',$content_x,62,$content_w,14,'Presented with distinction',21,400,$p['primary']);
				$add_merge('recipient',$content_x,82,$content_w,25,'recipient.name',31,700,$p['primary']);
				$add_line('name-rule',$content_x+38,109,$content_w-76,0.1,$p['accent'],0.55);
				$add_merge('program',$content_x,119,$content_w,18,'program.name',17,600,$p['secondary']);
				break;
			case 'executive':
				$rail=42.0;$add_shape('rail',$margin,$margin,$rail,$h-2*$margin,'rectangle',$p['primary']);
				$add_merge('issuer',$margin+8,31,$rail-16,35,'issuer.name',10,700,'#FFFFFF','left');
				$add_text('rail-label',$margin+8,$h-62,$rail-16,18,'EXECUTIVE CREDENTIAL',8,700,$p['soft'],'left',1.0);
				$x=$margin+$rail+18;$cw=$w-$x-$margin-15;
				$add_text('eyebrow',$x,39,$cw,7,'PROFESSIONAL ACHIEVEMENT',8,700,$p['accent'],'left',1.4);
				$add_text('title',$x,51,$cw,17,'Certificate of Distinction',25,700,$p['primary'],'left');
				$add_text('award',$x,76,$cw,7,'Presented to',9,400,$p['muted'],'left');
				$add_merge('recipient',$x,86,$cw,27,'recipient.name',30,700,$p['primary'],'left');
				$add_merge('program',$x,123,$cw,18,'program.name',17,600,$p['accent'],'left');
				break;
			case 'summit':
				$add_shape('band-one',$margin,$h-57,$w-2*$margin,43,'rectangle',$p['soft']);
				$add_shape('peak-one',$margin+9,$h-79,58,58,'rectangle',$p['accent'],'#00000000',0,0.11);
				$add_shape('peak-two',$margin+50,$h-66,78,45,'rectangle',$p['secondary'],'#00000000',0,0.08);
				$add_merge('issuer',$content_x,29,$content_w,8,'issuer.name',8,700,$p['muted'],'left');
				$add_text('eyebrow',$content_x,43,$content_w,7,'ACHIEVEMENT / SUMMIT',8,700,$p['accent'],'left',1.6);
				$add_text('title',$content_x,55,$content_w,18,'Certificate of Completion',24,700,$p['primary'],'left');
				$add_merge('recipient',$content_x,83,$content_w,27,'recipient.name',32,700,$p['primary'],'left');
				$add_line('rule',$content_x,115,70,0.1,$p['accent'],1.1);
				$add_merge('program',$content_x,124,$content_w,18,'program.name',17,600,$p['secondary'],'left');
				break;
			case 'folio':
				$rail=28.0;$add_shape('folio-rail',$margin,$margin,$rail,$h-2*$margin,'rectangle',$p['soft']);
				$add_text('folio-no',$margin+6,31,$rail-12,9,'FOLIO 01',7,700,$p['accent'],'left',1.2);
				$x=$margin+$rail+15;$cw=$w-$x-$margin-10;
				$add_merge('issuer',$x,39,$cw,8,'issuer.name',8,600,$p['muted'],'left');
				$add_text('eyebrow',$x,62,$cw,7,'CERTIFICATE',8,700,$p['accent'],'left',1.8);
				$add_text('title',$x,76,$cw,31,'A record of achievement',24,400,$p['primary'],'left');
				$add_text('award',$x,122,$cw,7,'Awarded to',9,400,$p['muted'],'left');
				$add_merge('recipient',$x,133,$cw,35,'recipient.name',28,700,$p['primary'],'left');
				$add_line('folio-rule',$x,176,52,0.1,$p['accent'],0.75);
				$add_merge('program',$x,187,$cw,24,'program.name',15,600,$p['secondary'],'left');
				break;
			case 'civic':
				$add_shape('masthead',$margin,$margin,$w-2*$margin,34,'rectangle',$p['primary']);
				$add_merge('issuer',$margin+11,22,$w-2*$margin-22,10,'issuer.name',10,700,'#FFFFFF','left');
				$add_text('office',$margin+11,34,$w-2*$margin-22,7,'OFFICIAL RECORD • PUBLIC CREDENTIAL',7,600,$p['soft'],'left',1.0);
				$add_text('title',$content_x,61,$content_w,16,'Certificate of Public Achievement',22,700,$p['primary']);
				$add_text('award',$content_x,82,$content_w,7,'This record formally recognizes',9,400,$p['muted']);
				$add_merge('recipient',$content_x,92,$content_w,26,'recipient.name',29,700,$p['primary']);
				$add_merge('program',$content_x,127,$content_w,17,'program.name',16,600,$p['accent']);
				$add_line('accountability',$content_x,154,$content_w,0.1,$p['soft'],0.5);
				break;
			case 'ledger':
				$add_shape('top-block',$margin,$margin,$w-2*$margin,48,'rectangle',$p['primary']);
				$add_merge('issuer',$margin+10,26,$w-2*$margin-20,9,'issuer.name',9,700,'#FFFFFF','left');
				$add_text('title',$margin+10,42,$w-2*$margin-20,16,'Credential Ledger',21,700,'#FFFFFF','left');
				$add_text('record-label',$content_x,80,$content_w,7,'RECIPIENT OF RECORD',7,700,$p['accent'],'left',1.4);
				$add_merge('recipient',$content_x,91,$content_w,31,'recipient.name',28,700,$p['primary'],'left');
				$add_text('program-label',$content_x,134,$content_w,7,'PROGRAM',7,700,$p['muted'],'left',1.3);
				$add_merge('program',$content_x,144,$content_w,22,'program.name',15,600,$p['secondary'],'left');
				$add_line('ledger-a',$content_x,180,$content_w,0.1,$p['soft'],0.5);
				$add_line('ledger-b',$content_x,204,$content_w,0.1,$p['soft'],0.5);
				break;
			case 'ribbon':
				$add_shape('frame',$margin,$margin,$w-2*$margin,$h-2*$margin,'rectangle','#FFFFFF00',$p['primary'],0.55);
				$add_shape('ribbon',$margin+16,27,84,15,'rectangle',$p['accent']);
				$add_text('ribbon-text',$margin+20,30,76,9,'DISTINGUISHED AWARD',7,700,'#FFFFFF','left',1.1);
				$add_merge('issuer',$w-126,29,95,8,'issuer.name',8,600,$p['muted'],'right');
				$add_text('title',$content_x,58,$content_w,18,'Certificate of Recognition',23,400,$p['primary']);
				$add_text('award',$content_x,82,$content_w,7,'Presented with appreciation to',9,400,$p['muted']);
				$add_merge('recipient',$content_x,92,$content_w,27,'recipient.name',31,700,$p['primary']);
				$add_merge('program',$content_x,130,$content_w,17,'program.name',16,600,$p['secondary']);
				break;
			case 'crest':
				$add_shape('outer',$margin,$margin,$w-2*$margin,$h-2*$margin,'rectangle','#FFFFFF00',$p['primary'],1.0);
				$add_shape('crest-ring',($w-30)/2,31,30,30,'ellipse','#FFFFFF00',$p['accent'],1.0);
				$add_shape('crest-core',($w-15)/2,38.5,15,15,'ellipse',$p['soft']);
				$add_text('crest-letter',($w-15)/2,40,15,12,'C',15,700,$p['primary']);
				$add_merge('issuer',$content_x,68,$content_w,8,'issuer.name',8,700,$p['muted']);
				$add_text('eyebrow',$content_x,84,$content_w,7,'ACADEMIC DISTINCTION',8,700,$p['accent'],'center',1.7);
				$add_text('title',$content_x,97,$content_w,18,'Certificate of Excellence',21,700,$p['primary']);
				$add_merge('recipient',$content_x,128,$content_w,33,'recipient.name',28,700,$p['primary']);
				$add_line('crest-rule',$content_x+30,168,$content_w-60,0.1,$p['accent'],0.5);
				$add_merge('program',$content_x,179,$content_w,22,'program.name',15,600,$p['secondary']);
				break;
			case 'atelier':
				$add_shape('corner',$margin,$margin,58,78,'rectangle',$p['soft']);
				$add_shape('small-square',$w-$margin-32,$margin+18,32,32,'rectangle',$p['accent'],'#00000000',0,0.10);
				$x=$margin+18;$cw=$w-2*$margin-36;
				$add_merge('issuer',$x,37,$cw,8,'issuer.name',8,600,$p['muted'],'right');
				$add_text('eyebrow',$x,73,$cw,7,'ATELIER / DISTINCTION',8,700,$p['accent'],'left',1.6);
				$add_text('title',$x,87,$cw,39,'Certificate of Distinction',24,400,$p['primary'],'left');
				$add_merge('recipient',$x,142,$cw,34,'recipient.name',28,700,$p['primary'],'left');
				$add_line('atelier-rule',$x,184,54,0.1,$p['accent'],0.7);
				$add_merge('program',$x,197,$cw,25,'program.name',15,500,$p['secondary'],'left');
				break;
			case 'gallery':
				$add_text('caption',$margin+3,31,44,7,'GALLERY CERTIFICATE',7,700,$p['accent'],'left',1.5);
				$add_line('caption-rule',$margin+3,43,38,0.1,$p['accent'],0.8);
				$add_merge('issuer',$margin+3,58,52,27,'issuer.name',8,600,$p['muted'],'left');
				$x=$margin+57;$cw=$w-$x-$margin;
				$add_text('title',$x,65,$cw,43,'Achievement recognized.',24,400,$p['primary'],'left');
				$add_text('award',$x,124,$cw,7,'Presented to',8,400,$p['muted'],'left');
				$add_merge('recipient',$x,136,$cw,39,'recipient.name',27,700,$p['primary'],'left');
				$add_merge('program',$x,190,$cw,30,'program.name',14,500,$p['secondary'],'left');
				break;
			case 'horizon':
				$band=34.0;$add_shape('horizon-band',$margin,$h-$margin-$band,$w-2*$margin,$band,'rectangle',$p['primary']);
				$add_shape('sun',$w-$margin-56,$h-$margin-$band-28,42,42,'ellipse',$p['accent'],'#00000000',0,0.12);
				$add_merge('issuer',$content_x,29,$content_w,8,'issuer.name',8,600,$p['muted'],'left');
				$add_text('eyebrow',$content_x,48,$content_w,7,'HORIZON / ACHIEVEMENT',8,700,$p['accent'],'left',1.5);
				$add_text('title',$content_x,60,$content_w,17,'Certificate of Completion',24,700,$p['primary'],'left');
				$add_merge('recipient',$content_x,89,$content_w,28,'recipient.name',31,700,$p['primary'],'left');
				$add_merge('program',$content_x,126,$content_w,18,'program.name',16,600,$p['secondary'],'left');
				break;
			case 'keystone':
				$add_shape('frame',$margin,$margin,$w-2*$margin,$h-2*$margin,'rectangle','#FFFFFF00',$p['primary'],0.65);
				$add_shape('key-top',($w-36)/2,29,36,16,'rectangle',$p['soft']);
				$add_shape('key-core',($w-20)/2,45,20,20,'rectangle',$p['accent'],'#00000000',0,0.18);
				$add_text('key-letter',($w-20)/2,48,20,14,'K',14,700,$p['primary']);
				$add_merge('issuer',$content_x,74,$content_w,8,'issuer.name',8,700,$p['muted']);
				$add_text('title',$content_x,93,$content_w,19,'Professional Credential',21,700,$p['primary']);
				$add_text('award',$content_x,119,$content_w,7,'Awarded to',8,400,$p['muted']);
				$add_merge('recipient',$content_x,130,$content_w,33,'recipient.name',28,700,$p['primary']);
				$add_line('key-rule',$content_x+24,171,$content_w-48,0.1,$p['accent'],0.55);
				$add_merge('program',$content_x,182,$content_w,25,'program.name',15,600,$p['secondary']);
				break;
			case 'parchment':
				$add_shape('wash',$margin,$margin,$w-2*$margin,$h-2*$margin,'rectangle',$p['paper']);
				$add_shape('outer',$margin+3,$margin+3,$w-2*$margin-6,$h-2*$margin-6,'rectangle','#FFFFFF00',$p['primary'],0.7);
				$add_line('corner-a',$margin+10,$margin+12,25,0.1,$p['accent'],1.0);$add_line('corner-b',$w-$margin-35,$margin+12,25,0.1,$p['accent'],1.0);
				$add_merge('issuer',$content_x,31,$content_w,8,'issuer.name',8,600,$p['muted']);
				$add_text('eyebrow',$content_x,48,$content_w,7,'ARCHIVAL CERTIFICATE',8,700,$p['accent'],'center',1.5);
				$add_text('title',$content_x,60,$content_w,18,'Certificate of Honour',23,400,$p['primary']);
				$add_merge('recipient',$content_x,88,$content_w,28,'recipient.name',30,700,$p['primary']);
				$add_line('parchment-rule',$content_x+42,119,$content_w-84,0.1,$p['accent'],0.5);
				$add_merge('program',$content_x,130,$content_w,18,'program.name',16,600,$p['secondary']);
				break;
			case 'signal':
				$add_shape('signal-a',$margin,$margin,12,$h-2*$margin,'rectangle',$p['accent']);
				$add_shape('signal-b',$margin+12,$margin,7,$h-2*$margin,'rectangle',$p['secondary']);
				$x=$margin+34;$cw=$w-$x-$margin-10;
				$add_merge('issuer',$x,29,$cw,8,'issuer.name',8,700,$p['muted'],'left');
				$add_text('eyebrow',$x,47,$cw,7,'TRAINING / VERIFIED',8,700,$p['accent'],'left',1.5);
				$add_text('title',$x,60,$cw,17,'Certificate of Completion',24,700,$p['primary'],'left');
				$add_text('award',$x,83,$cw,7,'Successfully completed by',9,400,$p['muted'],'left');
				$add_merge('recipient',$x,94,$cw,26,'recipient.name',30,700,$p['primary'],'left');
				$add_merge('program',$x,131,$cw,18,'program.name',16,600,$p['secondary'],'left');
				break;
			case 'milestone':
				$cx=$w/2;$add_line('axis',$cx,27,0.1,124,$p['soft'],1.2);
				$add_shape('marker',$cx-8,38,16,16,'ellipse',$p['accent']);
				$add_text('marker-text',$cx-8,40,16,12,'M',9,700,'#FFFFFF');
				$add_merge('issuer',$content_x,29,$content_w,8,'issuer.name',8,600,$p['muted']);
				$add_text('eyebrow',$content_x,60,$content_w,7,'MILESTONE ACHIEVEMENT',8,700,$p['accent'],'center',1.5);
				$add_text('title',$content_x,72,$content_w,17,'Certificate of Achievement',23,400,$p['primary']);
				$add_merge('recipient',$content_x,99,$content_w,27,'recipient.name',31,700,$p['primary']);
				$add_merge('program',$content_x,136,$content_w,18,'program.name',16,600,$p['secondary']);
				break;
			case 'conference':
				$add_shape('mast',$margin,$margin,$w-2*$margin,29,'rectangle',$p['primary']);
				$add_shape('accent-strip',$margin,$margin+29,$w-2*$margin,5,'rectangle',$p['accent']);
				$add_merge('issuer',$margin+11,21,$w-2*$margin-22,9,'issuer.name',9,700,'#FFFFFF','left');
				$add_text('event-tag',$content_x,55,$content_w,7,'EVENT • PARTICIPATION • LEARNING',8,700,$p['accent'],'left',1.2);
				$add_text('title',$content_x,67,$content_w,17,'Certificate of Participation',23,700,$p['primary'],'left');
				$add_text('award',$content_x,92,$content_w,7,'Presented to',9,400,$p['muted'],'left');
				$add_merge('recipient',$content_x,103,$content_w,26,'recipient.name',30,700,$p['primary'],'left');
				$add_merge('program',$content_x,139,$content_w,18,'program.name',16,600,$p['secondary'],'left');
				break;
			case 'license':
				$add_shape('header',$margin,$margin,$w-2*$margin,42,'rectangle',$p['primary']);
				$add_merge('issuer',$margin+10,24,$w-2*$margin-20,9,'issuer.name',9,700,'#FFFFFF','left');
				$add_text('license-label',$margin+10,38,$w-2*$margin-20,9,'PROFESSIONAL LICENSE / MEMBERSHIP',8,600,$p['soft'],'left',1.0);
				$add_text('title',$content_x,76,$content_w,19,'Credential of Professional Standing',20,700,$p['primary'],'left');
				$add_text('holder',$content_x,106,$content_w,7,'CREDENTIAL HOLDER',7,700,$p['accent'],'left',1.3);
				$add_merge('recipient',$content_x,117,$content_w,32,'recipient.name',28,700,$p['primary'],'left');
				$add_text('scope',$content_x,164,$content_w,7,'SCOPE / PROGRAM',7,700,$p['muted'],'left',1.2);
				$add_merge('program',$content_x,175,$content_w,26,'program.name',15,600,$p['secondary'],'left');
				$add_shape('license-band',$margin+9,218,$w-2*$margin-18,32,'rectangle',$p['soft']);
				break;
			case 'assurance':
			default:
				$add_shape('grid-head',$margin,$margin,$w-2*$margin,31,'rectangle',$p['primary']);
				$add_merge('issuer',$margin+10,21,$w-2*$margin-20,9,'issuer.name',9,700,'#FFFFFF','left');
				$add_shape('grid-a',$margin+10,57,9,9,'rectangle',$p['accent']);
				$add_shape('grid-b',$margin+23,57,9,9,'rectangle',$p['soft']);
				$add_shape('grid-c',$margin+36,57,9,9,'rectangle',$p['soft']);
				$x=$margin+57;$cw=$w-$x-$margin-12;
				$add_text('eyebrow',$x,55,$cw,7,'ASSURANCE / VERIFIED STANDARD',8,700,$p['accent'],'left',1.2);
				$add_text('title',$x,68,$cw,17,'Certificate of Conformity',24,700,$p['primary'],'left');
				$add_text('award',$x,93,$cw,7,'Issued to',9,400,$p['muted'],'left');
				$add_merge('recipient',$x,104,$cw,26,'recipient.name',30,700,$p['primary'],'left');
				$add_merge('program',$x,141,$cw,18,'program.name',16,600,$p['secondary'],'left');
				$add_line('assurance-rule',$x,169,$cw,0.1,$p['soft'],0.5);
				break;
		}

		// Shared factual area; still one credential identity and one verification QR.
		$qr = $landscape ? 21.0 : 23.0;
		$footer_y = $h - ( $landscape ? ( $version >= 2 ? 49.0 : 39.0 ) : 48.0 );
		$details_x = $content_x; $details_w = $landscape ? min( 108.0, $content_w * 0.45 ) : max( 65.0, $content_w - $qr - 18.0 );
		// Keep facts in the main content column, clear of decorative side rails.
		if ( $version >= 2 ) {
			if ( 'executive' === $concept ) { $details_x = $margin + 60.0; }
			elseif ( 'folio' === $concept ) { $details_x = $margin + 43.0; $details_w = $w - $details_x - $margin - $qr - 20.0; }
			elseif ( 'signal' === $concept ) { $details_x = $margin + 34.0; }
		}
		$footer_ink = $version >= 2 && 'horizon' === $concept ? '#FFFFFF' : $p['primary'];
		$footer_label = $version >= 2 && 'horizon' === $concept ? '#FFFFFF' : $p['muted'];
		// Horizon's band starts below the standard footer. Fit both facts inside it.
		$compact_footer = $version >= 2 && 'horizon' === $concept;
		if ( $compact_footer ) { $footer_y = $h - $margin - 32.0; }
		$add_text('id-label',$details_x,$footer_y,$details_w,6,'CREDENTIAL ID',6,700,$footer_label,'left',0.8);
		$add_merge('display-number',$details_x,$footer_y+6,$details_w,8,'credential.display_number',8,600,$footer_ink,'left');
		$add_text('date-label',$details_x,$footer_y+15,$details_w,6,'ISSUED',6,700,$footer_label,'left',0.8);
		$add_merge('issue-date',$details_x,$footer_y+21,$details_w,8,'credential.issue_date',8,500,$footer_ink,'left');
		$qr_x=$w-$margin-$qr-( $version >= 2 ? 11.0 : 5.0 );$qr_y=$h-$margin-$qr-( $version >= 2 ? 12.0 : 5.0 );$add_qr('verification-qr',$qr_x,$qr_y,$qr);
		$add_text('verify-label',$qr_x-7,$qr_y+$qr+1,$qr+14,5.5,'SCAN TO VERIFY',5.5,700,$footer_label);
		if ( $version >= 2 ) {
			foreach ( $elements as &$element ) {
				if ( 'merge' === $element['type'] && 'program.name' === $element['token'] ) {
					$element['style']['overflow'] = 'wrap';
					$element['style']['font_size_pt'] = 12.0;
				} elseif ( 'merge' === $element['type'] && 'issuer.name' === $element['token'] && in_array( $concept, array( 'executive', 'gallery' ), true ) ) {
					$element['style']['overflow'] = 'wrap';
				} elseif ( 'text' === $element['type'] && 'EXECUTIVE CREDENTIAL' === $element['text'] ) {
					$element['style']['overflow'] = 'wrap';
				}
			}
			unset( $element );
		}

		return array(
			'_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>LayoutSchema::VERSION_V1),
			'page'=>array('size'=>$size,'orientation'=>$orientation,'width_mm'=>$w,'height_mm'=>$h),
			'elements'=>$elements,
		);
	}

	/** @return array<string,string> */
	private function palette( $concept ) {
		$map = array(
			'heritage'=>array('primary'=>'#18253D','secondary'=>'#655037','accent'=>'#B28B46','muted'=>'#6E7480','soft'=>'#E7DDC9','paper'=>'#FCFAF4'),
			'executive'=>array('primary'=>'#142B3E','secondary'=>'#254E63','accent'=>'#2B7A78','muted'=>'#667985','soft'=>'#DCE9E8','paper'=>'#FFFFFF'),
			'summit'=>array('primary'=>'#14213D','secondary'=>'#334E8C','accent'=>'#2F6FED','muted'=>'#697386','soft'=>'#E5ECFA','paper'=>'#FFFFFF'),
			'folio'=>array('primary'=>'#29262B','secondary'=>'#75646D','accent'=>'#B18B6A','muted'=>'#777077','soft'=>'#EEE7E2','paper'=>'#FFFFFF'),
			'civic'=>array('primary'=>'#19354B','secondary'=>'#315D6C','accent'=>'#2D7C81','muted'=>'#667680','soft'=>'#DDE9E9','paper'=>'#FFFFFF'),
			'ledger'=>array('primary'=>'#1E293B','secondary'=>'#475569','accent'=>'#6366F1','muted'=>'#64748B','soft'=>'#E2E8F0','paper'=>'#FFFFFF'),
			'ribbon'=>array('primary'=>'#3A202B','secondary'=>'#6A4050','accent'=>'#A9793B','muted'=>'#7D6B72','soft'=>'#EADFD9','paper'=>'#FFFFFF'),
			'crest'=>array('primary'=>'#3B1F2B','secondary'=>'#684152','accent'=>'#AA7B3B','muted'=>'#76666C','soft'=>'#EEE0D8','paper'=>'#FFFFFF'),
			'atelier'=>array('primary'=>'#2A2528','secondary'=>'#785F6B','accent'=>'#B58E70','muted'=>'#766F73','soft'=>'#EEE8E4','paper'=>'#FFFFFF'),
			'gallery'=>array('primary'=>'#171717','secondary'=>'#404040','accent'=>'#6366F1','muted'=>'#737373','soft'=>'#E7E7E7','paper'=>'#FFFFFF'),
			'horizon'=>array('primary'=>'#18364A','secondary'=>'#326A76','accent'=>'#30909B','muted'=>'#657680','soft'=>'#DFECEE','paper'=>'#FFFFFF'),
			'keystone'=>array('primary'=>'#19263D','secondary'=>'#5B4A34','accent'=>'#B18A45','muted'=>'#6A7280','soft'=>'#E7DEC8','paper'=>'#FFFFFF'),
			'parchment'=>array('primary'=>'#4A382D','secondary'=>'#705849','accent'=>'#A67C45','muted'=>'#7B7068','soft'=>'#E9DDC9','paper'=>'#FBF6EA'),
			'signal'=>array('primary'=>'#111827','secondary'=>'#1E40AF','accent'=>'#2563EB','muted'=>'#667085','soft'=>'#E2EAFB','paper'=>'#FFFFFF'),
			'milestone'=>array('primary'=>'#213547','secondary'=>'#4E6E79','accent'=>'#2D8A8A','muted'=>'#687780','soft'=>'#DFEBEB','paper'=>'#FFFFFF'),
			'conference'=>array('primary'=>'#17354A','secondary'=>'#285E75','accent'=>'#2D8A92','muted'=>'#677985','soft'=>'#DCE9EB','paper'=>'#FFFFFF'),
			'license'=>array('primary'=>'#17233C','secondary'=>'#5E4B36','accent'=>'#B08A45','muted'=>'#657080','soft'=>'#E9DFC9','paper'=>'#FFFFFF'),
			'assurance'=>array('primary'=>'#172A3D','secondary'=>'#455A67','accent'=>'#A77B3B','muted'=>'#66727D','soft'=>'#E4E8E9','paper'=>'#FFFFFF'),
		);
		return $map[ $concept ];
	}

	/** @return array<string,mixed> */
	private function style( $size, $weight, $color, $align, $letter = 0.0 ) {
		return array('font_family'=>'sans-serif','font_size_pt'=>(float)$size,'font_weight'=>(int)$weight,'italic'=>false,'color'=>$color,'align'=>$align,'vertical_align'=>'middle','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>(float)$letter,'overflow'=>'shrink');
	}

	/** Stable UUIDv4-shaped element identifier derived from a curated factory-only seed. */
	private function uuid( $seed ) {
		$hex=substr(hash('sha256','certloom-curated-preset:'.(string)$seed),0,32);$hex[12]='4';$variants=array('8','9','a','b');$hex[16]=$variants[hexdec($hex[16])%4];
		return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
	}
}
