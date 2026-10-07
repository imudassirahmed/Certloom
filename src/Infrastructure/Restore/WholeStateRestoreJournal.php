<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Backup\SecretVault;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Target-sealed progress and rollback evidence; raw settings never enter job progress. */
final class WholeStateRestoreJournal {
	const OPTION = 'certloom_whole_restore_journal';
	const PURPOSE = 'certloom-whole-restore-journal-v1';
	const MAX_BYTES = 33554432;
	/** Presence needs no deserialization and must not depend on an option cache. */
	public static function pending() {
		global $wpdb;
		$wpdb->last_error = '';
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, self::OPTION ) );
		return '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_whole_restore_journal_read_failed', __( 'Certloom could not check recovery progress. Certificate changes remain paused until the database responds.', 'certloom' ) )
			: null !== $name;
	}

	public function read() {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT OCTET_LENGTH(option_value) AS bytes, IF(OCTET_LENGTH(option_value)<=%d,option_value,NULL) AS raw FROM %i WHERE option_name=%s', self::MAX_BYTES, $wpdb->options, self::OPTION ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		if ( null === $row ) { return null; }
		$raw = $row['raw'];
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES ) { return $this->invalid(); }
		set_error_handler( static function () { throw new \RuntimeException( 'Invalid local recovery envelope.' ); } );
		try { $envelope = unserialize( $raw, array( 'allowed_classes' => false, 'max_depth' => 4 ) ); } catch ( \Throwable $error ) { return $this->invalid(); } finally { restore_error_handler(); }
		if ( ! is_array( $envelope ) || count( $envelope ) !== 2 || ! is_string( $envelope['owner_token'] ?? null ) || ! is_string( $envelope['sealed'] ?? null ) || serialize( $envelope ) !== $raw ) { return $this->invalid(); }
		$journal = SecretVault::open( $envelope['sealed'] );
		if ( is_wp_error( $journal ) || ! $this->valid( $journal ) || $journal['owner_token'] !== $envelope['owner_token'] ) { return $this->invalid(); }
		return $journal;
	}

	/** Exact revision compare-and-swap; a stale worker cannot advance another slice. */
	public function write( array $journal ) {
		if ( ! $this->valid( $journal ) || ! ( new DomainMaintenanceLock() )->owned_active( $journal['owner_token'] ) ) { return $this->invalid(); }
		$prior_revision = $journal['revision']; ++$journal['revision'];
		$sealed = SecretVault::seal( $journal );
		if ( is_wp_error( $sealed ) ) { return $sealed; }
		$raw = serialize( array( 'owner_token' => $journal['owner_token'], 'sealed' => $sealed ) );
		if ( strlen( $raw ) > self::MAX_BYTES ) { return $this->invalid(); }
		$result = DomainMaintenanceLock::run_internal( function () use ( $journal, $raw, $prior_revision ) {
			return ( new Transaction() )->run( function () use ( $journal, $raw, $prior_revision ) {
				global $wpdb;
				$wpdb->last_error = '';
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::OPTION ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
				$current = $this->read();
				if ( is_wp_error( $current ) || ( $current ? ( $current['revision'] !== $prior_revision || $current['owner_token'] !== $journal['owner_token'] || $current['source_sha256'] !== $journal['source_sha256'] || $current['review_packet']['fingerprint'] !== $journal['review_packet']['fingerprint'] ) : 0 !== $prior_revision ) ) { return $this->invalid(); }
				$wpdb->last_error = '';
				$written = $row ? $wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => self::OPTION ) ) : $wpdb->insert( $wpdb->options, array( 'option_name' => self::OPTION, 'option_value' => $raw, 'autoload' => 'off' ) );
				return 1 === $written && '' === (string) $wpdb->last_error ? true : $this->invalid();
			} );
		} );
		DatabaseOptions::invalidate( array( self::OPTION ) );
		return is_wp_error( $result ) ? $result : $journal;
	}

	public function clear( array $journal ) {
		$result = DomainMaintenanceLock::run_internal( function () use ( $journal ) {
			return ( new Transaction() )->run( function () use ( $journal ) {
				global $wpdb; $current = $this->read();
				if ( is_wp_error( $current ) || ! $current || ( new CanonicalJson() )->hash( $current ) !== ( new CanonicalJson() )->hash( $journal ) ) { return $this->invalid(); }
				$wpdb->last_error = ''; $written = $wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION ) );
				return 1 === $written && '' === (string) $wpdb->last_error ? true : $this->invalid();
			} );
		} );
		DatabaseOptions::invalidate( array( self::OPTION ) ); return $result;
	}

	private function valid( $journal ) {
		$keys = array( 'version', 'purpose', 'target', 'owner_token', 'source_path', 'source_sha256', 'review_packet', 'settings_before', 'settings_after', 'generations_before', 'members', 'cursor', 'offset', 'design_cursor', 'designs_complete', 'cleanup_cursor', 'counts', 'expected_counts', 'phase', 'revision', 'created_at', 'receipt' );
		if ( ! is_array( $journal ) || count( $journal ) !== count( $keys ) || array_diff( array_keys( $journal ), $keys ) || 1 !== $journal['version'] || self::PURPOSE !== $journal['purpose'] || ! is_string( $journal['owner_token'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $journal['owner_token'] ) || ! is_string( $journal['source_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $journal['source_sha256'] ) || ! is_string( $journal['source_path'] ) || strlen( $journal['source_path'] ) > 2048 || false !== strpos( $journal['source_path'], "\0" ) || ! is_int( $journal['revision'] ) || $journal['revision'] < 0 || ! is_int( $journal['created_at'] ) || $journal['created_at'] < 1 || ! is_int( $journal['cursor'] ) || $journal['cursor'] < 0 || $journal['cursor'] > 3 || ! is_int( $journal['offset'] ) || $journal['offset'] < 0 || ! in_array( $journal['phase'], array( 'building', 'ready', 'prepared', 'swapped', 'committed', 'rolled_back' ), true ) ) { return false; }
		$identity = ( new WholeStateProvenanceRepository() )->identity();
		if ( ! is_int( $journal['design_cursor'] ) || $journal['design_cursor'] < 0 || ! is_bool( $journal['designs_complete'] ) || ! is_int( $journal['cleanup_cursor'] ) || $journal['cleanup_cursor'] < 0 || $journal['cleanup_cursor'] > 45 || ! is_array( $journal['settings_before'] ) || ! is_array( $journal['settings_after'] ) ) { return false; }
		if ( is_wp_error( $identity ) || ! WholeStateRestoreWorkspace::target_matches( $journal['target'], $identity ) ) { return false; }
		$publication = new WholeStateOptionPublication();
		if ( is_wp_error( $publication->validate( $journal['settings_before'] ) ) || is_wp_error( $publication->validate( $journal['settings_after'] ) ) || ! WholeStateRestoreWorkspace::valid_generations( $journal['generations_before'] ) ) { return false; }
		$packet = $journal['review_packet'];
		if ( ! is_array( $packet ) || count( $packet ) !== 5 || ! is_int( $packet['actor'] ?? null ) || $packet['actor'] < 1 || ! is_array( $packet['accounts'] ?? null ) || count( $packet['accounts'] ) > 5000 || ! is_array( $packet['objects'] ?? null ) || count( $packet['objects'] ) > 5000 || ! is_array( $packet['images'] ?? null ) || count( $packet['images'] ) > 5000 || ! is_string( $packet['fingerprint'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $packet['fingerprint'] ) ) { return false; }
		foreach ( array( 'counts', 'expected_counts' ) as $key ) {
			if ( ! is_array( $journal[$key] ) || count( $journal[$key] ) !== 21 || array_diff( array_keys( $journal[$key] ), WholeStateRestoreWorkspace::entities() ) ) { return false; }
			foreach ( $journal[$key] as $count ) { if ( ! is_int( $count ) || $count < 0 || $count > 500000 ) { return false; } }
		}
		if ( ! is_array( $journal['members'] ) || count( $journal['members'] ) !== 3 || array_values( $journal['members'] ) !== $journal['members'] || ! is_array( $journal['receipt'] ) ) { return false; }
		foreach ( $journal['members'] as $index => $member ) { if ( ! WholeStateRestoreWorkspace::valid_member( $member, $journal['owner_token'], $index ) ) { return false; } }
		if ( 'committed' === $journal['phase'] ) {
			if ( ! WholeStateRestoreWorkspace::valid_receipt( $journal['receipt'] ) || $journal['receipt']['owner_token'] !== $journal['owner_token'] || $journal['receipt']['source_sha256'] !== $journal['source_sha256'] || $journal['receipt']['counts'] !== $journal['counts'] ) { return false; }
		} elseif ( array() !== $journal['receipt'] ) { return false; }
		return true;
	}
	private function invalid() { return new \WP_Error( 'certloom_whole_restore_journal_invalid', __( 'Certloom could not verify its complete recovery journal. Certificate writes remain paused until its previous state is recovered.', 'certloom' ) ); }
}
