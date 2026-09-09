<?php
/** Browser fixture for the user-reviewed Phase 4 sticky-workspace correction. */
ob_start();
require __DIR__ . '/build_ui_phase4_contextual_properties_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Contextual Designer fixture did not render.' ); }

$sample = '<label class="certloom-template-designer-sample-data"><span>Sample data</span><select data-certloom-designer-sample-data aria-describedby="certloom-designer-sample-data-note">'
	. '<option value="standard">Normal person</option><option value="long">Long name</option><option value="very-long-name">Very long name</option><option value="rtl">RTL person</option><option value="organization">Organization</option><option value="long-organization">Long organization</option><option value="long-iso-scope">Long ISO scope</option><option value="school-result">School result</option>'
	. '</select><small id="certloom-designer-sample-data-note">Preview only</small></label>';
$marker = '<label class="certloom-template-designer-snap">';
if ( false === strpos( $html, $marker ) ) { throw new RuntimeException( 'Sticky-workspace sample marker missing.' ); }
$html = str_replace( $marker, $sample . $marker, $html, $count );
if ( 1 !== $count ) { throw new RuntimeException( 'Sticky-workspace sample insertion was ambiguous.' ); }

/* Mirror the compact production wording introduced by the review correction. */
$html = str_replace( '>Reset unsaved changes</button>', ' title="Reset unsaved changes">Reset</button>', $html );

if ( false === strpos( $html, 'data-certloom-designer-context-dock' ) || false === strpos( $html, 'data-certloom-designer-sample-data' ) ) {
	throw new RuntimeException( 'Sticky-workspace fixture is incomplete.' );
}
echo $html;
