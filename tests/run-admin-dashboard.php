<?php
/** Regression tests for the Phase 1 premium admin dashboard contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );

function __( $text ) { return $text; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function get_option( $key, $default = false ) { return 'legacy_review' === $key ? 3 : $default; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {}

class Ecapla_CertVerify_Database {
	const LEGACY_REVIEW_OPTION = 'legacy_review';
	public static $list_args = array();
	public static function get_dashboard_metrics() {
		return array(
			'total' => '17', 'valid' => 10, 'suspended' => 2, 'withdrawn' => 1,
			'expired' => 4, 'expiring_soon' => 3, 'issued_this_month' => 6,
		);
	}
	public static function get_list( $args = array() ) {
		self::$list_args = $args;
		$rows = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$rows[] = array( 'id' => $i, 'cert_number' => 'CERT-' . $i );
		}
		return array( 'items' => $rows, 'total' => 7 );
	}
}
class Ecapla_CertVerify_System_Status {
	public static function get_checks() {
		return array(
			array( 'id' => 'legacy_registry', 'label' => 'Registry', 'status' => 'good', 'value' => 'Ready' ),
			array( 'id' => 'authority', 'label' => 'Authority', 'status' => 'good', 'value' => 'Legacy' ),
			array( 'id' => 'https', 'label' => 'HTTPS', 'status' => 'warning', 'value' => 'Not detected' ),
			array( 'id' => 'database_proof', 'label' => 'Database proof', 'status' => 'warning', 'value' => 'Not run' ),
		);
	}
}

require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-dashboard.php';

function dashboard_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$data = Ecapla_CertVerify_Dashboard::get_data();
dashboard_assert( is_array( $data ), 'Dashboard data should be a stable array payload.' );
dashboard_assert( 17 === $data['metrics']['total'] && 10 === $data['metrics']['valid'], 'Dashboard metrics should normalize database values to integers.' );
dashboard_assert( 5 === count( $data['recent'] ), 'Dashboard should bound recent credentials to five rows.' );
dashboard_assert( 5 === Ecapla_CertVerify_Database::$list_args['per_page'], 'Dashboard should request only five recent rows from the repository.' );
dashboard_assert( 3 === $data['legacy_review_count'], 'Dashboard should surface the existing legacy review count without mutating it.' );
dashboard_assert( 'warning' === $data['health']['overall'], 'A current-production warning should be visible on the dashboard.' );

$health_ids = array();
foreach ( $data['health']['checks'] as $check ) {
	$health_ids[] = $check['id'];
}
dashboard_assert( in_array( 'https', $health_ids, true ), 'Current HTTPS health should be part of the everyday dashboard.' );
dashboard_assert( ! in_array( 'database_proof', $health_ids, true ), 'Development acceptance-proof warnings must stay on System Status instead of degrading the everyday dashboard.' );

$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$view  = file_get_contents( $root . '/admin/views/dashboard.php' );
if ( false === $admin || false === $view ) {
	throw new RuntimeException( 'Could not read premium admin sources.' );
}
dashboard_assert( false !== strpos( $admin, "const CERTIFICATES_PAGE = 'certloom-certificates';" ), 'Certificates need a dedicated submenu route once the root becomes Dashboard.' );
dashboard_assert( false !== strpos( $admin, "array( \$this, 'render_dashboard_screen' )" ), 'Top-level Certloom route should render the premium dashboard.' );
dashboard_assert( false !== strpos( $view, 'Recent credentials' ) && false !== strpos( $view, 'Total credentials' ) && false !== strpos( $view, 'Needs attention' ), 'Unified Dashboard should expose dense current-credential operational views.' );
dashboard_assert( false === strpos( $view, 'AdminUiMode::' ) && false === strpos( $view, '$certloom_simple_normalized' ), 'Dashboard presentation must not depend on the retired Simple/Advanced workspace mode.' );
dashboard_assert( false !== strpos( $view, 'certloom-dashboard-quick-grid' ) && false !== strpos( $view, "'Manage Fields', 'certloom'" ) && false !== strpos( $view, "'Email Designs', 'certloom'" ) && false !== strpos( $view, "'Integrations', 'certloom'" ), 'Dashboard should expose the primary shortcuts as a compact keypad grid with Manage Fields first.' );
dashboard_assert( false !== strpos( $view, "'Backup & Restore', 'certloom'" ) && false !== strpos( $view, "'Analytics', 'certloom'" ) && false !== strpos( $view, "'Verification page', 'certloom'" ) && false === strpos( $view, 'certloom-dashboard-more-tools' ), 'Dashboard should expose Backup & Restore, Analytics and Verification page as the third keypad row without a secondary More tools list.' );
dashboard_assert( false !== strpos( $view, "'Program / batch', 'certloom'" ) && false !== strpos( $view, "'Expiry date', 'certloom'" ) && false !== strpos( $view, "'Credential status', 'certloom'" ) && false !== strpos( $view, "'Record version', 'certloom'" ) && false !== strpos( $view, "'Added to Certloom', 'certloom'" ), 'Recent credentials should reuse the registry column vocabulary instead of a second card-list presentation.' );
dashboard_assert( false !== strpos( $view, 'Credential authority and storage' ) && false !== strpos( $view, 'certloom-dashboard-data-safety certloom-technical-details' ), 'Data safety should remain available under a deliberate disclosure instead of occupying the primary workflow.' );
dashboard_assert( false !== strpos( $admin, "current_user_can( Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS )" ), 'ADM-02 preview must require the granular analytics capability.' );
dashboard_assert( false !== strpos( $admin, 'Ecapla_CertVerify_Analytics::report( 30 )' ) && false !== strpos( $admin, 'IssuanceAnalyticsService() )->report( 30 )' ), 'ADM-02 preview must use the canonical 30-day ANL-01 and ANL-02 reports.' );
dashboard_assert( false !== strpos( $admin, '! is_wp_error( $verification_analytics ) && ! is_wp_error( $issuance_analytics )' ), 'ADM-02 preview must fail closed if either analytics report is unavailable.' );
dashboard_assert( false !== strpos( $view, 'Credential analytics' ) && false !== strpos( $view, 'Verification lookups' ) && false !== strpos( $view, 'Failed lookups' ), 'ADM-02 should expose verification and failed-lookup summaries.' );
dashboard_assert( false !== strpos( $view, 'Issuance trend' ) && false !== strpos( $view, 'Verification trend' ), 'ADM-02 should expose both canonical trend previews.' );
dashboard_assert( false !== strpos( $view, 'Top credential' ) && false !== strpos( $view, 'Top Program' ) && false !== strpos( $view, 'Top certificate design' ), 'ADM-02 should expose the promised top credential, Program and certificate-design leaders.' );
dashboard_assert( false !== strpos( $view, 'Expiring ≤90 days' ), 'ADM-02 should expose the current ANL-02 90-day expiry forecast without claiming renewal automation.' );
foreach ( array( 'update_option(', 'delete_option(', '->insert(', '->update(', '->delete(' ) as $mutation ) {
	dashboard_assert( false === strpos( $view, $mutation ), 'Viewing the dashboard must remain read-only; found mutation primitive ' . $mutation );
}

echo "PASS  premium admin dashboard is bounded, read-only, authority-safe and operationally useful\n";
echo "\n21 checks, 0 failures\n";
