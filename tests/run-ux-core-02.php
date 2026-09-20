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
$issue_js = ux2src( $root, 'admin/assets/issue-credential.js' );
$issue_rest = ux2src( $root, 'src/Infrastructure/Admin/IssueCredentialRest.php' );
$sync = ux2src( $root, 'src/Infrastructure/Recipient/RecipientUserSync.php' );
$resolver = ux2src( $root, 'src/Infrastructure/Integration/LinkedRecipientResolver.php' );
$plugin = ux2src( $root, 'src/Plugin.php' );
$settings = ux2src( $root, 'includes/class-ecapla-certverify-settings.php' );
$settings_view = ux2src( $root, 'admin/views/settings-manager.php' );
$batch_admin = ux2src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$nav = ux2src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain = ux2src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$css = ux2src( $root, 'admin/assets/admin.css' );

ux2( false !== strpos( $manual, 'data-recipient-mode="existing"' ) && false !== strpos( $manual, 'data-recipient-mode="new"' ) && false !== strpos( $issue_js, 'initRecipientModes' ), 'Manual Issuance has exclusive existing/new Recipient modes' );
ux2( false !== strpos( $manual, 'data-new-recipient' ) && false !== strpos( $manual, 'create a reusable Recipient profile without leaving issuance' ), 'New Recipient uses a focused first-step profile panel rather than a competing second form' );
foreach ( array( 'display_name', 'email', 'first_name', 'middle_name', 'last_name', 'external_id' ) as $field ) {
	ux2( false !== strpos( $manual, 'new_recipient.' . $field ), 'New Recipient panel carries canonical profile field ' . $field );
}
ux2( false !== strpos( $manual, "render_async_picker( 'wp_user'" ) && false !== strpos( $issue_js, 'state.issuance.new_recipient.wp_user_id = id' ), 'New Recipient panel can link an exact WordPress account without exposing a raw numeric ID' );
ux2( false !== strpos( $manual, 'data-step-key="details"' ) && false === strpos( $manual, 'data-step-key="data"' ) && false !== strpos( $manual, 'credential-specific facts remain on each issued credential' ), 'Credential-specific fields stay separate from Recipient profile creation while living in the combined Details step' );
ux2( false !== strpos( $issue_js, "if ('recipient' === source)" ) && false !== strpos( $issue_js, 'From Recipient profile' ), 'Program/Batch field contracts keep Recipient-owned facts read-only in credential data' );
ux2( false !== strpos( $issue_js, "'grade' === key" ) && false !== strpos( $issue_js, 'data-scoped-input="grade"' ), 'Required Grade remains a credential field for existing or new Recipients' );
ux2( false !== strpos( $service, "if ( 'recipient' === \$source )" ) && false !== strpos( $service, "'grade' === \$key" ) && false !== strpos( $service, '$submitted[ $key ]' ), 'Issuance resolves Recipient, Grade and credential-custom values from their separate authorities' );
ux2( false !== strpos( $manual_repo, "'wp_user_id'" ) && false !== strpos( $manual_repo, 'find_by_wp_user_id' ), 'New Recipient can link an exact existing WordPress account without duplicate ownership' );

ux2( false !== strpos( $sync, "add_action( 'user_register'" ) && false !== strpos( $sync, "add_action( 'profile_update'" ), 'New WordPress registrations and profile updates participate in Recipient sync' );
ux2( false !== strpos( $sync, "add_action( 'added_user_meta'" ) && false !== strpos( $sync, "add_action( 'updated_user_meta'" ), 'Registration plugins saving first/last name after user creation are also synchronized' );
ux2( false !== strpos( $sync, 'find_by_wp_user_id' ) && false === strpos( $sync, 'find_by_email' ), 'Recipient account sync uses exact WordPress user ID and never email/name guessing' );
ux2( false !== strpos( $plugin, 'RecipientUserSync' ), 'Recipient account sync is booted with the normalized product' );
ux2( false !== strpos( $settings, "'recipient_user_sync_enabled' => true" ) && false !== strpos( $settings_view, 'Keep WordPress users connected to Recipient profiles' ), 'Recipient account sync is an explicit enabled-by-default setting' );
ux2( false !== strpos( $resolver, 'ensure_for_user' ) && false !== strpos( $resolver, 'RecipientUserSync::enabled' ), 'Account-based integrations can provision the exact Recipient when the sync policy is enabled' );

