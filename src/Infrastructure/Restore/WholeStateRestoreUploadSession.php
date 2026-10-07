<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Backup\WholeStateBackupFormat;
use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\WholeStateBackupBuilder;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Restore\WholeStateAccountMappingProof;
use Certloom\Application\Restore\WholeStateObjectMappingProof;
use Certloom\Application\Restore\WholeStateRestoreReview;
use Certloom\Infrastructure\Backup\SecretVault;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Database\DatabaseSession;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DatabaseOptions;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Owner-scoped draft choices; only a freshly rebuilt typed review can authorize restore. */
final class WholeStateRestoreUploadSession {
	const PREFIX = 'certloom_whole_restore_upload_';
	const PURPOSE = 'certloom-whole-restore-upload-v1';
	const TTL = DAY_IN_SECONDS;
	const PRUNE_CURSOR = 'certloom_whole_upload_prune_cursor';

	public function create( $source, $name, $safety_job ) {
		if ( ! $this->authorized() || ! is_string( $source ) || ! is_file( $source ) || is_link( $source ) || filesize( $source ) > WholeStateBackupFormat::MAX_ARCHIVE_BYTES || ! $this->token( $safety_job ) ) { return $this->invalid(); }
		$identity = ( new WholeStateProvenanceRepository() )->identity();
		if ( is_wp_error( $identity ) ) { return $identity; }
		$token = bin2hex( random_bytes( 16 ) );
		$path = ( new PrivateJobFileStore() )->store_copy( $token, $source, 'whole-upload' );
		if ( is_wp_error( $path ) ) { return $path; }
		$archive = ( new WholeStateBackupReader() )->analyze( $path );
		if ( is_wp_error( $archive ) || $archive['uncompressed_bytes'] > WholeStateBackupBuilder::MAX_CAPTURE_BYTES ) { ( new PrivateJobFileStore() )->delete( $path ); return is_wp_error( $archive ) ? $archive : $this->invalid(); }
		$session = array( 'purpose' => self::PURPOSE, 'token' => $token, 'actor' => (int) get_current_user_id(), 'target' => $identity, 'source' => array( 'path' => $path, 'sha256' => $archive['archive_sha256'] ), 'name' => sanitize_file_name( $name ), 'expires' => time() + self::TTL, 'safety_job' => $safety_job, 'accounts' => array(), 'objects' => array(), 'images' => array(), 'fingerprint' => null, 'queued_job' => null );
		$saved = $this->save( $session );
		if ( is_wp_error( $saved ) ) { ( new PrivateJobFileStore() )->delete( $path ); return $saved; }
		return $token;
	}

