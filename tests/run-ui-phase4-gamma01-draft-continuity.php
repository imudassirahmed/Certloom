<?php
/** Phase 4 Gamma-01 Anchor — per-admin draft continuity contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
$GLOBALS['cl_gamma_meta'] = array();
$GLOBALS['cl_gamma_user'] = 10;
$GLOBALS['cl_gamma_uuid'] = 0;
$GLOBALS['cl_gamma_now'] = '2026-09-13 06:45:00';
function __( $text ){ return $text; }
function sanitize_key( $value ){ return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ){ return trim( strip_tags( (string) $value ) ); }
function absint( $value ){ return abs( (int) $value ); }
function get_current_user_id(){ return (int) $GLOBALS['cl_gamma_user']; }
function get_user_meta( $user_id, $key, $single = false ){ return $GLOBALS['cl_gamma_meta'][ $user_id ][ $key ] ?? ( $single ? '' : array() ); }
function update_user_meta( $user_id, $key, $value ){ $GLOBALS['cl_gamma_meta'][ $user_id ][ $key ] = $value; return 1; }
function delete_user_meta( $user_id, $key ){ unset( $GLOBALS['cl_gamma_meta'][ $user_id ][ $key ] ); return true; }
function wp_generate_uuid4(){ $GLOBALS['cl_gamma_uuid']++; return '10000000-0000-4000-8000-' . str_pad( (string) $GLOBALS['cl_gamma_uuid'], 12, '0', STR_PAD_LEFT ); }
function current_time( $type ){ return $GLOBALS['cl_gamma_now']; }
class WP_Error { private $message; public function __construct( $code = '', $message = '' ){ $this->message = $message; } public function get_error_message(){ return $this->message; } }

require __DIR__ . '/support/draft-database.php';
require dirname( __DIR__ ) . '/src/Application/Admin/DraftWorkspace.php';
use Certloom\Application\Admin\DraftWorkspace;

$checks = 0;
function gamma_check( $ok, $label ){ global $checks; if ( ! $ok ) { throw new RuntimeException( $label ); } $checks++; }

$first = DraftWorkspace::save( 'setup', array( 'wizard_draft' => array( 'organization_name' => 'Ecapla' ) ), array( 'step' => 'organization', 'client_revision' => 1000 ) );
gamma_check( is_array( $first ) && ! empty( $first['run_id'] ), 'First autosave must create a stable Setup run ID.' );
$run = $first['run_id'];
$second = DraftWorkspace::save( 'setup', array( 'wizard_draft' => array( 'organization_name' => 'Ecapla Ltd' ) ), array( 'step' => 'organization', 'client_revision' => 2000 ) );
gamma_check( $run === $second['run_id'], 'Repeated autosaves must stay in the same run.' );
gamma_check( 'Ecapla Ltd' === $second['payload']['wizard_draft']['organization_name'], 'Newer draft data must replace earlier draft data.' );
$stale = DraftWorkspace::save( 'setup', array( 'wizard_draft' => array( 'organization_name' => 'STALE' ) ), array( 'step' => 'organization', 'client_revision' => 1500 ) );
gamma_check( 'Ecapla Ltd' === $stale['payload']['wizard_draft']['organization_name'], 'Out-of-order autosave requests must not overwrite newer draft data.' );

$GLOBALS['cl_gamma_user'] = 20;
gamma_check( array() === DraftWorkspace::get( 'setup' ), 'A second administrator must not inherit another administrator draft.' );
$other = DraftWorkspace::save( 'setup', array( 'wizard_draft' => array( 'organization_name' => 'Other Org' ) ), array( 'step' => 'profile', 'client_revision' => 3000 ) );
gamma_check( $run !== $other['run_id'], 'Each administrator gets an independent Setup run.' );
$GLOBALS['cl_gamma_user'] = 10;
gamma_check( 'Ecapla Ltd' === DraftWorkspace::get( 'setup' )['payload']['wizard_draft']['organization_name'], 'Returning to the first administrator must restore that administrator draft.' );
$new = DraftWorkspace::start_new( 'setup', array(), array( 'step' => 'welcome' ) );
gamma_check( $run !== $new['run_id'] && 'welcome' === $new['step'], 'Start new setup must create a fresh run rather than mutate the previous run identity.' );
$cleared = DraftWorkspace::clear( 'setup' );
gamma_check( true === $cleared && array() === DraftWorkspace::get( 'setup' ), 'Explicit discard must clear only the current administrator workspace.' );

$root = dirname( __DIR__ );
$onboarding = file_get_contents( $root . '/includes/class-ecapla-certverify-onboarding.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$view = file_get_contents( $root . '/admin/views/setup-wizard.php' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
foreach ( array( $onboarding, $admin, $js, $view, $css ) as $source ) { gamma_check( is_string( $source ) && '' !== $source, 'Gamma-01 source must be readable.' ); }
gamma_check( false !== strpos( $onboarding, 'autosave_wizard_draft' ) && false !== strpos( $onboarding, 'DraftWorkspace::save' ), 'Setup state must expose a lightweight per-user autosave path.' );
gamma_check( false !== strpos( $admin, "wp_ajax_certloom_setup_draft_autosave" ) && false !== strpos( $admin, 'render_active_setup_draft_notice' ), 'Admin must register autosave and a return-to-Setup surface.' );
gamma_check( false !== strpos( $js, "body.set( 'action', 'certloom_setup_draft_autosave' )" ) && false !== strpos( $js, 'keepalive: !!keepalive' ) && false !== strpos( $js, "addEventListener( 'pagehide'" ), 'Browser continuity must autosave normally and during navigation/reload.' );
gamma_check( false !== strpos( $js, 'certloom_draft_revision' ) && false !== strpos( $js, "root.addEventListener( 'click'" ), 'Autosave must use monotonic client revisions and save before leaving through Setup links.' );
gamma_check( false !== strpos( $view, 'data-certloom-draft-run' ) && false !== strpos( $view, 'data-certloom-draft-status' ), 'Setup must expose stable run identity and visible autosave state.' );
gamma_check( false !== strpos( $css, '.certloom-setup-v2-draft-status') && false !== strpos( $css, '.certloom-active-draft-notice' ), 'Draft continuity status/return UI must have bounded design-system styling.' );

printf( "PASS  Phase 4 Gamma-01 Anchor draft continuity\n\n%d checks, 0 failures\n", $checks );
