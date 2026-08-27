<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Application\CredentialLifecycleService;
use Certloom\Application\CredentialVerificationService;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationPreflightService;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\LegacyCertificateRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrator-triggered acceptance proof for the additive Credential Series domain.
 *
 * Unlike the isolated database-capability proof, this deliberately provisions and
 * verifies Certloom's real gated domain tables. It still never migrates a live
 * certificate row, never changes credential authority, and runs every synthetic
 * repository/lifecycle write inside one rollback-only transaction.
 */
final class DomainAcceptanceProof {
	const RESULT_OPTION  = 'certloom_domain_acceptance_proof_last';
	const RESULT_SCHEMA  = 'certloom-domain-acceptance-proof';
	const RESULT_VERSION = 7;
	const MAX_AGE         = 7 * DAY_IN_SECONDS;
	const ROLLBACK_CODE   = 'certloom_domain_acceptance_intentional_rollback';

	/** @var string[] */
	private static $tables = array(
		'recipients', 'issuers', 'programs', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions',
		'credential_numbers', 'credential_events', 'email_deliveries', 'jobs', 'import_rows', 'restore_map',
	);

	/** @return array<string,mixed>|\WP_Error */
	public static function run() {
		global $wpdb;

		$preconditions = self::preconditions();
		if ( is_wp_error( $preconditions ) ) {
			return $preconditions;
		}
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new \WP_Error( 'certloom_domain_acceptance_database_unavailable', __( 'Certloom could not access the WordPress database connection for the gated-domain proof.', 'certloom' ) );
		}

		$runtime_payload = RuntimePayloadEvidence::current();
		if ( ! is_array( $runtime_payload ) ) {
			return new \WP_Error( 'certloom_domain_acceptance_runtime_payload_unavailable', __( 'Certloom could not fingerprint the exact installed plugin payload for gated-domain acceptance evidence.', 'certloom' ) );
		}
		$runtime_environment = RuntimeEnvironmentEvidence::current();
		if ( ! is_array( $runtime_environment ) ) {
			return new \WP_Error( 'certloom_domain_acceptance_runtime_environment_unavailable', __( 'Certloom could not identify the exact WordPress/PHP/database environment for gated-domain acceptance evidence.', 'certloom' ) );
		}

		$empty = self::assert_existing_domain_empty();
		if ( is_wp_error( $empty ) ) {
			return $empty;
		}

		try {
			$owner = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_domain_acceptance_random_failed', __( 'Certloom could not prepare a secure gated-domain proof owner token.', 'certloom' ) );
		}

