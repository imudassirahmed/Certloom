<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Installation references are review evidence, never target account/object authority. */
final class WholeStateReferenceRecord {
	const MAX_ACCOUNTS = 100000;
	const MAX_OBJECTS = 5000;
	const MAX_ASSET_REFERENCES = 5000;
	const MAX_ASSETS = 2048;

	public function validate( $row ) {
		if ( ! is_array( $row ) || ! is_string( $row['kind'] ?? null ) ) { return $this->invalid(); }
		if ( 'account' === $row['kind'] ) {
			if ( ! $this->keys( $row, array( 'kind', 'recipient_id', 'source_user_id', 'identity_sha256' ) ) || ! self::id( $row['recipient_id'] ) || ! self::id( $row['source_user_id'] ) || ( null !== $row['identity_sha256'] && ! self::sha( $row['identity_sha256'] ) ) ) { return $this->invalid(); }
		} elseif ( 'object' === $row['kind'] ) {
			if ( ! $this->keys( $row, array( 'kind', 'source_id', 'post_type', 'identity_sha256' ) ) || ! self::id( $row['source_id'] ) || ( null === $row['identity_sha256'] ? null !== $row['post_type'] : ( ! self::sha( $row['identity_sha256'] ) || ! is_string( $row['post_type'] ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/D', $row['post_type'] ) ) ) ) { return $this->invalid(); }
		} elseif ( 'image' === $row['kind'] ) {
			if ( ! $this->keys( $row, array( 'kind', 'reference', 'descriptor' ) ) || ! is_string( $row['reference'] ) || 1 !== preg_match( '/^attachment:([1-9][0-9]{0,18})$/D', $row['reference'], $match ) || (string) (int) $match[1] !== $match[1] ) { return $this->invalid(); }
			$d = $row['descriptor'];
			if ( ! is_array( $d ) || ! $this->keys( $d, array( 'sha256', 'mime', 'size', 'width_px', 'height_px' ) ) || ! self::sha( $d['sha256'] ) || ! in_array( $d['mime'], array( 'image/png', 'image/jpeg' ), true ) || ! self::id( $d['size'] ) || $d['size'] > \Certloom\Application\Rendering\AssetInspector::MAX_BYTES || ! self::id( $d['width_px'] ) || ! self::id( $d['height_px'] ) || $d['width_px'] > 12000 || $d['height_px'] > 12000 || $d['width_px'] * $d['height_px'] > 40000000 ) { return $this->invalid(); }
		} elseif ( 'font' === $row['kind'] ) {
			if ( ! $this->keys( $row, array( 'kind', 'sha256', 'format', 'size' ) ) || ! self::sha( $row['sha256'] ) || ! in_array( $row['format'], array( 'ttf', 'otf' ), true ) || ! self::id( $row['size'] ) || $row['size'] > \Certloom\Application\Rendering\CustomFontLibrary::MAX_BYTES ) { return $this->invalid(); }
		} elseif ( 'file' === $row['kind'] ) {
			if ( ! $this->keys( $row, array( 'kind', 'reference', 'descriptor' ) ) || ! is_string( $row['reference'] ) || 1 !== preg_match( '/^attachment:([1-9][0-9]{0,18})$/D', $row['reference'], $match ) || (string) (int) $match[1] !== $match[1] ) { return $this->invalid(); }
			$d = $row['descriptor'];
			if ( ! is_array( $d ) || ! $this->keys( $d, array( 'sha256', 'size', 'file_type' ) ) || ! self::sha( $d['sha256'] ) || ! self::id( $d['size'] ) || $d['size'] > \Certloom\Application\Email\EmailTemplateService::MAX_ATTACHMENT_BYTES || 'email_attachment' !== $d['file_type'] ) { return $this->invalid(); }
		} elseif ( 'media_url' === $row['kind'] ) {
			if ( ! $this->keys( $row, array( 'kind', 'source_url', 'source_id', 'image_sha256' ) ) || ! self::id( $row['source_id'] ) || ! self::sha( $row['image_sha256'] ) || ! self::media_url( $row['source_url'] ) ) { return $this->invalid(); }
		} else { return $this->invalid(); }
		return $row;
	}

	/** Sort identities without lossy integer coercion or duplicate aliases. */
	public function key( array $row ) {
		$valid = $this->validate( $row ); if ( is_wp_error( $valid ) ) { return $valid; }
		if ( 'account' === $row['kind'] ) { return '0:' . str_pad( (string) $row['recipient_id'], 20, '0', STR_PAD_LEFT ); }
		if ( 'object' === $row['kind'] ) { return '1:' . str_pad( (string) $row['source_id'], 20, '0', STR_PAD_LEFT ); }
		if ( 'image' === $row['kind'] ) { return '2:' . $row['reference'] . ':' . $row['descriptor']['sha256']; }
		if ( 'font' === $row['kind'] ) { return '3:' . $row['sha256'] . ':' . $row['format']; }
		if ( 'file' === $row['kind'] ) { return '4:' . $row['reference']; }
		return '5:' . hash( 'sha256', $row['source_url'] );
	}
	public static function media_url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) { return false; }
		$parts = parse_url( $url );
		return is_array( $parts ) && in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! array_intersect( array_keys( $parts ), array( 'user', 'pass', 'query', 'fragment' ) );
	}
	public static function id( $v ) { return is_int( $v ) && $v > 0; }
	public static function sha( $v ) { return is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $v ); }
	private function keys( array $a, array $b ) { return count( $a ) === count( $b ) && ! array_diff( array_keys( $a ), $b ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_reference_invalid', __( 'A recovery reference is missing, malformed or outside the supported bounds.', 'certloom' ) ); }
}
