<?php
namespace Certloom\Application\Integration;

use Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reload current authority. Old evidence is never remapped to a new connection revision. */
final class WebhookPendingContext {
	public function validate( array $row ) {
		if ( (int) ( $row['id'] ?? 0 ) > 0 && ! \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready() ) { return new \WP_Error( 'certloom_pending_source_authority_closed', __( 'Automatic app issuance is held until current writing authority is available.', 'certloom' ), array( 'reason_code' => 'source_authority_closed' ) ); }
		if ( ! is_string( $row['source_ref'] ?? null ) || 1 !== preg_match( '/^(v2-[a-f0-9]{12}):([a-f0-9]{64}):([a-f0-9]{64})$/D', $row['source_ref'], $parts ) || ! hash_equals( hash( 'sha256', 'certloom-webhook-v2-intake:' . $parts[1] . ':' . $parts[2] ), (string) ( $row['intake_key'] ?? '' ) ) ) { return $this->blocked(); }
		$repo = new WebhookV2ConnectionRepository(); $source = $repo->find( $parts[1] );
		if ( is_wp_error( $source ) ) { return $source; }
		if ( ! is_array( $source ) || ! $source['active'] || $source['program_id'] !== (int) $row['program_id'] || $source['batch_id'] !== (int) $row['batch_id'] || ! hash_equals( $repo->mapping_sha256( $source ), (string) ( $row['mapping_sha256'] ?? '' ) ) ) { return $this->blocked(); }
		$context = ( new RecipientFieldContext() )->load( array( 'program_id' => $source['program_id'], 'batch_id' => $source['batch_id'], 'status' => 'active' ) );
		if ( is_wp_error( $context ) ) { return $context; }
		$valid = ( new WebhookFieldMapping() )->validate( $source['fields'], $context['contract'], $context['catalog'] ); return is_wp_error( $valid ) ? $valid : true;
	}
	private function blocked() { return new \WP_Error( 'certloom_pending_source_mapping_changed', __( 'This app connection is paused, missing or changed. Review the original request and current mapping; customer details cannot replace its source authority.', 'certloom' ), array( 'reason_code' => 'source_mapping_changed' ) ); }
}
