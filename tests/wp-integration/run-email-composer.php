<?php
/** Actual email composer saves, retained layouts and private live previews; every fixture rolls back. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( realpath( (string) getenv( 'CERTLOOM_TEST_WP_ROOT' ) ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local site and verified private snapshot required.\n" ); exit( 1 ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); } if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { throw new RuntimeException( 'Refusing another site.' ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled' ); }, PHP_INT_MAX );

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailComposerDocument;
use Certloom\Infrastructure\Admin\EmailDesignAdmin;
use Certloom\Infrastructure\Admin\AdminSearchRest;
class ComposerProbeRedirect extends RuntimeException { public $url; public function __construct( $url ) { $this->url = $url; parent::__construct( 'Owned composer redirect' ); } }
$checks = array(); $failure = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; };
$fingerprint = static function () {
	global $wpdb; $out = array();
	$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options ), array_map( array( SchemaManager::class, 'table' ), \Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types() ) );
	foreach ( $tables as $table ) { $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Fingerprint read failed.' ); } $encoded = array_map( 'wp_json_encode', $rows ); sort( $encoded ); $out[ $table ] = hash( 'sha256', implode( "\n", $encoded ) ); }
	return $out;
};

$before = $fingerprint(); $qa_user = 0; $failure = null;
$redirect = static function ( $url ) { throw new ComposerProbeRedirect( $url ); }; add_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
$die = static function () { return static function () { throw new RuntimeException( 'The composer handler rejected the probe form.' ); }; }; add_filter( 'wp_die_handler', $die, PHP_INT_MAX );
$result = ( new Transaction() )->run( static function () use ( $check, $ok, &$failure, &$qa_user ) {
 try {
  $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); wp_set_current_user( (int) $admins[0] );
  $catalog = new EmailTemplateCatalogRepository(); $compiler = new EmailDesignCompiler(); $id = 'qa-composer-' . bin2hex( random_bytes( 6 ) );
  $blocks = array( array( 'type' => 'container', 'direction' => 'vertical', 'padding' => array( 'top' => 20 ), 'children' => array( array( 'type' => 'heading', 'text' => 'QA {{program_title}}', 'style' => array( 'font_size' => 28 ) ), array( 'type' => 'text', 'html' => '<p>Hello {{recipient_name}}</p>' ), array( 'type' => 'button', 'text' => 'View certificate', 'url' => '{{verification_url}}' ), array( 'type' => 'html', 'html' => '<table><tr><td>Additional saved layout</td></tr></table>' ), array( 'type' => 'text', 'html' => '<p>Original footer</p>', 'style' => array( 'font_size' => 12 ) ) ) ) );
  $theme = EmailDesignCompiler::default_theme(); $theme['content_background_image'] = 'https://example.test/retained-brand.png'; $theme['content_background_size'] = 'contain'; $theme['content_background_repeat'] = 'repeat-x'; $theme['email_width'] = 680;
  $ok( $catalog->save( array( 'id' => $id, 'name' => 'QA composer', 'purpose' => 'credential_issued', 'subject' => 'Hello {{recipient_name}}', 'preheader' => 'Sample preview', 'blocks' => $blocks, 'theme' => $theme, 'active' => 0 ) ) );
  $existing = $catalog->get( $id ); $original = $existing; $controls = EmailComposerDocument::controls( $existing['blocks'] );
  $check( array( 0, 'children', 1 ) === $controls['message']['path'] && 'html' === $controls['additional'][0]['block']['type'], 'Actual saved nested layouts expose the correct composer controls without flattening.' );
  $plain = EmailComposerDocument::custom_plain_text( $existing['blocks'], $existing['theme'], $existing['text_body'] );
  $check( '' === $plain, 'Previously generated plain text continues to update with the message.' );
  $blocks = $existing['blocks']; $blocks[0]['children'][1]['html'] = '<p>Updated message for {{recipient_name}}</p>';
  $_POST = array( '_wpnonce' => wp_create_nonce( 'certloom_email_design_save' ), 'design_id' => $id, 'name' => $existing['name'], 'purpose' => $existing['purpose'], 'subject' => $existing['subject'], 'preheader' => $existing['preheader'], 'blocks_json' => wp_slash( wp_json_encode( $blocks ) ), 'composer_theme_json' => wp_slash( wp_json_encode( $existing['theme'] ) ), 'attachments_json' => '[]', 'text_body' => $plain, 'save_intent' => 'save' );
  $post = static function () { $_REQUEST = $_POST; try { ( new EmailDesignAdmin() )->save(); } catch ( ComposerProbeRedirect $redirect ) { parse_str( parse_url( $redirect->url, PHP_URL_QUERY ), $query ); return $query; } throw new RuntimeException( 'Expected handler redirect.' ); };
  $saved = $post(); $check( ! isset( $saved['certloom_email_error'] ) && ! isset( $saved['certloom_error'] ), 'The actual authorized composer form saves through the existing handler.' );
  $current = $catalog->get( $id );
  $check( $current['blocks'][0]['children'][1]['html'] === $blocks[0]['children'][1]['html'] && $current['blocks'][0]['children'][3] === $original['blocks'][0]['children'][3], 'Message edits preserve additional custom HTML and its nested location.' );
  $check( $current['theme'] === $existing['theme'] && $current['blocks'][0]['children'][0]['style'] === $existing['blocks'][0]['children'][0]['style'], 'Composer saving retains the complete original theme and untouched node styles.' );
  $check( strpos( $current['text_body'], 'Updated message' ) !== false && ! $current['active'], 'Generated plain text is refreshed while draft publication state stays unchanged.' );
  $_POST['text_body'] = 'Deliberately custom plain message'; $post();
  $check( 'Deliberately custom plain message' === $catalog->get( $id )['text_body'], 'An explicit custom plain-text alternative is preserved.' );
  $admin = new AdminSearchRest();
  $request = new WP_REST_Request( 'POST', '/certloom/v1/admin/email-preview' ); $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( array( 'blocks' => $blocks, 'theme' => $theme, 'subject' => '{{recipient_name}}', 'preheader' => '{{program_title}}' ) ) );
  $baseline = $catalog->all(); $preview = $ok( $admin->email_preview( $request ) ); $data = $preview->get_data();
  $check( strpos( $data['html'], 'Updated message' ) !== false && strpos( $data['html'], '{{recipient_name}}' ) === false, 'Live preview compiles the current message and replaces tokens with synthetic values.' );
  $check( $baseline === $catalog->all() && strpos( $preview->get_headers()['Cache-Control'], 'no-store' ) !== false, 'Preview is a private read and leaves saved designs untouched.' );
  $request->set_body( wp_json_encode( array( 'blocks' => $blocks, 'theme' => $theme, 'subject' => array() ) ) );
  $check( is_wp_error( $admin->email_preview( $request ) ), 'Malformed preview metadata is rejected rather than coerced.' );
  wp_set_current_user( 0 ); $check( ! $admin->email_preview_permission() && ! $admin->template_preview_permission(), 'Anonymous visitors cannot preview organization designs.' );
  $qa_user = $ok( wp_insert_user( array( 'user_login' => $id, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ) ); ( new WP_User( $qa_user ) )->add_cap( Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ); wp_set_current_user( $qa_user );
  $check( $admin->template_preview_permission() && ! $admin->email_preview_permission(), 'A Program manager can see its certificate preview without acquiring email editing permission.' );
 } catch ( Throwable $error ) { $failure = $error->getMessage(); }
 return new WP_Error( 'composer_probe_rollback', 'Always roll back all owned composer fixtures.' );
} );
remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX ); remove_filter( 'wp_die_handler', $die, PHP_INT_MAX ); wp_set_current_user( 0 ); if ( $qa_user ) { clean_user_cache( $qa_user ); } LegacyWriteGuard::invalidate_cache(); wp_cache_flush();
$check( is_wp_error( $result ) && 'composer_probe_rollback' === $result->get_error_code(), 'Owned composer fixture transaction rolls back.' );
$check( $before === $fingerprint(), 'All database rows match their pre-probe fingerprints, including LearnDash.' );
if ( $failure ) { fwrite( STDERR, $failure . "\n" ); exit( 1 ); }
echo wp_json_encode( array( 'success' => true, 'checks_passed' => count( $checks ), 'native_handler' => true, 'fixtures_rolled_back' => true, 'external_transport_disabled' => true ) ) . "\n";
