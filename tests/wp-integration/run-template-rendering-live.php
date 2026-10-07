<?php
/** Real runtime render of every built-in design. No credential/database writes. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $snapshot = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $snapshot || ! is_file( $snapshot ) || filesize( $snapshot ) < 1024 ) { throw new RuntimeException( 'Exact Local site and recovery snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
use Certloom\Application\Contract\ArtifactAuthorityGateInterface;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\CuratedPresetCatalog;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Infrastructure\Rendering\ProductionRendererRuntime;
use Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver;
use Certloom\Infrastructure\Rendering\LocalQrEncoder;
use Certloom\Infrastructure\Rendering\LocalCode128Encoder;
final class TemplateProbeAuthority implements ArtifactAuthorityGateInterface {
 public function artifact_status( $format, RendererRegistry $registry ) { return array( 'accepted' => true, 'reason' => 'artifact_authorized' ); }
 public function can_render( $format, RendererRegistry $registry ) { return true; }
}
$checks = 0;
function trl_check( $ok, $message ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } }
$run = getenv( 'CERTLOOM_TEMPLATE_TEST_RUN' ) ?: 'template-runtime-review-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
if ( ! preg_match( '/^[a-z0-9_-]{1,96}$/', $run ) ) { throw new RuntimeException( 'Review output requires a bounded directory name.' ); }
$output = CERTLOOM_PATH . 'output/playwright/' . $run;
trl_check( ! file_exists( $output ) && wp_mkdir_p( $output ), 'Artifact output must be fresh.' );
$runtime = new ProductionRendererRuntime( new TemplateProbeAuthority() );
$components = $runtime->components(); $resolver = new LayoutResolver( null, null, null, new LocalQrEncoder(), new LocalCode128Encoder() );
$samples = new TemplatePreviewSamples(); $assets = new WordPressLocalAssetResolver(); $layouts = new LayoutValidator();
$catalog = array_merge( ( new PresetCatalog() )->all(), ( new CuratedPresetCatalog() )->all() );
trl_check( 42 === count( $catalog ), 'Full current catalog must be covered.' ); $results = array();
foreach ( $catalog as $preset ) {
 $key = $preset['preset_key']; $row = array( 'preset' => $key, 'name' => $preset['name'], 'page' => $preset['layout']['page'], 'qr' => array_values( array_filter( $preset['layout']['elements'], static function ( $element ) { return 'qr' === $element['type']; } ) ), 'formats' => array() );
 $layout = $preset['layout']; $hash = $layouts->hash( $layout );
 $document = $resolver->resolve( $layout, $samples->snapshot_for_layout( $layout ), $samples->verification_url(), $assets );
 $runtime->assert_supported( $document ); ++$checks;
 foreach ( array( 'pdf','png','jpeg' ) as $format ) {
  $one = $components['coordinator']->render( $format, $hash, $document ); $two = $components['coordinator']->render( $format, $hash, $document );
  trl_check( $one['bytes'] === $two['bytes'], "$key/$format must be byte deterministic." );
  trl_check( strlen( $one['bytes'] ) > 1000 && hash( 'sha256', $one['bytes'] ) === $one['receipt']['sha256'], "$key/$format artifact receipt must bind actual bytes." );
  trl_check( 'pdf' === $format ? str_starts_with( $one['bytes'], '%PDF-' ) : false !== getimagesizefromstring( $one['bytes'] ), "$key/$format must be a real artifact." );
  $name = $key . '.' . ( 'jpeg' === $format ? 'jpg' : $format );
  trl_check( strlen( $one['bytes'] ) === file_put_contents( $output . '/' . $name, $one['bytes'] ), 'Write this review artifact.' );
  $row['formats'][ $format ] = array( 'file' => $name, 'bytes' => strlen( $one['bytes'] ), 'sha256' => $one['receipt']['sha256'] );
 }
 $long = $resolver->resolve( $layout, $samples->snapshot_for_layout( $layout, TemplatePreviewSamples::VERY_LONG_NAME ), $samples->verification_url( TemplatePreviewSamples::VERY_LONG_NAME ), $assets );
 $runtime->assert_supported( $long ); $render = $components['coordinator']->render( 'png', $hash, $long );
 $row['long_name_png'] = $key . '-long.png';
 trl_check( false !== getimagesizefromstring( $render['bytes'] ) && strlen( $render['bytes'] ) === file_put_contents( $output . '/' . $row['long_name_png'], $render['bytes'] ) && hash_file( 'sha256', $output . '/' . $row['long_name_png'] ) === $render['receipt']['sha256'], 'Long-name layout writes a real PNG bound to its receipt.' );
 $row['long_name_sha256'] = $render['receipt']['sha256'];
 $results[] = $row; echo "PASS  $key real PDF/PNG/JPEG A/B and very-long-name PNG\n";
}
trl_check( ! ( new ProductionRendererRuntime() )->status( 'pdf' )['accepted'] && ! file_exists( CERTLOOM_PATH . 'resources/renderer/accepted.json' ), 'CLI proof must not enable production authority.' );
file_put_contents( $output . '/results.json', wp_json_encode( array( 'schema' => 'certloom-template-runtime-review', 'version' => 1, 'checks' => $checks, 'verification_url' => $samples->verification_url(), 'long_verification_url' => $samples->verification_url( TemplatePreviewSamples::VERY_LONG_NAME ), 'presets' => $results ), JSON_PRETTY_PRINT ) . "\n" );
echo "\n$checks template-runtime checks, 0 failures; 42 designs, 252 A/B artifacts and 42 long-name PNGs\n";
