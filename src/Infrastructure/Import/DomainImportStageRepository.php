<?php
namespace Certloom\Infrastructure\Import;

use Certloom\Application\Contract\DomainImportStageInterface;
use Certloom\Infrastructure\Database\SchemaManager;

/** Job-scoped durable import staging with cross-request duplicate enforcement. */
final class DomainImportStageRepository implements DomainImportStageInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function stage( $job_key, $row_number, array $plan ) {
		global $wpdb;
		$job_key = $this->job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$row_number = absint( $row_number );
		$display_number = trim( (string) ( $plan['version']['display_number'] ?? '' ) );
		if ( $row_number < 1 || '' === $display_number || $this->length( $display_number ) > 100 ) {
			return new \WP_Error( 'certloom_import_stage_row_invalid', __( 'Certloom could not stage an invalid import row.', 'certloom' ) );
		}
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error( 'certloom_import_stage_encode_failed', __( 'Certloom could not encode the validated import row.', 'certloom' ) );
		}
		$hash = hash( 'sha256', $json );
		$table = SchemaManager::table( 'import_rows' );

		// A retried preflight slice is idempotent only when the exact normalized plan matches.
		$wpdb->last_error = '';
		$by_row = $wpdb->get_row( $wpdb->prepare( 'SELECT source_row_number, display_number, plan_hash, outcome, existing_series_id FROM %i WHERE job_key = %s AND source_row_number = %d', $table, $job_key, $row_number ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_import_stage_read_failed', __( 'Certloom could not inspect its import staging rows.', 'certloom' ) );
		}
		if ( is_array( $by_row ) ) {
			if ( hash_equals( (string) $by_row['plan_hash'], $hash ) && (string) $by_row['display_number'] === $display_number ) {
				return array( 'outcome' => 'already_staged', 'row_number' => $row_number, 'existing_series_id' => absint( $by_row['existing_series_id'] ?? 0 ) ?: null );
			}
			return new \WP_Error( 'certloom_import_stage_row_changed', __( 'An import row changed after it was already preflighted. Restart the import review.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$by_number = $wpdb->get_row( $wpdb->prepare( 'SELECT source_row_number FROM %i WHERE job_key = %s AND display_number = %s', $table, $job_key, $display_number ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_import_stage_read_failed', __( 'Certloom could not inspect its staged certificate numbers.', 'certloom' ) );
		}
		if ( is_array( $by_number ) ) {
			return new \WP_Error(
				'certloom_import_stage_duplicate_number',
				sprintf( /* translators: %1$s: credential number; %2$d: source row number; %3$d: row number. */ __( 'Certificate number %1$s appears more than once in this import (rows %2$d and %3$d).', 'certloom' ), $display_number, absint( $by_number['source_row_number'] ), $row_number )
			);
		}

		$existing_series_id = $this->existing_series_id( $display_number );
		if ( is_wp_error( $existing_series_id ) ) { return $existing_series_id; }
		$outcome = $existing_series_id ? 'duplicate' : 'ready';
		$wpdb->last_error = '';
		$inserted = $wpdb->insert(
			$table,
			array(
				'job_key' => $job_key,
				'source_row_number' => $row_number,
				'display_number' => $display_number,
				'plan_hash' => $hash,
				'plan_json' => $json,
				'existing_series_id' => $existing_series_id ?: null,
				'outcome' => $outcome,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			// A concurrent insert can race our checks; classify it deterministically.
			$wpdb->last_error = '';
			$conflict = $wpdb->get_row( $wpdb->prepare( 'SELECT source_row_number, plan_hash FROM %i WHERE job_key = %s AND display_number = %s', $table, $job_key, $display_number ), ARRAY_A );
			if ( is_array( $conflict ) && absint( $conflict['source_row_number'] ?? 0 ) !== $row_number ) {
				return new \WP_Error( 'certloom_import_stage_duplicate_number', __( 'A duplicate certificate number was detected while the import was being preflighted.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_import_stage_write_failed', __( 'Certloom could not save the validated import row.', 'certloom' ) );
		}
		return array( 'outcome' => $outcome, 'row_number' => $row_number, 'existing_series_id' => $existing_series_id ?: null );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function read_batch( $job_key, $after_row, $limit = 500, $outcome = 'ready' ) {
		global $wpdb;
		$job_key = $this->job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$outcome = in_array( $outcome, array( 'ready', 'duplicate' ), true ) ? $outcome : 'ready';
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT source_row_number, display_number, plan_json, existing_series_id, outcome, commit_outcome, target_series_id, target_version_id FROM %i WHERE job_key = %s AND outcome = %s AND commit_outcome IS NULL AND source_row_number > %d ORDER BY source_row_number ASC LIMIT %d', SchemaManager::table( 'import_rows' ), $job_key, $outcome, absint( $after_row ), $limit ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_import_stage_read_failed', __( 'Certloom could not read a staged import batch.', 'certloom' ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$plan = json_decode( (string) ( $row['plan_json'] ?? '' ), true );
			if ( ! is_array( $plan ) || JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error( 'certloom_import_stage_corrupt', __( 'A validated import row could not be decoded safely.', 'certloom' ) );
			}
			$out[] = array(
				'row_number' => absint( $row['source_row_number'] ),
				'display_number' => (string) $row['display_number'],
				'plan' => $plan,
				'existing_series_id' => absint( $row['existing_series_id'] ?? 0 ) ?: null,
				'outcome' => (string) $row['outcome'],
				'commit_outcome' => isset( $row['commit_outcome'] ) ? (string) $row['commit_outcome'] : null,
				'target_series_id' => absint( $row['target_series_id'] ?? 0 ) ?: null,
				'target_version_id' => absint( $row['target_version_id'] ?? 0 ) ?: null,
			);
		}
		return $out;
	}

	/** @return array<string,int>|\WP_Error */
	public function summary( $job_key ) {
		global $wpdb;
		$job_key = $this->job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT outcome, COUNT(*) AS row_count FROM %i WHERE job_key = %s GROUP BY outcome', SchemaManager::table( 'import_rows' ), $job_key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_import_stage_summary_failed', __( 'Certloom could not summarize the import preflight.', 'certloom' ) );
		}
		$result = array( 'total' => 0, 'ready' => 0, 'duplicate' => 0 );
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['outcome'] ?? '' ) );
			$count = absint( $row['row_count'] ?? 0 );
			$result['total'] += $count;
			if ( array_key_exists( $key, $result ) ) { $result[ $key ] += $count; }
		}
		return $result;
	}

	/** @return true|\WP_Error */
	public function mark_committed( $job_key, $row_number, $outcome, $series_id = null, $version_id = null ) {
		global $wpdb;
		$job_key = $this->job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$row_number = absint( $row_number );
		$outcome = sanitize_key( (string) $outcome );
		if ( ! $row_number || ! in_array( $outcome, array( 'created', 'corrected' ), true ) ) {
			return new \WP_Error( 'certloom_import_commit_receipt_invalid', __( 'Certloom could not save an invalid import commit receipt.', 'certloom' ) );
		}
		$row = array(
			'commit_outcome' => $outcome,
			'target_series_id' => $series_id ? absint( $series_id ) : null,
			'target_version_id' => $version_id ? absint( $version_id ) : null,
			'committed_at' => current_time( 'mysql', true ),
		);
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'import_rows' ), $row, array( 'job_key' => $job_key, 'source_row_number' => $row_number, 'commit_outcome' => null ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_import_commit_receipt_failed', __( 'Certloom could not save the durable import result for a committed row.', 'certloom' ) );
		}
		if ( 1 === (int) $updated ) { return true; }

		$wpdb->last_error = '';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT commit_outcome, target_series_id, target_version_id FROM %i WHERE job_key = %s AND source_row_number = %d', SchemaManager::table( 'import_rows' ), $job_key, $row_number ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $existing ) ) {
			return new \WP_Error( 'certloom_import_commit_receipt_conflict', __( 'Certloom could not verify the durable import result after a concurrent retry.', 'certloom' ) );
		}
		$matches = (string) ( $existing['commit_outcome'] ?? '' ) === $outcome
			&& absint( $existing['target_series_id'] ?? 0 ) === absint( $series_id )
			&& absint( $existing['target_version_id'] ?? 0 ) === absint( $version_id );
		return $matches ? true : new \WP_Error( 'certloom_import_commit_receipt_conflict', __( 'A committed import row has a conflicting durable result.', 'certloom' ) );
	}

	/** @return array<string,int>|\WP_Error */
	public function commit_summary( $job_key ) {
		global $wpdb;
		$job_key = $this->job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT commit_outcome, COUNT(*) AS row_count FROM %i WHERE job_key = %s AND commit_outcome IS NOT NULL GROUP BY commit_outcome', SchemaManager::table( 'import_rows' ), $job_key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_import_commit_summary_failed', __( 'Certloom could not summarize committed import rows.', 'certloom' ) );
		}
		$result = array( 'committed' => 0, 'created' => 0, 'corrected' => 0 );
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['commit_outcome'] ?? '' ) );
			$count = absint( $row['row_count'] ?? 0 );
			$result['committed'] += $count;
			if ( array_key_exists( $key, $result ) ) { $result[ $key ] += $count; }
		}
		return $result;
	}

	/** @return true|\WP_Error */
	public function clear( $job_key ) {
		global $wpdb;
		$job_key = $this->job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$wpdb->last_error = '';
		$deleted = $wpdb->delete( SchemaManager::table( 'import_rows' ), array( 'job_key' => $job_key ) );
		return false === $deleted || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_import_stage_clear_failed', __( 'Certloom could not clean up its import staging rows.', 'certloom' ) )
			: true;
	}

	/** @return int|\WP_Error */
	private function existing_series_id( $display_number ) {
		global $wpdb;
		$wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT series_id FROM %i WHERE display_number = %s', SchemaManager::table( 'credential_numbers' ), $display_number ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_import_existing_number_read_failed', __( 'Certloom could not check existing certificate-number assignments.', 'certloom' ) );
		}
		return absint( $id );
	}

	/** @return string|\WP_Error */
	private function job_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : new \WP_Error( 'certloom_job_key_invalid', __( 'The Certloom job identifier is invalid.', 'certloom' ) );
	}
	private function length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value ); }
}
