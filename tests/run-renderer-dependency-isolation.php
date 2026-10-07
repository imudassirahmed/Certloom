<?php
/** Real frozen classes/functions coexist with another Composer bootstrap. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Rendering\BundledRendererDependencies;
$checks = 0;
function rdi_check( $ok, $message ) { global $checks; ++$checks; if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS  $message\n"; }
// Reproduce both collision sources using their exact frozen generated IDs.
class ComposerAutoloaderInitc67409430429359f4781d3c9e7054efb {}
$original = array( '6124b4c8570aa390c21fafd04a26c69f' => true, 'db356362850385d08a5381de2638b5fd' => true );
$GLOBALS['__composer_autoload_files'] = $original;
BundledRendererDependencies::load( CERTLOOM_PATH . 'resources/renderer/vendor' );
rdi_check( class_exists( 'CertloomVendor\\Mpdf\\Mpdf' ), 'Real prefixed PDF class loads despite identical foreign Composer bootstrap' );
rdi_check( ! class_exists( 'Mpdf\\Mpdf', false ), 'No unprefixed PDF class is loaded' );
rdi_check( function_exists( 'CertloomVendor\\DeepCopy\\deep_copy' ), 'Prefixed function loads despite foreign Composer file IDs' );
$object = (object) array( 'value' => (object) array( 'one' => 1 ) );
$copy = \CertloomVendor\DeepCopy\deep_copy( $object );
rdi_check( $copy !== $object && $copy->value !== $object->value && 1 === $copy->value->one, 'Real dependency function uses isolated prefixed classes' );
rdi_check( $original === $GLOBALS['__composer_autoload_files'], 'Foreign Composer function registry remains unchanged' );
$before = count( spl_autoload_functions() ); BundledRendererDependencies::load( CERTLOOM_PATH . 'resources/renderer/vendor' );
rdi_check( $before === count( spl_autoload_functions() ), 'Repeated bootstrap does not add another loader' );
rdi_check( ! class_exists( 'Composer\\InstalledVersions', false ), 'Certloom does not install global Composer metadata classes' );
echo "\n$checks dependency-isolation checks, 0 failures\n";
