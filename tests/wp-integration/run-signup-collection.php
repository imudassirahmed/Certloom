<?php
/** Actual signup validation, exact ownership and approval writes; every fixture rolls back. */
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
use Certloom\Infrastructure\Recipient\RecipientSignupCollection;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Application\Fields\FieldCollectionPolicy;
$checks = array(); $failure = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; };
$fingerprint = static function () {
	global $wpdb; $out = array();
	$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options ), array_map( array( SchemaManager::class, 'table' ), \Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types() ) );
	foreach ( $tables as $table ) { $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Fingerprint read failed.' ); } $encoded = array_map( 'wp_json_encode', $rows ); sort( $encoded ); $out[ $table ] = hash( 'sha256', implode( "\n", $encoded ) ); }
	return $out;
};
$before = $fingerprint(); $new_user = 0;
$result = ( new Transaction() )->run( static function () use ( $check, $ok, &$failure, &$new_user ) {
	try {
		$nonce = 'signup-' . bin2hex( random_bytes( 6 ) );
		$field = array( 'key' => 'qa_signup_id', 'label' => 'QA identity', 'type' => 'text', 'column' => null, 'core' => false, 'sensitivity' => 'personal', 'public_exposure' => 'private', 'order' => 999 );
		$field[ FieldCollectionPolicy::KEY ] = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'recipient', 'writer' => 'recipient', 'collect_when' => 'signup', 'approval_required' => true, 'show_on_certificate' => false, 'help' => 'Required to prepare your certificate.' );
		$fields = Ecapla_CertVerify_Fields::get_fields(); $fields[] = $field; $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'Real Fields Manager storage accepts explicit signup ownership.' );
		$catalog = Ecapla_CertVerify_Fields::get_field_map(); $ok( ( new ScopedFieldGlobalRepository() )->save( array( 'qa_signup_id' => 'required' ), $catalog ) );
		wp_set_current_user( 0 ); $signup = new RecipientSignupCollection(); $signup->register();
		ob_start(); $signup->render(); $html = ob_get_clean();
		$check( false !== strpos( $html, 'certloom_signup_values[qa_signup_id]' ) && false !== strpos( $html, 'aria-required="true"' ) && false !== strpos( $html, 'reviews this answer' ), 'Standard signup renders required personal details and explains approval.' );
		preg_match( '/name="certloom_signup_revision" value="([a-f0-9]{64})"/', $html, $match );
		$_POST = array( 'billing_email' => $nonce . '@example.test' );
		$checkout = new WP_Error(); $check( $signup->validate_woocommerce( $checkout, $nonce, $nonce . '@example.test' ) === $checkout && ! $checkout->has_errors(), 'Checkout and API account creation are not blocked by fields absent from their forms.' );
		$_POST = array( 'woocommerce-register-nonce' => 'native-form-marker' );
		$check( $signup->validate_woocommerce( new WP_Error(), $nonce, $nonce . '@example.test' )->has_errors(), 'The supported WooCommerce signup form cannot omit its enabled certificate controls.' );
		$_POST = array( 'certloom_signup_nonce' => wp_create_nonce( 'certloom_recipient_signup' ), 'certloom_signup_revision' => $match[1], 'certloom_signup_values' => array( 'qa_signup_id' => '00000123' ) );
		$valid = $signup->validate( new WP_Error(), $nonce, $nonce . '@example.test' ); $check( ! $valid->has_errors(), 'Current signup settings accept the typed identifier without losing leading zeros.' );
		$_POST['certloom_signup_values']['score'] = '999'; $check( $signup->validate( new WP_Error(), $nonce, $nonce . '@example.test' )->has_errors(), 'Signup cannot smuggle a result or unrelated field.' ); unset( $_POST['certloom_signup_values']['score'] );
		$_POST['certloom_signup_values']['qa_signup_id'] = ''; $check( $signup->validate( new WP_Error(), $nonce, $nonce . '@example.test' )->has_errors(), 'Required signup detail is validated on the server.' );
		$_POST['certloom_signup_values']['qa_signup_id'] = '00000123'; $revision = $_POST['certloom_signup_revision']; $_POST['certloom_signup_revision'] = str_repeat( '0', 64 ); $check( $signup->validate( new WP_Error(), $nonce, $nonce . '@example.test' )->has_errors(), 'Changed signup settings require a fresh form.' ); $_POST['certloom_signup_revision'] = $revision;
		$valid = $signup->validate( new WP_Error(), $nonce, $nonce . '@example.test' ); $check( ! $valid->has_errors(), 'A corrected signup can be submitted again.' );
		$new_user = $ok( wp_insert_user( array( 'user_login' => $nonce, 'user_email' => $nonce . '@example.test', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA customer' ) ) );
		$recipient = $ok( ( new RecipientRepository() )->find_by_wp_user_id( $new_user ) ); $check( is_array( $recipient ) && (int) $recipient['wp_user_id'] === $new_user, 'Validated signup creates only the exact new WordPress account link.' );
		$latest = $ok( ( new FieldSubmissionRepository() )->latest_map( $recipient['id'], 'recipient', $recipient['id'] ) ); $check( '00000123' === $latest['qa_signup_id']['candidate_value'] && 'pending' === $latest['qa_signup_id']['status'], 'Signup uses the canonical approval queue rather than bypassing approval.' );
		$check( empty( $recipient['custom_fields']['qa_signup_id'] ), 'An unapproved answer does not become canonical recipient data.' );
		wp_set_current_user( $new_user ); $view = $ok( ( new RecipientCollectionService() )->read_for_user( $new_user ) ); $check( $view['linked'] && count( array_filter( $view['fields'], static function ( $row ) { return 'qa_signup_id' === $row['key']; } ) ) === 1, 'The new customer can correct their signup detail in their own profile.' );
		$program_field = $field; $program_field['key'] = 'qa_enrollment_id'; $program_field['label'] = 'QA Program registration number'; $program_field[ FieldCollectionPolicy::KEY ]['lifetime'] = 'registration'; $program_field[ FieldCollectionPolicy::KEY ]['collect_when'] = 'registration'; $program_field[ FieldCollectionPolicy::KEY ]['approval_required'] = false;
		$fields = Ecapla_CertVerify_Fields::get_fields(); $fields[] = $program_field; $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'Program enrollment fields use the same canonical collection catalog.' );
		$metadata = $ok( ( new \Certloom\Application\Fields\ScopedFieldContract() )->with_program_metadata( array(), array( 'qa_enrollment_id' => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ) );
		$program = $ok( ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->create( array( 'title' => 'QA signup enrollment', 'code' => $nonce, 'metadata' => $metadata ) ) );
		$registration = $ok( ( new \Certloom\Infrastructure\Repository\ProgramRegistrationRepository() )->ensure( $recipient['id'], $program, 0, 'manual', $nonce ) );
		$service = new RecipientCollectionService(); $enrollment = $ok( $service->read_for_user( $new_user, $registration['id'] ) );
		$asked = array_values( array_filter( $enrollment['fields'], static function ( $row ) { return 'qa_enrollment_id' === $row['key']; } ) );
		$check( count( $asked ) === 1 && $asked[0]['required'], 'The exact linked enrollment automatically asks this customer for its required Program detail.' );
		$profile = $ok( $service->read_for_user( $new_user ) ); $check( ! array_filter( $profile['fields'], static function ( $row ) { return 'qa_enrollment_id' === $row['key']; } ), 'An enrollment-specific detail never appears as an unrelated global profile field.' );
		$other = $ok( wp_insert_user( array( 'user_login' => $nonce . '-other', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ) );
		$ok( ( new \Certloom\Infrastructure\Recipient\RecipientUserSync() )->ensure_for_user( $other ) ); $check( is_wp_error( $service->read_for_user( $other, $registration['id'] ) ), 'Another signed-in customer cannot read someone else\'s Program enrollment.' );
		$saved = $ok( $service->save_for_user( $new_user, $registration['id'], $enrollment['revision'], array( 'qa_enrollment_id' => 'MBA-00007' ) ) );
		$check( 'MBA-00007' === $ok( ( new \Certloom\Infrastructure\Repository\ProgramRegistrationRepository() )->find( $registration['id'] ) )['fields']['qa_enrollment_id'], 'The customer\'s own answer is stored on that enrollment instead of a global recipient record.' );
		$check( 0 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d', SchemaManager::table( 'credential_series' ), $recipient['id'] ) ), 'Signup and approval submission do not issue a credential or confer qualification.' );
	} catch ( Throwable $error ) { $failure = $error->getMessage(); }
	return new WP_Error( 'signup_probe_rollback', 'Always roll back owned signup fixtures.' );
} );
wp_set_current_user( 0 ); if ( $new_user ) { clean_user_cache( $new_user ); } LegacyWriteGuard::invalidate_cache(); wp_cache_flush();
$check( is_wp_error( $result ) && 'signup_probe_rollback' === $result->get_error_code(), 'Owned signup fixture transaction rolls back.' );
$check( $before === $fingerprint(), 'Every database row matches its pre-probe fingerprint.' );
if ( $failure ) { fwrite( STDERR, $failure . "\n" ); exit( 1 ); }
echo wp_json_encode( array( 'success' => true, 'checks_passed' => count( $checks ), 'fixtures_rolled_back' => true, 'external_transport_disabled' => true ) ) . "\n";
