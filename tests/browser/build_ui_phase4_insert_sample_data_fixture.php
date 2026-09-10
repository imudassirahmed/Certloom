<?php
/** Self-contained Phase 4.4 Insert + sample-data fixture over the Designer-v2 shell fixture. */
ob_start();
require __DIR__ . '/build_ui_phase4_designer_v2_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Phase 4 Designer fixture did not render.' ); }

$samples = ( new \Certloom\Application\Templates\TemplatePreviewSamples() )->browser_contract();
$samples_json = json_encode( $samples, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $samples_json ) ) { throw new RuntimeException( 'Sample data fixture contract unavailable.' ); }

$sample_selector = '<label class="certloom-template-designer-sample-data"><span>Sample data</span><select data-certloom-designer-sample-data aria-describedby="certloom-designer-sample-data-note">'
 . '<option value="standard">Normal person</option><option value="long">Long name</option><option value="very-long-name">Very long name</option><option value="rtl">RTL person</option><option value="organization">Organization</option><option value="long-organization">Long organization</option><option value="long-iso-scope">Long ISO scope</option><option value="school-result">School result</option>'
 . '</select><small id="certloom-designer-sample-data-note">Preview only</small></label>';
$marker = '<label class="certloom-template-designer-snap">';
if ( false === strpos( $html, $marker ) ) { throw new RuntimeException( 'Designer toolbar sample insertion marker missing.' ); }
$html = str_replace( $marker, $sample_selector . $marker, $html, $count );
if ( 1 !== $count ) { throw new RuntimeException( 'Designer sample selector insertion was ambiguous.' ); }

$html = preg_replace_callback(
    '/<button type="button" class="button" data-certloom-designer-add="([^"]+)"([^>]*)>/',
    static function( $match ) {
        $kind = (string) $match[1]; $tail = (string) $match[2];
        $disabled = false !== strpos( $tail, 'disabled' );
        $category = in_array( $kind, array( 'text', 'merge', 'list' ), true ) ? 'content' : ( in_array( $kind, array( 'image', 'logo', 'seal', 'signature' ), true ) ? 'media' : 'graphics' );
        return '<button type="button" class="button" data-certloom-designer-add="' . htmlspecialchars( $kind, ENT_QUOTES, 'UTF-8' ) . '" data-certloom-designer-palette-item data-certloom-designer-palette-category="' . $category . '"' . ( $disabled ? '' : ' draggable="true"' ) . $tail . '>';
    },
    $html
);

$registry_marker = '<script>window.certloomTemplateElementRegistry=';
if ( false === strpos( $html, $registry_marker ) ) { throw new RuntimeException( 'Designer registry script marker missing.' ); }
$html = str_replace( $registry_marker, '<script>window.certloomTemplateDesignerSamples=' . $samples_json . ';</script>' . $registry_marker, $html, $sample_count );
if ( 1 !== $sample_count ) { throw new RuntimeException( 'Sample browser contract injection was ambiguous.' ); }

if ( false === strpos( $html, 'data-certloom-designer-sample-data' ) || false === strpos( $html, 'data-certloom-designer-palette-item' ) ) { throw new RuntimeException( 'Phase 4.4 fixture transformation incomplete.' ); }
echo $html;
