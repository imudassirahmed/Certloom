<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Integration\WebhookV2Contract;
use Certloom\Application\Integration\WebhookFieldMapping;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;
use Certloom\Infrastructure\Admin\IntegrationMappingTargetGuard;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** V2 connections are separate from legacy completion-only clients. No plaintext secrets. */
final class WebhookV2ConnectionRepository {
	public function validate_recovery_value( $stored ) {
		if ( ! is_array( $stored ) || count( $stored ) !== 2 || array_diff( array_keys( $stored ), array( 'schema_version', 'connections' ) ) || 2 !== ( $stored['schema_version'] ?? null ) || ! is_array( $stored['connections'] ?? null ) || count( $stored['connections'] ) > self::MAX_CONNECTIONS || array_values( $stored['connections'] ) !== $stored['connections'] ) { return $this->invalid(); }
		$seen = array();
		foreach ( $stored['connections'] as $row ) {
			if ( ! is_array( $row ) || ! \Certloom\Application\Restore\WholeStateOptionRows::date( $row['updated_at'] ?? null, 'Y-m-d H:i:s' ) ) { return $this->invalid(); }
			$normalized = $this->normalize( $row ); if ( is_wp_error( $normalized ) ) { return $normalized; }
			if ( ! \Certloom\Application\Restore\WholeStateOptionRows::same( $normalized, $row ) || isset( $seen[$row['key']] ) ) { return $this->invalid(); }
			$ciphertext = \Certloom\Application\Restore\WholeStateSealedConnectionFormat::validate( $row['secret_sealed'], 8192 ); if ( is_wp_error( $ciphertext ) ) { return $ciphertext; }
			$seen[$row['key']] = true;
		}
		return $stored;
	}
	const OPTION = 'certloom_webhook_sources_v2';
	const MAX_CONNECTIONS = 30;

	public function all() { $rows = $this->read(); return is_wp_error( $rows ) ? array() : $rows; }
	public function read() {
		$stored = get_option( self::OPTION, null );
		if ( null === $stored || false === $stored ) { return array(); }
		if ( ! is_array( $stored ) || array_diff( array_keys( $stored ), array( 'schema_version', 'connections' ) ) || 2 !== ( $stored['schema_version'] ?? null ) || ! is_array( $stored['connections'] ?? null ) || count( $stored['connections'] ) > self::MAX_CONNECTIONS || ( $stored['connections'] && array_keys( $stored['connections'] ) !== range( 0, count( $stored['connections'] ) - 1 ) ) ) { return $this->invalid(); }
		$out = array();
		foreach ( $stored['connections'] as $row ) {
			$row = $this->normalize( $row );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( isset( $out[ $row['key'] ] ) ) { return $this->invalid(); }
			$out[ $row['key'] ] = $row;
		}
		ksort( $out, SORT_STRING ); return $out;
	}
	public function find( $key ) { if ( ! WebhookV2Contract::source_key( $key ) ) { return $this->invalid(); } $rows = $this->read(); return is_wp_error( $rows ) ? $rows : ( $rows[ $key ] ?? null ); }
	public function revision( array $row ) { return ( new CanonicalJson() )->hash( $row ); }
	public function mapping_sha256( array $row ) { return ( new CanonicalJson() )->hash( array( 'schema_version' => 2, 'key' => $row['key'], 'program_id' => $row['program_id'], 'batch_id' => $row['batch_id'], 'active' => $row['active'], 'mapping_revision' => $row['mapping_revision'], 'fields' => $row['fields'] ) ); }
	public function secret( array $row ) { $opened = IntegrationSecretVault::open( $row['secret_sealed'] ); if ( is_wp_error( $opened ) ) { return $opened; } $secret = $opened['secret'] ?? null; return is_string( $secret ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $secret ) ? $secret : $this->invalid(); }