		$maintenance = new DomainMaintenanceLock();
		$locked = $maintenance->acquire( $owner, 'acceptance_proof', 10 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$result = self::new_result( $runtime_payload, $runtime_environment );
		$legacy_before = null;
		$generation_before_exists = false;
		$generation_before = null;
		$release_error = null;

		try {
			$legacy_before = ( new LegacyCertificateRepository() )->snapshot();
			if ( is_wp_error( $legacy_before ) ) {
				self::add_check( $result, 'legacy_boundary', 'fail', __( 'Legacy registry boundary', 'certloom' ), __( 'Certloom could not capture the live registry boundary before the proof.', 'certloom' ) );
			} else {
				self::add_check( $result, 'legacy_boundary', 'pass', __( 'Legacy registry boundary', 'certloom' ), __( 'The proof captured a read-only aggregate boundary for the live registry.', 'certloom' ) );
			}

			if ( ! self::has_failures( $result ) ) {
				$schema = SchemaManager::activate();
				if ( is_wp_error( $schema ) ) {
					self::add_check(
						$result,
						'domain_schema',
						'fail',
						__( 'Credential-domain schema', 'certloom' ),
						sprintf(
							/* translators: %s: privacy-safe internal schema diagnostic code and logical object. */
							__( 'Certloom could not provision or verify its additive gated-domain tables on this host. Diagnostic: %s.', 'certloom' ),
							self::safe_schema_diagnostic( $schema )
						)
					);
				} else {
					$health = SchemaManager::verify_health_now( false );
					self::add_check(
						$result,
						'domain_schema',
						is_wp_error( $health ) ? 'fail' : 'pass',
						__( 'Credential-domain schema', 'certloom' ),
						is_wp_error( $health )
							? sprintf(
								/* translators: %s: privacy-safe internal schema diagnostic code and logical object. */
								__( 'The gated-domain tables exist but failed Certloom’s exact schema/engine health contract. Diagnostic: %s.', 'certloom' ),
								self::safe_schema_diagnostic( $health )
							)
							: __( 'All additive Credential Series tables match the current Certloom schema and storage-engine contract.', 'certloom' )
					);
				}
			}

			if ( ! self::has_failures( $result ) ) {
				$empty_after_schema = self::assert_existing_domain_empty();
				if ( is_wp_error( $empty_after_schema ) ) {
					self::add_check( $result, 'domain_empty', 'fail', __( 'Gated-domain isolation', 'certloom' ), __( 'The gated domain contains data, so the synthetic acceptance proof stopped before writing anything.', 'certloom' ) );
				} else {
					self::add_check( $result, 'domain_empty', 'pass', __( 'Gated-domain isolation', 'certloom' ), __( 'The additive domain is empty and safe for rollback-only synthetic acceptance data.', 'certloom' ) );
				}
			}

			$missing = new \stdClass();
			$generation_before = get_option( DomainGeneration::OPTION, $missing );
			$generation_before_exists = $missing !== $generation_before;
			if ( ! $generation_before_exists ) {
				$generation_before = null;
			}

			if ( ! self::has_failures( $result ) ) {
				$transaction = new Transaction();
				$transaction_result = DomainMaintenanceLock::run_internal(
					function () use ( $transaction, &$result ) {
						return $transaction->run(
							function () use ( &$result ) {
								$exercise = self::exercise_domain( $result );
								if ( is_wp_error( $exercise ) ) {
									return $exercise;
								}
								return new \WP_Error( self::ROLLBACK_CODE, __( 'Intentional rollback-only acceptance proof.', 'certloom' ) );
							}
						);
					}
				);

				if ( is_wp_error( $transaction_result ) && self::ROLLBACK_CODE === $transaction_result->get_error_code() ) {
					self::add_check( $result, 'proof_rollback', 'pass', __( 'Synthetic transaction rollback', 'certloom' ), __( 'Every synthetic Credential Series write was rolled back together after the proof.', 'certloom' ) );
				} else {
					if ( ! self::has_failures( $result ) ) {
						self::add_check( $result, 'domain_execution', 'fail', __( 'Credential-domain execution', 'certloom' ), __( 'The real domain acceptance transaction did not complete its expected rollback-only path.', 'certloom' ) );
					}
				}
			}

			$generation = self::restore_generation( $generation_before_exists, $generation_before );
			self::add_check(
				$result,
				'generation_restore',
				is_wp_error( $generation ) ? 'fail' : 'pass',
				__( 'Domain-generation cleanup', 'certloom' ),
				is_wp_error( $generation ) ? __( 'The synthetic proof rolled back its rows but could not restore the pre-proof domain-generation marker.', 'certloom' ) : __( 'The domain-generation marker matches its pre-proof state.', 'certloom' )
			);

			$empty_after = self::assert_existing_domain_empty();
			self::add_check(
				$result,
				'synthetic_cleanup',
				is_wp_error( $empty_after ) ? 'fail' : 'pass',
				__( 'Synthetic data cleanup', 'certloom' ),
				is_wp_error( $empty_after ) ? __( 'One or more synthetic acceptance rows remained after rollback. The gated domain must be reviewed before migration.', 'certloom' ) : __( 'No synthetic acceptance rows remain in the gated domain.', 'certloom' )
			);

			$legacy_after = ( new LegacyCertificateRepository() )->snapshot();
			$legacy_unchanged = is_array( $legacy_before ) && is_array( $legacy_after )
				&& absint( $legacy_before['count'] ?? -1 ) === absint( $legacy_after['count'] ?? -2 )
				&& absint( $legacy_before['max_id'] ?? -1 ) === absint( $legacy_after['max_id'] ?? -2 )
				&& hash_equals( (string) ( $legacy_before['generation'] ?? '' ), (string) ( $legacy_after['generation'] ?? '' ) );
			self::add_check(
				$result,
				'legacy_unchanged',
				$legacy_unchanged ? 'pass' : 'fail',
				__( 'Live registry unchanged', 'certloom' ),
				$legacy_unchanged ? __( 'The proven 1.5 registry boundary did not change during the gated-domain proof.', 'certloom' ) : __( 'The live registry boundary changed while the proof was running, so this acceptance evidence cannot be trusted.', 'certloom' )
			);
		} catch ( \Throwable $error ) {
			self::add_check( $result, 'unexpected', 'fail', __( 'Gated-domain proof execution', 'certloom' ), __( 'The gated-domain acceptance proof encountered an unexpected error and stopped safely.', 'certloom' ) );
			self::restore_generation( $generation_before_exists, $generation_before );
		} finally {
			$released = $maintenance->release( $owner );
			if ( is_wp_error( $released ) ) {
				$release_error = $released;
				self::add_check( $result, 'maintenance_cleanup', 'fail', __( 'Acceptance maintenance cleanup', 'certloom' ), __( 'The proof completed but could not release its gated-domain maintenance lease safely.', 'certloom' ) );
			} else {
				self::add_check( $result, 'maintenance_cleanup', 'pass', __( 'Acceptance maintenance cleanup', 'certloom' ), __( 'The gated-domain maintenance lease was released.', 'certloom' ) );
			}
		}

		$result['overall'] = self::overall_status( $result );
		$result['checked_at'] = time();
		$saved = update_option( self::RESULT_OPTION, $result, false );
		if ( ! $saved && $result !== get_option( self::RESULT_OPTION, null ) ) {
			return new \WP_Error( 'certloom_domain_acceptance_result_save_failed', __( 'The gated-domain proof ran, but WordPress could not save its non-sensitive result.', 'certloom' ) );
		}
		if ( is_wp_error( $release_error ) ) {
			return $result;
		}
		return $result;
	}

