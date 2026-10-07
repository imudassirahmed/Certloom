<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, privacy-safe operational evidence for email automation outcomes. */
final class EmailAutomationRunRepository {
	const OPTION = 'certloom_email_automation_runs_v1';
	const MAX_RECORDS = 50;

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_values( array_slice( $stored, 0, self::MAX_RECORDS ) ) : array();
	}

	/** @return array<string,mixed>|null */
	public function latest_for_rule( $rule_id ) {
		$rule_id = sanitize_key( (string) $rule_id );
		foreach ( $this->all() as $row ) {
			if ( is_array( $row ) && $rule_id === (string) ( $row['rule_id'] ?? '' ) ) { return $row; }
		}
		return null;
	}

	/** @return bool */
	public function record( $rule_id, $version_id, $source_type, $outcome, $code = '' ) {
		$rule_id = sanitize_key( (string) $rule_id );
		$source_type = sanitize_key( (string) $source_type );
		$outcome = sanitize_key( (string) $outcome );
		$code = sanitize_key( (string) $code );
		if ( '' === $rule_id || ! in_array( $outcome, array( 'queued', 'skipped', 'failed' ), true ) ) { return false; }
		$row = array(
			'rule_id' => $rule_id,
			'version_id' => absint( $version_id ),
			'source_type' => $source_type,
			'outcome' => $outcome,
			'code' => $code,
			'finished_at' => current_time( 'mysql', true ),
		);
		$result = ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->change( self::OPTION, static function ( $bytes ) use ( $row ) {
			$all = null === $bytes ? array() : maybe_unserialize( $bytes );
			if ( ! is_array( $all ) || count( $all ) > self::MAX_RECORDS ) { return new \WP_Error( 'certloom_email_automation_history_invalid', __( 'Email automation history needs review.', 'certloom' ) ); }
			array_unshift( $all, $row );
			return maybe_serialize( array_slice( $all, 0, self::MAX_RECORDS ) );
		} );
		return true === $result;
	}
}
