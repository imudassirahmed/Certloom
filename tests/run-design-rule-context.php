<?php
/** Contextual Issuer / Program / Batch Design Rule integration regression. */
$root = dirname( __DIR__ );
$files = array(
	'domain' => 'src/Infrastructure/Admin/DomainAdmin.php',
	'structure' => 'src/Infrastructure/Admin/DomainStructureAdmin.php',
	'manual' => 'src/Infrastructure/Admin/ManualIssuanceAdmin.php',
	'rules' => 'src/Infrastructure/Admin/DesignRulesAdmin.php',
	'manager' => 'src/Infrastructure/Admin/DesignRuleManager.php',
	'context_js' => 'admin/assets/design-rule-context.js',
	'rules_js' => 'admin/assets/design-rules.js',
	'css' => 'admin/assets/design-rules.css',
);
$src = array();
foreach ( $files as $key => $file ) {
	$src[ $key ] = file_get_contents( $root . '/' . $file );
	if ( ! is_string( $src[ $key ] ) || '' === $src[ $key ] ) { throw new RuntimeException( 'Required Design Rule context source unavailable: ' . $file ); }
}
$checks = 0;
function drc_check( $condition, $message ) { global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } $checks++; }
function drc_has( $source, $needle, $message ) { drc_check( false !== strpos( $source, $needle ), $message ); }
function drc_lacks( $source, $needle, $message ) { drc_check( false === strpos( $source, $needle ), $message ); }

// Existing entity forms use one contextual UI, not embedded assignment selectors.
drc_has( $src['domain'], "context_card( 'program', \$id )", 'Existing Program screens must render the shared Designs card.' );
drc_has( $src['domain'], "context_card( 'issuer', \$id )", 'Existing Issuer screens must render the shared Designs card.' );
drc_has( $src['structure'], "context_card( 'batch', \$id )", 'Existing Batch screens must render the shared Designs card.' );
drc_has( $src['rules'], 'Presentation & communication', 'Context card must explain the combined certificate/email presentation purpose without assignment jargon.' );
drc_has( $src['rules'], 'Certificate and email rules inherit independently through Global → Issuer → Program → Batch.', 'Context card must explain independent hierarchy resolution.' );
drc_has( $src['rules'], 'Change designs', 'Context card must use a compact progressive-disclosure action.' );
drc_has( $src['rules'], 'Open Design Rules', 'Context popup must provide a route to the full hierarchy workspace.' );

// Omitted legacy fields must not mutate direct rules during unrelated entity edits.
drc_has( $src['domain'], "isset( \$_POST['program_template_id'] ) ? \$this->save_scope_template_assignment", 'Program persistence must only change a certificate rule when the legacy field is explicitly submitted.' );
drc_has( $src['domain'], "isset( \$_POST['issuer_template_id'] ) ? \$this->save_scope_template_assignment", 'Issuer persistence must only change a certificate rule when the legacy field is explicitly submitted.' );
drc_has( $src['structure'], "isset( \$_POST['batch_template_id'] ) ? \$this->save_batch_template_assignment", 'Batch persistence must only change a certificate rule when the legacy field is explicitly submitted.' );
drc_has( $src['domain'], "isset( \$_POST['program_template_id'] ) ? absint( \$_POST['program_template_id'] ) : \$this->stored_scope_template_id", 'Program validation must preserve the stored direct certificate rule when the selector is absent.' );
drc_has( $src['domain'], "isset( \$_POST['issuer_template_id'] ) ? absint( \$_POST['issuer_template_id'] ) : \$this->stored_scope_template_id", 'Issuer validation must preserve the stored direct certificate rule when the selector is absent.' );
drc_has( $src['structure'], "isset( \$_POST['batch_template_id'] ) ? absint( \$_POST['batch_template_id'] ) : \$this->stored_batch_template_id", 'Batch validation must preserve the stored direct certificate rule when the selector is absent.' );

// Quick creation inherits; design assignment is a separate job.
drc_has( $src['manual'], 'Designs inherit automatically', 'Quick Program creation must explain automatic inheritance.' );
drc_has( $src['manual'], 'its Designs card or Design Rules', 'Quick profile-aware Program creation must point to the dedicated design workflow.' );
drc_lacks( $src['manual'], 'name="program_template_id"', 'Quick issuance Program creation must not mix design assignment into entity creation.' );

// The popup writes through the same manager and preserves untouched dimensions.
drc_has( $src['rules'], 'new DesignRuleManager()', 'Context and central screens must share one Design Rule manager.' );
drc_has( $src['context_js'], "certActual === certOriginal ? 'no_change' : certActual", 'Context popup must preserve certificate rules that were only inspected.' );
drc_has( $src['context_js'], "emailActual === emailOriginal ? 'no_change' : emailActual", 'Context popup must preserve email rules that were only inspected.' );
drc_has( $src['context_js'], 'certloom_design_rules_apply_ajax', 'Context popup must use the centralized AJAX mutation boundary.' );
drc_has( $src['context_js'], "delete rules[currentPurpose]", 'Restoring inheritance must update the client-side direct-rule snapshot without reloading.' );
drc_has( $src['context_js'], "updateSummary(dialog, 'certificate'", 'Successful certificate changes must refresh the compact summary in place.' );
drc_has( $src['context_js'], "updateSummary(dialog, 'email'", 'Successful issued-email changes must refresh the compact summary in place.' );
drc_has( $src['context_js'], 'dialog._certloomOpener', 'Popup focus must return to the control that opened it.' );

// Usage deep-links are truthful, bounded and remain server-backed outside the first inventory page.
drc_has( $src['rules'], 'Showing records with a direct rule for this design.', 'Used-by filtering must state that it represents direct rules, not inherited descendants.' );
drc_has( $src['rules'], 'The Global default also uses this design.', 'Used-by filtering must disclose Global usage separately.' );
drc_has( $src['rules'], 'usage_target_page(', 'Used-by filtering must have a dedicated bounded target path.' );
drc_has( $src['rules'], 'usage_target_labels(', 'Used-by search must avoid loading every target record just to match a query.' );
drc_has( $src['rules'], 'array_slice( $ids, $offset, self::TARGET_PAGE_SIZE )', 'Used-by target hydration must stay page-bounded.' );
drc_has( $src['rules_js'], "body.set('design_type', usageType)", 'Server-backed target search must preserve the active design-usage filter.' );
drc_has( $src['rules_js'], "body.set('design_id', usageId)", 'Server-backed target pagination must preserve the exact filtered design identity.' );

// Accessibility / resilient presentation remains part of the shared component.
drc_has( $src['css'], '.certloom-context-design-dialog', 'Shared context dialog needs a dedicated responsive visual contract.' );
drc_has( $src['css'], '@media(prefers-reduced-motion:reduce)', 'Design Rule surfaces must respect reduced motion.' );
drc_has( $src['css'], '@media(forced-colors:active)', 'Design Rule surfaces must remain legible in forced-colors mode.' );

echo "PASS  Issuer / Program / Batch design controls share one inheritance-safe Design Rules workflow\n";
echo "PASS  unrelated entity saves preserve stored rules and Used-by filtering remains truthful beyond the first target page\n\n{$checks} checks, 0 failures\n";