	/** @return array<string,mixed>|null */
	public static function get_last_result() {
		$result = get_option( self::RESULT_OPTION, null );
		if ( ! is_array( $result ) || self::RESULT_SCHEMA !== (string) ( $result['schema'] ?? '' ) || self::RESULT_VERSION !== (int) ( $result['version'] ?? 0 ) || ! isset( $result['checks'] ) || ! is_array( $result['checks'] ) ) {
			return null;
		}
		if ( ! is_array( RuntimePayloadEvidence::normalize( $result['runtime_payload'] ?? null ) ) || ! is_array( RuntimeEnvironmentEvidence::normalize( $result['runtime_environment'] ?? null ) ) ) {
			return null;
		}
		$overall = sanitize_key( (string) ( $result['overall'] ?? '' ) );
		$version = sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) );
		$checked_at = absint( $result['checked_at'] ?? 0 );
		if ( ! in_array( $overall, array( 'passed', 'incomplete', 'failed' ), true ) || '' === $version || ! $checked_at || empty( $result['checks'] ) ) {
			return null;
		}
		foreach ( $result['checks'] as $check ) {
			if ( ! is_array( $check ) || '' === sanitize_key( (string) ( $check['id'] ?? '' ) ) || ! in_array( (string) ( $check['status'] ?? '' ), array( 'pass', 'warning', 'fail' ), true ) || '' === trim( (string) ( $check['label'] ?? '' ) ) || '' === trim( (string) ( $check['message'] ?? '' ) ) ) {
				return null;
			}
		}
		return self::overall_status( $result ) === $overall ? $result : null;
	}

	/** @return string passed|failed|incomplete|stale|not_run */
	public static function evidence_state() {
		$result = self::get_last_result();
		if ( ! is_array( $result ) ) { return 'not_run'; }
		$overall = sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) );
		if ( 'passed' !== $overall ) { return in_array( $overall, array( 'failed', 'incomplete' ), true ) ? $overall : 'incomplete'; }
		$current = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
		$version = sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) );
		$checked_at = absint( $result['checked_at'] ?? 0 );
		if ( '' === $current || '' === $version || ! hash_equals( $current, $version ) || ! RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ?? null ) || ! RuntimeEnvironmentEvidence::matches_current( $result['runtime_environment'] ?? null ) || ! $checked_at || $checked_at > time() + 300 || time() - $checked_at > self::MAX_AGE ) {
			return 'stale';
		}
		return 'passed';
	}

	/** @return bool */
	public static function is_current_passed() {
		return 'passed' === self::evidence_state();
	}

	/** @return string */
	public static function get_text_report( $result = null ) {
		$result = is_array( $result ) ? $result : self::get_last_result();
		if ( ! is_array( $result ) ) { return __( 'Certloom gated-domain acceptance proof: not run.', 'certloom' ); }
		$lines = array( sprintf( 'Certloom gated-domain acceptance proof: %s', strtoupper( sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) ) ) ) );
		$lines[] = sprintf( 'Certloom version: %s', sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) ) );
		if ( is_array( $result['runtime_payload'] ?? null ) && ! empty( $result['runtime_payload']['sha256'] ) ) {
			$lines[] = sprintf( 'Certloom payload SHA-256: %s', sanitize_text_field( (string) $result['runtime_payload']['sha256'] ) );
		}
		$environment_hash = RuntimeEnvironmentEvidence::sha256( $result['runtime_environment'] ?? null );
		if ( '' !== $environment_hash ) { $lines[] = sprintf( 'Runtime environment SHA-256: %s', $environment_hash ); }
		$lines[] = sprintf( 'Checked UTC: %s', gmdate( 'Y-m-d H:i:s', absint( $result['checked_at'] ?? 0 ) ) );
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			if ( is_array( $check ) ) {
				$lines[] = sprintf( '[%s] %s: %s', strtoupper( sanitize_key( (string) ( $check['status'] ?? 'warning' ) ) ), (string) ( $check['label'] ?? '' ), (string) ( $check['message'] ?? '' ) );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Returns a privacy-safe schema diagnostic without database names, prefixes, SQL or raw server errors.
	 *
	 * @param \WP_Error $error Schema error.
	 * @return string
	 */
	private static function safe_schema_diagnostic( $error ) {
		$code = is_wp_error( $error ) ? sanitize_key( (string) $error->get_error_code() ) : '';
		$parts = array( '' !== $code ? $code : 'certloom_domain_schema_unknown' );
		$data = is_object( $error ) && method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : null;
		if ( is_array( $data ) ) {
			foreach ( array( 'stage', 'object', 'field', 'index' ) as $key ) {
				$value = sanitize_key( (string) ( $data[ $key ] ?? '' ) );
				if ( '' !== $value ) {
					$parts[] = $key . '=' . $value;
				}
			}
		}
		return implode( '; ', $parts );
	}

	/** @return true|\WP_Error */
	private static function preconditions() {
		if ( ! class_exists( '\\Ecapla_CertVerify_Database_Proof' ) || ! \Ecapla_CertVerify_Database_Proof::is_current_passed() ) {
			return new \WP_Error( 'certloom_domain_acceptance_database_proof_required', __( 'Run a fresh passing isolated database proof before the gated Credential Series acceptance proof.', 'certloom' ) );
		}
		if ( ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY )
			|| ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW )
			|| ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY ) ) {
			return new \WP_Error( 'certloom_domain_acceptance_feature_gate_active', __( 'The synthetic gated-domain proof is only available before next-domain development features are enabled.', 'certloom' ) );
		}
		$cutover = new DomainCutoverState();
		if ( ! $cutover->is_clean_legacy_state() ) {
			return new \WP_Error( 'certloom_domain_acceptance_cutover_active', __( 'The synthetic gated-domain proof is not available after credential authority has been cut over.', 'certloom' ) );
		}
		$migration = new LegacyMigrationState();
		if ( LegacyMigrationState::NOT_STARTED !== $migration->status() ) {
			return new \WP_Error( 'certloom_domain_acceptance_migration_state', __( 'The synthetic gated-domain proof requires legacy migration to be completely not started.', 'certloom' ) );
		}
		if ( false !== get_option( 'certloom_domain_restore_journal', false ) || false !== get_option( 'certloom_domain_restore_build', false ) ) {
			return new \WP_Error( 'certloom_domain_acceptance_restore_state', __( 'The gated-domain proof will not run while domain restore/recovery state exists.', 'certloom' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function assert_existing_domain_empty() {
		global $wpdb;
		foreach ( self::$tables as $logical ) {
			$table = SchemaManager::table( $logical );
			$wpdb->last_error = '';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_domain_acceptance_table_inspect_failed', __( 'Certloom could not inspect the gated-domain tables safely.', 'certloom' ) );
			}
			if ( (string) $exists !== (string) $table ) { continue; }
			$wpdb->last_error = '';
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( '' !== (string) $wpdb->last_error || null === $count ) {
				return new \WP_Error( 'certloom_domain_acceptance_table_count_failed', __( 'Certloom could not confirm that the gated domain is empty.', 'certloom' ) );
			}
			if ( 0 !== (int) $count ) {
				return new \WP_Error( 'certloom_domain_acceptance_not_empty', __( 'The gated Credential Series tables already contain data. Certloom refused to mix synthetic proof rows with existing domain data.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function exercise_domain( array &$result ) {
		$token = strtolower( wp_generate_password( 10, false, false ) );
		$issuer_repo = new IssuerRepository();
		$recipient_repo = new RecipientRepository();
		$program_repo = new ProgramRepository();
		$batch_repo = new BatchRepository();
		$signer_repo = new SignerRepository();
		$credential_repo = new CredentialRepository();
		$event_repo = new EventRepository();
		$lifecycle = new CredentialLifecycleService( $credential_repo, $event_repo, null, new DirectTransaction() );
		$verify = new CredentialVerificationService( $credential_repo );
		$template_repo = new TemplateRepository( null, null, new DirectTransaction() );

		$issuer = $issuer_repo->create( array( 'name' => 'Certloom Acceptance Proof ' . $token, 'slug' => 'certloom-acceptance-proof-' . $token, 'settings' => array( 'proof' => true ) ) );
		if ( is_wp_error( $issuer ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $issuer ); }
		$recipient = $recipient_repo->create( array( 'display_name' => 'Certloom Acceptance Proof Recipient ' . $token, 'custom_fields' => array( 'proof' => true, 'unicode' => 'اردو' ) ) );
		if ( is_wp_error( $recipient ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $recipient ); }
		$program = $program_repo->create( array( 'issuer_id' => $issuer, 'code' => 'CAP-' . strtoupper( $token ), 'title' => 'Certloom Acceptance Proof Program', 'metadata' => array( 'proof' => true ) ) );
		if ( is_wp_error( $program ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $program ); }
		$signer = $signer_repo->create( array( 'display_name' => 'Acceptance Signer ' . $token, 'slug' => 'acceptance-signer-' . $token, 'title' => 'Director', 'active' => true, 'metadata' => array( 'proof' => true ) ) );
		if ( is_wp_error( $signer ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $signer ); }
		$batch = $batch_repo->create( array( 'program_id' => $program, 'code' => 'BATCH-' . strtoupper( $token ), 'name' => 'Acceptance Cohort', 'instructor_signer_id' => $signer, 'active' => true, 'metadata' => array( 'proof' => true ) ) );
		if ( is_wp_error( $batch ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $batch ); }
		$member = $batch_repo->add_recipient( $batch, $recipient );
		if ( is_wp_error( $member ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $member ); }
		$assignment = $signer_repo->add_assignment( $signer, 'batch', $batch, 'signer', 10 );
		if ( is_wp_error( $assignment ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $assignment ); }
		$resolved = $signer_repo->resolve_hierarchy( $issuer, $program, $batch, 'signer' );
		if ( is_wp_error( $resolved ) || empty( $resolved ) || absint( $resolved[0]['signer_id'] ?? 0 ) !== absint( $signer ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), is_wp_error( $resolved ) ? $resolved : new \WP_Error( 'certloom_domain_acceptance_signer_hierarchy', __( 'The signer hierarchy did not resolve the most-specific batch signer.', 'certloom' ) ) ); }
		$template_global = $template_repo->create( array( 'machine_key' => 'acceptance-global-' . $token, 'name' => 'Acceptance Global Template ' . $token, 'description' => 'Rollback-only global assignment proof', 'layout' => TemplateDefaults::blank_a4_landscape() ), get_current_user_id() );
		if ( is_wp_error( $template_global ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), $template_global ); }
		$template_specific = $template_repo->create( array( 'machine_key' => 'acceptance-specific-' . $token, 'name' => 'Acceptance Specific Template ' . $token, 'description' => 'Rollback-only specific assignment proof', 'layout' => TemplateDefaults::blank_a4_landscape() ), get_current_user_id() );
		if ( is_wp_error( $template_specific ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), $template_specific ); }
		$global_assignment = $template_repo->assign( $template_global, 'global', 0 );
		if ( is_wp_error( $global_assignment ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), $global_assignment ); }
		$program_assignment = $template_repo->assign( $template_specific, 'program', $program );
		if ( is_wp_error( $program_assignment ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), $program_assignment ); }
		$template_resolved = $template_repo->resolve_assignment_details( array( 'issuer_id' => $issuer, 'program_id' => $program, 'batch_id' => $batch ) );
		if ( is_wp_error( $template_resolved ) || ! is_array( $template_resolved ) || absint( $template_resolved['template_id'] ?? 0 ) !== absint( $template_specific ) || 'program' !== (string) ( $template_resolved['scope_type'] ?? '' ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), is_wp_error( $template_resolved ) ? $template_resolved : new \WP_Error( 'certloom_domain_acceptance_template_program_fallback', __( 'The Template hierarchy did not resolve the Program assignment before Global fallback.', 'certloom' ) ) ); }
		$direct_program = $template_repo->get_assignment( 'program', $program );
		if ( is_wp_error( $direct_program ) || ! is_array( $direct_program ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), is_wp_error( $direct_program ) ? $direct_program : new \WP_Error( 'certloom_domain_acceptance_template_direct_missing', __( 'The direct Program Template assignment could not be read.', 'certloom' ) ) ); }
		$stale_assignment = $template_repo->assign( $template_global, 'program', $program, 100, absint( $direct_program['id'] ), 'stale-proof' );
		if ( ! is_wp_error( $stale_assignment ) || 'certloom_template_assignment_stale' !== $stale_assignment->get_error_code() ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_template_stale', __( 'A stale Template assignment review was not rejected.', 'certloom' ) ) ); }
		$cleared_program = $template_repo->clear_assignment( 'program', $program, absint( $direct_program['id'] ), (string) $direct_program['updated_at'] );
		if ( is_wp_error( $cleared_program ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), $cleared_program ); }
		$template_fallback = $template_repo->resolve_assignment_details( array( 'issuer_id' => $issuer, 'program_id' => $program, 'batch_id' => $batch ) );
		if ( is_wp_error( $template_fallback ) || ! is_array( $template_fallback ) || absint( $template_fallback['template_id'] ?? 0 ) !== absint( $template_global ) || 'global' !== (string) ( $template_fallback['scope_type'] ?? '' ) ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), is_wp_error( $template_fallback ) ? $template_fallback : new \WP_Error( 'certloom_domain_acceptance_template_global_fallback', __( 'Clearing the Program Template assignment did not fall back to Global.', 'certloom' ) ) ); }
		$archive_assigned = $template_repo->archive( $template_global );
		if ( ! is_wp_error( $archive_assigned ) || 'certloom_template_archive_assigned' !== $archive_assigned->get_error_code() ) { return self::operation_error( $result, 'template_assignments', __( 'Template assignment hierarchy', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_template_archive_guard', __( 'An actively assigned Template was allowed to archive.', 'certloom' ) ) ); }
		self::add_check( $result, 'template_assignments', 'pass', __( 'Template assignment hierarchy', 'certloom' ), __( 'Real repository writes proved Program-over-Global resolution, explicit None/inherit fallback, stale-review rejection and assigned-Template archive protection.', 'certloom' ) );
		$series = $credential_repo->create_series( array( 'recipient_id' => $recipient, 'issuer_id' => $issuer, 'program_id' => $program, 'batch_id' => $batch ) );
		if ( is_wp_error( $series ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $series ); }
		$series_row = $credential_repo->find_series( $series );
		if ( is_wp_error( $series_row ) || ! is_array( $series_row ) || empty( $series_row['public_id'] ) || ! ( new PublicCredentialId() )->is_valid_v2( $series_row['public_id'] ) ) {
			return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), is_wp_error( $series_row ) ? $series_row : new \WP_Error( 'certloom_domain_acceptance_public_id', __( 'The proof could not read a valid public credential ID from the real repository.', 'certloom' ) ) );
		}
		$display_number = 'CAP-' . strtoupper( $token ) . '-001';
		$version = $credential_repo->create_version( $series, array( 'display_number' => $display_number, 'source_type' => 'certloom_acceptance_proof', 'source_ref' => $token, 'source_idempotency_key' => hash( 'sha256', 'certloom-acceptance-proof:' . $token ), 'snapshot' => array( 'proof' => true, 'holder' => 'Acceptance Probe' ) ) );
		if ( is_wp_error( $version ) ) { return self::operation_error( $result, 'repositories', __( 'Repository persistence', 'certloom' ), $version ); }
		self::add_check( $result, 'repositories', 'pass', __( 'Repository persistence', 'certloom' ), __( 'Recipient, issuer, program, batch, signer hierarchy, Credential Series and immutable Version persisted through the real repositories.', 'certloom' ) );

		$issued = $lifecycle->issue( $series, $version, array( 'reason_code' => 'acceptance_proof' ) );
		if ( is_wp_error( $issued ) ) { return self::operation_error( $result, 'lifecycle', __( 'Lifecycle and event history', 'certloom' ), $issued ); }
		$valid = $verify->verify_public_id( $series_row['public_id'] );
		if ( is_wp_error( $valid ) || ! $valid->found() || ! $valid->status() || ! $valid->status()->is_valid() ) {
			return self::operation_error( $result, 'verification', __( 'Status/verification truth', 'certloom' ), is_wp_error( $valid ) ? $valid : new \WP_Error( 'certloom_domain_acceptance_verify_valid', __( 'The issued synthetic credential did not verify Valid.', 'certloom' ) ) );
		}
		$suspended = $lifecycle->suspend( $series, array( 'reason_code' => 'acceptance_proof' ) );
		if ( is_wp_error( $suspended ) ) { return self::operation_error( $result, 'lifecycle', __( 'Lifecycle and event history', 'certloom' ), $suspended ); }
		$suspended_result = $verify->verify_public_id( $series_row['public_id'] );
		if ( is_wp_error( $suspended_result ) || ! $suspended_result->status() || 'suspended' !== $suspended_result->status()->code() ) {
			return self::operation_error( $result, 'verification', __( 'Status/verification truth', 'certloom' ), is_wp_error( $suspended_result ) ? $suspended_result : new \WP_Error( 'certloom_domain_acceptance_verify_suspended', __( 'The suspended synthetic credential did not verify Suspended.', 'certloom' ) ) );
		}
		$reinstated = $lifecycle->reinstate( $series, array( 'reason_code' => 'acceptance_proof' ) );
		if ( is_wp_error( $reinstated ) ) { return self::operation_error( $result, 'lifecycle', __( 'Lifecycle and event history', 'certloom' ), $reinstated ); }
		$revoked = $lifecycle->revoke( $series, array( 'reason_code' => 'acceptance_proof' ) );
		if ( is_wp_error( $revoked ) ) { return self::operation_error( $result, 'lifecycle', __( 'Lifecycle and event history', 'certloom' ), $revoked ); }
		$revoked_result = $verify->verify_public_id( $series_row['public_id'] );
		if ( is_wp_error( $revoked_result ) || ! $revoked_result->status() || 'revoked' !== $revoked_result->status()->code() ) {
			return self::operation_error( $result, 'verification', __( 'Status/verification truth', 'certloom' ), is_wp_error( $revoked_result ) ? $revoked_result : new \WP_Error( 'certloom_domain_acceptance_verify_revoked', __( 'The revoked synthetic credential did not verify Revoked.', 'certloom' ) ) );
		}
		$events = $event_repo->list_for_series( $series, 20 );
		if ( is_wp_error( $events ) || count( $events ) < 4 ) {
			return self::operation_error( $result, 'lifecycle', __( 'Lifecycle and event history', 'certloom' ), is_wp_error( $events ) ? $events : new \WP_Error( 'certloom_domain_acceptance_events', __( 'The synthetic lifecycle did not produce the expected append-only event history.', 'certloom' ) ) );
		}
		self::add_check( $result, 'lifecycle', 'pass', __( 'Lifecycle and event history', 'certloom' ), __( 'Issue, suspend, reinstate and revoke transitions persisted atomically with append-only events.', 'certloom' ) );
		self::add_check( $result, 'verification', 'pass', __( 'Status/verification truth', 'certloom' ), __( 'The real repository and Status Service returned Valid, Suspended and Revoked at the expected transitions.', 'certloom' ) );

		$series_two = $credential_repo->create_series( array( 'recipient_id' => $recipient, 'issuer_id' => $issuer, 'program_id' => $program ) );
		if ( is_wp_error( $series_two ) ) { return self::operation_error( $result, 'constraints', __( 'Credential constraints', 'certloom' ), $series_two ); }
		$collision = $credential_repo->create_version( $series_two, array( 'display_number' => $display_number, 'snapshot' => array( 'proof' => true ) ) );
		if ( ! is_wp_error( $collision ) || 'certloom_display_number_duplicate' !== $collision->get_error_code() ) {
			return self::operation_error( $result, 'constraints', __( 'Credential constraints', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_number_constraint', __( 'The real domain did not enforce global human certificate-number ownership.', 'certloom' ) ) );
		}
		global $wpdb;
		$wpdb->last_error = '';
		$number_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE display_number = %s', SchemaManager::table( 'credential_numbers' ), $display_number ) );
		if ( '' !== (string) $wpdb->last_error || 1 !== (int) $number_count ) {
			return self::operation_error( $result, 'constraints', __( 'Credential constraints', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_number_registry', __( 'The global certificate-number registry did not retain exactly one owner after the duplicate attempt.', 'certloom' ) ) );
		}
		$generation_before_bad = ( new DomainGeneration() )->current();
		$bad_number = 'CAP-' . strtoupper( $token ) . '-BAD';
		$bad_resource = fopen( 'php://temp', 'r' );
		$bad = $credential_repo->create_version( $series_two, array( 'display_number' => $bad_number, 'snapshot' => array( 'resource' => $bad_resource ) ) );
		if ( is_resource( $bad_resource ) ) { fclose( $bad_resource ); }
		if ( ! is_wp_error( $bad ) || 'certloom_version_snapshot_invalid' !== $bad->get_error_code() ) {
			return self::operation_error( $result, 'constraints', __( 'Credential constraints', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_snapshot_constraint', __( 'The real repository did not reject an unserializable immutable snapshot through the safe path.', 'certloom' ) ) );
		}
		$wpdb->last_error = '';
		$bad_reserved = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE display_number = %s', SchemaManager::table( 'credential_numbers' ), $bad_number ) );
		if ( '' !== (string) $wpdb->last_error || 0 !== (int) $bad_reserved || ! hash_equals( $generation_before_bad, ( new DomainGeneration() )->current() ) ) {
			return self::operation_error( $result, 'constraints', __( 'Credential constraints', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_snapshot_side_effect', __( 'The rejected immutable snapshot changed generation state or stranded a certificate-number reservation.', 'certloom' ) ) );
		}
		self::add_check( $result, 'constraints', 'pass', __( 'Credential constraints', 'certloom' ), __( 'The real database/repository path enforced one global display-number owner and rejected invalid immutable snapshots without side effects.', 'certloom' ) );

		$today = gmdate( 'Y-m-d' );
		$tomorrow = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$rows = array(
			array( 'id' => 900001, 'cert_number' => 'MIG-V-' . $token, 'holder_name' => 'Migration Valid', 'status' => 'valid', 'status_source' => 'manual', 'issue_date' => $yesterday, 'expiry_date' => $tomorrow, 'custom_fields' => array() ),
			array( 'id' => 900002, 'cert_number' => 'MIG-S-' . $token, 'holder_name' => 'Migration Suspended', 'status' => 'suspended', 'status_source' => 'manual', 'issue_date' => $yesterday, 'expiry_date' => $tomorrow, 'custom_fields' => array() ),
			array( 'id' => 900003, 'cert_number' => 'MIG-R-' . $token, 'holder_name' => 'Migration Withdrawn', 'status' => 'withdrawn', 'status_source' => 'manual', 'issue_date' => $yesterday, 'expiry_date' => $tomorrow, 'custom_fields' => array() ),
			array( 'id' => 900004, 'cert_number' => 'MIG-E-' . $token, 'holder_name' => 'Migration Expired', 'status' => 'valid', 'status_source' => 'manual', 'issue_date' => $yesterday, 'expiry_date' => $yesterday, 'custom_fields' => array() ),
		);
		$preflight = ( new LegacyMigrationPreflightService() )->preflight( $rows, $today );
		if ( 4 !== absint( $preflight['total'] ?? 0 ) || 4 !== absint( $preflight['ready'] ?? 0 ) || 0 !== absint( $preflight['errors'] ?? 0 ) ) {
			return self::operation_error( $result, 'migration_parity', __( 'Legacy migration semantics', 'certloom' ), new \WP_Error( 'certloom_domain_acceptance_migration_parity', __( 'The synthetic legacy status set did not pass the migration parity mapper/Status Service.', 'certloom' ) ) );
		}
		self::add_check( $result, 'migration_parity', 'pass', __( 'Legacy migration semantics', 'certloom' ), __( 'Synthetic Valid, Suspended, Withdrawn→Revoked and date-expired legacy rows all preserve public-status parity.', 'certloom' ) );

		return true;
	}

	/** @return \WP_Error */
	private static function operation_error( array &$result, $id, $label, $error ) {
		self::add_check( $result, $id, 'fail', $label, __( 'The real gated-domain acceptance path failed and the outer transaction will roll back all synthetic writes.', 'certloom' ) );
		return is_wp_error( $error ) ? $error : new \WP_Error( 'certloom_domain_acceptance_failed', __( 'The gated-domain acceptance proof failed.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private static function restore_generation( $existed, $value ) {
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( DomainGeneration::OPTION, 'options' ); }
		$current_exists = false !== get_option( DomainGeneration::OPTION, false );
		$current = get_option( DomainGeneration::OPTION, null );
		if ( $existed ) {
			if ( $current_exists && (string) $current === (string) $value ) { return true; }
			$saved = update_option( DomainGeneration::OPTION, $value, false );
			return $saved || (string) get_option( DomainGeneration::OPTION, '' ) === (string) $value ? true : new \WP_Error( 'certloom_domain_acceptance_generation_restore_failed', __( 'Certloom could not restore the domain-generation marker after its rollback-only proof.', 'certloom' ) );
		}
		if ( ! $current_exists ) { return true; }
		$deleted = delete_option( DomainGeneration::OPTION );
		return $deleted || false === get_option( DomainGeneration::OPTION, false ) ? true : new \WP_Error( 'certloom_domain_acceptance_generation_restore_failed', __( 'Certloom could not remove the synthetic domain-generation marker after its rollback-only proof.', 'certloom' ) );
	}

	/** @return array<string,mixed> */
	private static function new_result( array $runtime_payload, array $runtime_environment ) {
		return array(
			'schema' => self::RESULT_SCHEMA,
			'version' => self::RESULT_VERSION,
			'plugin_version' => defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '',
			'runtime_payload' => $runtime_payload,
			'runtime_environment' => $runtime_environment,
			'overall' => 'incomplete',
			'checked_at' => 0,
			'checks' => array(),
		);
	}

	private static function add_check( array &$result, $id, $status, $label, $message ) {
		$result['checks'][] = array(
			'id' => sanitize_key( (string) $id ),
			'status' => in_array( $status, array( 'pass', 'warning', 'fail' ), true ) ? $status : 'warning',
			'label' => (string) $label,
			'message' => (string) $message,
		);
	}

	private static function has_failures( array $result ) {
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			if ( is_array( $check ) && 'fail' === (string) ( $check['status'] ?? '' ) ) { return true; }
		}
		return false;
	}

	private static function overall_status( array $result ) {
		$warning = false;
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			$status = is_array( $check ) ? (string) ( $check['status'] ?? '' ) : '';
			if ( 'fail' === $status ) { return 'failed'; }
			if ( 'warning' === $status ) { $warning = true; }
		}
		return $warning ? 'incomplete' : 'passed';
	}
}