ux2( false !== strpos( $manual, 'data-quick-dialog="program"' ) && false !== strpos( $issue_rest, "self::BASE . '/quick-program'" ), 'Issuance can quick-add a Program without leaving the draft' );
ux2( false !== strpos( $manual, 'data-quick-dialog="batch"' ) && false !== strpos( $issue_rest, "self::BASE . '/quick-batch'" ), 'Issuance can quick-add a Batch without leaving the draft' );
ux2( false !== strpos( $issue_js, "root.querySelectorAll('[data-quick-form]')" ) && false !== strpos( $issue_js, "rest(url, {method:'POST', body:payload})" ), 'Quick-create dialogs save over authenticated REST so unsaved issuance state is preserved' );
ux2( false !== strpos( $batch_admin, 'certloom-batch-quick-program-dialog' ) && false !== strpos( $batch_admin, 'data-certloom-quick-target="#certloom-batch-program-id"' ), 'Batch creation can quick-add and select a Program in place' );
ux2( false === strpos( $manual, 'name="program_template_id"' ) && false !== strpos( $issue_js, 'loadProgramContext(false)' ) && false !== strpos( $issue_js, 'state.fieldContract' ), 'Issuance quick Program inherits design authority while loading its canonical credential-field contract after selection' );
ux2( false === strpos( $manual, 'name="batch_template_id"' ) && false !== strpos( $issue_js, "if ('batch' === kind)" ) && false !== strpos( $issue_js, 'loadProgramContext(false)' ), 'Issuance quick Batch inherits presentation while refreshing canonical Batch field changes' );
ux2( false === strpos( $batch_admin, 'name="batch_template_id"' ) && false !== strpos( $batch_admin, "( new DesignRulesAdmin() )->context_card( 'batch', \$id )" ), 'Main Batch editor delegates certificate/email overrides to the shared Designs card' );
ux2( false !== strpos( $batch_admin, 'certloom-batch-quick-program-dialog' ) && false === strpos( $batch_admin, 'name="program_template_id"' ) && false !== strpos( $batch_admin, 'quick-created Program inherits its Issuer and Global certificate/email designs' ) && false !== strpos( $batch_admin, 'program_field_requirement[' ), 'Batch quick Program inherits presentation while retaining field decisions' );
ux2( false === strpos( $domain, 'name="program_template_id"' ) && false !== strpos( $domain, "( new DesignRulesAdmin() )->context_card( 'program', \$id )" ), 'Program editor delegates presentation overrides to the shared Designs card without embedding assignment controls' );
ux2( false !== strpos( $css, '.certloom-premium-dialog>form' ) && false !== strpos( $css, 'overflow:auto' ) && false !== strpos( $css, 'max-height:min(84vh,760px)' ), 'Quick-create dialogs are viewport-bounded with independently scrollable bodies' );

ux2( false !== strpos( $nav, "'Signers'" ) && false !== strpos( $nav, "'certloom-domain-signers'" ), 'Signers remain visible in the unified Programs section' );
ux2( false !== strpos( $domain, "certloom_program_quick_signer" ) && false !== strpos( $domain, "<h3>' . esc_html__( 'Signers'" ), 'Program workspace can manage and quick-add Signers' );
ux2( false !== strpos( $domain, "'page' => ManualIssuanceAdmin::PAGE, 'recipient_id'" ) && false !== strpos( $domain, "'program_id'" ) && false !== strpos( $domain, "'batch_id'" ), 'Issue another keeps Recipient, Program and Batch context' );

printf( "\n%d UX-CORE-02 source checks, %d failures\n", $pass + $fail, $fail );
exit( $fail ? 1 : 0 );
