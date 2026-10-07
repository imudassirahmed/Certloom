<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Backup\DomainBackupReader;
use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\WholeStateBackupBuilder;
use Certloom\Application\Backup\WholeStateConfigurationFormat;
use Certloom\Application\Backup\WholeStateAuthoringFormat;
use Certloom\Application\Backup\WholeStateDeliveryFormat;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Backup\WholeStateAssetRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Restore\WholeStateOptionPublication;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Complete source preflight and explicit target bindings; never serializable authority. */
final class WholeStateRestoreReview {
	private $path;
	private $sha;
	private $accounts;
	private $objects;
	private $baseline;
	private $proposal;
	private $field_catalog;
	private $counts;
	private $current_revisions = array();
	private $images = array();
	private $image_choices = array();
	private $image_proofs = array();
	private $files = array();
	private $file_proofs = array();
	private $media_urls = array();
	private $url_proofs = array();
	private $source_configuration;
	private $legacy_generation;
	private function __construct() {}
	private function __clone() {}
	public function __serialize() { throw new \LogicException( 'Complete recovery requires current source and target review.' ); }
	public function __unserialize( array $data ) { throw new \LogicException( 'Serialized recovery approval is not authority.' ); }

	public static function review( $path, $sha, WholeStateAccountMappingProof $accounts, WholeStateObjectMappingProof $objects, array $image_choices = array() ) {
		$archive = ( new WholeStateBackupReader() )->analyze( $path );
		if ( is_wp_error( $archive ) || $archive['archive_sha256'] !== $sha || $archive['uncompressed_bytes'] > WholeStateBackupBuilder::MAX_CAPTURE_BYTES || $accounts->receipt()['archive_sha256'] !== $sha || $objects->receipt()['archive_sha256'] !== $sha || is_wp_error( $accounts->assert_current() ) || is_wp_error( $objects->assert_current() ) ) { return self::invalid(); }
		$review = new self();
		$review->path = $path; $review->sha = $sha; $review->accounts = $accounts; $review->objects = $objects; $review->image_choices = $image_choices;
		$review->baseline = ( new WholeStateOptionPublication() )->snapshot();
		$review->legacy_generation = \Ecapla_CertVerify_Backup::read_current_generation();
		if ( is_wp_error( $review->baseline ) || is_wp_error( $review->legacy_generation ) ) { return self::invalid(); }
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) { return self::invalid(); }
		$files = new PrivateJobFileStore(); $temporary = null;
		try {
			$temporary = $files->create_path( bin2hex( random_bytes( 16 ) ), 'whole-source-review' );
			if ( is_wp_error( $temporary ) ) { $temporary = null; return self::invalid(); }
			$in = $zip->getStream( 'data/domain.cvbackup' ); $out = fopen( $temporary, 'xb' );
			if ( ! is_resource( $in ) || ! is_resource( $out ) ) { if ( is_resource( $in ) ) { fclose( $in ); } if ( is_resource( $out ) ) { fclose( $out ); } return self::invalid(); }
			try { $written = stream_copy_to_stream( $in, $out, $archive['manifest']['members']['data/domain.cvbackup']['bytes'] + 1 ); $flushed = fflush( $out ); } finally { fclose( $in ); fclose( $out ); }
			if ( ! $flushed || $written !== $archive['manifest']['members']['data/domain.cvbackup']['bytes'] ) { return self::invalid(); }
			@chmod( $temporary, 0600 );
			$domain = ( new DomainBackupReader() )->analyze( $temporary );
			if ( is_wp_error( $domain ) || 5 !== $domain['manifest']['format_version'] ) { return self::invalid(); }
			$review->source_configuration = $domain['manifest']['configuration'];
			$review->counts = $domain['counts'];
			$configuration = ( new WholeStateConfigurationFormat() )->decode( $zip->getFromName( 'data/configuration.json' ) );
			$authoring = ( new WholeStateAuthoringFormat() )->decode( $zip->getFromName( 'data/authoring.jsonl' ) );
			if ( is_wp_error( $configuration ) || is_wp_error( $authoring ) ) { return self::invalid(); }
			$refs = $review->analyze_stream( $zip, 'data/references.jsonl', new WholeStateReferenceFormat(), function ( $row ) use ( $review ) {
				if ( 'image' === $row['kind'] ) { $review->images[$row['reference']][$row['descriptor']['sha256']] = $row['descriptor']; }
				elseif ( 'file' === $row['kind'] ) { $review->files[$row['reference']] = $row['descriptor']; }
				elseif ( 'media_url' === $row['kind'] ) { $review->media_urls[$row['source_url']] = $row; }
				return true;
			} );
			$legacy = $review->analyze_stream( $zip, 'data/legacy.cvbackup', new WholeStateLegacyFormat(), static function () { return true; } );
			$deliveries = $review->analyze_stream( $zip, 'data/deliveries.jsonl', new WholeStateDeliveryFormat(), function ( $entity, $row ) use ( $review ) { return ( new WholeStateDeliveryRowValidator() )->validate( $entity, $row ); } );
			if ( is_wp_error( $refs ) || $refs['format_version'] !== $archive['manifest']['scope']['references'] || is_wp_error( $legacy ) || is_wp_error( $deliveries ) ) { return self::invalid(); }
			$codec = new WholeStateOptionRecord(); $fixed = $configuration['options'];
			foreach ( $review->source_configuration as $key => $entry ) { $fixed[$key] = $codec->from_storage( $entry['exists'] ? maybe_serialize( $entry['value'] ) : null ); }
			$fixed['certverify_number_sequence_next'] = $codec->from_storage( null === $legacy['manifest']['numbering_state'] ? null : (string) $legacy['manifest']['numbering_state'] );
			$fixed += array_intersect_key( $authoring, array_flip( WholeStateOptionInventory::keys( 'authoring' ) ) );
			$fixed += $refs['options']; $fixed['certloom_email_automation_runs_v1'] = $deliveries['automation_runs'];
			$decoded = ( new WholeStateOptionSemantics() )->validate_set( $fixed );
			$recommendations = array_diff_key( $authoring, array_flip( WholeStateOptionInventory::keys( 'authoring' ) ) );
			if ( is_wp_error( $decoded ) ) { return $decoded; }
			$recommendations_valid = ( new WholeStateOptionSemantics() )->validate_recommendations( $recommendations ); if ( is_wp_error( $recommendations_valid ) ) { return $recommendations_valid; }
			foreach ( array( 'certverify_fields' => 'fields', 'certverify_retired_fields' => 'retired_fields' ) as $key => $member ) {
				if ( $decoded[$key]['exists'] && ! WholeStateOptionRows::same( $decoded[$key]['value'], $legacy['manifest'][$member] ) ) { return self::invalid(); }
			}
			foreach ( $decoded['certverify_settings']['exists'] ? $decoded['certverify_settings']['value'] : array() as $key => $value ) {
				if ( ! array_key_exists( $key, $legacy['manifest']['settings'] ) || ! WholeStateOptionRows::same( $value, $legacy['manifest']['settings'][$key] ) ) { return self::invalid(); }
			}
			$review->field_catalog = $legacy['manifest']['retired_fields'];
			foreach ( $legacy['manifest']['fields'] as $field ) { $review->field_catalog[$field['key']] = $field; }
			$graph = WholeStateConfigurationGraph::build( $decoded, $recommendations, $review->field_catalog ); if ( is_wp_error( $graph ) ) { return $graph; }
			$review->counts['legacy_registry'] = $legacy['records'];
			foreach ( $deliveries['entities'] as $entity => $bound ) { $review->counts[$entity] = $bound['count']; }
			$review->proposal = $review->project_options( $fixed, $decoded, $recommendations );
			if ( is_wp_error( $review->proposal ) ) { return $review->proposal; }
			$domain_rows = fopen( $temporary, 'rb' );
			try {
				while ( false !== ( $line = fgets( $domain_rows, DomainBackupFormat::MAX_LINE_BYTES + 1 ) ) ) {
					$item = json_decode( $line, true, 64 );
					if ( 'entity' !== ( $item['type'] ?? null ) ) { continue; }
					$graph_row = $graph->visit( $item['entity'], $item['data'] ); if ( is_wp_error( $graph_row ) ) { return $graph_row; }
					if ( 'templates' === $item['entity'] ) { foreach ( array( 'current_revision_id', 'published_revision_id' ) as $key ) { if ( $item['data'][$key] ) { $review->current_revisions[(int) $item['data'][$key]] = true; } } }
					$result = $review->project_domain( $item['entity'], $item['data'] );
					if ( is_wp_error( $result ) ) { return $result; }
				}
			} finally { fclose( $domain_rows ); }
			$graph_valid = $graph->finish(); if ( is_wp_error( $graph_valid ) ) { return $graph_valid; }
			return is_wp_error( $review->assert_current() ) ? self::invalid() : $review;
		} catch ( \Throwable $error ) { return self::invalid( 'review_runtime' ); }
		finally { $zip->close(); if ( $temporary && is_file( $temporary ) ) { $files->delete( $temporary ); } }
	}

	public function assert_current() {
		if ( is_wp_error( $this->accounts->assert_current() ) || is_wp_error( $this->objects->assert_current() ) || $this->legacy_generation !== \Ecapla_CertVerify_Backup::read_current_generation() ) { return self::invalid(); }
		foreach ( $this->image_proofs as $target => $descriptor ) {
			$asset = ( new WholeStateAssetRepository() )->image( 'attachment:' . $target );
			if ( is_wp_error( $asset ) ) { return self::invalid(); } unset( $asset['path'] );
			if ( ! WholeStateOptionRows::same( $asset, $descriptor ) ) { return self::invalid(); }
		}
		foreach ( $this->file_proofs as $target => $descriptor ) {
			$asset = ( new WholeStateAssetRepository() )->file( 'attachment:' . $target ); if ( is_wp_error( $asset ) ) { return self::invalid(); } unset( $asset['path'] );
			if ( ! WholeStateOptionRows::same( $asset, $descriptor ) ) { return self::invalid(); }
		}
		foreach ( $this->url_proofs as $target => $evidence ) {
			$current = ( new WholeStateAssetRepository() )->attachment_url_evidence( $target ); if ( is_wp_error( $current ) || ! WholeStateOptionRows::same( $current, $evidence ) ) { return self::invalid(); }
		}
		$publication = new WholeStateOptionPublication(); $current = $publication->snapshot();
		return ! is_wp_error( $current ) && $publication->fingerprint( $current ) === $publication->fingerprint( $this->baseline ) ? true : self::invalid();
	}
	public function account_proof() { return $this->accounts; }
	public function path() { return $this->path; }
	public function sha256() { return $this->sha; }
	public function baseline() { return $this->baseline; }
	public function proposal() { return $this->proposal; }
	public function counts() { return $this->counts; }
	public function field_catalog() { return $this->field_catalog; }
	public function packet() {
		return array( 'actor' => $this->accounts->receipt()['actor_user_id'], 'accounts' => $this->accounts->review_choices(), 'objects' => $this->objects->review_choices(), 'images' => $this->image_choices, 'fingerprint' => $this->fingerprint() );
	}
	public function fingerprint() {
		$parts = array( $this->sha, $this->accounts->fingerprint(), $this->objects->fingerprint(), $this->baseline, $this->proposal, $this->image_proofs, $this->legacy_generation );
		// Existing approved jobs without these references keep their original receipt.
		if ( $this->file_proofs || $this->url_proofs ) { $parts[] = $this->file_proofs; $parts[] = $this->url_proofs; }
		return ( new CanonicalJson() )->hash( $parts );
	}

	public function project_domain( $entity, array $row ) {
		foreach ( $row as $value ) { if ( null !== $value && ! is_string( $value ) ) { return self::invalid(); } }
		$valid = WholeStateOptionRows::guarded( function () use ( $entity, $row ) { return ( new DomainRestoreRowValidator( null, $this->accounts ) )->validate( $entity, $row ); } );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$fields = ( new WholeStateFieldCatalogReview() )->validate( $entity, $row, $this->field_catalog ); if ( is_wp_error( $fields ) ) { return $fields; }
		if ( 'recipients' === $entity ) { $row = $this->accounts->project_recipient( $row ); if ( is_wp_error( $row ) ) { return $row; } }
		if ( in_array( $entity, array( 'issuers', 'signers' ), true ) ) {
			foreach ( array( 'logo_attachment_id', 'seal_attachment_id', 'signature_attachment_id' ) as $key ) {
				if ( ! empty( $row[$key] ) ) { $target = $this->image_target( (int) $row[$key], true ); if ( is_wp_error( $target ) ) { return $target; } $row[$key] = null === $target ? null : (string) $target; }
			}
		}
		if ( 'template_revisions' === $entity && isset( $this->current_revisions[(int) $row['id']] ) ) {
			$layout = $this->map_layout( json_decode( $row['layout_json'], true, 64 ) );
			if ( is_wp_error( $layout ) ) { return $layout; }
		}
		// Numeric staff IDs are source audit evidence, never a target account association.
		if ( 'credential_events' === $entity ) { $row['actor_user_id'] = null; }
		if ( 'template_revisions' === $entity ) { $row['created_by_user_id'] = null; }
		return RecipientWorkflowRestorePolicy::prepare( $entity, $row );
	}

	public function map_layout( $layout ) {
		if ( ! is_array( $layout ) ) { return self::invalid(); }
		$mapped = $this->map_images( $layout, false );
		if ( is_wp_error( $mapped ) ) { return $mapped; }
		try { return ( new \Certloom\Application\Rendering\LayoutValidator() )->normalize( $mapped ); } catch ( \Throwable $error ) { return self::invalid(); }
	}

	private function project_options( array $fixed, array $decoded, array $recommendations ) {
		$codec = new WholeStateOptionRecord();
		$indexes = array( 'certloom_tutor_lms_mappings' => 'course_id', 'certloom_woocommerce_mappings' => 'product_id', 'certloom_learndash_mappings' => 'course_id', 'certloom_coursera_mappings_v1' => 'course_id', 'certloom_credly_mappings_v1' => 'program_id' );
		foreach ( $decoded as $name => $entry ) {
			if ( ! $entry['exists'] ) { continue; } $value = $entry['value'];
			if ( false !== strpos( $name, '_mappings' ) || in_array( $name, array( 'certloom_webhook_sources_v1', 'certloom_webhook_sources_v2' ), true ) ) {
				foreach ( array( 'mappings', 'sources', 'connections' ) as $list ) {
					if ( ! isset( $value[$list] ) ) { continue; } $rows = array();
					foreach ( $value[$list] as $row ) {
						$row['active'] = false; $keep = true;
						foreach ( array( 'course_id', 'product_id' ) as $key ) {
							if ( ! isset( $row[$key] ) || ! is_int( $row[$key] ) ) { continue; }
							$type = 'product_id' === $key ? 'product' : ( 'certloom_learndash_mappings' === $name ? 'sfwd-courses' : 'courses' );
							$target = $this->objects->target_for( $row[$key], $type ); if ( is_wp_error( $target ) ) { return $target; }
							if ( null === $target ) { $keep = false; } else { $row[$key] = $target; }
						}
						if ( $keep ) {
							if ( isset( $indexes[$name] ) && 'mappings' === $list ) { $rows[(string) $row[$indexes[$name]]] = $row; }
							else { $rows[] = $row; }
						}
					} $value[$list] = $rows;
				}
			}
			if ( 'certloom_email_automation_rules_v1' === $name ) { foreach ( $value as &$rule ) { $rule['active'] = 0; } unset( $rule ); }
			if ( 'certloom_backup_schedule' === $name ) { $value = 'off'; }
			if ( 'certloom_google_drive_config' === $name ) { $value['enabled'] = 0; }
			if ( 'certverify_settings' === $name ) {
				$value[\Certloom\Application\Issuance\PendingRequestPolicy::KEY] = \Certloom\Application\Issuance\PendingRequestPolicy::defaults();
				$value[\Certloom\Application\Issuance\PendingRequestPolicy::NOTICE_SINCE_KEY] = \Certloom\Application\Issuance\PendingRequestPolicy::notice_since_defaults();
			}
			if ( 'certverify_onboarding' === $name && ! empty( $value['verification_page_id'] ) ) { $target = $this->objects->target_for( $value['verification_page_id'], 'page' ); if ( is_wp_error( $target ) ) { return $target; } $value['verification_page_id'] = $target ?: 0; }
			if ( in_array( $name, array( 'certloom_email_template_catalog_v1', 'certloom_email_template_issued_v1', 'certverify_onboarding' ), true ) ) { $value = $this->map_images( $value, true ); if ( is_wp_error( $value ) ) { return $value; } }
			// Provider credentials must be reconnected on a foreign installation.
			if ( ! $this->objects->receipt()['provenance_verified'] && in_array( $name, array( 'certloom_google_drive_secrets', 'certloom_coursera_xapi_credentials_v1', 'certloom_credly_connection_v1', 'certloom_webhook_sources_v1', 'certloom_webhook_sources_v2' ), true ) ) { $fixed[$name] = $codec->from_storage( null ); continue; }
			$fixed[$name] = $codec->from_storage( maybe_serialize( $value ) );
		}
		// A source catalog is evidence of copies, never proof they exist on this host.
		foreach ( array( 'certloom_local_backup_catalog', 'certloom_remote_backup_catalog' ) as $name ) { $fixed[$name] = $this->baseline['fixed'][$name]; }
		$mapped_valid = ( new WholeStateOptionSemantics() )->validate_set( $fixed ); if ( is_wp_error( $mapped_valid ) ) { return $mapped_valid; }
		$autoloads = array(); foreach ( $fixed + $recommendations as $name => $record ) { if ( $record['exists'] ) { $autoloads[$name] = 'off'; } }
		return array( 'fixed' => $fixed, 'recommendations' => $recommendations, 'autoloads' => $autoloads );
	}

	private function map_images( $value, $allow_disconnect, $depth = 0 ) {
		if ( $depth > 32 ) { return self::invalid(); }
		if ( is_string( $value ) && preg_match( '/^attachment:([1-9][0-9]*)$/D', $value, $match ) ) { $target = $this->image_target( (int) $match[1], $allow_disconnect ); return is_wp_error( $target ) ? $target : ( null === $target ? '' : 'attachment:' . $target ); }
		if ( is_string( $value ) ) {
			return \Certloom\Application\Backup\WholeStateEmailMedia::rewrite( $value, function ( $url ) use ( $allow_disconnect ) {
				if ( ! isset( $this->media_urls[$url] ) ) { return $url; }
				$source = $this->media_urls[$url]; $target = $this->image_target( $source['source_id'], $allow_disconnect, $source['image_sha256'] );
				if ( is_wp_error( $target ) || null === $target ) { return is_wp_error( $target ) ? $target : ''; }
				$evidence = $this->url_proofs[$target] ?? ( new WholeStateAssetRepository() )->attachment_url_evidence( $target ); if ( is_wp_error( $evidence ) ) { return $evidence; }
				$this->url_proofs[$target] = $evidence; return $evidence['url'];
			} );
		}
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $key => $item ) {
			if ( 'attachments' === $key ) {
				if ( ! is_array( $item ) ) { return self::invalid(); } $value[$key] = array(); $bytes = 0;
				foreach ( $item as $id ) {
					$target = $this->file_target( $id, $allow_disconnect ); if ( is_wp_error( $target ) ) { return $target; }
					if ( null !== $target ) { $value[$key][] = $target; $bytes += $this->file_proofs[$target]['size']; }
					if ( $bytes > \Certloom\Application\Email\EmailTemplateService::MAX_ATTACHMENTS_TOTAL_BYTES ) { return self::invalid( 'email_attachment_limit' ); }
				}
			}
			elseif ( in_array( $key, array( 'attachment_id', 'logo_attachment_id', 'seal_attachment_id', 'signature_attachment_id', 'organization_logo_id', 'certificate_image_id', 'setup_new_signer_signature_id' ), true ) && $item ) { $target = $this->image_target( (int) $item, $allow_disconnect ); if ( is_wp_error( $target ) ) { return $target; } $value[$key] = $target ?: 0; }
			else { $value[$key] = $this->map_images( $item, $allow_disconnect, $depth + 1 ); if ( is_wp_error( $value[$key] ) ) { return $value[$key]; } }
		}
		// Preserve an intentionally blank position instead of silently installing
		// the email compiler's default design when its only image was disconnected.
		if ( $allow_disconnect && 'image' === ( $value['type'] ?? null ) && array_key_exists( 'url', $value ) && '' === $value['url'] ) { return array( 'type' => 'spacer', 'height' => 8 ); }
		return $value;
	}
	private function image_target( $source_id, $allow_disconnect, $expected_sha = null ) {
		$target = $this->objects->target_for( $source_id, 'attachment' );
		if ( is_wp_error( $target ) || ( null === $target && ! $allow_disconnect ) ) { return self::invalid(); }
		if ( null === $target ) { return null; }
		$reference = 'attachment:' . $source_id; $descriptors = $this->images[$reference] ?? array();
		$choice = $this->image_choices[$reference] ?? $expected_sha ?? ( 1 === count( $descriptors ) ? key( $descriptors ) : null );
		if ( ! is_string( $choice ) || ! isset( $descriptors[$choice] ) || ( null !== $expected_sha && $choice !== $expected_sha ) ) { return self::invalid(); }
		if ( isset( $this->image_proofs[$target] ) ) { return WholeStateOptionRows::same( $this->image_proofs[$target], $descriptors[$choice] ) ? $target : self::invalid(); }
		$asset = ( new WholeStateAssetRepository() )->image( 'attachment:' . $target );
		if ( is_wp_error( $asset ) ) { return self::invalid(); } unset( $asset['path'] );
		if ( ! WholeStateOptionRows::same( $asset, $descriptors[$choice] ) ) { return self::invalid(); }
		$this->image_proofs[$target] = $asset;
		return $target;
	}
	private function file_target( $source_id, $allow_disconnect ) {
		if ( ! \Certloom\Application\Backup\WholeStateReferenceRecord::id( $source_id ) ) { return self::invalid(); }
		$target = $this->objects->target_for( $source_id, 'attachment' );
		if ( is_wp_error( $target ) || ( null === $target && ! $allow_disconnect ) ) { return self::invalid(); }
		if ( null === $target ) { return null; }
		$descriptor = $this->files['attachment:' . $source_id] ?? null;
		if ( isset( $this->file_proofs[$target] ) ) { return WholeStateOptionRows::same( $this->file_proofs[$target], $descriptor ) ? $target : self::invalid(); }
		$asset = ( new WholeStateAssetRepository() )->file( 'attachment:' . $target ); if ( is_wp_error( $asset ) || ! is_array( $descriptor ) ) { return self::invalid(); } unset( $asset['path'] );
		if ( ! WholeStateOptionRows::same( $descriptor, $asset ) ) { return self::invalid(); }
		$this->file_proofs[$target] = $asset; return $target;
	}
	private function analyze_stream( \ZipArchive $zip, $name, $format, callable $callback ) {
		$stream = $zip->getStream( $name ); if ( ! is_resource( $stream ) ) { return self::invalid(); }
		try { return $format->analyze( $stream, $callback ); } finally { fclose( $stream ); }
	}
	private static function invalid( $stage = 'connections' ) { return new \WP_Error( 'certloom_whole_restore_review_invalid', __( 'Review the complete recovery settings, account and media connections against this site. Changed targets, incomplete fields or unproven file bytes cannot be restored.', 'certloom' ), array( 'stage' => $stage ) ); }
}
