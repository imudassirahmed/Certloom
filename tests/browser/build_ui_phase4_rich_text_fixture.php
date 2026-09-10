<?php
/** Self-contained Phase 4.6 Schema 4 rich-inline-text fixture over the sticky Designer shell. */
putenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION=4' );
ob_start();
require __DIR__ . '/build_ui_phase4_sticky_workspace_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Sticky Designer fixture did not render.' ); }
$edit = '<button type="button" class="button" data-certloom-designer-context-edit-text hidden>Edit text</button>';
$rich = $edit . '<button type="button" class="button" data-certloom-designer-context-rich-text hidden>Edit inline content</button>';
if ( false === strpos( $html, $edit ) ) { throw new RuntimeException( 'Context text-edit marker missing.' ); }
$html = str_replace( $edit, $rich, $html, $count );
if ( 1 !== $count ) { throw new RuntimeException( 'Rich context insertion was ambiguous.' ); }
$html = str_replace( '</form>', '<datalist id="certloom-designer-merge-tokens"><option value="recipient.name"><option value="credential.display_number"><option value="program.name"><option value="credential.issue_date"><option value="credential.expiry_date"><option value="credential.grade"><option value="issuer.name"><option value="credential.status"></datalist></form>', $html, $datalist_count );
if ( 1 !== $datalist_count ) { throw new RuntimeException( 'Rich token datalist insertion was ambiguous.' ); }
$i18n_bootstrap = '<script>window.certloomTemplateDesignerI18n=Object.assign({},window.certloomTemplateDesignerI18n||{},{richText:"Rich text",richTextPrefix:"This certifies that ",inlineText:"Text",inlineField:"Field"});</script>';
$registry_marker = '<script>window.certloomTemplateElementRegistry=';
if ( false === strpos( $html, $registry_marker ) ) { throw new RuntimeException( 'Designer registry script marker missing.' ); }
$html = str_replace( $registry_marker, $i18n_bootstrap . $registry_marker, $html, $i18n_count );
if ( 1 !== $i18n_count ) { throw new RuntimeException( 'Rich i18n bootstrap insertion was ambiguous.' ); }
if ( false === strpos( $html, 'data-certloom-designer-add="rich_text"' ) || false === strpos( $html, 'data-certloom-designer-rich-runs' ) || false === strpos( $html, 'data-certloom-designer-context-rich-text' ) ) {
	throw new RuntimeException( 'Schema 4 rich-text fixture is incomplete.' );
}
echo $html;
