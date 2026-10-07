<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Streaming delivery evidence; execution jobs and send permission are never imported. */
final class WholeStateDeliveryFormat {
	const FORMAT = 'certloom-whole-state-deliveries';
	const VERSION = 1;
	const MAX_LINE_BYTES = 3145728;

	public function header( array $snapshot, array $runs ) {
		$valid = $this->bounds( $snapshot['entities'] ?? null );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$valid = ( new WholeStateOptionRecord() )->validate( $runs );
		if ( is_wp_error( $valid ) ) { return $valid; }
		return $this->line( array( 'type' => 'manifest', 'format' => self::FORMAT, 'format_version' => self::VERSION, 'entities' => $snapshot['entities'], 'automation_runs' => $runs ) );
	}

	public function record( array $record ) {
		$valid = ( new WholeStateDeliveryRecord() )->to_row( $record );
		return is_wp_error( $valid ) ? $valid : $this->line( array( 'type' => 'delivery', 'data' => $record ) );
	}

	public function footer( $records, $hash ) {
		if ( ! is_int( $records ) || $records < 0 || $records > WholeStateDeliveryRecord::MAX_RECORDS || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) { return $this->invalid(); }
		return $this->line( array( 'type' => 'integrity', 'records' => $records, 'chain_sha256' => $hash ) );
	}

	public static function next_hash( $hash, $line ) { return hash( 'sha256', $hash . $line ); }

	/** The callback runs on untrusted evidence, never on live publication. */
	public function analyze( $handle, callable $on_record ) {
		if ( ! is_resource( $handle ) ) { return $this->invalid(); }
		$bytes = 0; $line = $this->read_line( $handle, $bytes );
		if ( is_wp_error( $line ) || null === $line ) { return $this->invalid(); }
		$header = $this->decode_line( $line );
		if ( is_wp_error( $header ) || ! $this->keys( $header, array( 'type', 'format', 'format_version', 'entities', 'automation_runs' ) ) || 'manifest' !== $header['type'] || self::FORMAT !== $header['format'] || self::VERSION !== $header['format_version'] || is_wp_error( $this->bounds( $header['entities'] ) ) || is_wp_error( ( new WholeStateOptionRecord() )->validate( $header['automation_runs'] ) ) ) { return $this->invalid(); }
		$hash = self::next_hash( '', $line ); $count = 0; $index = 0; $last = 0;
		$types = array( 'email_deliveries', 'destination_deliveries' ); $counts = array_fill_keys( $types, 0 ); $sizes = $counts; $max = $counts;
		while ( null !== ( $line = $this->read_line( $handle, $bytes ) ) ) {
			if ( is_wp_error( $line ) ) { return $line; }
			$row = $this->decode_line( $line ); if ( is_wp_error( $row ) ) { return $row; }
			if ( 'integrity' === ( $row['type'] ?? null ) ) {
				if ( ! $this->keys( $row, array( 'type', 'records', 'chain_sha256' ) ) || $row['records'] !== $count || ! is_string( $row['chain_sha256'] ) || ! hash_equals( $hash, $row['chain_sha256'] ) || null !== $this->read_line( $handle, $bytes ) ) { return $this->invalid(); }
				foreach ( $types as $type ) { $bound = $header['entities'][ $type ]; if ( $counts[ $type ] !== $bound['count'] || $sizes[ $type ] !== $bound['bytes'] || (string) $max[ $type ] !== $bound['max_id'] ) { return $this->invalid(); } }
				return array( 'records' => $count, 'automation_runs' => $header['automation_runs'], 'entities' => $header['entities'], 'bytes' => $bytes );
			}
			if ( ! $this->keys( $row, array( 'type', 'data' ) ) || 'delivery' !== $row['type'] || ! is_array( $row['data'] ) || ++$count > WholeStateDeliveryRecord::MAX_RECORDS ) { return $this->invalid(); }
			$record = $row['data']; $decoded = ( new WholeStateDeliveryRecord() )->to_row( $record ); if ( is_wp_error( $decoded ) ) { return $decoded; }
			$next = array_search( $record['entity'], $types, true );
			if ( false === $next || $next < $index ) { return $this->invalid(); }
			if ( $next !== $index ) { $last = 0; $index = $next; }
			$id = (int) $record['id']; $type = $types[ $index ]; $bound = $header['entities'][ $type ];
			if ( $id <= $last || $id > (int) $bound['max_id'] || ++$counts[ $type ] > $bound['count'] || $record['bytes'] > $bound['bytes'] - $sizes[ $type ] ) { return $this->invalid(); }
			$last = $id; $max[ $type ] = $id; $sizes[ $type ] += $record['bytes']; $hash = self::next_hash( $hash, $line );
			$result = $on_record( $record['entity'], $decoded ); if ( is_wp_error( $result ) ) { return $result; }
		}
		return $this->invalid();
	}

	private function bounds( $entities ) {
		$types = array( 'email_deliveries', 'destination_deliveries' );
		if ( ! is_array( $entities ) || ! $this->keys( $entities, $types ) ) { return $this->invalid(); }
		$count = 0; $bytes = 0;
		foreach ( $types as $type ) {
			$b = $entities[ $type ];
			if ( ! is_array( $b ) || ! $this->keys( $b, array( 'count', 'max_id', 'bytes' ) ) || ! is_int( $b['count'] ) || $b['count'] < 0 || $b['count'] > WholeStateDeliveryRecord::MAX_RECORDS - $count || ! WholeStateDeliveryRecord::id( $b['max_id'], true ) || $b['count'] > (int) $b['max_id'] || ( 0 === $b['count'] ) !== ( '0' === $b['max_id'] ) || ! is_int( $b['bytes'] ) || $b['bytes'] < $b['count'] || $b['bytes'] > WholeStateDeliveryRecord::MAX_TOTAL_BYTES - $bytes || ( 0 === $b['count'] && 0 !== $b['bytes'] ) ) { return $this->invalid(); }
			$count += $b['count']; $bytes += $b['bytes'];
		}
		return true;
	}
	private function read_line( $handle, &$bytes ) {
		$line = fgets( $handle, self::MAX_LINE_BYTES + 1 );
		if ( false === $line ) { return feof( $handle ) ? null : $this->invalid(); }
		if ( '' === $line || "\n" !== substr( $line, -1 ) || strlen( $line ) > self::MAX_LINE_BYTES || strlen( $line ) > WholeStateBackupFormat::MAX_MEMBER_BYTES - $bytes ) { return $this->invalid(); }
		$bytes += strlen( $line ); return $line;
	}
	private function line( array $row ) { try { $line = ( new CanonicalJson() )->encode( $row ) . "\n"; } catch ( \Throwable $e ) { return $this->invalid(); } return strlen( $line ) <= self::MAX_LINE_BYTES ? $line : $this->invalid(); }
	private function decode_line( $line ) { $row = json_decode( $line, true, 12 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $row ) ) { return $this->invalid(); } $canonical = $this->line( $row ); return is_wp_error( $canonical ) || ! hash_equals( $canonical, $line ) ? $this->invalid() : $row; }
	private function keys( array $row, array $keys ) { return count( $row ) === count( $keys ) && ! array_diff( array_keys( $row ), $keys ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_delivery_stream_invalid', __( 'This recovery snapshot has incomplete or changed delivery evidence.', 'certloom' ) ); }
}
