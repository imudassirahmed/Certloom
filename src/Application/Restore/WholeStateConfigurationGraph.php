<?php
namespace Certloom\Application\Restore;

use Certloom\Infrastructure\Repository\TemplateRecommendationRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded option references are checked against source records, never this site's IDs. */
final class WholeStateConfigurationGraph {
	private $needed = array( 'issuers' => array(), 'programs' => array(), 'batches' => array(), 'templates' => array() );
	private $links = array();
	private $template_keys = array();
	private $error = null;

	public static function build( array $decoded, array $recommendations, array $catalog ) {
		$self = new self();
		// The native catalog always supplies this factory design, even when no
		// catalog option has been saved. Its issued-email content has its own scope.
		$designs = array( \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository::DEFAULT_ID => array( 'purpose' => \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED ) );
		foreach ( $decoded['certloom_email_template_catalog_v1']['value']['templates'] ?? array() as $design ) { $designs[$design['id']] = $design; }
		foreach ( $decoded as $option => $entry ) {
			if ( ! $entry['exists'] ) { continue; } $value = $entry['value'];
			if ( false !== strpos( $option, '_mappings' ) || in_array( $option, array( 'certloom_webhook_sources_v1', 'certloom_webhook_sources_v2' ), true ) ) {
				foreach ( array( 'mappings', 'sources', 'connections' ) as $container ) {
					foreach ( $value[$container] ?? array() as $row ) {
						$self->link( $row, $option );
						if ( 'certloom_webhook_sources_v2' === $option ) {
							foreach ( $row['fields'] as $field ) { if ( ! isset( $catalog[$field] ) ) { $self->fail( $option ); } }
						}
					}
				}
			}
			if ( 'certloom_email_automation_rules_v1' === $option ) { foreach ( $value as $row ) { $self->link( $row, $option ); } }
			if ( 'certloom_email_design_assignments_v1' === $option ) {
				foreach ( $value as $row ) {
					if ( 'global' !== $row['scope_type'] ) { $self->need( $row['scope_type'] . ( 'batch' === $row['scope_type'] ? 'es' : 's' ), $row['scope_id'], $option ); }
					if ( ! isset( $designs[$row['design_id']] ) || $designs[$row['design_id']]['purpose'] !== $row['purpose'] ) { $self->fail( $option ); }
				}
			}
			if ( 'certverify_onboarding' === $option ) {
				$draft = $value['draft'] ?? array();
				foreach ( array( 'setup_issuer_id' => 'issuers', 'setup_program_id' => 'programs', 'setup_batch_id' => 'batches', 'design_template_id' => 'templates' ) as $key => $entity ) { $self->need( $entity, $draft[$key] ?? 0, $option ); }
				if ( ! empty( $draft['setup_email_design_id'] ) && ! isset( $designs[$draft['setup_email_design_id']] ) ) { $self->fail( $option ); }
			}
		}
		foreach ( $recommendations as $key => $record ) { $self->need( 'templates', (int) substr( $key, strlen( TemplateRecommendationRepository::OPTION_PREFIX ) ), $key ); }
		return $self->error ?: $self;
	}

	/** Only referenced records are retained; the 500,000-row domain stays streamed. */
	public function visit( $entity, array $row, $include_variants = true ) {
		$id = (int) ( $row['id'] ?? 0 );
		if ( isset( $this->needed[$entity][$id] ) ) {
			$this->needed[$entity][$id]['found'] = true;
			$this->needed[$entity][$id]['parent'] = (int) ( $row[ 'batches' === $entity ? 'program_id' : 'issuer_id' ] ?? 0 );
		}
		if ( $include_variants && 'programs' === $entity && ! empty( $row['metadata_json'] ) ) {
			$metadata = json_decode( $row['metadata_json'], true, 64 );
			foreach ( $metadata[\Certloom\Application\Program\ProgramProfile::STORAGE_KEY]['template_variants'] ?? array() as $key ) {
				if ( ! array_key_exists( $key, $this->template_keys ) ) { $this->template_keys[$key] = false; }
				if ( count( $this->template_keys ) > 10000 ) { return $this->invalid( 'program_template_variants' ); }
			}
		}
		if ( 'templates' === $entity && array_key_exists( $row['machine_key'] ?? '', $this->template_keys ) ) { $this->template_keys[$row['machine_key']] = true; }
		return true;
	}
	public function finish() {
		if ( $this->error ) { return $this->error; }
		if ( in_array( false, $this->template_keys, true ) ) { return $this->invalid( 'program_template_variants' ); }
		foreach ( $this->needed as $rows ) { foreach ( $rows as $row ) { if ( ! $row['found'] ) { return $this->invalid( $row['option'] ); } } }
		foreach ( $this->links as $link ) {
			if ( $link['batch'] && $link['program'] && $this->needed['batches'][$link['batch']]['parent'] !== $link['program'] ) { return $this->invalid( $link['option'] ); }
			if ( $link['issuer'] && $link['program'] && $this->needed['programs'][$link['program']]['parent'] !== $link['issuer'] ) { return $this->invalid( $link['option'] ); }
		}
		return true;
	}
	/** The final workspace supplies SQL-backed, bounded lookups instead of trusting preflight. */
	public function validate_lookup( callable $lookup ) {
		foreach ( $this->needed as $entity => $rows ) { foreach ( $rows as $id => $reference ) { $row = $lookup( $entity, $id ); if ( is_wp_error( $row ) ) { return $row; } if ( is_array( $row ) ) { $this->visit( $entity, $row ); } } }
		return $this->finish();
	}
	public function validate_batches( callable $lookup ) {
		foreach ( $this->needed as $entity => $references ) {
			foreach ( array_chunk( array_keys( $references ), 200 ) as $ids ) {
				$rows = $lookup( $entity, $ids ); if ( is_wp_error( $rows ) ) { return $rows; }
				if ( ! is_array( $rows ) || count( $rows ) > count( $ids ) ) { return $this->invalid( 'workspace_connections' ); }
				foreach ( $rows as $row ) { $valid = $this->visit( $entity, $row, false ); if ( is_wp_error( $valid ) ) { return $valid; } }
			}
		}
		return $this->finish();
	}
	private function link( array $row, $option ) {
		$link = array( 'program' => (int) ( $row['program_id'] ?? 0 ), 'batch' => (int) ( $row['batch_id'] ?? 0 ), 'issuer' => (int) ( $row['issuer_id'] ?? 0 ), 'option' => $option );
		foreach ( array( 'program' => 'programs', 'batch' => 'batches', 'issuer' => 'issuers' ) as $key => $entity ) { $this->need( $entity, $link[$key], $option ); }
		// App template IDs and email rule legacy IDs are historical evidence only.
		$this->links[] = $link;
		if ( count( $this->links ) > 10000 ) { $this->fail( $option ); }
	}
	private function need( $entity, $id, $option ) {
		if ( ! $id ) { return; }
		if ( ! isset( $this->needed[$entity] ) || ! is_int( $id ) || $id < 1 ) { $this->fail( $option ); return; }
		$this->needed[$entity][$id] = array( 'found' => false, 'parent' => 0, 'option' => $option );
		if ( count( $this->needed[$entity] ) > 10000 ) { $this->fail( $option ); }
	}
	private function fail( $option ) { $this->error = $this->invalid( $option ); }
	private function invalid( $option ) { return new \WP_Error( 'certloom_whole_configuration_graph_invalid', sprintf( __( 'The saved setting %s refers to a missing or incompatible record in this snapshot. Repair the source connection before recovery.', 'certloom' ), $option ), array( 'option' => $option ) ); }
}
