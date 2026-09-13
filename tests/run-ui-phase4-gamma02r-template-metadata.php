<?php
/** Phase 4 Gamma-02R inline Template identity + recommendation metadata contract. */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/fake-wp/' ); }
if ( ! function_exists( '__' ) ) { function __( $text ) { return $text; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $value ) { return abs( (int) $value ); } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; private $message; public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $value ) { return $value instanceof WP_Error; } }
$GLOBALS['certloom_meta_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['certloom_meta_test_options'] ) ? $GLOBALS['certloom_meta_test_options'][ $key ] : $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $key, $value, $autoload = null ) { $changed = ! array_key_exists( $key, $GLOBALS['certloom_meta_test_options'] ) || $GLOBALS['certloom_meta_test_options'][ $key ] !== $value; $GLOBALS['certloom_meta_test_options'][ $key ] = $value; return $changed; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $key ) { unset( $GLOBALS['certloom_meta_test_options'][ $key ] ); return true; } }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Templates\TemplateRecommendationProfiles;
use Certloom\Infrastructure\Repository\TemplateRecommendationRepository;

$root = dirname( __DIR__ );
$profiles_source = file_get_contents( $root . '/src/Application/Templates/TemplateRecommendationProfiles.php' );
$recommendation_repo = file_get_contents( $root . '/src/Infrastructure/Repository/TemplateRecommendationRepository.php' );
$library = file_get_contents( $root . '/src/Application/Templates/TemplateLibraryMetadata.php' );
$package = file_get_contents( $root . '/src/Application/Templates/TemplatePackage.php' );
$template_repo = file_get_contents( $root . '/src/Infrastructure/Repository/TemplateRepository.php' );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$js = file_get_contents( $root . '/admin/assets/designer.js' );
$css = file_get_contents( $root . '/admin/assets/designer.css' );
$uninstall = file_get_contents( $root . '/uninstall.php' );

$checks = 0; $failures = 0;
function g02meta_check( $condition, $label ) {
	global $checks, $failures; $checks++;
	if ( $condition ) { echo "PASS  {$label}\n"; return; }
	$failures++; echo "FAIL  {$label}\n";
}
foreach ( compact( 'profiles_source', 'recommendation_repo', 'library', 'package', 'template_repo', 'admin', 'js', 'css', 'uninstall' ) as $name => $source ) {
	g02meta_check( is_string( $source ) && '' !== $source, $name . ' source available' );
}

$labels = TemplateRecommendationProfiles::labels();
$expected = array(
	'training_lms', 'iso_management', 'government_licensing', 'school_results', 'corporate_compliance',
	'membership_association', 'events_awards', 'product_inspection', 'general_other',
);
g02meta_check( $expected === array_keys( $labels ), 'recommendation metadata mirrors the exact nine current Setup usage profiles' );
g02meta_check( array( 'training_lms', 'general_other' ) === TemplateRecommendationProfiles::normalize( array( 'training_lms', 'INVALID', 'training_lms', 'general_other' ) ), 'recommendation profile normalization rejects unknown and duplicate keys' );
$profile_store = new TemplateRecommendationRepository();
$profile_saved = $profile_store->save( 77, array( 'training_lms', 'invalid', 'training_lms', 'iso_management' ) );
g02meta_check( true === $profile_saved && array( 'training_lms', 'iso_management' ) === $profile_store->get( 77 ), 'recommendation repository round-trips only normalized profile guidance' );
$profile_store->save( 77, array() );
g02meta_check( array() === $profile_store->get( 77 ) && ! array_key_exists( TemplateRecommendationRepository::OPTION_PREFIX . '77', $GLOBALS['certloom_meta_test_options'] ), 'clearing recommendation profiles removes the detached option rather than storing empty noise' );
g02meta_check( is_wp_error( $profile_store->save( 0, array( 'training_lms' ) ) ), 'recommendation repository fails closed without a valid Template identity' );
g02meta_check( false !== strpos( $profiles_source, 'PurposePresetEngine' ) && false === strpos( $profiles_source, 'ProgramProfile' ), 'recommendation profiles derive from Setup profiles rather than legacy Program-purpose vocabulary' );

g02meta_check( false !== strpos( $recommendation_repo, "const OPTION_PREFIX = 'certloom_template_recommendations_'" ) && false !== strpos( $recommendation_repo, 'TemplateRecommendationProfiles::normalize' ), 'recommendation repository stores normalized presentation-only metadata separately from Template authority' );
g02meta_check( false !== strpos( $library, "'purposes' =>" ) && false !== strpos( $library, "'profiles' => \$recommended_profiles" ), 'library metadata keeps legacy purpose guidance separate from usage-profile recommendations' );
g02meta_check( false !== strpos( $library, 'profile_labels() { return TemplateRecommendationProfiles::labels(); }' ), 'library exposes current usage-profile labels for Studio and future Gallery discovery' );

g02meta_check( false !== strpos( $admin, 'wp_ajax_certloom_template_studio_metadata' ) && false !== strpos( $admin, 'certloom_template_studio_metadata_' ), 'Studio metadata writes use a dedicated capability/nonce-guarded AJAX boundary' );
g02meta_check( false !== strpos( $admin, 'data-certloom-studio-name-display' ) && false !== strpos( $admin, 'data-certloom-studio-name-input' ), 'Studio renders click-to-rename Template identity controls' );
g02meta_check( false !== strpos( $admin, 'data-certloom-studio-profile-picker' ) && false !== strpos( $admin, 'data-certloom-studio-profile' ), 'Studio renders direct recommended-profile assignment controls' );
g02meta_check( false !== strpos( $admin, 'They never prevent another profile from using this design.' ), 'Studio explains that profile tags are recommendations rather than compatibility gates' );
g02meta_check( false !== strpos( $admin, "'expected_updated_at'" ) && false !== strpos( $admin, 'update_metadata(' ), 'inline rename retains optimistic-concurrency proof' );

g02meta_check( false !== strpos( $js, "studioMetadataRequest( 'name'" ) && false !== strpos( $js, 'beginStudioRename' ) && false !== strpos( $js, 'finishStudioRename' ), 'Studio runtime persists inline rename through the metadata boundary' );
g02meta_check( false !== strpos( $js, "'Enter' === event.key" ) && false !== strpos( $js, "'Escape' === event.key" ) && false !== strpos( $js, "addEventListener( 'blur'" ), 'inline rename supports Enter commit Escape cancel and blur commit' );
g02meta_check( false !== strpos( $js, "studioMetadataRequest( 'recommended_profiles'" ) && false !== strpos( $js, 'studioProfileTimer' ) && false !== strpos( $js, '260' ), 'recommended-profile editing is compact and debounced' );
g02meta_check( false !== strpos( $css, '.certloom-studio-title-button' ) && false !== strpos( $css, '.certloom-studio-profile-picker' ) && false !== strpos( $css, '.certloom-studio-profile-popover' ), 'inline identity and profile controls have dedicated compact Studio styling' );

g02meta_check( false !== strpos( $package, "\$identity['recommended_profiles']" ) && false !== strpos( $package, "array( 'description', 'machine_key', 'name', 'recommended_profiles' )" ), '.certloom packages can port recommendation metadata without requiring it in older archives' );
g02meta_check( false !== strpos( $template_repo, "\$template['recommended_profiles']" ) && false !== strpos( $template_repo, 'TemplateRecommendationRepository' ), 'single-template export attaches recommendation metadata to portable packages' );
g02meta_check( false !== strpos( $template_repo, "unset( \$decoded['assets'], \$decoded['layout_sha256'], \$decoded['recommended_profiles'] )" ) && false !== strpos( $template_repo, 'save( $created, $recommended_profiles )' ), 'import separates recommendation guidance from canonical Template creation then restores it safely' );
g02meta_check( false !== strpos( $template_repo, 'a copy must never become an orphaned failure' ), 'Template duplication preserves profile guidance without turning presentation metadata into creation authority' );
g02meta_check( false !== strpos( $uninstall, "esc_like( 'certloom_template_recommendations_' )" ), 'explicit delete-data uninstall removes detached recommendation metadata' );

g02meta_check( false === strpos( $profiles_source, 'accepted.json' ) && false === strpos( $recommendation_repo, 'accepted.json' ), 'profile guidance does not manufacture renderer/domain release authority' );
g02meta_check( false === strpos( $recommendation_repo, 'wp_remote_' ) && false === strpos( $js, 'localStorage' ) && false === strpos( $js, 'sessionStorage' ), 'metadata work adds no remote or browser-local authority path' );

echo "\n" . ( $checks - $failures ) . " checks passed, {$failures} failures\n";
exit( $failures ? 1 : 0 );
