<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen-selection, review-before-commit bulk operations for the live registry.
 */
class Ecapla_CertVerify_Bulk_Manager {

	const TRANSIENT_PREFIX = 'ecapla_certverify_bulk_';
	const TTL              = 900;
	const MAX_SELECTED     = 200;

	/**
	 * Creates a user-bound frozen selection for a later review/commit step.
	 *
	 * @param array $ids          Internal certificate IDs.
	 * @param array $return_state Read-only registry state restored after commit.
	 * @return string|WP_Error
	 */
	public static function create_session( $ids, $return_state = array() ) {
		$ids = self::normalize_ids( $ids );
		if ( empty( $ids ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_empty', __( 'Select at least one certificate before starting a bulk action.', 'certloom' ) );
		}
		if ( count( $ids ) > self::MAX_SELECTED ) {
			return new WP_Error( 'ecapla_certverify_bulk_too_large', sprintf( /* translators: %d: maximum selected certificates. */ __( 'Bulk review is limited to %d selected certificates at a time.', 'certloom' ), self::MAX_SELECTED ) );
		}

		$token = strtolower( wp_generate_password( 32, false, false ) );
		if ( ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_token_failed', __( 'Certloom could not start a secure bulk review session.', 'certloom' ) );
		}
		$session = array(
			'owner'        => get_current_user_id(),
			'ids'          => $ids,
			'selection'    => hash( 'sha256', implode( ',', $ids ) ),
			'created_at'   => time(),
			'expires_at'   => time() + self::TTL,
			'return_state' => self::sanitize_return_state( $return_state ),
			'preview'      => null,
			'committed'    => false,
		);
		if ( ! set_transient( self::TRANSIENT_PREFIX . $token, $session, self::TTL ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_session_failed', __( 'Certloom could not save the bulk review session. No certificate was changed.', 'certloom' ) );
		}
		return $token;
	}

	/** @return array|WP_Error */
	public static function get_session( $token ) {
		$token = self::normalize_token( $token );
		if ( '' === $token ) {
			return new WP_Error( 'ecapla_certverify_bulk_session_invalid', __( 'That bulk review session is invalid.', 'certloom' ) );
		}
		$session = get_transient( self::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $session ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_session_expired', __( 'That bulk review session expired. Select the certificates again.', 'certloom' ) );
		}
		if ( absint( $session['owner'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'ecapla_certverify_bulk_session_owner', __( 'That bulk review session belongs to another administrator.', 'certloom' ) );
		}
		$ids = self::normalize_ids( $session['ids'] ?? array() );
		if ( empty( $ids ) || count( $ids ) > self::MAX_SELECTED || ! hash_equals( (string) ( $session['selection'] ?? '' ), hash( 'sha256', implode( ',', $ids ) ) ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_session_corrupt', __( 'The saved bulk selection failed its integrity check. No certificate was changed.', 'certloom' ) );
		}
		if ( absint( $session['expires_at'] ?? 0 ) < time() ) {
			delete_transient( self::TRANSIENT_PREFIX . $token );
			return new WP_Error( 'ecapla_certverify_bulk_session_expired', __( 'That bulk review session expired. Select the certificates again.', 'certloom' ) );
		}
		$session['ids'] = $ids;
		return $session;
	}

	/**
	 * Validates one requested bulk change and freezes its exact eligible set.
	 *
	 * @param string $token Session token.
	 * @param array  $input Raw operation input.
	 * @return array|WP_Error
	 */
	public static function prepare_preview( $token, $input ) {
		$session = self::get_session( $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( ! empty( $session['committed'] ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_already_committed', __( 'That bulk change has already been committed.', 'certloom' ) );
		}

		$operation = self::sanitize_operation( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $operation ) ) {
			return $operation;
		}

		$eligible = array();
		$skipped  = array();
		foreach ( $session['ids'] as $id ) {
			$record = Ecapla_CertVerify_Database::get_by_id( $id );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			if ( ! $record ) {
				$skipped[] = array( 'id' => $id, 'cert_number' => '', 'reason' => __( 'Certificate no longer exists.', 'certloom' ) );
				continue;
			}
			$patch = self::patch_for_record( $record, $operation );
			if ( is_wp_error( $patch ) ) {
				$skipped[] = array( 'id' => $id, 'cert_number' => (string) $record['cert_number'], 'reason' => $patch->get_error_message() );
				continue;
			}
			$eligible[] = array( 'id' => $id, 'cert_number' => (string) $record['cert_number'], 'updated_at' => (string) ( $record['updated_at'] ?? '' ) );
		}

		$preview = array(
			'operation'       => $operation,
			'eligible'        => $eligible,
			'skipped'         => $skipped,
			'prepared_at'     => time(),
			'selection'       => (string) $session['selection'],
			'eligible_digest' => hash( 'sha256', implode( ',', wp_list_pluck( $eligible, 'id' ) ) ),
		);
		$session['preview'] = $preview;
		if ( ! set_transient( self::TRANSIENT_PREFIX . self::normalize_token( $token ), $session, max( 1, absint( $session['expires_at'] ) - time() ) ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_preview_save_failed', __( 'Certloom could not save the reviewed bulk change. No certificate was changed.', 'certloom' ) );
		}
		return $preview;
	}

	/**
	 * Commits only the exact reviewed operation and frozen eligible selection.
	 *
	 * @return array|WP_Error
	 */
	public static function commit( $token ) {
		$session = self::get_session( $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$preview = isset( $session['preview'] ) && is_array( $session['preview'] ) ? $session['preview'] : array();
		if ( empty( $preview ) || ! isset( $preview['operation'], $preview['eligible'], $preview['eligible_digest'] ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_preview_required', __( 'Review the bulk change before committing it.', 'certloom' ) );
		}
		if ( ! empty( $session['committed'] ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_already_committed', __( 'That bulk change has already been committed.', 'certloom' ) );
		}
		$eligible_ids = self::normalize_ids( wp_list_pluck( $preview['eligible'], 'id' ) );
		if ( ! hash_equals( (string) $preview['eligible_digest'], hash( 'sha256', implode( ',', $eligible_ids ) ) ) ) {
			return new WP_Error( 'ecapla_certverify_bulk_preview_corrupt', __( 'The reviewed bulk change failed its integrity check. No certificate was changed.', 'certloom' ) );
		}

		$changed = 0;
		$failed  = array();
		Ecapla_CertVerify_Database::begin_batch();
		$reviewed_rows = array();
		foreach ( (array) $preview['eligible'] as $reviewed ) {
			$reviewed_rows[ absint( $reviewed['id'] ?? 0 ) ] = $reviewed;
		}
		foreach ( $eligible_ids as $id ) {
			$record = Ecapla_CertVerify_Database::get_by_id( $id );
			if ( is_wp_error( $record ) ) {
				$failed[] = array( 'id' => $id, 'reason' => $record->get_error_message() );
				continue;
			}
			if ( ! $record ) {
				$failed[] = array( 'id' => $id, 'reason' => __( 'Certificate no longer exists.', 'certloom' ) );
				continue;
			}
			$reviewed_updated_at = isset( $reviewed_rows[ $id ]['updated_at'] ) ? (string) $reviewed_rows[ $id ]['updated_at'] : '';
			$current_updated_at  = isset( $record['updated_at'] ) ? (string) $record['updated_at'] : '';
			if ( $reviewed_updated_at !== $current_updated_at ) {
				$failed[] = array( 'id' => $id, 'reason' => __( 'Certificate changed after bulk review. Select it again before applying another bulk change.', 'certloom' ) );
				continue;
			}
			$patch = self::patch_for_record( $record, $preview['operation'] );
			if ( is_wp_error( $patch ) ) {
				$failed[] = array( 'id' => $id, 'reason' => $patch->get_error_message() );
				continue;
			}
			$result = Ecapla_CertVerify_Database::update( $id, $patch );
			if ( is_wp_error( $result ) ) {
				$failed[] = array( 'id' => $id, 'reason' => $result->get_error_message() );
				continue;
			}
			$changed++;
		}
		$batch_result = Ecapla_CertVerify_Database::end_batch();
		if ( is_wp_error( $batch_result ) ) {
			$failed[] = array( 'id' => 0, 'reason' => $batch_result->get_error_message() );
		}

		$session['committed'] = true;
		$session['result']    = array(
			'changed' => $changed,
			'failed'  => $failed,
			'skipped' => (array) ( $preview['skipped'] ?? array() ),
		);
		set_transient( self::TRANSIENT_PREFIX . self::normalize_token( $token ), $session, min( 300, max( 1, absint( $session['expires_at'] ) - time() ) ) );
		return $session['result'];
	}

	/** @return array */
	public static function get_editable_custom_fields() {
		$fields = array();
		foreach ( Ecapla_CertVerify_Fields::get_fields() as $field ) {
			if ( ! empty( $field['column'] ) || ! empty( $field['core'] ) || ! empty( $field['protected'] ) || ! empty( $field['locked_visibility'] ) ) {
				continue;
			}
			$fields[ $field['key'] ] = $field;
		}
		return $fields;
	}

	/** @return array */
	public static function sanitize_return_state( $state ) {
		$allowed = array( 'legacy_expired', 'status', 'attention', 'search_mode', 's', 'issue_from', 'issue_to', 'expiry_from', 'expiry_to', 'paged', 'orderby', 'order' );
		$clean   = array();
		foreach ( $allowed as $key ) {
			if ( ! isset( $state[ $key ] ) || is_array( $state[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $state[ $key ] );
			if ( '' !== $value ) {
				$clean[ $key ] = substr( $value, 0, 100 );
			}
		}
		return $clean;
	}

	private static function sanitize_operation( $input ) {
		$type = isset( $input['bulk_operation'] ) ? sanitize_key( (string) $input['bulk_operation'] ) : '';
		if ( 'set_status' === $type ) {
			$status = isset( $input['bulk_status'] ) ? sanitize_key( (string) $input['bulk_status'] ) : '';
			if ( ! in_array( $status, array( 'valid', 'suspended', 'withdrawn' ), true ) ) {
				return new WP_Error( 'ecapla_certverify_bulk_status_invalid', __( 'Choose a valid workflow status for the bulk change.', 'certloom' ) );
			}
			$reason_set = ! empty( $input['bulk_status_reason_set'] );
			$reason     = isset( $input['bulk_status_reason'] ) ? sanitize_textarea_field( (string) $input['bulk_status_reason'] ) : '';
			$validated  = Ecapla_CertVerify_Validator::custom_field_value( $reason, Ecapla_CertVerify_Fields::get_field( 'status_reason' ) );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			return array( 'type' => $type, 'status' => $status, 'reason_set' => $reason_set, 'reason' => $validated );
		}
		if ( in_array( $type, array( 'set_expiry', 'clear_expiry' ), true ) ) {
			$date = 'set_expiry' === $type ? Ecapla_CertVerify_Validator::date( $input['bulk_expiry_date'] ?? '' ) : null;
			if ( is_wp_error( $date ) || ( 'set_expiry' === $type && null === $date ) ) {
				return is_wp_error( $date ) ? $date : new WP_Error( 'ecapla_certverify_bulk_expiry_required', __( 'Choose an expiry date for the bulk change.', 'certloom' ) );
			}
			return array( 'type' => $type, 'expiry_date' => $date );
		}
		if ( in_array( $type, array( 'set_custom_field', 'clear_custom_field' ), true ) ) {
			$key    = isset( $input['bulk_field_key'] ) ? sanitize_key( (string) $input['bulk_field_key'] ) : '';
			$fields = self::get_editable_custom_fields();
			if ( '' === $key || ! isset( $fields[ $key ] ) ) {
				return new WP_Error( 'ecapla_certverify_bulk_field_invalid', __( 'Choose an editable custom field for the bulk change.', 'certloom' ) );
			}
			$value = '';
			if ( 'set_custom_field' === $type ) {
				$value = Ecapla_CertVerify_Validator::custom_field_value( $input['bulk_field_value'] ?? '', $fields[ $key ] );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
			return array( 'type' => $type, 'field_key' => $key, 'value' => $value );
		}
		return new WP_Error( 'ecapla_certverify_bulk_operation_invalid', __( 'Choose a supported bulk change.', 'certloom' ) );
	}

	private static function patch_for_record( $record, $operation ) {
		$type = sanitize_key( (string) ( $operation['type'] ?? '' ) );
		if ( 'set_status' === $type ) {
			$patch = array( 'status' => $operation['status'] );
			if ( ! empty( $operation['reason_set'] ) ) {
				$patch['custom_fields'] = array( 'status_reason' => (string) $operation['reason'] );
			}
			return $patch;
		}
		if ( 'set_expiry' === $type || 'clear_expiry' === $type ) {
			$expiry = 'clear_expiry' === $type ? '' : (string) $operation['expiry_date'];
			$range  = Ecapla_CertVerify_Validator::date_range( $record['issue_date'] ?? null, '' === $expiry ? null : $expiry );
			if ( is_wp_error( $range ) ) {
				return $range;
			}
			return array( 'expiry_date' => $expiry );
		}
		if ( 'set_custom_field' === $type || 'clear_custom_field' === $type ) {
			return array( 'custom_fields' => array( (string) $operation['field_key'] => (string) $operation['value'] ) );
		}
		return new WP_Error( 'ecapla_certverify_bulk_operation_invalid', __( 'The reviewed bulk operation is no longer supported.', 'certloom' ) );
	}

	private static function normalize_ids( $ids ) {
		$ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $ids ) ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function normalize_token( $token ) {
		$token = strtolower( sanitize_text_field( (string) $token ) );
		return preg_match( '/^[a-z0-9]{32}$/', $token ) ? $token : '';
	}
}
