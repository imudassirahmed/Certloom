<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use Certloom\Application\Privacy\RecipientAnonymizationService;
use Certloom\Infrastructure\Repository\PrivacyRepository;

/** WordPress Privacy API integration for Certloom. */
final class Ecapla_CertVerify_Privacy {
	const PAGE_SIZE = 50;
	const POLICY_RETAIN = 'retain';
	const POLICY_ANONYMIZE_MUTABLE = 'anonymize_mutable_recipient';

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['certloom'] = array(
			'exporter_friendly_name' => __( 'Certloom credential data', 'certloom' ),
			'callback' => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['certloom'] = array(
			'eraser_friendly_name' => __( 'Certloom mutable recipient data', 'certloom' ),
			'callback' => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/** @return array{data:array,done:bool} */
	public static function export_personal_data( $email_address, $page = 1 ) {
		$email = sanitize_email( (string) $email_address );
		$page  = max( 1, absint( $page ) );
		if ( '' === $email ) { return array( 'data' => array(), 'done' => true ); }
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$data = array();
		$counts = array();

		$legacy = Ecapla_CertVerify_Database::get_privacy_email_candidates( $email, $offset, self::PAGE_SIZE );
		if ( is_wp_error( $legacy ) ) { return self::export_error( $legacy ); }
		$counts[] = count( $legacy );
		foreach ( $legacy as $row ) {
			if ( ! self::record_contains_email( $row['custom_fields'] ?? array(), $email ) ) { continue; }
			$data[] = self::legacy_export_item( $row );
		}

		$repo = new PrivacyRepository();
		$domain_exists = $repo->domain_storage_exists();
		if ( ! $domain_exists && is_wp_error( $repo->storage_error() ) ) { return self::export_error( $repo->storage_error() ); }
		if ( $domain_exists ) {
			$wp_user = function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;
			$wp_user_id = $wp_user && isset( $wp_user->ID ) ? absint( $wp_user->ID ) : 0;
			$recipients = $repo->find_recipients_for_identity( $email, $wp_user_id, $offset, self::PAGE_SIZE );
			if ( is_wp_error( $recipients ) ) { return self::export_error( $recipients ); }
			$counts[] = count( $recipients );
			foreach ( $recipients as $recipient ) { $data[] = self::recipient_export_item( $recipient ); }
			foreach ( array(
				array( 'method' => 'export_series_for_identity', 'group' => 'certloom-credential-series', 'label' => __( 'Certloom credential identities', 'certloom' ), 'prefix' => 'series' ),
				array( 'method' => 'export_versions_for_identity', 'group' => 'certloom-credential-versions', 'label' => __( 'Certloom issued credential history', 'certloom' ), 'prefix' => 'version' ),
				array( 'method' => 'export_events_for_identity', 'group' => 'certloom-credential-events', 'label' => __( 'Certloom credential audit history', 'certloom' ), 'prefix' => 'event' ),
			) as $spec ) {
				$rows = $repo->{$spec['method']}( $email, $wp_user_id, $offset, self::PAGE_SIZE );
				if ( is_wp_error( $rows ) ) { return self::export_error( $rows ); }
				$counts[] = count( $rows );
				foreach ( $rows as $row ) { $data[] = self::domain_history_export_item( $spec['prefix'], $row ); }
			}
			$signers = $repo->export_signers_for_email( $email, $offset, self::PAGE_SIZE );
			if ( is_wp_error( $signers ) ) { return self::export_error( $signers ); }
			$counts[] = count( $signers );
			foreach ( $signers as $row ) { $data[] = self::signer_export_item( $row ); }

			$issuers = $repo->export_issuers_for_email( $email, $offset, self::PAGE_SIZE );
			if ( is_wp_error( $issuers ) ) { return self::export_error( $issuers ); }
			$counts[] = count( $issuers );
			foreach ( $issuers as $row ) {
				$issuer_settings = self::decode_json_array_strict( $row['settings_json'] ?? null );
				if ( is_wp_error( $issuer_settings ) ) { return self::export_error( $issuer_settings ); }
				if ( self::record_contains_email( $issuer_settings, $email ) ) {
					$data[] = self::issuer_export_item( $row, $email, $issuer_settings );
				}
			}
		}
		$done = true;
		foreach ( $counts as $count ) { if ( $count >= self::PAGE_SIZE ) { $done = false; break; } }
		return array( 'data' => $data, 'done' => $done );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:array,done:bool} */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		$email = sanitize_email( (string) $email_address );
		$page  = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( '' === $email ) { return $result; }

		/* Current-authority legacy certificate rows are credential evidence, not mutable profiles. */
		$legacy = Ecapla_CertVerify_Database::get_privacy_email_candidates( $email, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE );
		if ( is_wp_error( $legacy ) ) { $result['items_retained'] = true; $result['messages'][] = $legacy->get_error_message(); return $result; }
		foreach ( $legacy as $row ) {
			if ( self::record_contains_email( $row['custom_fields'] ?? array(), $email ) ) {
				$result['items_retained'] = true;
				$result['messages'][] = __( 'Certloom retained one or more certificate records because they are issued credential evidence. An administrator can separately change public visibility where appropriate.', 'certloom' );
				break;
			}
		}
		if ( count( $legacy ) >= self::PAGE_SIZE ) { $result['done'] = false; }

		$repo = new PrivacyRepository();
		if ( ! $repo->domain_storage_exists() ) {
			if ( is_wp_error( $repo->storage_error() ) ) {
				$result['items_retained'] = true;
				$result['messages'][] = $repo->storage_error()->get_error_message();
			}
			return self::normalize_eraser_messages( $result );
		}
		$wp_user = function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;
		$wp_user_id = $wp_user && isset( $wp_user->ID ) ? absint( $wp_user->ID ) : 0;
		$recipients = $repo->find_recipients_for_identity( $email, $wp_user_id, $offset, self::PAGE_SIZE );
		if ( is_wp_error( $recipients ) ) { $result['items_retained'] = true; $result['messages'][] = $recipients->get_error_message(); return self::normalize_eraser_messages( $result ); }
		if ( count( $recipients ) >= self::PAGE_SIZE ) { $result['done'] = false; }

		$policy = sanitize_key( (string) Ecapla_CertVerify_Settings::get( 'privacy_erasure_policy', self::POLICY_RETAIN ) );
		if ( self::POLICY_ANONYMIZE_MUTABLE === $policy ) {
			$service = new RecipientAnonymizationService( $repo );
			foreach ( $recipients as $recipient ) {
				$out = $service->anonymize( absint( $recipient['id'] ?? 0 ) );
				if ( is_wp_error( $out ) ) {
					$result['items_retained'] = true;
					$result['messages'][] = $out->get_error_message();
					continue;
				}
				if ( ! empty( $out['anonymized'] ) ) { $result['items_removed'] = true; }
				if ( ! empty( $out['items_retained'] ) ) {
					$result['items_retained'] = true;
					$result['messages'][] = __( 'Certloom anonymized the mutable recipient profile but retained immutable issued credential and audit evidence.', 'certloom' );
				}
			}
		} elseif ( ! empty( $recipients ) ) {
			$result['items_retained'] = true;
			$result['messages'][] = __( 'Certloom retained the mutable recipient profile because this site’s privacy policy is configured to require administrator review before anonymization.', 'certloom' );
		}

		$signers = $repo->export_signers_for_email( $email, 0, 1 );
		$issuers = $repo->export_issuers_for_email( $email, 0, 10 );
		if ( is_wp_error( $signers ) || is_wp_error( $issuers ) ) {
			$result['items_retained'] = true;
			$result['messages'][] = __( 'Certloom could not fully inspect issuer/signer contact data during this erasure request. No organizational identity data was changed.', 'certloom' );
		} else {
			$issuer_match = false;
			foreach ( $issuers as $row ) {
				$issuer_settings = self::decode_json_array_strict( $row['settings_json'] ?? null );
				if ( is_wp_error( $issuer_settings ) ) {
					$result['items_retained'] = true;
					$result['messages'][] = $issuer_settings->get_error_message();
					return self::normalize_eraser_messages( $result );
				}
				if ( self::record_contains_email( $issuer_settings, $email ) ) { $issuer_match = true; break; }
			}
			if ( ! empty( $signers ) || $issuer_match ) {
				$result['items_retained'] = true;
				$result['messages'][] = __( 'Certloom retained issuer or signer contact data because organizational credential identities require explicit administrator/legal-owner review before alteration.', 'certloom' );
			}
		}
		return self::normalize_eraser_messages( $result );
	}

	private static function legacy_export_item( array $row ) {
		$fields = Ecapla_CertVerify_Fields::get_field_map();
		$data = array();
		foreach ( array( 'cert_number', 'holder_name', 'status', 'issue_date', 'expiry_date' ) as $key ) {
			if ( isset( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
				$label = isset( $fields[ $key ]['label'] ) ? $fields[ $key ]['label'] : ucwords( str_replace( '_', ' ', $key ) );
				$data[] = array( 'name' => $label, 'value' => (string) $row[ $key ] );
			}
		}
		foreach ( (array) ( $row['custom_fields'] ?? array() ) as $key => $value ) {
			$label = isset( $fields[ $key ]['label'] ) ? $fields[ $key ]['label'] : (string) $key;
			$data[] = array( 'name' => $label, 'value' => self::export_value( $value ) );
		}
		return array( 'group_id' => 'certloom-legacy-credentials', 'group_label' => __( 'Certloom certificates', 'certloom' ), 'item_id' => 'credential-' . absint( $row['id'] ?? 0 ), 'data' => $data );
	}

	private static function recipient_export_item( array $row ) {
		return self::generic_export_item( 'certloom-recipients', __( 'Certloom recipient profiles', 'certloom' ), 'recipient', $row );
	}

	private static function domain_history_export_item( $kind, array $row ) {
		$kind = sanitize_key( (string) $kind );
		if ( 'event' === $kind ) {
			$allowed = array( 'id', 'series_id', 'version_id', 'event_type', 'old_state_json', 'new_state_json', 'reason_code', 'public_reason', 'created_at' );
			$group_id = 'certloom-credential-events';
			$label = __( 'Certloom credential audit history', 'certloom' );
		} elseif ( 'version' === $kind ) {
			$allowed = array( 'id', 'series_id', 'version_number', 'previous_version_id', 'display_number', 'issuance_state', 'legacy_expired', 'valid_from', 'issued_at', 'expires_at', 'grade', 'source_type', 'source_ref', 'snapshot_json', 'created_at' );
			$group_id = 'certloom-credential-versions';
			$label = __( 'Certloom issued credential history', 'certloom' );
		} else {
			$allowed = array( 'id', 'public_id', 'program_id', 'batch_id', 'issuer_id', 'current_version_id', 'administrative_state', 'compliance_state', 'visibility', 'created_at', 'updated_at', 'closed_at' );
			$group_id = 'certloom-credential-series';
			$label = __( 'Certloom credential identities', 'certloom' );
			$kind = 'series';
		}
		return self::generic_export_item( $group_id, $label, $kind, array_intersect_key( $row, array_flip( $allowed ) ) );
	}

	private static function signer_export_item( array $row ) {
		$allowed = array( 'id', 'display_name', 'slug', 'title', 'email', 'active', 'metadata_json', 'created_at', 'updated_at' );
		return self::generic_export_item( 'certloom-signers', __( 'Certloom signer profiles', 'certloom' ), 'signer', array_intersect_key( $row, array_flip( $allowed ) ) );
	}

	private static function issuer_export_item( array $row, $email, array $settings ) {
		$contact = array();
		foreach ( $settings as $key => $value ) {
			if ( self::record_contains_email( $value, $email ) ) { $contact[ $key ] = $value; }
		}
		$clean = array(
			'id' => $row['id'] ?? null,
			'name' => $row['name'] ?? null,
			'slug' => $row['slug'] ?? null,
			'contact_data' => $contact,
			'created_at' => $row['created_at'] ?? null,
			'updated_at' => $row['updated_at'] ?? null,
		);
		return self::generic_export_item( 'certloom-issuers', __( 'Certloom issuer contact data', 'certloom' ), 'issuer', $clean );
	}

	private static function generic_export_item( $group_id, $group_label, $prefix, array $row ) {
		$data = array();
		foreach ( $row as $key => $value ) {
			if ( null === $value || '' === $value || array() === $value ) { continue; }
			$data[] = array( 'name' => ucwords( str_replace( '_', ' ', (string) $key ) ), 'value' => self::export_value( $value ) );
		}
		return array( 'group_id' => (string) $group_id, 'group_label' => (string) $group_label, 'item_id' => sanitize_key( (string) $prefix ) . '-' . absint( $row['id'] ?? 0 ), 'data' => $data );
	}

	private static function export_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return false === $encoded ? '' : $encoded;
		}
		return (string) $value;
	}

	private static function record_contains_email( $value, $email ) {
		if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $nested ) { if ( self::record_contains_email( $nested, $email ) ) { return true; } }
			return false;
		}
		return 0 === strcasecmp( trim( (string) $value ), (string) $email );
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function decode_json_array_strict( $json ) {
		if ( is_array( $json ) ) { return $json; }
		if ( null === $json || '' === trim( (string) $json ) ) { return array(); }
		$decoded = json_decode( (string) $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new WP_Error( 'certloom_privacy_issuer_settings_corrupt', __( 'Stored Certloom issuer contact data is invalid and could not be inspected safely for this privacy request.', 'certloom' ) );
		}
		return $decoded;
	}

	private static function export_error( $error ) {
		return array(
			'data' => array(
				array(
					'group_id' => 'certloom-privacy-error',
					'group_label' => __( 'Certloom privacy export status', 'certloom' ),
					'item_id' => 'privacy-export-error',
					'data' => array( array( 'name' => __( 'Export status', 'certloom' ), 'value' => $error->get_error_message() ) ),
				),
			),
			'done' => true,
		);
	}

	private static function normalize_eraser_messages( array $result ) {
		$result['messages'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $result['messages'] ) ) ) );
		return $result;
	}
}