	public function create( $name, $program_id, $batch_id, array $fields ) {
		if ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > 120 || ! is_int( $program_id ) || $program_id < 1 || ! is_int( $batch_id ) || $batch_id < 0 ) { return $this->invalid(); }
		try { $key = 'v2-' . bin2hex( random_bytes( 6 ) ); $secret = bin2hex( random_bytes( 32 ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
		$sealed = IntegrationSecretVault::seal( array( 'secret' => $secret ) ); if ( is_wp_error( $sealed ) ) { return $sealed; }
		return $this->command( $program_id, $batch_id, function ( array $rows ) use ( $name, $program_id, $batch_id, $fields, $key, $secret, $sealed ) {
			if ( count( $rows ) >= self::MAX_CONNECTIONS || isset( $rows[ $key ] ) ) { return $this->invalid(); }
			$valid = $this->validate_target( $program_id, $batch_id, $fields ); if ( is_wp_error( $valid ) ) { return $valid; }
			$row = $this->normalize( array( 'key' => $key, 'name' => sanitize_text_field( $name ), 'program_id' => $program_id, 'batch_id' => $batch_id, 'fields' => $valid, 'active' => true, 'revision' => 1, 'mapping_revision' => 1, 'secret_sealed' => $sealed, 'updated_at' => current_time( 'mysql', true ) ) );
			if ( is_wp_error( $row ) ) { return $row; }
			$rows[ $key ] = $row;
			return array( 'rows' => $rows, 'value' => array( 'key' => $key, 'secret' => $secret ) );
		} );
	}

	public function update_mapping( $key, $expected_revision, array $fields, $active ) {
		if ( ! is_bool( $active ) ) { return $this->invalid(); }
		return $this->edit( $key, $expected_revision, function ( array $row ) use ( $fields, $active ) {
			$valid = $active ? $this->validate_target( $row['program_id'], $row['batch_id'], $fields ) : ( new WebhookV2Contract() )->mapping( $fields );
			if ( is_wp_error( $valid ) ) { return $valid; }
			if ( $row['fields'] === $valid && $row['active'] === $active ) { return $row; }
			$row['fields'] = $valid; $row['active'] = $active; ++$row['mapping_revision']; ++$row['revision']; $row['updated_at'] = current_time( 'mysql', true ); return $row;
		}, $active );
	}
	public function rotate( $key, $expected_revision ) {
		try { $secret = bin2hex( random_bytes( 32 ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
		$sealed = IntegrationSecretVault::seal( array( 'secret' => $secret ) ); if ( is_wp_error( $sealed ) ) { return $sealed; }
		$result = $this->edit( $key, $expected_revision, static function ( array $row ) use ( $sealed ) { $row['secret_sealed'] = $sealed; ++$row['revision']; $row['updated_at'] = current_time( 'mysql', true ); return $row; }, false );
		return is_wp_error( $result ) ? $result : array( 'key' => $key, 'secret' => $secret );
	}
	public function delete( $key, $expected_revision ) { return $this->edit( $key, $expected_revision, static function () { return null; }, false ); }

	/** Checked configuration locks precede recipient/request locks in every executor. */
	private function command( $program_id, $batch_id, callable $callback, $lock_target = true ) {
		$changed = false;
		$result = ( new Transaction() )->run( function () use ( $program_id, $batch_id, $callback, $lock_target, &$changed ) {
			// Pausing, rotating and removing must remain possible after a target is removed.
			// They still serialize with every executor on the same configuration locks.
			$locked = ( new RecipientFieldContext() )->lock( $lock_target ? $program_id : 0, $lock_target ? $batch_id : 0 ); if ( is_wp_error( $locked ) ) { return $locked; }
			LegacyWriteGuard::invalidate_option( self::OPTION );
			$before = $this->read(); if ( is_wp_error( $before ) ) { return $before; }
			$next = $callback( $before ); if ( is_wp_error( $next ) ) { return $next; }
			if ( $before === $next['rows'] ) { return $next['value']; }
			$generation = ( new DomainGeneration() )->mark_before_mutation(); if ( is_wp_error( $generation ) ) { return $generation; }
			ksort( $next['rows'], SORT_STRING ); $payload = array( 'schema_version' => 2, 'connections' => array_values( $next['rows'] ) );
			update_option( self::OPTION, $payload, false );
			if ( ! LegacyWriteGuard::verify_option( self::OPTION, $payload ) ) { return $this->invalid(); }
			$signal = ( new PendingConfigurationQueue() )->changed( $program_id ); if ( is_wp_error( $signal ) ) { return $signal; }
			$changed = true; return $next['value'];
		} );
		if ( ! is_wp_error( $result ) && $changed ) { do_action( 'certloom_pending_configuration_changed' ); }
		DatabaseOptions::invalidate( array( self::OPTION ) ); return $result;
	}
	private function edit( $key, $expected_revision, callable $callback, $lock_target = true ) {
		if ( ! is_string( $expected_revision ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_revision ) ) { return $this->invalid(); }
		$initial = $this->find( $key ); if ( is_wp_error( $initial ) ) { return $initial; } if ( ! is_array( $initial ) ) { return $this->invalid(); }
		return $this->command( $initial['program_id'], $initial['batch_id'], function ( array $rows ) use ( $key, $expected_revision, $callback ) {
			if ( ! isset( $rows[ $key ] ) || ! hash_equals( $expected_revision, $this->revision( $rows[ $key ] ) ) ) { return new \WP_Error( 'certloom_webhook_v2_connection_stale', __( 'This connection changed. Reload it before saving, rotating or removing it.', 'certloom' ) ); }
			$next = $callback( $rows[ $key ] ); if ( is_wp_error( $next ) ) { return $next; }
			if ( null === $next ) { unset( $rows[ $key ] ); } else { $rows[ $key ] = $next; }
			return array( 'rows' => $rows, 'value' => true );
		}, $lock_target );
	}
	public function validate_target( $program_id, $batch_id, array $fields ) {
		$context = ( new RecipientFieldContext() )->load( array( 'program_id' => $program_id, 'batch_id' => $batch_id, 'status' => 'active' ) ); if ( is_wp_error( $context ) ) { return $context; }
		$valid = ( new WebhookFieldMapping() )->validate( $fields, $context['contract'], $context['catalog'] ); if ( is_wp_error( $valid ) ) { return $valid; }
		$target = ( new IntegrationMappingTargetGuard() )->validate_snapshot( $context['program'], $context['batch'], null, null, $valid ); return is_wp_error( $target ) ? $target : $valid;
	}
	private function normalize( $row ) {
		if ( ! is_array( $row ) || count( $row ) !== 10 || array_diff( array_keys( $row ), array( 'key', 'name', 'program_id', 'batch_id', 'fields', 'active', 'revision', 'mapping_revision', 'secret_sealed', 'updated_at' ) ) ) { return $this->invalid(); }
		if ( ! WebhookV2Contract::source_key( $row['key'] ?? null ) || ! is_string( $row['name'] ?? null ) || '' === $row['name'] || strlen( $row['name'] ) > 120 || sanitize_text_field( $row['name'] ) !== $row['name'] || ! is_int( $row['program_id'] ?? null ) || $row['program_id'] < 1 || ! is_int( $row['batch_id'] ?? null ) || $row['batch_id'] < 0 || ! is_bool( $row['active'] ?? null ) || ! is_int( $row['revision'] ?? null ) || $row['revision'] < 1 || ! is_int( $row['mapping_revision'] ?? null ) || $row['mapping_revision'] < 1 || ! is_string( $row['secret_sealed'] ?? null ) || '' === $row['secret_sealed'] || strlen( $row['secret_sealed'] ) > 8192 || ! is_string( $row['updated_at'] ?? null ) || 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $row['updated_at'] ) ) { return $this->invalid(); }
		$fields = ( new WebhookV2Contract() )->mapping( $row['fields'] ?? null ); if ( is_wp_error( $fields ) ) { return $fields; } $row['fields'] = $fields; return $row;
	}
	private function invalid() { return new \WP_Error( 'certloom_webhook_v2_connection_invalid', __( 'This app connection could not be read or saved safely. Check its name, Program, mapped fields and current revision.', 'certloom' ) ); }
}
