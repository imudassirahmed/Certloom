<?php
/** Phase 3 guided Dashboard tour source/state contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['certloom_test_user_meta'] = array();
$GLOBALS['certloom_test_now'] = '2026-09-09 06:30:00';

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function get_current_user_id() { return 17; }
function get_user_meta( $user_id, $key, $single = false ) { return $GLOBALS['certloom_test_user_meta'][ $user_id ][ $key ] ?? ( $single ? '' : array() ); }
function update_user_meta( $user_id, $key, $value ) { $GLOBALS['certloom_test_user_meta'][ $user_id ][ $key ] = $value; return 1; }
function current_time( $type ) { return $GLOBALS['certloom_test_now']; }

require dirname( __DIR__ ) . '/src/Application/Setup/PurposePresetEngine.php';
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-guided-tour.php';

$checks = 0; $failures = array();
function tour_check( $condition, $label ) { global $checks, $failures; $checks++; if ( ! $condition ) { $failures[] = $label; } }

$defaults = Ecapla_CertVerify_Guided_Tour::defaults();
tour_check( 'new' === $defaults['status'], 'default status new' );
tour_check( 0 === $defaults['last_step'], 'default last step zero' );
tour_check( 1 === $defaults['version'], 'versioned state' );

$steps = Ecapla_CertVerify_Guided_Tour::steps( 'iso_management' );
tour_check( 6 === count( $steps ), 'exact six steps' );
tour_check( array( 'issue','fields','programs','design','automations','analytics' ) === array_column( $steps, 'id' ), 'stable six step ids' );
tour_check( false !== strpos( $steps[2]['body'], 'certification scheme' ), 'profile aware Program copy' );
foreach ( $steps as $index => $step ) {
	tour_check( '' !== $step['target'], 'real target selector ' . $index );
	tour_check( '' !== $step['title'], 'translated title ' . $index );
	tour_check( '' !== $step['body'], 'meaningful body ' . $index );
	tour_check( '' !== $step['action'], 'real action label ' . $index );
}

$state = Ecapla_CertVerify_Guided_Tour::get_state( 17 );
tour_check( 'new' === $state['status'], 'new user state' );
tour_check( ! Ecapla_CertVerify_Guided_Tour::should_auto_start( $state, false ), 'does not auto start before onboarding' );
tour_check( Ecapla_CertVerify_Guided_Tour::should_auto_start( $state, true ), 'auto starts after onboarding' );

$result = Ecapla_CertVerify_Guided_Tour::apply_event( 'start', 2, 17 );
tour_check( true === $result, 'start saves' );
$state = Ecapla_CertVerify_Guided_Tour::get_state( 17 );
tour_check( 'in_progress' === $state['status'], 'start in progress' );
tour_check( 2 === $state['last_step'], 'start saves current step' );
tour_check( '' !== $state['started_at'], 'start timestamp' );
tour_check( Ecapla_CertVerify_Guided_Tour::should_auto_start( $state, true ), 'in progress resumes automatically' );

$result = Ecapla_CertVerify_Guided_Tour::apply_event( 'progress', 4, 17 );
tour_check( true === $result, 'progress saves' );
tour_check( 4 === Ecapla_CertVerify_Guided_Tour::get_state( 17 )['last_step'], 'progress step four' );

$result = Ecapla_CertVerify_Guided_Tour::apply_event( 'dismiss', 4, 17 );
tour_check( true === $result, 'dismiss saves' );
$state = Ecapla_CertVerify_Guided_Tour::get_state( 17 );
tour_check( 'dismissed' === $state['status'], 'dismissed status' );
tour_check( '' !== $state['dismissed_at'], 'dismissed timestamp' );
tour_check( ! Ecapla_CertVerify_Guided_Tour::should_auto_start( $state, true ), 'dismiss prevents auto start' );

$result = Ecapla_CertVerify_Guided_Tour::apply_event( 'restart', 5, 17 );
tour_check( true === $result, 'restart saves' );
$state = Ecapla_CertVerify_Guided_Tour::get_state( 17 );
tour_check( 'in_progress' === $state['status'], 'restart in progress' );
tour_check( 0 === $state['last_step'], 'restart begins step zero' );
tour_check( '' === $state['dismissed_at'], 'restart clears dismissal' );

$result = Ecapla_CertVerify_Guided_Tour::apply_event( 'complete', 5, 17 );
tour_check( true === $result, 'complete saves' );
$state = Ecapla_CertVerify_Guided_Tour::get_state( 17 );
tour_check( 'completed' === $state['status'], 'completed status' );
tour_check( 5 === $state['last_step'], 'complete pins final step' );
tour_check( '' !== $state['completed_at'], 'completed timestamp' );
tour_check( ! Ecapla_CertVerify_Guided_Tour::should_auto_start( $state, true ), 'complete prevents auto start' );

$result = Ecapla_CertVerify_Guided_Tour::apply_event( 'wrong', 0, 17 );
tour_check( is_wp_error( $result ), 'invalid event fails closed' );

$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$view = file_get_contents( $root . '/admin/views/dashboard.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$plugin = file_get_contents( $root . '/certloom.php' );

tour_check( false !== strpos( $plugin, "class-ecapla-certverify-guided-tour.php" ), 'tour class loaded by plugin' );
tour_check( false !== strpos( $admin, "wp_ajax_certloom_guided_tour_state" ), 'nonce protected ajax route registered' );
tour_check( false !== strpos( $admin, "register_dashboard_help" ), 'Dashboard Help registration' );
tour_check( false !== strpos( $admin, "add_help_tab" ) && false !== strpos( $admin, "Product tour" ), 'WordPress Help replay entry' );
tour_check( false !== strpos( $admin, "certloom_tour' => 'restart'" ), 'Help can restart tour' );
tour_check( false !== strpos( $admin, 'should_auto_start' ), 'Dashboard honors post-onboarding auto start' );
tour_check( false !== strpos( $view, 'data-certloom-guided-tour' ), 'Dashboard has tour region' );
tour_check( false !== strpos( $view, 'aria-live="polite"' ), 'tour announces step changes' );
tour_check( false === strpos( $view, 'aria-modal="true"' ), 'tour is not modal' );
tour_check( false !== strpos( $view, 'Dismiss tour' ), 'explicit permanent dismiss control' );
tour_check( false !== strpos( $view, 'replay it later from the WordPress Help tab' ), 'replay guidance visible' );
tour_check( false !== strpos( $js, 'function initGuidedDashboardTour()' ), 'tour JS initializer' );
tour_check( false !== strpos( $js, "persist( 'progress', current )" ), 'step progress persisted' );
tour_check( false !== strpos( $js, "persist( 'complete', current )" ), 'completion persisted' );
tour_check( false !== strpos( $js, "closeTour( true )" ), 'dismiss persisted' );
tour_check( false !== strpos( $js, 'certloom-guided-tour-target' ), 'real targets highlighted' );
tour_check( false !== strpos( $css, '.certloom-guided-tour' ), 'tour CSS exists' );
tour_check( false !== strpos( $css, 'pointer-events: none' ) && false !== strpos( $css, 'pointer-events: auto' ), 'tour remains non-blocking outside coachmark' );
tour_check( false !== strpos( $css, '@media (forced-colors: active)' ), 'forced colors covered' );

if ( $failures ) {
	fwrite( STDERR, 'FAIL guided tour: ' . implode( ', ', $failures ) . "\n" );
	exit( 1 );
}
echo "PASS Phase 3 guided Dashboard tour source/state contract\n\n{$checks} guided-tour checks, 0 failures\n";
