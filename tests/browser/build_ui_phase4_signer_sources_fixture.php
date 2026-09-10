<?php
/** Self-contained Phase 4.7 Signer signature-source fixture over sticky Designer shell. */
if ( false === getenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION' ) || '' === (string) getenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION' ) ) {
	putenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION=4' );
}
ob_start();
require __DIR__ . '/build_ui_phase4_sticky_workspace_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Sticky Designer fixture did not render.' ); }

$barcode = '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="barcode" hidden><span>Barcode</span><input type="number" min="10" max="50" data-certloom-designer-content-field="quiet_zone_modules"></div></div>';
$signature_context = '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="signature" hidden><span>Signature</span><select data-certloom-designer-content-field="fit"><option value="contain">Contain</option><option value="cover">Cover</option><option value="fill">Fill</option></select><button type="button" class="button" data-certloom-designer-signature-source="signer">Use credential Signer</button><button type="button" class="button" data-certloom-designer-context-media>Choose fixed image</button></div></div>';
if ( false === strpos( $html, $barcode ) ) { throw new RuntimeException( 'Signer context insertion marker missing.' ); }
$html = str_replace( $barcode, str_replace( '</div></div>', '</div>', $barcode ) . $signature_context, $html, $context_count );
if ( 1 !== $context_count ) { throw new RuntimeException( 'Signer context insertion was ambiguous.' ); }

$asset = '<div hidden data-certloom-designer-content="asset"><input data-certloom-designer-content-field="asset"><select data-certloom-designer-content-field="fit"><option value="contain">Contain</option><option value="cover">Cover</option><option value="fill">Fill</option></select><button type="button" class="button" data-certloom-designer-media>Media</button></div>';
$signature = '<div hidden data-certloom-designer-content="signature"><p class="description" data-certloom-designer-signature-status></p><select data-certloom-designer-content-field="fit"><option value="contain">Contain</option><option value="cover">Cover</option><option value="fill">Fill</option></select><button type="button" class="button" data-certloom-designer-signature-source="signer">Use credential Signer signature</button><button type="button" class="button" data-certloom-designer-media>Choose fixed signature image</button></div>';
if ( false === strpos( $html, $asset ) ) { throw new RuntimeException( 'Signer Properties insertion marker missing.' ); }
$html = str_replace( $asset, $asset . $signature, $html, $properties_count );
if ( 1 !== $properties_count ) { throw new RuntimeException( 'Signer Properties insertion was ambiguous.' ); }

if ( false === strpos( $html, 'data-certloom-designer-signature-source="signer"' ) || false === strpos( $html, 'data-certloom-designer-signature-status' ) ) {
	throw new RuntimeException( 'Signer source fixture is incomplete.' );
}
echo $html;
