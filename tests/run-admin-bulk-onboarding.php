<?php
/** Phase 1D regression for reviewed bulk actions and non-blocking onboarding. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
$GLOBALS['certloom_bulk_transients'] = array();
$GLOBALS['certloom_bulk_owner'] = 9;
$GLOBALS['certloom_bulk_records'] = array(
	2 => array( 'id' => 2, 'cert_number' => 'CERT-2', 'holder_name' => 'Ada', 'status' => 'valid', 'issue_date' => '2026-01-01', 'expiry_date' => '', 'custom_fields' => array(), 'updated_at' => '2026-08-23 05:00:00' ),
	5 => array( 'id' => 5, 'cert_number' => 'CERT-5', 'holder_name' => 'Lin', 'status' => 'valid', 'issue_date' => '2026-10-01', 'expiry_date' => '', 'custom_fields' => array(), 'updated_at' => '2026-08-23 05:00:00' ),
);
$GLOBALS['certloom_bulk_updates'] = array();

function __( $text ) { return $text; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_generate_password() { return 'abcdefghijklmnopqrstuvwx12345678'; }
function get_current_user_id() { return (int) $GLOBALS['certloom_bulk_owner']; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['certloom_bulk_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['certloom_bulk_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['certloom_bulk_transients'][ $key ] ); return true; }
function wp_list_pluck( $list, $field ) { return array_map( static function ( $row ) use ( $field ) { return $row[ $field ]; }, $list ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class Ecapla_CertVerify_Fields {
	public static function get_fields() {
		return array(
			array( 'key' => 'department', 'label' => 'Department', 'type' => 'text', 'column' => null, 'core' => false, 'protected' => false, 'locked_visibility' => false ),
			array( 'key' => 'internal_notes', 'label' => 'Internal notes', 'type' => 'textarea', 'column' => null, 'core' => true, 'protected' => true, 'locked_visibility' => true ),
			array( 'key' => 'holder_name', 'label' => 'Holder', 'type' => 'text', 'column' => 'holder_name', 'core' => true, 'protected' => true, 'locked_visibility' => false ),
		);
	}
	public static function get_field( $key ) {
		if ( 'status_reason' === $key ) { return array( 'key' => 'status_reason', 'label' => 'Status reason', 'type' => 'textarea' ); }
		foreach ( self::get_fields() as $field ) { if ( $key === $field['key'] ) { return $field; } }
		return null;
	}
}
class Ecapla_CertVerify_Validator {
	public static function date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return null; }
		$d = DateTime::createFromFormat( '!Y-m-d', $value );
		return $d && $d->format( 'Y-m-d' ) === $value ? $value : new WP_Error( 'bad_date', 'Bad date' );
	}
	public static function date_range( $issue, $expiry ) { return $issue && $expiry && $expiry < $issue ? new WP_Error( 'range', 'Expiry date cannot be earlier than issue date.' ) : true; }
	public static function custom_field_value( $value, $field ) { return sanitize_textarea_field( $value ); }
}
class Ecapla_CertVerify_Database {
	public static function get_by_id( $id ) { return $GLOBALS['certloom_bulk_records'][ $id ] ?? null; }
	public static function begin_batch() { return true; }
	public static function end_batch() { return true; }
	public static function update( $id, $patch ) {
		$GLOBALS['certloom_bulk_updates'][ $id ] = $patch;
		return true;
	}
}

require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-bulk-manager.php';

function p1d_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$checks = 0;
function p1d_check( $condition, $message ) { global $checks; p1d_assert( $condition, $message ); $checks++; }

$token = Ecapla_CertVerify_Bulk_Manager::create_session( array( 5, 2, 5, 0 ), array( 'status' => 'valid', 's' => 'Ada' ) );
p1d_check( is_string( $token ) && 32 === strlen( $token ), 'Bulk session must use a random bounded token.' );
$session = Ecapla_CertVerify_Bulk_Manager::get_session( $token );
p1d_check( array( 2, 5 ) === $session['ids'], 'Bulk selection must normalize, deduplicate and freeze selected IDs.' );
p1d_check( 'valid' === $session['return_state']['status'] && 'Ada' === $session['return_state']['s'], 'Bulk session must retain only sanitized read-only return state.' );

$preview = Ecapla_CertVerify_Bulk_Manager::prepare_preview( $token, array( 'bulk_operation' => 'set_expiry', 'bulk_expiry_date' => '2026-06-01' ) );
p1d_check( ! is_wp_error( $preview ), 'Valid bulk expiry review should prepare.' );
p1d_check( 1 === count( $preview['eligible'] ) && 2 === $preview['eligible'][0]['id'], 'Preview must freeze only eligible certificates.' );
p1d_check( 1 === count( $preview['skipped'] ) && 5 === $preview['skipped'][0]['id'], 'Preview must report certificates whose date range would become invalid.' );
$result = Ecapla_CertVerify_Bulk_Manager::commit( $token );
p1d_check( 1 === $result['changed'] && empty( $result['failed'] ), 'Commit should update exactly the frozen eligible set.' );
p1d_check( isset( $GLOBALS['certloom_bulk_updates'][2]['expiry_date'] ) && '2026-06-01' === $GLOBALS['certloom_bulk_updates'][2]['expiry_date'], 'Committed expiry must match the reviewed value.' );
p1d_check( ! isset( $GLOBALS['certloom_bulk_updates'][5] ), 'Skipped certificate must never be mutated.' );


// A record edited after review must not be overwritten by a stale bulk commit.
$GLOBALS['certloom_bulk_updates'] = array();
$token2 = Ecapla_CertVerify_Bulk_Manager::create_session( array( 2 ) );
$preview2 = Ecapla_CertVerify_Bulk_Manager::prepare_preview( $token2, array( 'bulk_operation' => 'set_status', 'bulk_status' => 'suspended' ) );
p1d_check( ! is_wp_error( $preview2 ), 'Second review should prepare before the concurrency test.' );
$GLOBALS['certloom_bulk_records'][2]['updated_at'] = '2026-08-23 05:01:00';
$result2 = Ecapla_CertVerify_Bulk_Manager::commit( $token2 );
p1d_check( 0 === $result2['changed'] && 1 === count( $result2['failed'] ), 'Commit must refuse to overwrite a certificate changed after review.' );
p1d_check( ! isset( $GLOBALS['certloom_bulk_updates'][2] ), 'Optimistic-concurrency failure must produce zero stale overwrite.' );
$GLOBALS['certloom_bulk_records'][2]['updated_at'] = '2026-08-23 05:00:00';

$editable = Ecapla_CertVerify_Bulk_Manager::get_editable_custom_fields();
p1d_check( isset( $editable['department'] ), 'User-defined custom fields should be eligible for reviewed bulk editing.' );
p1d_check( ! isset( $editable['internal_notes'] ), 'Protected/locked internal fields must not be exposed as generic bulk-edit targets.' );

$GLOBALS['certloom_bulk_owner'] = 10;
$foreign = Ecapla_CertVerify_Bulk_Manager::get_session( $token );
p1d_check( is_wp_error( $foreign ), 'Bulk sessions must be user-bound.' );
$GLOBALS['certloom_bulk_owner'] = 9;

$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$list = file_get_contents( $root . '/admin/class-ecapla-certverify-list-table.php' );
$bulk_view = file_get_contents( $root . '/admin/views/bulk-review.php' );
$setup = file_get_contents( $root . '/admin/views/setup-wizard.php' );
$onboarding = file_get_contents( $root . '/includes/class-ecapla-certverify-onboarding.php' );
$dashboard = file_get_contents( $root . '/admin/views/dashboard.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
$bootstrap = file_get_contents( $root . '/certloom.php' );
$database = file_get_contents( $root . '/includes/class-ecapla-certverify-database.php' );
$list_view = file_get_contents( $root . '/admin/views/list-certificates.php' );
$editor_view = file_get_contents( $root . '/admin/views/edit-certificate.php' );
$placeholder_view = file_get_contents( $root . '/admin/views/roadmap-placeholder.php' );
$product_navigation = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
foreach ( array( $admin, $list, $bulk_view, $setup, $onboarding, $dashboard, $uninstall, $bootstrap, $database, $list_view, $editor_view, $placeholder_view, $product_navigation ) as $source ) { p1d_check( is_string( $source ) && '' !== $source, 'Required Phase 1D source unavailable.' ); }
p1d_check( false !== strpos( $list, "'bulk_edit' => __( 'Change selected…', 'certloom' )" ), 'Registry must expose reviewed bulk change action.' );
p1d_check( false !== strpos( $admin, 'Ecapla_CertVerify_Bulk_Manager::prepare_preview' ) && false !== strpos( $admin, 'Ecapla_CertVerify_Bulk_Manager::commit' ), 'Admin controller must separate review and commit.' );
p1d_check( false !== strpos( $bulk_view, 'Selection is frozen before mutation' ) && false !== strpos( $bulk_view, 'Program and batch assignment stay unavailable' ), 'Bulk UI must explain frozen selection and the normalized-domain boundary.' );
p1d_check( false !== strpos( $bulk_view, 'certverify_bulk_preview' ) && false !== strpos( $bulk_view, 'certverify_bulk_commit' ), 'Bulk review and commit must each be nonce-protected.' );
p1d_check( false !== strpos( $admin, "__( 'Setup', 'certloom' )" ) && false !== strpos( $admin, 'render_onboarding_screen' ), 'ONB-01 must have a dedicated Setup screen.' );
p1d_check( false !== strpos( $setup, 'Set up your first credential.' ) && false !== strpos( $onboarding, "'profile'      => __( 'Profile', 'certloom' )" ) && false !== strpos( $onboarding, "'organization' => __( 'Organization', 'certloom' )" ) && false !== strpos( $onboarding, "'fields'       => __( 'Credential fields', 'certloom' )" ) && false !== strpos( $onboarding, "'appearance'   => __( 'Appearance', 'certloom' )" ) && false !== strpos( $onboarding, "'issue'        => __( 'First credential', 'certloom' )" ) && false !== strpos( $onboarding, "'finish'       => __( 'Finish', 'certloom' )" ), 'Setup must expose the approved Setup v2 profile-to-first-credential flow rather than the retired purpose/program wizard.' );
p1d_check( false === strpos( $setup, 'Simple mode' ) && false === strpos( $setup, 'Advanced mode' ) && false !== strpos( $setup, 'What are you using Certloom for?' ) && false !== strpos( $setup, 'Programs, Batches and Signers are optional power tools' ) && false === strpos( $setup, 'First Program' ), 'Setup v2 must keep the required path profile-driven and free of Program/Simple/Advanced setup requirements.' );
p1d_check( false !== strpos( $setup, "value=\"details_only\"" ) && false !== strpos( $setup, "value=\"image\"" ) && false !== strpos( $setup, "value=\"design\"" ) && false !== strpos( $onboarding, "array( 'details_only', 'image', 'design' )" ), 'Setup v2 must expose the three mutually exclusive credential-presentation modes through canonical persisted state.' );
p1d_check( false !== strpos( $onboarding, 'wp_delete_post' ) && false !== strpos( $onboarding, 'page_cleanup_failed' ), 'Verification-page creation must compensate if the setup link cannot be persisted.' );
p1d_check( false !== strpos( $onboarding, 'if ( $state === $stored )' ), 'No-op onboarding saves must not advance mutation generation.' );
p1d_check( false !== strpos( $dashboard, "'Setup wizard', 'certloom'" ) && false !== strpos( $dashboard, 'certloom-dashboard-setup-link' ), 'Dashboard must keep Setup permanently reachable through a compact action after first-run onboarding.' );
p1d_check( false !== strpos( $dashboard, "progress_completed" ) && false !== strpos( $dashboard, "progress_total" ), 'Dashboard onboarding callout must report real setup progress.' );
p1d_check( false !== strpos( $setup, 'Verification URL' ) && false !== strpos( $setup, 'Copy link' ) && false !== strpos( $setup, 'View credential' ) && false !== strpos( $setup, 'Certloom is ready. Issue your first credential when you are ready.' ), 'Setup v2 Finish must show real verification actions when a first credential exists and an honest deferred-issuance completion state otherwise.' );
p1d_check( false !== strpos( $onboarding, "'first_credential_id'" ) && false !== strpos( $onboarding, "'first_credential_series_id'" ) && false !== strpos( $onboarding, 'record_first_credential' ) && false !== strpos( $onboarding, "'wizard_completed_at'" ), 'Setup v2 must record real first-credential identity when issued while still allowing an explicit do-later completion.' );
p1d_check( false !== strpos( $setup, 'Setup progress' ) && false !== strpos( $onboarding, "'wizard_completed_steps'" ) && false !== strpos( $onboarding, "'progress_completed'" ) && false !== strpos( $setup, 'Save & finish later' ), 'Setup v2 must preserve resumable wizard progress while keeping legacy readiness reporting separate.' );
p1d_check( false !== strpos( $list_view, 'name="issue_from"' ) && false !== strpos( $list_view, 'name="issue_to"' ) && false !== strpos( $list_view, 'name="expiry_from"' ) && false !== strpos( $list_view, 'name="expiry_to"' ), 'Registry must expose the four planned date-bound filters.' );
p1d_check( false !== strpos( $database, "'issue_from'" ) && false !== strpos( $database, 'issue_date >= %s' ) && false !== strpos( $database, 'expiry_date <= %s' ), 'Database list query must enforce validated issue/expiry date boundaries.' );
p1d_check( false !== strpos( $database, 'ecapla_certverify_invalid_registry_date_range' ), 'Registry date ranges must fail closed when from/to ordering is invalid.' );
p1d_check( false !== strpos( $admin, 'certloom_registry_hidden_columns' ) && false !== strpos( $list, 'certloom_registry_hidden_columns' ), 'Column preferences must be stored per WordPress user and applied by the list table.' );
p1d_check( false !== strpos( $list_view, 'certverify_registry_columns_save' ) && false !== strpos( $list_view, 'name="issue_from"' ), 'Column preference save form must be nonce-protected and preserve active registry date state.' );
p1d_check( false !== strpos( $uninstall, '_transient_ecapla_certverify_bulk_' ) && false !== strpos( $uninstall, 'certverify_onboarding' ), 'Uninstall cleanup must include Phase 1D bulk sessions and onboarding state when explicit data deletion is requested.' );
p1d_check( false !== strpos( $product_navigation, "'certloom'" ) && false !== strpos( $product_navigation, "=> 'Home'" ) && false !== strpos( $product_navigation, "'certloom-domain-credentials'" ) && false !== strpos( $product_navigation, "=> 'Credentials'" ) && false !== strpos( $product_navigation, "'certloom-domain-programs'" ) && false !== strpos( $product_navigation, "=> 'Programs'" ) && false !== strpos( $product_navigation, "'certloom-templates'" ) && false !== strpos( $product_navigation, "=> 'Design'" ) && false !== strpos( $product_navigation, "'certloom-automations'" ) && false !== strpos( $product_navigation, "=> 'Automations'" ) && false !== strpos( $product_navigation, "'certloom-settings'" ) && false !== strpos( $product_navigation, "=> 'Settings'" ) && false === strpos( $product_navigation, "'certloom-certificates'    => 'Credentials'" ), 'Current regression protects one canonical six-section unified-workspace vocabulary.' );
p1d_check( false !== strpos( $admin, 'DomainFeatureGate::admin_preview_ready()' ) && false !== strpos( $admin, "'certloom-domain-recipients'" ) && false !== strpos( $admin, "'certloom-domain-programs'" ), 'Recipients/Programs shell entries must remain informational while normalized-domain preview is gated.' );
p1d_check( false !== strpos( $placeholder_view, 'workspace remains read-only' ) && false !== strpos( $placeholder_view, 'does not create placeholder records' ), 'Protected shell workspaces must use honest availability UX rather than fake controls.' );
foreach ( array( 'Overview', 'Recipient', 'Program', 'Status', 'Custom Fields', 'Verification', 'Activity', 'Actions' ) as $workspace_section ) { p1d_check( false !== strpos( $editor_view, '>' . $workspace_section . '<' ) || false !== strpos( $editor_view, "__( '$workspace_section', 'certloom' )" ) || false !== strpos( $editor_view, "esc_html_e( '$workspace_section', 'certloom' )" ), 'Credential workspace is missing planned section: ' . $workspace_section ); }
p1d_check( false !== strpos( $editor_view, 'Linked recipient profiles and WordPress-user connections are not available in this build yet' ) && false !== strpos( $editor_view, 'Legacy credential issued before the current Program and Batch model' ) && false !== strpos( $editor_view, 'Open Credential Management' ), 'Credential workspace must explain the Legacy Recipient/Program boundary in customer language and point to normalized Credential Management without inventing relationships.' );
p1d_check( false !== strpos( $editor_view, 'Open verification result' ) && false !== strpos( $editor_view, 'get_visible_fields' ), 'Credential workspace must expose a truthful verification section tied to public-field policy.' );
p1d_check( false !== strpos( $editor_view, 'Created' ) && false !== strpos( $editor_view, 'Last updated' ) && false !== strpos( $editor_view, 'Full credential history is not available for current-registry records yet' ), 'Activity workspace must show only truthful current-registry timestamps and explain the unavailable history in customer language.' );
p1d_check( false !== strpos( file_get_contents( $root . '/includes/class-ecapla-certverify-bulk-manager.php' ), 'Certificate changed after bulk review' ), 'Bulk commit must include optimistic-concurrency protection.' );
p1d_check( false !== strpos( $admin, 'maybe_redirect_after_activation' ) && false !== strpos( $bootstrap, 'certloom_activation_redirect_' ) && false !== strpos( $admin, 'isset( $_GET[\'activate-multi\'] )' ), 'First-run Setup continuation must be one-shot and skip bulk activation rather than permanently hijacking admin navigation.' );
preg_match( '/Version:\s+(1\.7\.0-alpha\.\d+(?:\.\d+)*)/', $bootstrap, $header_version );
preg_match( "/ECAPLA_CERTVERIFY_VERSION',\s*'(1\.7\.0-alpha\.\d+(?:\.\d+)*)'/", $bootstrap, $constant_version );
p1d_check( ! empty( $header_version[1] ) && ! empty( $constant_version[1] ) && $header_version[1] === $constant_version[1], 'Current build must use matching 1.7 alpha versioning in header and constant.' );
p1d_check( false !== strpos( $bootstrap, "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false" ) && false !== strpos( $bootstrap, "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false" ) && false !== strpos( $bootstrap, "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false" ), 'Phase 1D must leave all future-domain production gates disabled.' );

echo "PASS  Phase 1D reviewed bulk management and non-blocking onboarding are authority-safe\n";
echo "PASS  frozen selection, eligibility preview, local setup and data-preservation boundaries remain explicit\n\n{$checks} checks, 0 failures\n";
