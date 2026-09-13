<?php
/** Alpha.4.27 Credentials overview hierarchy, compact filters, and sorting contract. */
$root = dirname( __DIR__ );
$nav = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$repo = file_get_contents( $root . '/src/Infrastructure/Repository/CredentialRepository.php' );
$unified = file_get_contents( $root . '/src/Infrastructure/Repository/UnifiedCredentialRegistry.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$pass=0;$fail=0;
function c427($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
c427(false!==strpos($nav,'render_current_subnav')&&false!==strpos($nav,'page_renders_own_subnav'),'shared navigation supports page-owned final placement');
c427(false!==strpos($nav,"'certloom-domain-credentials'")&&false!==strpos($nav,"'certloom-issuance'"),'Credentials and Issue Credential suppress early admin_notices subnav output');
c427(false!==strpos($domain,"( new AdminProductNavigation() )->render_current_subnav();"),'Domain hero renders contextual navigation server-side immediately after hero');
c427(false!==strpos($js,"if ( nav.previousElementSibling !== hero )")&&false!==strpos($js,"hero.insertAdjacentElement( 'afterend', nav )"),'JavaScript relocation is idempotent legacy fallback rather than normal placement');
c427(false!==strpos($css,'alpha.4.27 — contextual navigation is rendered in its final server-side'),'visual contract documents no-flash navigation ownership');
c427(false!==strpos($domain,'certloom-credential-search-filter')&&false!==strpos($domain,'certloom-credential-program-filter'),'Credentials keeps explicit Search and Program filter groups');
c427(false!==strpos($domain,'data-certloom-context-compact="1"'),'Program lookup marks compact registry context');
c427(false!==strpos($css,'grid-template-columns: minmax(320px, 1fr) minmax(390px, 470px) auto'),'filter toolbar uses compact one-row desktop geometry');
c427(false!==strpos($css,'.certloom-credential-program-filter .certloom-context-lookup')&&false!==strpos($css,'"search select"'),'Program search and selector share one desktop row');
c427(false!==strpos($domain,'private function credential_sort_header')&&false!==strpos($domain,'certloom-sort-indicator'),'each registry heading is rendered as an accessible sort control');
c427(substr_count($domain,'$this->credential_sort_header(')>=8,'all eight detailed registry columns are sortable');
c427(false!==strpos($domain, '\'orderby\'           => $column')&&false!==strpos($domain, '\'order\'             => $next_order'),'sort links toggle direction via bounded query arguments');
c427(false!==strpos($domain,'aria-sort=')&&false!==strpos($domain,"__( 'ascending', 'certloom' )")&&false!==strpos($domain,"__( 'descending', 'certloom' )"),'sort direction is exposed accessibly');
c427(false!==strpos($domain,'name="orderby"')&&false!==strpos($domain,'name="order"'),'filter submissions preserve current sort');
c427(false!==strpos($domain,'$args[\'orderby\'] = $orderby')&&false!==strpos($domain,'$args[\'order\']   = strtolower( $order )'),'status views preserve active sort');
c427(false!==strpos($repo,'public function list_management_page( $search = \'\', $page = 1, $per_page = 20, $status = \'all\', $program_id = 0, $exclude_source_type = \'\', $orderby = \'recent\', $order = \'DESC\' )'),'normalized repository accepts bounded sort arguments');
c427(false!==strpos($repo,"case 'credential':")&&false!==strpos($repo,"case 'status':")&&false!==strpos($repo,"case 'added':"),'normalized repository sorts all requested semantic columns through strict switch expressions');
c427(false!==strpos($unified,'private function normalize_orderby')&&false!==strpos($unified,'compare_sort_values'),'unified current + historical registry applies one deterministic sort contract');
c427(false!==strpos($css,'.certloom-sort-link')&&false!==strpos($css,'.certloom-sort-indicator'),'sortable headings receive compact visual affordances');
c427(false===strpos($domain,'certloom-registry-secondary-actions'),'Credentials overview does not reintroduce duplicate Import CTA');
echo "\n$pass alpha.4.27 Credentials overview source checks, $fail failures\n"; exit($fail?1:0);
