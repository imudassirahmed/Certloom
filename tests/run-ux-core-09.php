<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$editor = file_get_contents( $root . '/admin/views/edit-certificate.php' );
$nav = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
$passed = 0; $failed = 0;
function ux9( $ok, $label ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS: $label\n"; } else { $failed++; echo "FAIL: $label\n"; } }
ux9( false !== strpos( $admin, "DomainFeatureGate::admin_preview_ready() ) {\n\t\t\t// The historical list slug remains a compatibility route" ), 'Legacy list registration has an explicit normalized-preview compatibility branch' );
ux9( false !== strpos( $admin, "add_submenu_page( null, __( 'Credentials', 'certloom' ), __( 'Credentials', 'certloom' ), ECAPLA_CERTVERIFY_CAP, self::CERTIFICATES_PAGE" ), 'Legacy list slug is hidden-but-routable in normalized preview so WordPress does not 403 before callback' );
ux9( false !== strpos( $admin, '$success_url = $id ? $back_url : admin_url' ), 'Existing Legacy save returns to the same edit workspace instead of the retired list route' );
ux9( false !== strpos( $editor, "isset( \$_GET['ecapla_certverify_saved'] )" ) && false !== strpos( $editor, "Saved." ), 'Legacy editor renders an explicit post-save success notice' );
ux9( false !== strpos( $editor, "admin.php?page=certloom-domain-credentials" ) && false !== strpos( $editor, '$ecapla_certverify_credentials_url' ), 'Legacy editor Back/Cancel resolve to canonical Credentials during normalized preview' );
ux9( false !== strpos( $admin, "'credential_status'" ) && false !== strpos( $admin, "'withdrawn' === \$status" ) && false !== strpos( $admin, "'revoked'" ), 'Compatibility list redirect translates common legacy status filters to canonical registry semantics' );
ux9( false !== strpos( $admin, "! empty( \$_GET['legacy_expired'] )" ) && false !== strpos( $admin, "\$status = 'expired'" ), 'Legacy Expired review links survive compatibility redirect without a dead route' );
ux9( false !== strpos( $nav, "'certloom-add', 'certloom-certificates', 'certloom-domain-credentials'" ), 'Navigation parent mapping keeps the compatibility route inside Certloom' );
ux9( false === strpos( $editor, "href=\"<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>\"" ), 'Legacy editor no longer hard-codes dead Back/Cancel links' );
echo "UX-CORE-09: {$passed}/" . ( $passed + $failed ) . " passed\n";
exit( $failed ? 1 : 0 );
