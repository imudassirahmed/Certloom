<?php
/** Self-contained Phase 4.5 canvas-interaction fixture over the user-approved sticky workspace. */
ob_start();
require __DIR__ . '/build_ui_phase4_sticky_workspace_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Sticky Designer fixture did not render.' ); }

/* The permanent browser fixture predates the two new canvas-view buttons; add their production contract. */
$marker = '<button type="button" class="button" data-certloom-designer-true-size title="Actual size / 100% (1)">1:1</button>';
$controls = $marker
	. '<button type="button" class="button" data-certloom-designer-safe-area aria-pressed="false" title="Show or hide the 10 mm safe area">Safe area</button>'
	. '<button type="button" class="button" data-certloom-designer-pan-tool aria-pressed="false" title="Pan canvas (Space + drag)">Pan</button>';
if ( false === strpos( $html, $marker ) ) { throw new RuntimeException( 'Canvas controls insertion marker missing.' ); }
$html = str_replace( $marker, $controls, $html, $count );
if ( 1 !== $count ) { throw new RuntimeException( 'Canvas controls insertion was ambiguous.' ); }

$ids = array(
	'a' => '11111111-1111-4111-8111-111111111111',
	'b' => '22222222-2222-4222-8222-222222222222',
	'c' => '33333333-3333-4333-8333-333333333333',
	'd' => '44444444-4444-4444-8444-444444444444',
);
$elements = array();
$xs = array( 20.0, 70.0, 128.0, 170.0 );
$i = 0;
foreach ( $ids as $id ) {
	$elements[] = array(
		'id' => $id,
		'type' => 'shape',
		'shape' => 'rectangle',
		'x_mm' => $xs[ $i ],
		'y_mm' => 45.0,
		'width_mm' => 20.0,
		'height_mm' => 20.0,
		'rotation_deg' => 0.0,
		'z' => $i,
		'opacity' => 1.0,
		'fill' => '#EAF0FE',
		'stroke' => '#1450E0',
		'stroke_width_mm' => 0.4,
	);
	$i++;
}
$layout = array(
	'_meta' => array( 'schema' => 'certloom-layout', 'schema_version' => 3 ),
	'page' => array( 'size' => 'A4', 'orientation' => 'landscape', 'width_mm' => 297.0, 'height_mm' => 210.0 ),
	'elements' => $elements,
);
$json = json_encode( $layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $json ) ) { throw new RuntimeException( 'Canvas fixture layout could not be encoded.' ); }
$html = preg_replace(
	'/(<textarea[^>]*data-certloom-designer-json[^>]*>).*?(<\/textarea>)/s',
	'$1' . htmlspecialchars( $json, ENT_NOQUOTES, 'UTF-8' ) . '$2',
	$html,
	1,
	$layout_count
);
if ( 1 !== $layout_count ) { throw new RuntimeException( 'Canvas fixture layout replacement failed.' ); }
/* Keep authoring state clean and schema-v2 compatible. */
$html = preg_replace(
	'/(<textarea[^>]*data-certloom-designer-state-json[^>]*>).*?(<\/textarea>)/s',
	'$1{&quot;version&quot;:2,&quot;locked_ids&quot;:[],&quot;hidden_ids&quot;:[],&quot;layer_labels&quot;:{},&quot;snap_enabled&quot;:true}$2',
	$html,
	1
);

if ( false === strpos( $html, 'data-certloom-designer-safe-area' ) || false === strpos( $html, 'data-certloom-designer-pan-tool' ) ) {
	throw new RuntimeException( 'Phase 4.5 canvas fixture transformation incomplete.' );
}
echo $html;
