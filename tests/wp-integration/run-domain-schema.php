<?php
/**
 * Real WordPress/MySQL integration gate for Certloom 1.5.2.
 *
 * Run only on a disposable/staging WordPress install, for example:
 *   wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-schema.php
 *
 * The script inserts temporary next-generation domain rows, exercises the
 * lifecycle and verification services, cleans those rows, and confirms the
 * legacy registry row count did not change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This integration test must run inside WordPress.\n" );
	exit( 1 );
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! defined( 'CERTLOOM_RUN_INTEGRATION_TESTS' ) ) {
	wp_die( 'Certloom integration tests require WP-CLI or CERTLOOM_RUN_INTEGRATION_TESTS.' );
}

require_once __DIR__ . '/gate-evidence.php';
certloom_wp_integration_evidence( 'domain-schema', __FILE__ );

use Certloom\Application\CredentialLifecycleService;
use Certloom\Application\CredentialRelationshipCorrectionService;
use Certloom\Application\CredentialVerificationService;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

function certloom_it_fail( $message ) {
	throw new RuntimeException( $message );
}

function certloom_it_ok( $value, $message ) {
	if ( is_wp_error( $value ) ) {
		certloom_it_fail( $message . ': ' . $value->get_error_code() . ' — ' . $value->get_error_message() );
	}
	return $value;
}

function certloom_it_assert( $condition, $message ) {
	if ( ! $condition ) {
		certloom_it_fail( $message );
	}
}

global $wpdb;
$cleanup = array();
$privacy_settings_before = get_option( Ecapla_CertVerify_Settings::OPTION_KEY, null );
$legacy_table = $wpdb->prefix . ECAPLA_CERTVERIFY_TABLE;
$legacy_exists = $legacy_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) );
$legacy_before = $legacy_exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $legacy_table ) ) : null;

try {
	certloom_it_ok( SchemaManager::activate(), 'Schema activation/health verification failed' );

	$suffix = strtolower( wp_generate_password( 10, false, false ) );
	$recipient_repo = new RecipientRepository();
	$issuer_repo    = new IssuerRepository();
	$program_repo   = new ProgramRepository();
	$credential_repo = new CredentialRepository();

	$issuer_id = certloom_it_ok( $issuer_repo->create( array( 'name' => 'Certloom Integration ' . $suffix, 'slug' => 'certloom-it-' . $suffix ) ), 'Issuer create failed' );
	$cleanup['issuer'] = $issuer_id;
	$recipient_id = certloom_it_ok( $recipient_repo->create( array( 'display_name' => 'Integration Recipient ' . $suffix, 'email' => 'integration-' . $suffix . '@example.test' ) ), 'Recipient create failed' );
	$cleanup['recipient'] = $recipient_id;
	$program_id = certloom_it_ok( $program_repo->create( array( 'issuer_id' => $issuer_id, 'code' => 'IT-' . strtoupper( $suffix ), 'title' => 'Integration Program ' . $suffix ) ), 'Program create failed' );
	$cleanup['program'] = $program_id;

	$series_id = certloom_it_ok( $credential_repo->create_series( array( 'recipient_id' => $recipient_id, 'program_id' => $program_id, 'issuer_id' => $issuer_id ) ), 'Series create failed' );
	$cleanup['series'] = $series_id;
	$series = certloom_it_ok( $credential_repo->find_series( $series_id ), 'Series read failed' );
	certloom_it_assert( is_array( $series ) && ! empty( $series['public_id'] ), 'Series public ID missing.' );
	certloom_it_assert( ( new PublicCredentialId() )->is_valid( $series['public_id'] ), 'Series public ID checksum failed.' );

	$version_id = certloom_it_ok(
		$credential_repo->create_version(
			$series_id,
			array(
				'display_number' => 'IT-' . strtoupper( $suffix ) . '-001',
				'valid_from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
				'expires_at' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
				'snapshot' => array( 'integration_test' => true, 'suffix' => $suffix, 'recipient_name' => 'Integration Recipient ' . $suffix, 'recipient_email' => 'integration-' . $suffix . '@example.test' ),
			)
		),
		'Version create failed'
	);
	$cleanup['version'] = $version_id;

	$lifecycle = new CredentialLifecycleService();
	certloom_it_ok( $lifecycle->issue( $series_id, $version_id, array( 'reason_code' => 'integration_test' ) ), 'Issue command failed' );

	$verify = new CredentialVerificationService( $credential_repo );
	$result = certloom_it_ok( $verify->verify_public_id( $series['public_id'] ), 'Verification read failed' );
	certloom_it_assert( $result->found(), 'Issued credential not found by direct public ID.' );
	certloom_it_assert( $result->status()->is_valid(), 'Issued credential should verify valid.' );

	certloom_it_ok( $lifecycle->suspend( $series_id, array( 'reason_code' => 'integration_test' ) ), 'Suspend command failed' );
	$result = certloom_it_ok( $verify->verify_public_id( $series['public_id'] ), 'Suspended verification failed' );
	certloom_it_assert( 'suspended' === $result->status()->code(), 'Suspended credential did not verify suspended.' );

	certloom_it_ok( $lifecycle->reinstate( $series_id, array( 'reason_code' => 'integration_test' ) ), 'Reinstate command failed' );
	$result = certloom_it_ok( $verify->verify_public_id( $series['public_id'] ), 'Reinstated verification failed' );
	certloom_it_assert( $result->status()->is_valid(), 'Reinstated credential should verify valid.' );

	$batch_repo = new BatchRepository();
	$batch_id = certloom_it_ok( $batch_repo->create( array( 'program_id' => $program_id, 'name' => 'Integration Batch ' . $suffix, 'code' => 'IT-B-' . strtoupper( $suffix ) ) ), 'Batch create failed' );
	$cleanup['batch'] = $batch_id;
	$before_correction = certloom_it_ok( $credential_repo->find_series( $series_id ), 'Series read before correction failed' );
	$correction = new CredentialRelationshipCorrectionService();
	$corrected = certloom_it_ok( $correction->correct( $series_id, $program_id, $batch_id, array( 'actor_user_id' => get_current_user_id(), 'expected_updated_at' => $before_correction['updated_at'], 'reason_code' => 'integration_relationship_correction' ) ), 'Relationship correction failed' );
	certloom_it_assert( ! empty( $corrected['changed'] ) && ! empty( $corrected['version_id'] ), 'Relationship correction did not create immutable evidence.' );
	$after_correction = certloom_it_ok( $credential_repo->find_series( $series_id ), 'Series read after correction failed' );
	certloom_it_assert( absint( $after_correction['batch_id'] ) === absint( $batch_id ), 'Relationship correction did not update the continuing Series Batch.' );
	certloom_it_assert( absint( $after_correction['current_version_id'] ) === absint( $corrected['version_id'] ), 'Relationship correction did not advance the current Version pointer.' );
	$stale = $correction->correct( $series_id, $program_id, null, array( 'expected_updated_at' => $before_correction['updated_at'] ) );
	certloom_it_assert( is_wp_error( $stale ) && 'certloom_relationship_review_stale' === $stale->get_error_code(), 'Stale relationship review did not fail closed.' );

	$privacy_email = 'integration-' . $suffix . '@example.test';
	$export = Ecapla_CertVerify_Privacy::export_personal_data( $privacy_email, 1 );
	certloom_it_assert( is_array( $export ) && ! empty( $export['data'] ), 'WordPress privacy exporter returned no normalized recipient/credential data.' );
	$privacy_groups = array_values( array_unique( array_map( function( $item ){ return (string) ( $item['group_id'] ?? '' ); }, $export['data'] ) ) );
	certloom_it_assert( in_array( 'certloom-recipients', $privacy_groups, true ), 'Privacy exporter omitted the Recipient profile.' );
	certloom_it_assert( in_array( 'certloom-credential-versions', $privacy_groups, true ), 'Privacy exporter omitted immutable Version evidence.' );

	$privacy_settings = Ecapla_CertVerify_Settings::get_all();
	$privacy_settings['privacy_erasure_policy'] = 'anonymize_mutable_recipient';
	certloom_it_assert( Ecapla_CertVerify_Settings::save( $privacy_settings ), 'Could not enable the integration-test privacy anonymization policy.' );
	$erase = Ecapla_CertVerify_Privacy::erase_personal_data( $privacy_email, 1 );
	certloom_it_assert( ! empty( $erase['items_removed'] ), 'WordPress privacy eraser did not anonymize the mutable Recipient profile.' );
	certloom_it_assert( ! empty( $erase['items_retained'] ), 'WordPress privacy eraser did not report retained immutable credential evidence.' );
	$recipient_after_privacy = certloom_it_ok( $recipient_repo->find( $recipient_id ), 'Recipient read after privacy anonymization failed' );
	certloom_it_assert( null === $recipient_after_privacy['email'] && 0 === strpos( $recipient_after_privacy['display_name'], 'Recipient-ANON-' ), 'Mutable Recipient identity was not anonymized correctly.' );
	$current_after_privacy = certloom_it_ok( $credential_repo->find_series( $series_id ), 'Series read after privacy anonymization failed' );
	$version_after_privacy = certloom_it_ok( $credential_repo->find_version( $current_after_privacy['current_version_id'] ), 'Current Version read after privacy anonymization failed' );
	certloom_it_assert( $privacy_email === (string) ( $version_after_privacy['snapshot']['recipient_email'] ?? '' ), 'Privacy anonymization rewrote immutable Version evidence.' );
	$export_after_privacy = Ecapla_CertVerify_Privacy::export_personal_data( $privacy_email, 1 );
	certloom_it_assert( is_array( $export_after_privacy ) && ! empty( $export_after_privacy['data'] ), 'Privacy identity history could not re-associate the requester after mutable email anonymization.' );
	$privacy_groups_after = array_values( array_unique( array_map( function( $item ){ return (string) ( $item['group_id'] ?? '' ); }, $export_after_privacy['data'] ) ) );
	certloom_it_assert( in_array( 'certloom-credential-versions', $privacy_groups_after, true ), 'Post-erasure privacy export lost retained immutable Version evidence.' );
	certloom_it_assert( ! empty( $recipient_after_privacy['privacy_identity_hashes'] ), 'Privacy anonymization did not retain a private one-way identity digest for future data-subject access.' );

	certloom_it_ok( $lifecycle->revoke( $series_id, array( 'reason_code' => 'integration_test' ) ), 'Revoke command failed' );
	$result = certloom_it_ok( $verify->verify_public_id( $series['public_id'] ), 'Revoked verification failed' );
	certloom_it_assert( 'revoked' === $result->status()->code(), 'Revoked credential did not verify revoked.' );

	$event_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE series_id = %d', SchemaManager::table( 'credential_events' ), $series_id ) );
	certloom_it_assert( $event_count >= 4, 'Lifecycle event rows were not written as expected.' );

	$legacy_after = $legacy_exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $legacy_table ) ) : null;
	certloom_it_assert( $legacy_before === $legacy_after, 'Legacy certificate registry row count changed during additive integration test.' );

	echo "PASS  schema activation and exact health verification\n";
	echo "PASS  recipient/program/issuer/Series/Version persistence\n";
	echo "PASS  transaction-backed issue/suspend/reinstate/revoke events\n";
	echo "PASS  immutable Program/Batch relationship correction and stale-review rejection\n";
	echo "PASS  WordPress privacy export + mutable Recipient anonymization with immutable evidence retained\n";
	echo "PASS  direct public-ID verification and read-time Status Service\n";
	echo "PASS  legacy registry remained untouched\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL  ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	if ( null === $privacy_settings_before ) {
		delete_option( Ecapla_CertVerify_Settings::OPTION_KEY );
	} else {
		update_option( Ecapla_CertVerify_Settings::OPTION_KEY, $privacy_settings_before );
	}
	/* Delete only rows created by this integration test, in dependency order. */
	if ( ! empty( $cleanup['series'] ) ) {
		$wpdb->delete( SchemaManager::table( 'credential_events' ), array( 'series_id' => (int) $cleanup['series'] ), array( '%d' ) );
	}
	if ( ! empty( $cleanup['series'] ) ) {
		$wpdb->delete( SchemaManager::table( 'credential_numbers' ), array( 'series_id' => (int) $cleanup['series'] ), array( '%d' ) );
		$wpdb->delete( SchemaManager::table( 'credential_versions' ), array( 'series_id' => (int) $cleanup['series'] ), array( '%d' ) );
	}
	if ( ! empty( $cleanup['series'] ) ) {
		$wpdb->delete( SchemaManager::table( 'credential_series' ), array( 'id' => (int) $cleanup['series'] ), array( '%d' ) );
	}
	if ( ! empty( $cleanup['batch'] ) ) {
		$wpdb->delete( SchemaManager::table( 'batch_recipients' ), array( 'batch_id' => (int) $cleanup['batch'] ), array( '%d' ) );
		$wpdb->delete( SchemaManager::table( 'batches' ), array( 'id' => (int) $cleanup['batch'] ), array( '%d' ) );
	}
	if ( ! empty( $cleanup['program'] ) ) {
		$wpdb->delete( SchemaManager::table( 'programs' ), array( 'id' => (int) $cleanup['program'] ), array( '%d' ) );
	}
	if ( ! empty( $cleanup['issuer'] ) ) {
		$wpdb->delete( SchemaManager::table( 'issuers' ), array( 'id' => (int) $cleanup['issuer'] ), array( '%d' ) );
	}
	if ( ! empty( $cleanup['recipient'] ) ) {
		$wpdb->delete( SchemaManager::table( 'recipients' ), array( 'id' => (int) $cleanup['recipient'] ), array( '%d' ) );
	}
}

exit( isset( $exit_code ) ? $exit_code : 0 );
