<?php
/** Source contract for Phase 4 dashboard visual-review corrections. */
$root       = dirname( __DIR__ );
$foundation = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$admin_css  = file_get_contents( $root . '/admin/assets/admin.css' );
$dashboard  = file_get_contents( $root . '/admin/views/dashboard.php' );
$admin_php  = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );

$checks   = 0;
$failures = array();
function p4dash_check( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

p4dash_check( false !== strpos( $foundation, 'clear: both;' ), 'Certloom admin shell clears native WordPress Help/Screen Options floats' );
p4dash_check( false !== strpos( $dashboard, 'certloom-setup-reminder-actions' ), 'setup reminder owns a dedicated responsive action group' );
p4dash_check( false !== strpos( $dashboard, 'certloom-setup-reminder-review' ), 'setup reminder review action has an explicit semantic hook' );
p4dash_check( false !== strpos( $foundation, '.certloom-admin-shell .certloom-setup-reminder-actions' ), 'setup reminder action group is styled by current foundation authority' );
p4dash_check( false !== strpos( $foundation, '.certloom-admin-shell .certloom-setup-reminder-dismiss .button-link' ) && false !== strpos( $foundation, 'width: auto;' ) && false !== strpos( $foundation, 'white-space: nowrap;' ), 'long reminder actions are no longer forced into 30px square controls' );
p4dash_check( false === strpos( $dashboard, '\\t</div>' ), 'literal backslash-t output is absent from dashboard markup' );
p4dash_check( false !== strpos( $dashboard, "esc_html_e( 'Dates', 'certloom' )" ) && false !== strpos( $dashboard, "esc_html_e( 'Issued', 'certloom' )" ) && false !== strpos( $dashboard, "esc_html_e( 'Expires', 'certloom' )" ) && false === strpos( $dashboard, "esc_html_e( 'Validity', 'certloom' )" ), 'dashboard recent-activity table uses one compact Dates column with explicit issued/expiry rows' );
p4dash_check( false !== strpos( $dashboard, "\$certloom_dashboard_date_only" ) && false !== strpos( $dashboard, "preg_match( '/^(\\d{4}-\\d{2}-\\d{2})/'" ), 'dashboard date summary strips timestamp detail to date-only values' );
p4dash_check( false !== strpos( $dashboard, "\$is_historical ? __( 'Manage', 'certloom' ) : __( 'Edit', 'certloom' )" ), 'current Dashboard credentials expose Edit while historical credentials preserve Manage' );
p4dash_check( false !== strpos( $dashboard, "class_exists( '\\Certloom\\Infrastructure\\Issuance\\NormalizedPublicCredentialUrl' )" ) && false === strpos( $dashboard, "class_exists( '\\Certloom\\Infrastructure\\Verification\\PublicVerificationGate' ) && ( new \\Certloom\\Infrastructure\\Verification\\PublicVerificationGate() )->is_ready()" ), 'current Dashboard credentials retain View whenever their stable public URL can be built' );
p4dash_check( false === strpos( $dashboard, "<th scope=\"col\"><?php esc_html_e( 'Record version', 'certloom' ); ?></th>" ), 'dashboard summary no longer duplicates deep record-version metadata' );
p4dash_check( false === strpos( $dashboard, "<th scope=\"col\"><?php esc_html_e( 'Added to Certloom', 'certloom' ); ?></th>" ), 'dashboard summary no longer duplicates deep added-at metadata' );
p4dash_check( false !== strpos( $foundation, '.certloom-admin-shell .certloom-dashboard-recent-table' ) && false !== strpos( $foundation, 'min-width: 0 !important;' ) && false !== strpos( $foundation, 'width: 100% !important;' ) && false !== strpos( $foundation, 'table-layout: fixed;' ), 'desktop dashboard table hard-overrides registry 1120px min-width and fits its panel' );
p4dash_check( false !== strpos( $foundation, 'th:nth-child(2) { width: 31%; }' ) && false !== strpos( $foundation, 'th:nth-child(4) { width: 22%; }' ) && false !== strpos( $foundation, 'th:nth-child(5) { width: 16%; }' ), 'dashboard column balance reduces Recipient width while reserving more room for Dates and Status' );
p4dash_check( false !== strpos( $foundation, 'padding-inline-end: 14px;' ) && false !== strpos( $foundation, 'padding-inline-start: 16px;' ), 'Program / batch and Dates have deliberate internal separation without expanding table width' );
p4dash_check( false !== strpos( $foundation, '.certloom-admin-shell .certloom-dashboard-recent-table td::before' ) && false !== strpos( $foundation, 'content: attr(data-col);' ), 'mobile dashboard activity rows expose accessible compact labels without horizontal scrolling' );
p4dash_check( is_file( $root . '/admin/assets/certloom-brand-mark.png' ) && hash_file( 'sha256', $root . '/admin/assets/certloom-brand-mark.png' ) === '1173edd257e9f0808dee4ad2733caca251fc709c69753687f6b37151935c6450', 'user-approved Certloom watermark asset remains byte-identical' );
p4dash_check( false !== strpos( $foundation, 'opacity: .115;' ) && false !== strpos( $foundation, 'right: -34px;' ), 'user-approved watermark scale/crop treatment remains unchanged' );

p4dash_check( false !== strpos( $dashboard, "array( 'good', 'warning', 'bad' )" ), 'system-health status is normalized to the supported semantic states' );
p4dash_check( false !== strpos( $admin_css, '.certloom-health-panel.is-good { border-top-color: var(--cl-valid-mark); }' ) && false !== strpos( $admin_css, '.certloom-health-panel.is-warning { border-top-color: var(--cl-expired); }' ) && false !== strpos( $admin_css, '.certloom-health-panel.is-bad { border-top-color: var(--cl-error); }' ), 'system-health panel border follows good/warning/bad semantics' );
p4dash_check( false !== strpos( $admin_css, '.certloom-health-panel.is-good .certloom-health-dot' ) && false !== strpos( $admin_css, 'background: var(--cl-valid-mark);' ) && false !== strpos( $admin_css, 'box-shadow: 0 0 0 5px var(--cl-valid-wash);' ), 'ready system-health dot is explicitly green' );
p4dash_check( false !== strpos( $admin_css, '.certloom-health-panel.is-warning .certloom-health-dot' ) && false !== strpos( $admin_css, 'background: var(--cl-expired);' ), 'warning system-health dot uses warning verdict colour' );
p4dash_check( false !== strpos( $admin_css, '.certloom-health-panel.is-bad .certloom-health-dot' ) && false !== strpos( $admin_css, 'background: var(--cl-error);' ), 'bad system-health dot uses error verdict colour' );
p4dash_check( false !== strpos( $admin_php, "esc_html( 'Ecapla' )" ) && false === strpos( $admin_php, "esc_html__( 'Ecapla', 'certloom' )" ), 'Ecapla brand capitalization is canonical and not translator-variable in the admin footer' );

if ( $failures ) {
	echo 'FAIL  Phase 4 dashboard review source contract: ' . implode( ', ', $failures ) . "\n\n";
	echo ( $checks - count( $failures ) ) . ' passed, ' . count( $failures ) . " failures\n";
	exit( 1 );
}

echo "PASS  Phase 4 dashboard review source contract\n\n";
echo $checks . " checks, 0 failures\n";
