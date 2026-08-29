<?php
/** Browser-only ADM-02 dashboard analytics fixture using the production view/CSS. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; public function __construct( $c = '', $m = '' ) { $this->message = $m; } public function get_error_message(){ return $this->message; } }
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function __( $v, $d = null ){ return $v; }
function _n( $s, $p, $n, $d = null ){ return 1 === (int) $n ? $s : $p; }
function esc_html__( $v, $d = null ){ return $v; }
function esc_attr__( $v, $d = null ){ return $v; }
function esc_html_e( $v, $d = null ){ echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $v, $d = null ){ echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function admin_url( $v = '' ){ return 'https://example.test/wp-admin/' . ltrim( (string) $v, '/' ); }
function add_query_arg( $args, $url ){ $sep = false === strpos( $url, '?' ) ? '?' : '&'; return $url . $sep . http_build_query( $args ); }
function absint( $v ){ return abs( (int) $v ); }
function sanitize_key( $v ){ return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function number_format_i18n( $v ){ return number_format( (int) $v ); }

class Ecapla_CertVerify_Admin { const CERTIFICATES_PAGE = 'certloom-certificates'; }
class Ecapla_CertVerify_Dashboard {
	public static function normalize_metrics( $m ){ return array_merge( array( 'total'=>0,'valid'=>0,'suspended'=>0,'withdrawn'=>0,'expired'=>0,'expiring_soon'=>0,'issued_this_month'=>0 ), is_array( $m ) ? $m : array() ); }
	public static function health_summary( $c ){ return array( 'overall'=>'good','checks'=>array() ); }
}
class Ecapla_CertVerify_Fields {
	public static function get_statuses(){ return array( 'valid'=>array( 'label'=>'Valid' ), 'expired'=>array( 'label'=>'Expired' ) ); }
}
class Ecapla_CertVerify_Database {
	public static function effective_status( $r ){ return isset( $r['status'] ) ? $r['status'] : 'valid'; }
}
class Ecapla_CertVerify_Validator { public static function format_date( $v ){ return (string) $v; } }

$dashboard = array(
	'metrics' => array( 'total'=>184,'valid'=>151,'suspended'=>4,'withdrawn'=>3,'expired'=>26,'expiring_soon'=>19,'issued_this_month'=>37 ),
	'recent' => array(
		array( 'id'=>1,'cert_number'=>'CERT-171-001','holder_name'=>'Ada Example','status'=>'valid','issue_date'=>'2026-08-26' ),
		array( 'id'=>2,'cert_number'=>'CERT-171-002','holder_name'=>'Sam Example','status'=>'expired','issue_date'=>'2026-08-25' ),
	),
	'health' => array( 'overall'=>'good','checks'=>array( array( 'label'=>'Authority','value'=>'Current production','status'=>'good' ) ) ),
	'legacy_review_count' => 0,
);
$dashboard_state = getenv( 'CERTLOOM_DASHBOARD_STATE' ) ?: 'ready';
if ( 'fresh' === $dashboard_state ) {
	$onboarding_summary = array(
		'completed'=>false, 'wizard_completed'=>false,
		'progress_completed'=>0, 'progress_total'=>6,
		'organization_ready'=>false, 'fields_ready'=>false, 'page_ready'=>false,
		'registry_ready'=>false, 'verification_tested'=>false, 'appearance_ready'=>false,
	);
} elseif ( 'wizard_complete' === $dashboard_state ) {
	$onboarding_summary = array(
		'completed'=>false, 'wizard_completed'=>true,
		'progress_completed'=>3, 'progress_total'=>6,
		'organization_ready'=>true, 'fields_ready'=>true, 'page_ready'=>true,
		'registry_ready'=>false, 'verification_tested'=>false, 'appearance_ready'=>false,
	);
} else {
	$onboarding_summary = array( 'completed'=>true, 'wizard_completed'=>true, 'progress_completed'=>6, 'progress_total'=>6 );
}
$mode = getenv( 'CERTLOOM_DASHBOARD_ANALYTICS' ) ?: 'present';
if ( 'present' === $mode ) {
	$issuance_trend = array();
	$verification_trend = array();
	for ( $i = 1; $i <= 30; $i++ ) {
		$issuance_trend[] = array( 'label'=>'Aug ' . $i . ', 2026', 'total'=>2 + ( $i % 7 ) );
		$verification_trend[] = array( 'label'=>'Aug ' . $i . ', 2026', 'success'=>8 + ( $i % 4 ), 'failed'=>1, 'invalid'=>( 0 === $i % 5 ? 2 : 0 ), 'expired'=>( 0 === $i % 6 ? 1 : 0 ), 'revoked'=>( 0 === $i % 10 ? 1 : 0 ) );
	}
	$dashboard_analytics = array(
		'verification' => array(
			'summary'=>array( 'total'=>342,'success'=>290,'failed'=>21,'invalid'=>17,'expired'=>9,'revoked'=>5 ),
			'trend'=>$verification_trend,
			'most_verified'=>array( array( 'certificate_number'=>'CERT-TOP-171','total'=>48 ) ),
		),
		'issuance' => array(
			'summary'=>array( 'total'=>126,'manual'=>48,'bulk'=>72,'other'=>6 ),
			'trend'=>$issuance_trend,
			'dimensions'=>array(
				'programs'=>array( array( 'label'=>'Leadership','total'=>52 ) ),
				'templates'=>array( array( 'label'=>'Premium A4','total'=>69 ) ),
			),
			'expiry_forecast'=>array( 'total_90'=>30 ),
		),
	);
}
$css = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
ob_start(); include dirname( __DIR__, 2 ) . '/admin/views/dashboard.php'; $view = ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 12px;border:1px solid #2271b1;background:#f6f7f7;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}.widefat{width:100%;border-collapse:collapse;background:#fff}.widefat th,.widefat td{padding:9px;border-bottom:1px solid #dcdcde;text-align:left}.certloom-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.screen-reader-text{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}.dashicons{display:inline-block}<?php echo $css; ?>
</style></head><body><?php echo $view; ?></body></html>
