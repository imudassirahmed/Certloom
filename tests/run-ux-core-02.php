<?php
/**
 * UX-CORE-02 — real-WordPress acceptance corrections recovered after QA.8.
 *
 * This contract protects Recipient/issuance ownership, exact WordPress-account
 * identity, contextual quick-create continuity and the Programs sub-navigation.
 */
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ux2( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  {$label}\n"; } else { $fail++; echo "FAIL  {$label}\n"; } }
function ux2src( $root, $path ) { $value = file_get_contents( $root . '/' . $path ); return false === $value ? '' : $value; }

$manual = ux2src( $root, 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$service = ux2src( $root, 'src/Application/Issuance/ManualIssuanceService.php' );
$manual_repo = ux2src( $root, 'src/Infrastructure/Repository/ManualIssuanceRepository.php' );
$js = ux2src( $root, 'admin/assets/admin.js' );
$sync = ux2src( $root, 'src/Infrastructure/Recipient/RecipientUserSync.php' );
$resolver = ux2src( $root, 'src/Infrastructure/Integration/LinkedRecipientResolver.php' );
$plugin = ux2src( $root, 'src/Plugin.php' );
$settings = ux2src( $root, 'includes/class-ecapla-certverify-settings.php' );
$settings_view = ux2src( $root, 'admin/views/settings-manager.php' );
$batch_admin = ux2src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$nav = ux2src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain = ux2src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$css = ux2src( $root, 'admin/assets/admin.css' );

ux2( false !== strpos( $manual, "recipient_mode', 'existing'" ) && false !== strpos( $manual, "recipient_mode', 'new'" ) && false !== strpos( $js, 'data-certloom-recipient-existing-panel' ), 'Manual Issuance has exclusive existing/new Recipient modes' );
ux2( false !== strpos( $manual, 'certloom-new-recipient-dialog' ) && false !== strpos( $manual, 'Complete recipient profile' ), 'New Recipient uses a focused profile popup rather than a second inline form' );
foreach ( array( 'display_name', 'email', 'first_name', 'middle_name', 'last_name', 'external_id', 'wp_user_id' ) as $field ) {
	ux2( false !== strpos( $manual, 'new_recipient[' . $field . ']' ), 'New Recipient popup carries canonical profile field ' . $field );
}
ux2( false !== strpos( $manual, 'Credential fields stay separate' ) && false !== strpos( $manual, 'data-certloom-scoped-field-panel' ), 'Credential-specific fields stay separate from Recipient profile creation' );
ux2( false !== strpos( $js, 'applyRecipientRequirements' ) && false !== strpos( $js, "'recipient' !== field.value_source" ), 'Program/Batch contract can make Recipient profile fields required only when relevant' );
ux2( false !== strpos( $js, "'grade' === field.key" ) && false !== strpos( $js, 'gradeInput.required = true' ), 'Required Grade remains a credential field for existing or new Recipients' );
ux2( false !== strpos( $service, "if ( 'recipient' === \$source )" ) && false !== strpos( $service, "'grade' === \$key" ) && false !== strpos( $service, '$submitted[ $key ]' ), 'Issuance resolves Recipient, Grade and credential-custom values from their separate authorities' );
ux2( false !== strpos( $manual_repo, "'wp_user_id'" ) && false !== strpos( $manual_repo, 'find_by_wp_user_id' ), 'New Recipient can link an exact existing WordPress account without duplicate ownership' );

ux2( false !== strpos( $sync, "add_action( 'user_register'" ) && false !== strpos( $sync, "add_action( 'profile_update'" ), 'New WordPress registrations and profile updates participate in Recipient sync' );
ux2( false !== strpos( $sync, "add_action( 'added_user_meta'" ) && false !== strpos( $sync, "add_action( 'updated_user_meta'" ), 'Registration plugins saving first/last name after user creation are also synchronized' );
ux2( false !== strpos( $sync, 'find_by_wp_user_id' ) && false === strpos( $sync, 'find_by_email' ), 'Recipient account sync uses exact WordPress user ID and never email/name guessing' );
ux2( false !== strpos( $plugin, 'RecipientUserSync' ), 'Recipient account sync is booted with the normalized product' );
ux2( false !== strpos( $settings, "'recipient_user_sync_enabled' => true" ) && false !== strpos( $settings_view, 'Keep WordPress users connected to Recipient profiles' ), 'Recipient account sync is an explicit enabled-by-default setting' );
ux2( false !== strpos( $resolver, 'ensure_for_user' ) && false !== strpos( $resolver, 'RecipientUserSync::enabled' ), 'Account-based integrations can provision the exact Recipient when the sync policy is enabled' );

ux2( false !== strpos( $manual, 'certloom-quick-program-dialog' ) && false !== strpos( $manual, 'certloom_manual_quick_program' ), 'Issuance can quick-add a Program without leaving the draft' );
ux2( false !== strpos( $manual, 'certloom-quick-batch-dialog' ) && false !== strpos( $manual, 'certloom_manual_quick_batch' ), 'Issuance can quick-add a Batch without leaving the draft' );
ux2( false !== strpos( $js, "form[data-certloom-quick-create]" ) && false !== strpos( $js, "fetch( window.ajaxurl" ), 'Quick-create popups save over AJAX so unsaved parent forms are preserved' );
ux2( false !== strpos( $batch_admin, 'certloom-batch-quick-program-dialog' ) && false !== strpos( $batch_admin, 'data-certloom-quick-target="#certloom-batch-program-id"' ), 'Batch creation can quick-add and select a Program in place' );
ux2( false === strpos( $manual, 'name="program_template_id"' ) && false !== strpos( $manual, 'Designs inherit automatically' ) && false !== strpos( $manual, 'program_field_requirement[' ), 'Issuance quick Program inherits presentation while retaining its useful credential-field contract' );
ux2( false === strpos( $manual, 'name="batch_template_id"' ) && false !== strpos( $manual, 'This Batch will follow its Program certificate/email designs' ) && false !== strpos( $manual, 'batch_field_requirement[' ), 'Issuance quick Batch inherits presentation while retaining Batch field changes' );
ux2( false === strpos( $batch_admin, 'name="batch_template_id"' ) && false !== strpos( $batch_admin, "( new DesignRulesAdmin() )->context_card( 'batch', \$id )" ), 'Main Batch editor delegates certificate/email overrides to the shared Designs card' );
ux2( false !== strpos( $batch_admin, 'certloom-batch-quick-program-dialog' ) && false === strpos( $batch_admin, 'name="program_template_id"' ) && false !== strpos( $batch_admin, 'quick-created Program inherits its Issuer and Global certificate/email designs' ) && false !== strpos( $batch_admin, 'program_field_requirement[' ), 'Batch quick Program inherits presentation while retaining field decisions' );
ux2( false === strpos( $domain, 'name="program_template_id"' ) && false !== strpos( $domain, "( new DesignRulesAdmin() )->context_card( 'program', \$id )" ), 'Program editor delegates presentation overrides to the shared Designs card without embedding assignment controls' );
ux2( false !== strpos( $css, '.certloom-premium-dialog>form' ) && false !== strpos( $css, 'overflow:auto' ) && false !== strpos( $css, 'max-height:min(84vh,760px)' ), 'Quick-create dialogs are viewport-bounded with independently scrollable bodies' );

ux2( false !== strpos( $nav, "'Signers'" ) && false !== strpos( $nav, "'certloom-domain-signers'" ), 'Signers remain visible in the unified Programs section' );
ux2( false !== strpos( $domain, "certloom_program_quick_signer" ) && false !== strpos( $domain, "<h3>' . esc_html__( 'Signers'" ), 'Program workspace can manage and quick-add Signers' );
ux2( false !== strpos( $domain, "'page' => ManualIssuanceAdmin::PAGE, 'recipient_id'" ) && false !== strpos( $domain, "'program_id'" ) && false !== strpos( $domain, "'batch_id'" ), 'Issue another keeps Recipient, Program and Batch context' );

printf( "\n%d UX-CORE-02 source checks, %d failures\n", $pass + $fail, $fail );
exit( $fail ? 1 : 0 );
