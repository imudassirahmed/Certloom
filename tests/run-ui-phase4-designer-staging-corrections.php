<?php
/** Alpha.4.35 Designer/publication staging corrections source contract. */
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$repo = file_get_contents( $root . '/src/Infrastructure/Repository/TemplateRepository.php' );
$autosave = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateDesignerAutosave.php' );
$factory = file_get_contents( $root . '/src/Application/Templates/FactoryPresetService.php' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$checks=0;$fail=0;
function a435($ok,$label){global$checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
foreach ( array( 'admin'=>$admin, 'repo'=>$repo, 'autosave'=>$autosave, 'factory'=>$factory, 'css'=>$css ) as $key=>$source ) { a435( is_string( $source ) && '' !== $source, 'source ' . $key . ' available' ); }
a435( false !== strpos( $css, '/* alpha.4.35 — Certificate appearance follows the page instead of creating a nested scroll region.' ), 'Issue Credential records the page-scroll correction explicitly' );
a435( false !== strpos( $css, 'position: static;' ) && false !== strpos( $css, 'max-height: none;' ) && false !== strpos( $css, 'overflow: visible;' ), 'Certificate appearance has no sticky nested-scroll geometry' );
a435( false === strpos( $repo, 'certloom_template_publish_renderer_gate' ), 'Template publication is no longer blocked by artifact renderer authority' );
a435( false === strpos( $repo, 'publication_status()' ), 'Template persistence no longer consults renderer publication status' );
a435( false !== strpos( $repo, 'certloom_template_publish_review_required' ) && false !== strpos( $repo, 'certloom_template_publish_review_stale' ), 'Publication still requires exact revision and hash review proof' );
a435( false !== strpos( $admin, "__( 'Publish design', 'certloom' )" ) && false === strpos( $admin, "__( 'Publishing is not available on this site yet.', 'certloom' )" ), 'Design editor exposes Publish design without the site-unavailable dead end' );
a435( false !== strpos( $admin, "__( 'Production downloads unavailable', 'certloom' )" ), 'Artifact/download authority remains communicated separately' );
a435( false !== strpos( $factory, "'starter_publication_ready'" ) && false === strpos( $factory, 'RendererAuthorityGate' ), 'Starter designs can publish for first-use selection without artifact authority' );
a435( false !== strpos( $autosave, '$still_current = $this->current_base' ) && false !== strpos( $autosave, '$this->discard( $template_id, $user_id, $session_id )' ), 'late Designer autosave rechecks its immutable base and self-discards after manual-save races' );
a435( false !== strpos( $autosave, 'late request cannot leave a stale recovery warning' ), 'autosave race correction is documented at the exact persistence boundary' );
echo "\n" . ( $checks - $fail ) . " passed, $fail failures\n";
exit( $fail ? 1 : 0 );
