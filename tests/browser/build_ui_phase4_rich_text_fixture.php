<?php
/** Current Schema 4 rich-inline-text fixture over the Certificate Studio shell. */
putenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION=4' );
ob_start(); require __DIR__ . '/build_ui_phase4_sticky_workspace_fixture.php'; $html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Current Studio fixture did not render.' ); }
$i18n = '<script>window.certloomTemplateDesignerI18n=Object.assign({},window.certloomTemplateDesignerI18n||{},{richText:"Rich text",richTextPrefix:"This certifies that ",inlineText:"Text",inlineField:"Field"});</script>';
$marker = '<script>window.certloomTemplateElementRegistry=';
if ( false === strpos( $html, $marker ) ) { throw new RuntimeException( 'Designer registry script marker missing.' ); }
$html = str_replace( $marker, $i18n . $marker, $html, $count );
if ( 1 !== $count || false === strpos( $html, 'data-certloom-designer-add="rich_text"' ) || false === strpos( $html, 'data-certloom-designer-rich-runs' ) ) { throw new RuntimeException( 'Current rich-text fixture is incomplete.' ); }
echo $html;
