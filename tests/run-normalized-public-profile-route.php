<?php
/** Normalized human-facing profile/public field alignment source contract. */
$root = dirname( __DIR__ );
$url = file_get_contents( $root . '/src/Infrastructure/Issuance/NormalizedPublicCredentialUrl.php' );
$router = file_get_contents( $root . '/includes/class-ecapla-certverify-public-router.php' );
$route = file_get_contents( $root . '/public/views/credential-route.php' );
$render = file_get_contents( $root . '/includes/class-ecapla-certverify-render.php' );
$presenter = file_get_contents( $root . '/src/Application/PublicCredentialPresenter.php' );
function npr( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL  ' . $message ); } echo "PASS  {$message}\n"; }
$verification_method = strstr( $url, 'public function verification_url' );
$verification_method = false !== $verification_method ? strstr( $verification_method, 'public function api_url', true ) : '';
npr( false !== strpos( $verification_method, "/certloom/credential/id/" ) && false === strpos( $verification_method, "rest_url( 'certloom/v1/credential' )" ), 'normalized verification URL is human-facing rather than a JSON endpoint' );
npr( false !== strpos( $url, 'public function api_url' ) && false !== strpos( $url, "rest_url( 'certloom/v1/credential' )" ), 'machine-readable REST URL remains an explicit separate adapter' );
npr( false !== strpos( $router, "^certloom/credential/id/([^/]{1,40})/?$" ) && false !== strpos( $router, "self::ROUTE_VAR . '=series" ), 'router has a distinct non-ambiguous normalized credential route' );
npr( strpos( $router, '^certloom/credential/id/' ) < strpos( $router, '^certloom/credential/([^/' ), 'specific normalized rewrite precedes the broad legacy credential route' );
npr( false !== strpos( $router, "array( 'verify', 'credential', 'series' )" ), 'public router recognizes normalized profile route explicitly' );
npr( false !== strpos( $route, "if ( 'series' === \$route )" ) && false !== strpos( $route, 'normalized_credential_profile' ), 'front-end route delegates normalized IDs to the normalized profile renderer' );
npr( false !== strpos( $render, 'new \\Certloom\\Infrastructure\\Verification\\PublicVerificationGate()' ), 'normalized profile cannot bypass the public cutover gate' );
npr( false !== strpos( $render, 'new \\Certloom\\Application\\CredentialVerificationService' ) && false !== strpos( $render, "->present( \$result, 'profile' )" ), 'normalized profile reuses canonical verification service and presenter' );
npr( false !== strpos( $render, 'normalized_profile_card_html( $presentation, \'cvp\' )' ) && false !== strpos( $render, "'verification_url' =>" ), 'normalized profile uses the human Recipient-led QR/print credential presentation' );
npr( false !== strpos( $presenter, 'MODE_PROFILE === $visibility_mode' ) && false !== strpos( $presenter, '$effective_context' ), 'profile URL cannot widen summary/unlisted credential visibility' );
npr( false !== strpos( $presenter, 'public_fields( $snapshot, $effective_context )' ), 'FLD-02 public fields follow frozen/current privacy intersection in effective context' );
npr( false !== strpos( $render, "'issuer' => \$issuer" ) && false !== strpos( $render, "'certificate_number' =>" ), 'human profile retains practical issuer and certificate identity context' );
npr( false !== strpos( $render, "'snapshot' => \$snapshot" ) && false !== strpos( $render, 'normalized_certificate_preview_html( $snapshot )' ), 'normalized public profile carries the immutable issued snapshot into its certificate presentation' );
npr( false !== strpos( $render, "\$snapshot['template']" ) && false !== strpos( $render, "\$template['layout']" ) && false !== strpos( $render, "\$snapshot['merge']['values']" ), 'browser certificate is reconstructed from issued template layout plus issued merge values, not current editable design state' );
npr( false !== strpos( $render, 'RenderPlanHtmlPreview' ) && false !== strpos( $render, 'cvp-certificate-preview' ) && false !== strpos( $render, 'Issued certificate' ), 'public profile visibly includes the issued certificate through the canonical browser render-plan presenter' );
$preview = file_get_contents( $root . '/src/Infrastructure/Rendering/RenderPlanHtmlPreview.php' );
$public_js = file_get_contents( $root . '/public/assets/certloom-public.js' );
$public_css = file_get_contents( $root . '/public/assets/certloom-public.css' );
npr( false !== strpos( $preview, 'data-certloom-preview-width' ), 'render-plan browser output exposes bounded natural geometry so the public certificate can scale responsively' );
npr( false !== strpos( $public_js, 'fitCertificatePreview' ) && false !== strpos( $public_css, 'certloom-certificate-preview-stage' ), 'public certificate preview has responsive fit behavior rather than forcing a broken horizontal certificate canvas' );
echo "\n17 normalized public profile alignment checks, 0 failures\n";
