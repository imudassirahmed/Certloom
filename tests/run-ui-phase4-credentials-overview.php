<?php
/** Phase 4 screenshot-review contract for the Credentials overview hierarchy. */
$root = dirname( __DIR__ );
$nav = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$pass=0;$fail=0;
$render_start = strpos( $domain, 'public function render_credentials()' );
$render_end = false !== $render_start ? strpos( $domain, '/** Read-only adapter view', $render_start ) : false;
$render_credentials = false !== $render_start && false !== $render_end ? substr( $domain, $render_start, $render_end - $render_start ) : '';
function c425($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
c425(false!==strpos($nav,'data-certloom-product-subnav'),'contextual subnav is marked for workspace placement');
c425(false!==strpos($js,'function initProductSubnavPlacement()')&&false!==strpos($js,"hero.insertAdjacentElement( 'afterend', nav )")&&false!==strpos($js,"nav.classList.add( 'is-below-hero' )"),'admin interaction moves subtabs directly below the hero');
c425(false!==strpos($css,'.certloom-product-subnav.is-below-hero')&&false!==strpos($css,'margin: 12px 0 16px'),'relocated subtabs align with the workspace width and hierarchy');
c425(false===strpos($domain,'certloom-registry-secondary-actions'),'Credentials overview removes the duplicate Import / issue from file button');
c425(false!==strpos($domain,'$this->notice( false );')&&false!==strpos($domain,'$this->technical_status_notice();'),'operational notices stay near the top while technical status is deferred below registry work');
c425(false!==strpos($domain,'certloom-domain-gate-notice-deferred'),'deferred technical status remains explicitly identifiable and available');
c425(false!==strpos($domain,'certloom-credential-search-filter')&&false!==strpos($domain,"esc_html__( 'Search', 'certloom' )")&&false!==strpos($domain,'certloom-credential-program-filter')&&false!==strpos($domain,"esc_html__( 'Program', 'certloom' )"),'Search and Program filters have visible grouping labels');
c425(false!==strpos($css,'.certloom-domain-screen .certloom-credential-filters')&&false!==strpos($css,'grid-template-columns: minmax(280px, 1fr) minmax(220px, 280px) auto'),'Credentials filters use a deliberate desktop grid instead of an accidental wrap');
c425(false!==strpos($domain,'$public_result = $url_builder->verification_url')&&false!==strpos($domain,"__( 'View credential', 'certloom' )"),'current Credential rows preserve stable public View continuity when a public ID can build a URL');
c425(''!==$render_credentials&&false===strpos($render_credentials,'$public_ready ? $url_builder->verification_url'),'Credentials row View action is no longer silently hidden by the visual-development readiness gate');
echo "\n$pass alpha.4.25 Credentials overview source checks, $fail failures\n"; exit($fail?1:0);