	public function get( $token ) {
		if ( ! $this->authorized() || ! $this->token( $token ) ) { return $this->invalid(); }
		global $wpdb; $wpdb->last_error = '';
		$sealed = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(OCTET_LENGTH(option_value)<=%d,option_value,NULL) FROM %i WHERE option_name=%s', WholeStateRestoreIntentStore::MAX_BYTES, $wpdb->options, self::PREFIX . $token ) );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		if ( ! is_string( $sealed ) || strlen( $sealed ) > WholeStateRestoreIntentStore::MAX_BYTES ) { return $this->invalid(); }
		$session = SecretVault::open( $sealed );
		$identity = ( new WholeStateProvenanceRepository() )->identity();
		$keys = array( 'purpose', 'token', 'actor', 'target', 'source', 'name', 'expires', 'safety_job', 'accounts', 'objects', 'images', 'fingerprint', 'queued_job' );
		if ( is_wp_error( $session ) || is_wp_error( $identity ) || count( $session ) !== count( $keys ) || array_diff( array_keys( $session ), $keys ) || self::PURPOSE !== $session['purpose'] || $token !== $session['token'] || get_current_user_id() !== $session['actor'] || ! is_int( $session['expires'] ) || $session['expires'] < time() || ! WholeStateRestoreWorkspace::target_matches( $session['target'], $identity ) || ! $this->token( $session['safety_job'] ) || ! is_string( $session['name'] ) || ! is_array( $session['source'] ) || count( $session['source'] ) !== 2 ) { return $this->invalid(); }
		$source = $session['source'];
		if ( ! is_string( $source['path'] ?? null ) || ! ( new PrivateJobFileStore() )->owns( $source['path'] ) || ! preg_match( '/^' . $token . '-whole-upload-[a-f0-9]{48}\.data$/D', basename( $source['path'] ) ) || ! is_string( $source['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $source['sha256'] ) || ! is_file( $source['path'] ) || is_link( $source['path'] ) || hash_file( 'sha256', $source['path'] ) !== $source['sha256'] ) { return $this->invalid(); }
		foreach ( array( 'accounts', 'objects', 'images' ) as $key ) { if ( ! is_array( $session[$key] ) || count( $session[$key] ) > 5000 ) { return $this->invalid(); } }
		if ( null !== $session['fingerprint'] && ( ! is_string( $session['fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $session['fingerprint'] ) ) ) { return $this->invalid(); }
		if ( null !== $session['queued_job'] && ! $this->token( $session['queued_job'] ) ) { return $this->invalid(); }
		return $session;
	}

	/** Serialize review, discard and commit against the same current draft. */
	public function with_session( $token, callable $callback ) {
		if ( ! $this->authorized() || ! $this->token( $token ) ) { return $this->invalid(); }
		return $this->with_token_lock( $token, function () use ( $token, $callback ) { $session = $this->get( $token ); return is_wp_error( $session ) ? $session : $callback( $session ); } );
	}
	private function with_token_lock( $token, callable $callback ) {
		return DatabaseSession::run( function () use ( $token, $callback ) {
			global $wpdb; $name = 'certloom-recovery-review-' . substr( hash( 'sha256', $wpdb->options . '|' . $token ), 0, 38 );
			$wpdb->last_error = ''; $held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $name ) );
			if ( '1' !== (string) $held || '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_recovery_review_busy', __( 'Another action is using this recovery review. Refresh its progress before submitting again.', 'certloom' ) ); }
			try { $result = $callback(); }
			finally { $wpdb->last_error = ''; $released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
			return '1' === (string) $released && '' === (string) $wpdb->last_error ? $result : $this->invalid();
		} );
	}

	public function mark_queued( array $session, $job_key ) {
		if ( ! $this->token( $job_key ) || null !== $session['queued_job'] ) { return $this->invalid(); }
		$session['queued_job'] = $job_key; $saved = $this->save( $session );
		return is_wp_error( $saved ) ? $saved : $session;
	}

	/** Validated source evidence is displayed without importing WordPress identities. */
	public function inventory( array $session ) {
		$archive = ( new WholeStateBackupReader() )->analyze( $session['source']['path'] );
		if ( is_wp_error( $archive ) || $archive['archive_sha256'] !== $session['source']['sha256'] ) { return $this->invalid(); }
		$rows = array( 'accounts' => array(), 'objects' => array(), 'images' => array() );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $session['source']['path'], \ZipArchive::RDONLY ) ) { return $this->invalid(); }
		try {
			$stream = $zip->getStream( 'data/references.jsonl' );
			if ( ! is_resource( $stream ) ) { return $this->invalid(); }
			try {
				$result = ( new WholeStateReferenceFormat() )->analyze( $stream, function ( $row ) use ( &$rows ) {
					if ( 'account' === $row['kind'] ) { $rows['accounts'][$row['recipient_id']] = $row; }
					elseif ( 'object' === $row['kind'] ) { $rows['objects'][$row['source_id']] = $row; }
					elseif ( 'image' === $row['kind'] ) { $rows['images'][$row['reference']][$row['descriptor']['sha256']] = $row['descriptor']; }
					return count( $rows['accounts'] ) > 5000 ? $this->invalid() : true;
				} );
			} finally { fclose( $stream ); }
			if ( is_wp_error( $result ) || $result['format_version'] !== $archive['manifest']['scope']['references'] ) { return $this->invalid(); }
			$rows['same_installation'] = ! is_wp_error( ( new WholeStateProvenanceRepository() )->verify( $archive['manifest'] ) );
			$rows['references_version'] = $result['format_version'];
			return $rows;
		} finally { $zip->close(); }
	}

	public function review( array $session, array $accounts, array $objects, array $images ) {
		$source = $session['source']; $actor = (int) get_current_user_id();
		$account_proof = WholeStateAccountMappingProof::review( $source['path'], $source['sha256'], $actor, $accounts, 'reviewed_target' );
		$object_proof = WholeStateObjectMappingProof::review( $source['path'], $source['sha256'], $actor, $objects, 'reviewed_target' );
		if ( is_wp_error( $account_proof ) ) { return $account_proof; }
		if ( is_wp_error( $object_proof ) ) { return $object_proof; }
		return WholeStateRestoreReview::review( $source['path'], $source['sha256'], $account_proof, $object_proof, $images );
	}

	public function remember_review( array $session, WholeStateRestoreReview $review ) {
		if ( ! $this->authorized() || null !== $session['queued_job'] || $session['source']['sha256'] !== $review->sha256() || $session['actor'] !== (int) get_current_user_id() ) { return $this->invalid(); }
		$packet = $review->packet();
		foreach ( array( 'accounts', 'objects', 'images', 'fingerprint' ) as $key ) { $session[$key] = $packet[$key]; }
		return $this->save( $session );
	}

	public function discard( array $session ) {
		$current = $this->get( $session['token'] );
		if ( is_wp_error( $current ) || $current !== $session ) { return $this->invalid(); }
		$removed = ( new PrivateJobFileStore() )->delete( $session['source']['path'] );
		if ( true !== $removed ) { return $this->invalid(); }
		global $wpdb; $wpdb->last_error = '';
		$removed = $wpdb->delete( $wpdb->options, array( 'option_name' => self::PREFIX . $session['token'] ) );
		DatabaseOptions::invalidate( array( self::PREFIX . $session['token'] ) );
		return 1 === $removed && '' === (string) $wpdb->last_error ? true : $this->invalid();
	}

	/** Expired uploads are never the immutable source copies owned by restore workers. */
	public function prune_expired() {
		$files = new PrivateJobFileStore(); $directory = $files->directory();
		if ( is_wp_error( $directory ) ) { return $directory; }
		$scanned = 0;
		try { foreach ( new \DirectoryIterator( $directory ) as $file ) {
			if ( ++$scanned > 5000 ) { break; }
			if ( ! preg_match( '/^[a-f0-9]{32}-whole-upload-[a-f0-9]{48}\.data$/D', $file->getFilename() ) || $file->isLink() || ! $file->isFile() || $file->getMTime() + 2 * self::TTL >= time() ) { continue; }
			$token = substr( $file->getFilename(), 0, 32 ); $path = $file->getPathname();
			$removed = $this->with_token_lock( $token, function () use ( $token, $path, $files ) {
				global $wpdb; $wpdb->last_error = '';
				$present = $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, self::PREFIX . $token ) );
				if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
				// A registered review is cleaned only through its sealed, unchanged
				// source evidence below. This pass removes abandoned upload files.
				return null !== $present ? true : $files->delete( $path );
			} );
			if ( is_wp_error( $removed ) && 'certloom_recovery_review_busy' === $removed->get_error_code() ) { continue; }
			if ( true !== $removed ) { return $this->invalid(); }
		} } catch ( \Throwable $error ) { return $this->invalid(); }
		global $wpdb;
		$cursor = max( 0, (int) get_option( self::PRUNE_CURSOR, 0 ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id,option_name,IF(OCTET_LENGTH(option_value)<=%d,option_value,NULL) AS raw FROM %i WHERE option_name LIKE %s AND option_id>%d ORDER BY option_id LIMIT 100', WholeStateRestoreIntentStore::MAX_BYTES, $wpdb->options, $wpdb->esc_like( self::PREFIX ) . '%', $cursor ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
		foreach ( $rows as $row ) {
			$cursor = (int) $row['option_id'];
			$token = substr( $row['option_name'], strlen( self::PREFIX ) ); if ( ! $this->token( $token ) || ! is_string( $row['raw'] ) ) { continue; }
			$result = $this->with_token_lock( $token, function () use ( $row, $token, $files ) {
			global $wpdb; $wpdb->last_error = '';
			$current = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(OCTET_LENGTH(option_value)<=%d,option_value,NULL) FROM %i WHERE option_name=%s', WholeStateRestoreIntentStore::MAX_BYTES, $wpdb->options, $row['option_name'] ) );
			if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			if ( $current !== $row['raw'] ) { return true; }
			$session = SecretVault::open( $current );
			$identity = ( new WholeStateProvenanceRepository() )->identity();
			if ( is_wp_error( $session ) || is_wp_error( $identity ) || self::PURPOSE !== ( $session['purpose'] ?? null ) || $token !== ( $session['token'] ?? null ) || ! WholeStateRestoreWorkspace::target_matches( $session['target'] ?? null, $identity ) || ! is_int( $session['expires'] ?? null ) || $session['expires'] >= time() ) { return true; }
			$source = $session['source'] ?? array(); $path = $source['path'] ?? null;
			if ( ! is_string( $path ) || ! $files->owns( $path ) || ! preg_match( '/^' . $token . '-whole-upload-[a-f0-9]{48}\.data$/D', basename( $path ) ) || ( is_file( $path ) && hash_file( 'sha256', $path ) !== ( $source['sha256'] ?? null ) ) ) { return true; }
			if ( true !== $files->delete( $path ) ) { return $this->invalid(); }
			$wpdb->last_error = ''; $removed = $wpdb->delete( $wpdb->options, array( 'option_name' => $row['option_name'], 'option_value' => $row['raw'] ) ); DatabaseOptions::invalidate( array( $row['option_name'] ) );
			if ( 1 !== $removed || '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			return true;
			} );
			if ( is_wp_error( $result ) && 'certloom_recovery_review_busy' !== $result->get_error_code() ) { return $result; }
		}
		$cursor = count( $rows ) < 100 ? 0 : $cursor;
		update_option( self::PRUNE_CURSOR, $cursor, false ); DatabaseOptions::invalidate( array( self::PRUNE_CURSOR ) );
		$wpdb->last_error = ''; $saved = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, self::PRUNE_CURSOR ) );
		if ( '' !== (string) $wpdb->last_error || (string) $saved !== (string) $cursor ) { return $this->invalid(); }
		return true;
	}

	private function save( array $session ) {
		$sealed = SecretVault::seal( $session );
		if ( is_wp_error( $sealed ) || strlen( $sealed ) > WholeStateRestoreIntentStore::MAX_BYTES ) { return $this->invalid(); }
		$name = self::PREFIX . $session['token'];
		$result = ( new Transaction() )->run( function () use ( $name, $sealed, $session ) {
			global $wpdb; $wpdb->last_error = '';
			$old = $wpdb->get_var( $wpdb->prepare( 'SELECT IF(OCTET_LENGTH(option_value)<=%d,option_value,NULL) FROM %i WHERE option_name=%s FOR UPDATE', WholeStateRestoreIntentStore::MAX_BYTES, $wpdb->options, $name ) );
			if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			if ( null !== $old ) { $prior = SecretVault::open( $old ); if ( is_wp_error( $prior ) || null !== ( $prior['queued_job'] ?? null ) || ( $prior['actor'] ?? null ) !== $session['actor'] || ( $prior['source'] ?? null ) !== $session['source'] || ( $prior['target'] ?? null ) !== $session['target'] || ( $prior['expires'] ?? null ) !== $session['expires'] ) { return $this->invalid(); } }
			$wpdb->last_error = ''; $data = array( 'option_value' => $sealed, 'autoload' => 'off' );
			$written = null === $old ? $wpdb->insert( $wpdb->options, array( 'option_name' => $name ) + $data ) : $wpdb->update( $wpdb->options, $data, array( 'option_name' => $name ) );
			return 1 === $written && '' === (string) $wpdb->last_error ? true : $this->invalid();
		} );
		DatabaseOptions::invalidate( array( $name ) ); return $result;
	}
	private function token( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $value ); }
	private function authorized() { return current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_upload_invalid', __( 'This recovery review has expired or changed. Upload the snapshot again and review its connections.', 'certloom' ) ); }
}
