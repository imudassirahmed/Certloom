<?php
namespace Certloom\Application\Backup;

use Certloom\Infrastructure\Backup\WholeStateDeliveryRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded delivery evidence capture; never queues, sends or reconstructs execution jobs. */
final class WholeStateDeliveryBuilder {
	private $files; private $repository;
	public function __construct( ?PrivateJobFileStore $files = null ) { $this->files = $files ?: new PrivateJobFileStore(); $this->repository = new WholeStateDeliveryRepository(); }
	public function start( $job_key, array $runs ) {
		$snapshot = $this->repository->snapshot(); if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$line = ( new WholeStateDeliveryFormat() )->header( $snapshot, $runs ); if ( is_wp_error( $line ) ) { return $line; }
		$path = $this->files->create_path( $job_key, 'whole-deliveries' ); if ( is_wp_error( $path ) ) { return $path; }
		$h = @fopen( $path, 'xb' ); if ( ! is_resource( $h ) ) { return $this->failed(); }
		$written = fwrite( $h, $line ); $flushed = fflush( $h ); $closed = fclose( $h );
		if ( strlen( $line ) !== $written || ! $flushed || ! $closed ) { $this->files->delete( $path ); return $this->failed(); } @chmod( $path, 0600 );
		return array( 'path' => $path, 'snapshot' => $snapshot, 'offset' => strlen( $line ), 'hash' => WholeStateDeliveryFormat::next_hash( '', $line ), 'index' => 0, 'cursor' => '0', 'records' => 0, 'counts' => array( 'email_deliveries' => 0, 'destination_deliveries' => 0 ), 'bytes' => array( 'email_deliveries' => 0, 'destination_deliveries' => 0 ), 'complete' => false );
	}
	public function continue_build( array $s ) {
		if ( ! $this->valid( $s ) ) { return $this->failed(); }
		$verified = $this->repository->verify( $s['snapshot'] ); if ( is_wp_error( $verified ) ) { return $verified; }
		if ( $s['complete'] ) { return $s; }
		$types = array( 'email_deliveries', 'destination_deliveries' ); $type = $types[$s['index']]; $bound = $s['snapshot']['entities'][$type]; $format = new WholeStateDeliveryFormat();
		$h = @fopen( $s['path'], 'r+b' ); if ( ! is_resource( $h ) ) { return $this->failed(); }
		try {
			$stat = fstat( $h ); if ( ! is_array( $stat ) || $stat['size'] < $s['offset'] || ! ftruncate( $h, $s['offset'] ) || 0 !== fseek( $h, $s['offset'] ) ) { return $this->failed(); }
			if ( (int) $s['cursor'] < (int) $bound['max_id'] ) {
				$rows = $this->repository->read_batch( $s['snapshot'], $type, $s['cursor'] ); if ( is_wp_error( $rows ) ) { return $rows; }
				foreach ( $rows as $row ) { $line = $format->record( $row ); $r = $this->append( $s, $h, $line ); if ( is_wp_error( $r ) ) { return $r; } $s['cursor'] = $row['id']; ++$s['records']; ++$s['counts'][$type]; $s['bytes'][$type] += $row['bytes']; }
			}
			if ( (int) $s['cursor'] >= (int) $bound['max_id'] ) {
				if ( $s['counts'][$type] !== $bound['count'] || $s['bytes'][$type] !== $bound['bytes'] ) { return $this->failed(); }
				if ( 0 === $s['index'] ) { $s['index'] = 1; $s['cursor'] = '0'; }
				else { $r = $this->append( $s, $h, $format->footer( $s['records'], $s['hash'] ), false ); if ( is_wp_error( $r ) ) { return $r; } $s['complete'] = true; }
			}
			return fflush( $h ) ? $s : $this->failed();
		} finally { fclose( $h ); }
	}
	private function append( array &$s, $h, $line, $chain = true ) { if ( is_wp_error( $line ) ) { return $line; } if ( strlen( $line ) > WholeStateBackupFormat::MAX_MEMBER_BYTES - $s['offset'] || strlen( $line ) !== fwrite( $h, $line ) ) { return $this->failed(); } $s['offset'] += strlen( $line ); if ( $chain ) { $s['hash'] = WholeStateDeliveryFormat::next_hash( $s['hash'], $line ); } return true; }
	private function valid( array $s ) { return count( $s ) === 10 && isset( $s['path'], $s['snapshot'], $s['offset'], $s['hash'], $s['index'], $s['cursor'], $s['records'], $s['counts'], $s['bytes'], $s['complete'] ) && is_string( $s['path'] ) && $this->files->owns( $s['path'] ) && is_file( $s['path'] ) && is_array( $s['snapshot'] ) && is_int( $s['offset'] ) && $s['offset'] > 0 && $s['offset'] <= WholeStateBackupFormat::MAX_MEMBER_BYTES && WholeStateReferenceRecord::sha( $s['hash'] ) && is_int( $s['index'] ) && in_array( $s['index'], array( 0, 1 ), true ) && WholeStateDeliveryRecord::id( $s['cursor'], true ) && is_int( $s['records'] ) && $s['records'] >= 0 && $s['records'] <= WholeStateDeliveryRecord::MAX_RECORDS && is_array( $s['counts'] ) && is_array( $s['bytes'] ) && is_bool( $s['complete'] ); }
	private function failed() { return new \WP_Error( 'certloom_whole_delivery_capture_failed', __( 'Certloom could not complete its durable delivery snapshot safely. No complete recovery archive was published.', 'certloom' ) ); }
}
