<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Restore\WholeStateRestoreReview;
use Certloom\Infrastructure\Backup\SecretVault;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable local approval choices, sealed outside the public WordPress root. */
final class WholeStateRestoreIntentStore {
	const PURPOSE = 'certloom-whole-restore-intent-v1';
	const MAX_BYTES = 2097152;

	/** Retain the approved archive and target choices without exposing raw settings in job progress. */
	public function store( WholeStateRestoreReview $review ) {
		if ( is_wp_error( $review->assert_current() ) ) { return $this->invalid(); }
		$identity = ( new WholeStateProvenanceRepository() )->identity();
		if ( is_wp_error( $identity ) ) { return $identity; }
		$files = new PrivateJobFileStore();
		$key = bin2hex( random_bytes( 16 ) );
		$source = $files->store_copy( $key, $review->path(), 'whole-restore-source' );
		if ( is_wp_error( $source ) ) { return $source; }
		$intent = $files->create_path( $key, 'whole-restore-intent' );
		if ( is_wp_error( $intent ) ) { $files->delete( $source ); return $intent; }
		$written = false;
		try {
			if ( hash_file( 'sha256', $source ) !== $review->sha256() || is_wp_error( $review->assert_current() ) ) { return $this->invalid(); }
			$source_descriptor = array( 'path' => $source, 'sha256' => $review->sha256() );
			$sealed = SecretVault::seal( array( 'purpose' => self::PURPOSE, 'target' => $identity, 'source' => $source_descriptor, 'packet' => $review->packet() ) );
			if ( is_wp_error( $sealed ) || strlen( $sealed ) > self::MAX_BYTES ) { return $this->invalid(); }
			$handle = fopen( $intent, 'xb' );
			if ( ! is_resource( $handle ) ) { return $this->invalid(); }
			try { $written = strlen( $sealed ) === fwrite( $handle, $sealed ) && fflush( $handle ); }
			finally { fclose( $handle ); }
			if ( ! $written ) { return $this->invalid(); }
			@chmod( $intent, 0600 );
			return array( 'intent' => array( 'path' => $intent, 'sha256' => hash( 'sha256', $sealed ) ), 'source' => $source_descriptor );
		} finally {
			if ( ! $written ) { $files->delete( $intent ); $files->delete( $source ); }
		}
	}

	/** The archive or an imported boolean can never supply this target-local approval. */
	public function read( array $descriptor, array $source ) {
		if ( ! $this->valid_descriptor( $descriptor, 'whole-restore-intent' ) || ! $this->valid_descriptor( $source, 'whole-restore-source' ) || substr( basename( $descriptor['path'] ), 0, 32 ) !== substr( basename( $source['path'] ), 0, 32 ) ) { return $this->invalid(); }
		if ( ! is_file( $descriptor['path'] ) || filesize( $descriptor['path'] ) > self::MAX_BYTES || hash_file( 'sha256', $descriptor['path'] ) !== $descriptor['sha256'] ) { return $this->invalid(); }
		$payload = SecretVault::open( file_get_contents( $descriptor['path'] ) );
		$identity = ( new WholeStateProvenanceRepository() )->identity();
		if ( is_wp_error( $payload ) || is_wp_error( $identity ) || count( $payload ) !== 4 || array_diff( array_keys( $payload ), array( 'purpose', 'target', 'source', 'packet' ) ) || self::PURPOSE !== $payload['purpose'] || ! WholeStateRestoreWorkspace::target_matches( $payload['target'], $identity ) || $payload['source'] !== $source || ! is_array( $payload['packet'] ) ) { return $this->invalid(); }
		$packet = $payload['packet'];
		if ( count( $packet ) !== 5 || array_diff( array_keys( $packet ), array( 'actor', 'accounts', 'objects', 'images', 'fingerprint' ) ) || ! is_int( $packet['actor'] ) || $packet['actor'] < 1 || ! is_string( $packet['fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $packet['fingerprint'] ) ) { return $this->invalid(); }
		foreach ( array( 'accounts', 'objects', 'images' ) as $key ) { if ( ! is_array( $packet[$key] ) || count( $packet[$key] ) > 5000 ) { return $this->invalid(); } }
		return $packet;
	}

	public function valid_descriptor( $descriptor, $purpose ) {
		return is_array( $descriptor ) && count( $descriptor ) === 2 && ! array_diff( array_keys( $descriptor ), array( 'path', 'sha256' ) )
			&& is_string( $descriptor['path'] ) && strlen( $descriptor['path'] ) <= 2048 && ( new PrivateJobFileStore() )->owns( $descriptor['path'] )
			&& preg_match( '/^[a-f0-9]{32}-' . preg_quote( $purpose, '/' ) . '-[a-f0-9]{48}\.data$/D', basename( $descriptor['path'] ) )
			&& is_string( $descriptor['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/D', $descriptor['sha256'] );
	}

	/** Delete only immutable private files whose exact approved bytes still match. */
	public function cleanup( array $descriptor, array $source ) {
		$files = new PrivateJobFileStore();
		foreach ( array( 'whole-restore-source' => $source, 'whole-restore-intent' => $descriptor ) as $purpose => $file ) {
			if ( ! $this->valid_descriptor( $file, $purpose ) || ( is_file( $file['path'] ) && hash_file( 'sha256', $file['path'] ) !== $file['sha256'] ) ) { return $this->invalid(); }
			$deleted = $files->delete( $file['path'] );
			if ( is_wp_error( $deleted ) || true !== $deleted ) { return $this->invalid(); }
		}
		return true;
	}

	private function invalid() {
		return new \WP_Error( 'certloom_whole_restore_intent_invalid', __( 'Certloom could not verify the local recovery approval or its unchanged private files. Review recovery again before replacing certificates.', 'certloom' ) );
	}
}
