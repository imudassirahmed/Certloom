<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\LayoutSchemaUpgrader;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Application\Templates\TemplatePackage;
use Certloom\Application\Templates\TemplateAssignmentPolicy;
use Certloom\Application\Templates\TemplateLifecycle;
use Certloom\Application\Templates\TemplateFieldCompatibility;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Infrastructure\Media\PortableDesignAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Versioned template persistence. Publication controls design availability; artifact rendering is gated separately. */
final class TemplateRepository {
	const SOURCE_MANUAL = 'manual';
	const SOURCE_IMPORT = 'import';
	const SOURCE_PRESET = 'preset';

	/** @var LayoutValidator */ private $layouts;
	/** @var CanonicalJson */ private $json;
	/** @var TransactionInterface */ private $transaction;

	public function __construct( ?LayoutValidator $layouts = null, ?CanonicalJson $json = null, ?TransactionInterface $transaction = null, $legacy_renderer_authority = null ) {
		$this->layouts             = $layouts ?: new LayoutValidator();
		$this->json                = $json ?: new CanonicalJson();
		$this->transaction         = $transaction ?: new Transaction();
	}

	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data, $actor_user_id = 0, $source_type = self::SOURCE_MANUAL ) {
		$identity = $this->normalize_identity( $data );
		if ( is_wp_error( $identity ) ) { return $identity; }
		$layout = $this->normalize_layout( $data['layout'] ?? null );
		if ( is_wp_error( $layout ) ) { return $layout; }
		$source_type = $this->normalize_source( $source_type );
		if ( is_wp_error( $source_type ) ) { return $source_type; }
		$is_preset_key = 0 === strpos( (string) $identity['machine_key'], 'certloom-preset-' );
		if ( self::SOURCE_PRESET === $source_type && ! $is_preset_key ) { return new \WP_Error( 'certloom_preset_key_required', __( 'Factory preset templates must use the reserved Certloom preset machine-key namespace.', 'certloom' ) ); }
		if ( self::SOURCE_PRESET !== $source_type && $is_preset_key ) { return new \WP_Error( 'certloom_preset_key_reserved', __( 'The certloom-preset machine-key namespace is reserved for built-in factory templates.', 'certloom' ) ); }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }

		return $this->transaction->run( function () use ( $identity, $layout, $actor_user_id, $source_type ) {
			global $wpdb;
			$now = current_time( 'mysql', true );
			$row = $identity + array(
				'current_revision_id'   => null,
				'published_revision_id' => null,
				'created_at'            => $now,
				'updated_at'            => $now,
				'archived_at'           => null,
			);
			$wpdb->last_error = '';
			$inserted = $wpdb->insert( SchemaManager::table( 'templates' ), $row );
			if ( false === $inserted || '' !== (string) $wpdb->last_error || absint( $wpdb->insert_id ) < 1 ) {
				return false !== stripos( (string) $wpdb->last_error, 'duplicate' )
					? new \WP_Error( 'certloom_template_key_duplicate', __( 'That template machine key is already in use.', 'certloom' ) )
					: new \WP_Error( 'certloom_template_create_failed', __( 'Certloom could not create the template.', 'certloom' ) );
			}
			$template_id = absint( $wpdb->insert_id );
			$revision_id = $this->insert_revision( $template_id, 1, null, $layout, $actor_user_id, $source_type, $now );
			if ( is_wp_error( $revision_id ) ) { return $revision_id; }
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'current_revision_id' => $revision_id ), array( 'id' => $template_id ) );
			if ( false === $updated || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_template_revision_link_failed', __( 'Certloom could not link the initial template revision safely.', 'certloom' ) );
			}
			return $template_id;
		} );
	}

	/** @return true|\WP_Error */
	public function update_metadata( $template_id, array $data, $expected_updated_at = '' ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$identity = $this->normalize_identity( $data, true );
		if ( is_wp_error( $identity ) ) { return $identity; }
		if ( empty( $identity ) ) { return true; }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $identity, $expected_updated_at ) {
			global $wpdb;
			$locked = $this->lock_template( $template_id );
			if ( is_wp_error( $locked ) ) { return $locked; }
			if ( ! is_array( $locked ) ) { return new \WP_Error( 'certloom_template_missing', __( 'The template no longer exists.', 'certloom' ) ); }
			if ( ! empty( $locked['archived_at'] ) ) { return new \WP_Error( 'certloom_template_archived_read_only', __( 'Archived templates are read-only. Restore this template before editing it.', 'certloom' ) ); }
			if ( '' !== (string) $expected_updated_at && ! hash_equals( (string) $locked['updated_at'], (string) $expected_updated_at ) ) {
				return new \WP_Error( 'certloom_template_stale_edit', __( 'This template changed after you opened it. Reload before saving.', 'certloom' ) );
			}
			if ( array_key_exists( 'machine_key', $identity ) && (string) $identity['machine_key'] !== (string) $locked['machine_key'] ) {
				$new_reserved = 0 === strpos( (string) $identity['machine_key'], 'certloom-preset-' );
				$old_reserved = 0 === strpos( (string) $locked['machine_key'], 'certloom-preset-' );
				if ( $new_reserved ) { return new \WP_Error( 'certloom_preset_key_reserved', __( 'The certloom-preset machine-key namespace is reserved for built-in factory templates.', 'certloom' ) ); }
				if ( $old_reserved ) {
					$has_preset = $this->has_preset_revision( $template_id );
					if ( is_wp_error( $has_preset ) ) { return $has_preset; }
					if ( $has_preset ) { return new \WP_Error( 'certloom_preset_key_immutable', __( 'Factory template machine keys are permanent. Duplicate the template if you need a separately named design.', 'certloom' ) ); }
				}
			}
			$identity['updated_at'] = current_time( 'mysql', true );
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), $identity, array( 'id' => $template_id ) );
			if ( false === $updated || '' !== (string) $wpdb->last_error ) {
				return false !== stripos( (string) $wpdb->last_error, 'duplicate' )
					? new \WP_Error( 'certloom_template_key_duplicate', __( 'That template machine key is already in use.', 'certloom' ) )
					: new \WP_Error( 'certloom_template_update_failed', __( 'Certloom could not update the template.', 'certloom' ) );
			}
			return true;
		} );
	}

	/** @return int|\WP_Error */
	public function save_revision( $template_id, $layout, $actor_user_id = 0, $expected_revision_id = 0, $source_type = self::SOURCE_MANUAL, $expected_revision_sha256 = '' ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$layout = $this->normalize_layout( $layout );
		if ( is_wp_error( $layout ) ) { return $layout; }
		$source_type = $this->normalize_source( $source_type );
		if ( is_wp_error( $source_type ) ) { return $source_type; }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $layout, $actor_user_id, $expected_revision_id, $source_type, $expected_revision_sha256 ) {
			global $wpdb;
			$template = $this->lock_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The template is unavailable for editing.', 'certloom' ) ); }
			if ( self::SOURCE_PRESET === $source_type && 0 !== strpos( (string) ( $template['machine_key'] ?? '' ), 'certloom-preset-' ) ) { return new \WP_Error( 'certloom_preset_key_required', __( 'Factory preset revisions may only be written to reserved Certloom factory templates.', 'certloom' ) ); }
			$current_id = absint( $template['current_revision_id'] ?? 0 );
			if ( $expected_revision_id && $current_id !== absint( $expected_revision_id ) ) {
				return new \WP_Error( 'certloom_template_stale_revision', __( 'This template draft changed after you opened it. Reload before saving.', 'certloom' ) );
			}
			$current = $current_id ? $this->find_revision_row( $current_id, true ) : null;
			if ( is_wp_error( $current ) ) { return $current; }
			if ( $current_id && ( ! is_array( $current ) || absint( $current['template_id'] ?? 0 ) !== $template_id ) ) { return new \WP_Error( 'certloom_template_revision_pointer_corrupt', __( 'The template draft points to an invalid revision.', 'certloom' ) ); }
			$expected_revision_sha256 = strtolower( trim( (string) $expected_revision_sha256 ) );
			if ( '' !== $expected_revision_sha256 ) {
				if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $expected_revision_sha256 ) || ! is_array( $current ) ) {
					return new \WP_Error( 'certloom_template_stale_revision_hash', __( 'This Template revision no longer matches the exact layout hash reviewed in Designer. Reload before saving.', 'certloom' ) );
				}
				$verified_current = $this->verify_revision_row( $current );
				if ( is_wp_error( $verified_current ) ) { return $verified_current; }
				if ( ! hash_equals( strtolower( (string) ( $verified_current['layout_sha256'] ?? '' ) ), $expected_revision_sha256 ) ) {
					return new \WP_Error( 'certloom_template_stale_revision_hash', __( 'This Template revision no longer matches the exact layout hash reviewed in Designer. Reload before saving.', 'certloom' ) );
				}
			}
			$hash = $this->json->hash( $layout );
			if ( is_array( $current ) && $this->json->matches_hash( $layout, $current['layout_sha256'] ) ) { return $current_id; }
			$number = is_array( $current ) ? absint( $current['revision_number'] ) + 1 : 1;
			$now = current_time( 'mysql', true );
			$revision_id = $this->insert_revision( $template_id, $number, $current_id ?: null, $layout, $actor_user_id, $source_type, $now );
			if ( is_wp_error( $revision_id ) ) { return $revision_id; }
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'current_revision_id' => $revision_id, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error
				? new \WP_Error( 'certloom_template_revision_advance_failed', __( 'Certloom could not advance the template draft safely.', 'certloom' ) )
				: $revision_id;
		} );
	}


	/**
	 * Explicitly upgrades the current draft to a newer canonical layout schema.
	 * Historical revisions are never rewritten; a successful upgrade creates a
	 * new immutable revision after exact revision/hash review is revalidated
	 * under the same template lock.
	 *
	 * @return int|\WP_Error
	 */
	public function upgrade_current_layout( $template_id, $expected_revision_id, $expected_revision_sha256, $actor_user_id = 0, $target_version = LayoutSchema::VERSION ) {
		$template_id = absint( $template_id );
		$expected_revision_id = absint( $expected_revision_id );
		$expected_revision_sha256 = strtolower( trim( (string) $expected_revision_sha256 ) );
		$target_version = absint( $target_version );
		if ( ! $template_id || ! $expected_revision_id || ! preg_match( '/^[a-f0-9]{64}$/', $expected_revision_sha256 ) || ! LayoutSchema::supports_version( $target_version ) ) {
			return new \WP_Error( 'certloom_template_upgrade_review_required', __( 'Layout upgrading requires the exact reviewed revision, layout hash and supported target schema.', 'certloom' ) );
		}
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $expected_revision_id, $expected_revision_sha256, $actor_user_id, $target_version ) {
			global $wpdb;
			$template = $this->lock_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The template is unavailable for schema upgrading.', 'certloom' ) ); }
			$current_id = absint( $template['current_revision_id'] ?? 0 );
			if ( $current_id !== $expected_revision_id ) { return new \WP_Error( 'certloom_template_stale_revision', __( 'This template draft changed after you reviewed it. Reload before upgrading.', 'certloom' ) ); }
			$current = $this->find_revision_row( $current_id, true );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! is_array( $current ) || absint( $current['template_id'] ?? 0 ) !== $template_id ) { return new \WP_Error( 'certloom_template_revision_pointer_corrupt', __( 'The template draft points to an invalid revision.', 'certloom' ) ); }
			$verified = $this->verify_revision_row( $current );
			if ( is_wp_error( $verified ) ) { return $verified; }
			if ( ! hash_equals( strtolower( (string) $verified['layout_sha256'] ), $expected_revision_sha256 ) ) { return new \WP_Error( 'certloom_template_upgrade_review_stale', __( 'The reviewed template hash no longer matches the revision selected for upgrade.', 'certloom' ) ); }
			$current_version = absint( $verified['layout']['_meta']['schema_version'] ?? 0 );
			if ( $current_version > $target_version ) { return new \WP_Error( 'certloom_template_upgrade_downgrade_forbidden', __( 'Certloom never downgrades immutable Template layout schemas.', 'certloom' ) ); }
			if ( $current_version === $target_version ) { return $current_id; }
			try { $layout = ( new LayoutSchemaUpgrader( $this->layouts ) )->upgrade( $verified['layout'], $target_version ); }
			catch ( \Throwable $error ) { return new \WP_Error( 'certloom_template_upgrade_failed', __( 'Certloom could not deterministically upgrade this Template layout.', 'certloom' ) ); }
			$number = absint( $verified['revision_number'] ?? 0 ) + 1;
			$now = current_time( 'mysql', true );
			$revision_id = $this->insert_revision( $template_id, $number, $current_id, $layout, $actor_user_id, self::SOURCE_MANUAL, $now );
			if ( is_wp_error( $revision_id ) ) { return $revision_id; }
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'current_revision_id' => $revision_id, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error
				? new \WP_Error( 'certloom_template_upgrade_advance_failed', __( 'Certloom created the upgraded revision but could not advance the Template draft safely.', 'certloom' ) )
				: absint( $revision_id );
		} );
	}

	/** @return true|\WP_Error */
	public function publish( $template_id, $expected_revision_id = 0, $expected_revision_sha256 = '' ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$expected_revision_id = absint( $expected_revision_id );
		$expected_revision_sha256 = strtolower( trim( (string) $expected_revision_sha256 ) );
		if ( ! $expected_revision_id || ! preg_match( '/\A[a-f0-9]{64}\z/', $expected_revision_sha256 ) ) {
			return new \WP_Error( 'certloom_template_publish_review_required', __( 'Publishing requires the exact reviewed template revision and layout hash.', 'certloom' ) );
		}
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $expected_revision_id, $expected_revision_sha256 ) {
			global $wpdb;
			$template = $this->lock_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The template is unavailable for publishing.', 'certloom' ) ); }
			$current_id = absint( $template['current_revision_id'] ?? 0 );
			if ( ! $current_id ) { return new \WP_Error( 'certloom_template_revision_required', __( 'The template has no current revision to publish.', 'certloom' ) ); }
			if ( $current_id !== $expected_revision_id ) { return new \WP_Error( 'certloom_template_stale_revision', __( 'This template draft changed after you reviewed it. Reload before publishing.', 'certloom' ) ); }
			$current = $this->find_revision_row( $current_id, true );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! is_array( $current ) || absint( $current['template_id'] ?? 0 ) !== $template_id ) { return new \WP_Error( 'certloom_template_revision_pointer_corrupt', __( 'The template draft points to an invalid revision.', 'certloom' ) ); }
			$verified = $this->verify_revision_row( $current );
			if ( is_wp_error( $verified ) ) { return $verified; }
			if ( ! hash_equals( strtolower( (string) $verified['layout_sha256'] ), $expected_revision_sha256 ) ) { return new \WP_Error( 'certloom_template_publish_review_stale', __( 'The reviewed template hash no longer matches the revision selected for publication.', 'certloom' ) ); }
			$compatibility = $this->validate_active_assignment_field_contracts( $template_id, (array) $verified['layout'] );
			if ( is_wp_error( $compatibility ) ) { return $compatibility; }
			if ( absint( $template['published_revision_id'] ?? 0 ) === $current_id ) { return true; }
			$now = current_time( 'mysql', true );
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'published_revision_id' => $current_id, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error
				? new \WP_Error( 'certloom_template_publish_failed', __( 'Certloom could not publish the template revision safely.', 'certloom' ) )
				: true;
		} );
	}

	/** Move an unassigned published template back to Draft without deleting revisions. @return true|\WP_Error */
	public function unpublish( $template_id, $expected_updated_at = '' ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $expected_updated_at ) {
			global $wpdb;
			$template = $this->lock_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The design is unavailable.', 'certloom' ) ); }
			if ( '' !== (string) $expected_updated_at && ! hash_equals( (string) $template['updated_at'], (string) $expected_updated_at ) ) { return new \WP_Error( 'certloom_template_stale_edit', __( 'This design changed after you opened it. Reload before changing its publication state.', 'certloom' ) ); }
			if ( ! absint( $template['published_revision_id'] ?? 0 ) ) { return true; }
			$wpdb->last_error = '';
			$assignment_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_id=%d AND active=1', SchemaManager::table( 'template_assignments' ), $template_id ) );
			if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not verify where this design is currently used.', 'certloom' ) ); }
			if ( $assignment_count > 0 ) { return new \WP_Error( 'certloom_template_unpublish_assigned', __( 'This design is currently assigned to a Program, Batch, Issuer or site default. Choose another design there before moving this one back to Draft.', 'certloom' ) ); }
			$now = current_time( 'mysql', true );
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'published_revision_id' => null, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_unpublish_failed', __( 'Certloom could not move this design back to Draft safely.', 'certloom' ) ) : true;
		} );
	}

	/** @return true|\WP_Error */
	public function archive( $template_id, $expected_updated_at = '' ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $expected_updated_at ) {
			global $wpdb;
			$template = $this->lock_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) ) { return new \WP_Error( 'certloom_template_missing', __( 'The template no longer exists.', 'certloom' ) ); }
			if ( '' !== (string) $expected_updated_at && ! hash_equals( (string) $template['updated_at'], (string) $expected_updated_at ) ) { return new \WP_Error( 'certloom_template_stale_edit', __( 'This template changed after you opened it. Reload before archiving.', 'certloom' ) ); }
			if ( ! empty( $template['archived_at'] ) ) { return true; }
			$wpdb->last_error = '';
			$assignment_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_id=%d AND active=1', SchemaManager::table( 'template_assignments' ), $template_id ) );
			if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not verify whether this template is still assigned.', 'certloom' ) ); }
			if ( $assignment_count > 0 ) { return new \WP_Error( 'certloom_template_archive_assigned', __( 'Remove this template from every active assignment before archiving it.', 'certloom' ) ); }
			$now = current_time( 'mysql', true );
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'archived_at' => $now, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_archive_failed', __( 'Certloom could not archive the template.', 'certloom' ) ) : true;
		} );
	}

	/** @return true|\WP_Error */
	public function restore( $template_id, $expected_updated_at = '' ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $expected_updated_at ) {
			global $wpdb;
			$template = $this->lock_template( $template_id );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) ) { return new \WP_Error( 'certloom_template_missing', __( 'The template no longer exists.', 'certloom' ) ); }
			if ( '' !== (string) $expected_updated_at && ! hash_equals( (string) $template['updated_at'], (string) $expected_updated_at ) ) { return new \WP_Error( 'certloom_template_stale_edit', __( 'This template changed after you opened it. Reload before restoring.', 'certloom' ) ); }
			if ( empty( $template['archived_at'] ) ) { return true; }
			$now = current_time( 'mysql', true );
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'archived_at' => null, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_restore_failed', __( 'Certloom could not restore the template.', 'certloom' ) ) : true;
		} );
	}

	/** @return int|\WP_Error */
	public function duplicate( $template_id, array $identity, $actor_user_id = 0, $expected_revision_id = 0 ) {
		$template = $this->find( $template_id );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) || empty( $template['current_revision'] ) ) { return new \WP_Error( 'certloom_template_duplicate_missing', __( 'The source template does not have a valid current revision to duplicate.', 'certloom' ) ); }
		$current_id = absint( $template['current_revision_id'] ?? 0 );
		if ( ! absint( $expected_revision_id ) || $current_id !== absint( $expected_revision_id ) ) { return new \WP_Error( 'certloom_template_stale_revision', __( 'The source template changed after you opened it. Reload before duplicating.', 'certloom' ) ); }
		$identity['layout'] = $template['current_revision']['layout'];
		$created = $this->create( $identity, $actor_user_id, self::SOURCE_MANUAL );
		if ( is_wp_error( $created ) ) { return $created; }
		$profiles = ( new TemplateRecommendationRepository() )->get( $template_id );
		/* Recommendation tags are presentation metadata; a copy must never become an orphaned failure after the canonical Template was created. */
		( new TemplateRecommendationRepository() )->save( $created, $profiles );
		return $created;
	}

	/** @return string */
	public function lifecycle_state( array $template ) {
		return TemplateLifecycle::state( $template );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_by_machine_key( $machine_key ) {
		$machine_key = sanitize_key( (string) $machine_key );
		if ( '' === $machine_key ) { return new \WP_Error( 'certloom_template_key_invalid', __( 'A valid template machine key is required.', 'certloom' ) ); }
		global $wpdb;
		$wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE machine_key=%s LIMIT 1', SchemaManager::table( 'templates' ), $machine_key ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_read_failed', __( 'Certloom could not read the template.', 'certloom' ) ); }
		return $id ? $this->find( absint( $id ) ) : null;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $template_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'templates' ), absint( $template_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_read_failed', __( 'Certloom could not read the template.', 'certloom' ) ); }
		if ( ! is_array( $row ) ) { return null; }
		$identity = $this->normalize_identity( array( 'machine_key' => (string) ( $row['machine_key'] ?? '' ), 'name' => (string) ( $row['name'] ?? '' ), 'description' => (string) ( $row['description'] ?? '' ) ) );
		if ( is_wp_error( $identity ) ) { return new \WP_Error( 'certloom_template_identity_corrupt', __( 'A stored template contains invalid identity data.', 'certloom' ) ); }
		if ( ! empty( $row['current_revision_id'] ) ) {
			$revision = $this->find_revision( absint( $row['current_revision_id'] ) );
			if ( is_wp_error( $revision ) ) { return $revision; }
			if ( ! is_array( $revision ) || absint( $revision['template_id'] ?? 0 ) !== absint( $row['id'] ) ) { return new \WP_Error( 'certloom_template_revision_pointer_corrupt', __( 'The template draft points to a revision owned by another template.', 'certloom' ) ); }
			$row['current_revision'] = $revision;
		}
		if ( ! empty( $row['published_revision_id'] ) ) {
			$published = $this->find_revision( absint( $row['published_revision_id'] ) );
			if ( is_wp_error( $published ) ) { return $published; }
			if ( ! is_array( $published ) || absint( $published['template_id'] ?? 0 ) !== absint( $row['id'] ) ) { return new \WP_Error( 'certloom_template_published_pointer_corrupt', __( 'The template published pointer is invalid.', 'certloom' ) ); }
			$row['published_revision'] = $published;
		}
		$row['lifecycle_state'] = TemplateLifecycle::state( $row );
		return $row;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_revision( $revision_id ) {
		$row = $this->find_revision_row( $revision_id, false );
		return is_wp_error( $row ) || ! is_array( $row ) ? $row : $this->verify_revision_row( $row );
	}

	/** Published-only read boundary for future authoritative issuance. @return array<string,mixed>|null|\WP_Error */
	public function find_published( $template_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'templates' ), absint( $template_id ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_read_failed', __( 'Certloom could not read the template.', 'certloom' ) ); }
		if ( ! is_array( $row ) ) { return null; }
		$identity = $this->normalize_identity( array( 'machine_key' => (string) ( $row['machine_key'] ?? '' ), 'name' => (string) ( $row['name'] ?? '' ), 'description' => (string) ( $row['description'] ?? '' ) ) );
		if ( is_wp_error( $identity ) ) { return new \WP_Error( 'certloom_template_identity_corrupt', __( 'A stored template contains invalid identity data.', 'certloom' ) ); }
		$published_id = absint( $row['published_revision_id'] ?? 0 );
		if ( ! $published_id ) { return new \WP_Error( 'certloom_template_not_published', __( 'This design is still a draft. Publish it in Design before using it for credential issuance.', 'certloom' ) ); }
		$published = $this->find_revision( $published_id );
		if ( is_wp_error( $published ) ) { return $published; }
		if ( ! is_array( $published ) || absint( $published['template_id'] ?? 0 ) !== absint( $row['id'] ) ) { return new \WP_Error( 'certloom_template_published_pointer_corrupt', __( 'The template published pointer is invalid.', 'certloom' ) ); }
		$row['published_revision'] = $published;
		$row['lifecycle_state'] = TemplateLifecycle::state( $row );
		return $row;
	}


	/** Stable-key published read boundary for Program locale variants. @return array<string,mixed>|null|\WP_Error */
	public function find_published_by_machine_key( $machine_key ) {
		$machine_key = sanitize_key( (string) $machine_key );
		if ( '' === $machine_key || strlen( $machine_key ) > 191 ) { return new \WP_Error( 'certloom_template_key_invalid', __( 'Choose a valid Template machine key.', 'certloom' ) ); }
		global $wpdb; $wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE machine_key=%s LIMIT 1', SchemaManager::table( 'templates' ), $machine_key ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_read_failed', __( 'Certloom could not read the template.', 'certloom' ) ); }
		return $id ? $this->find_published( absint( $id ) ) : null;
	}


	/** Published, non-archived Templates suitable for authoritative issuance selection. @return array<int,array<string,mixed>>|\WP_Error */
	public function list_published_candidates( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 250, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id,t.name,t.machine_key,t.published_revision_id,r.revision_number,r.layout_sha256,r.layout_schema_version FROM %i t INNER JOIN %i r ON r.id=t.published_revision_id AND r.template_id=t.id WHERE t.archived_at IS NULL AND t.published_revision_id IS NOT NULL ORDER BY t.name ASC,t.id ASC LIMIT %d',
				SchemaManager::table( 'templates' ), SchemaManager::table( 'template_revisions' ), $limit
			), ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_template_published_list_failed', __( 'Certloom could not load published templates for issuance.', 'certloom' ) ); }
		foreach ( $rows as &$row ) {
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['published_revision_id'] = absint( $row['published_revision_id'] ?? 0 );
			$row['revision_number'] = absint( $row['revision_number'] ?? 0 );
			$row['layout_schema_version'] = absint( $row['layout_schema_version'] ?? 0 );
			// The final issuance review still calls find_published(), independently
			// validating exact canonical bytes/hash before any mutation.
		}
		unset( $row );
		return $rows;
	}

	/** Search published, non-archived Templates without pretending a fixed first page is complete. @return array<int,array<string,mixed>>|\WP_Error */
	public function search_published_candidates( $search = '', $limit = 20 ) {
		global $wpdb;
		$search = trim( (string) $search );
		$limit = max( 1, min( 50, absint( $limit ) ) );
		$where = ''; $args = array( SchemaManager::table( 'templates' ), SchemaManager::table( 'template_revisions' ) );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where = ' AND (t.name LIKE %s OR t.machine_key LIKE %s OR t.description LIKE %s)';
			$args[] = $like; $args[] = $like; $args[] = $like;
		}
		$args[] = $limit; $wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id,t.name,t.machine_key,t.published_revision_id,r.revision_number,r.layout_sha256,r.layout_schema_version FROM %i t INNER JOIN %i r ON r.id=t.published_revision_id AND r.template_id=t.id WHERE t.archived_at IS NULL AND t.published_revision_id IS NOT NULL' . $where . ' ORDER BY t.name ASC,t.id ASC LIMIT %d',
				$args
			), ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_template_published_search_failed', __( 'Certloom could not search published templates.', 'certloom' ) ); }
		foreach ( $rows as &$row ) {
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['published_revision_id'] = absint( $row['published_revision_id'] ?? 0 );
			$row['revision_number'] = absint( $row['revision_number'] ?? 0 );
			$row['layout_schema_version'] = absint( $row['layout_schema_version'] ?? 0 );
		}
		unset( $row );
		return $rows;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 20, $status = 'active', $kind = 'all' ) {
		global $wpdb;
		$page = max( 1, absint( $page ) ); $per_page = max( 1, min( 100, absint( $per_page ) ) ); $offset = ( $page - 1 ) * $per_page;
		$status = in_array( $status, array( 'active', 'archived', 'all' ), true ) ? $status : 'active';
		$kind = in_array( $kind, array( 'all', 'factory', 'custom' ), true ) ? $kind : 'all';
		$where = array(); $args = array(); $search = trim( (string) $search );
		if ( 'active' === $status ) { $where[] = 't.archived_at IS NULL'; } elseif ( 'archived' === $status ) { $where[] = 't.archived_at IS NOT NULL'; }
		if ( 'factory' === $kind ) { $where[] = "t.machine_key LIKE 'certloom-preset-%'"; } elseif ( 'custom' === $kind ) { $where[] = "t.machine_key NOT LIKE 'certloom-preset-%'"; }
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(t.name LIKE %s OR t.machine_key LIKE %s OR t.description LIKE %s)'; $args = array_merge( $args, array( $like, $like, $like ) ); }
		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$table = SchemaManager::table( 'templates' ); $revisions = SchemaManager::table( 'template_revisions' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t' . $where_sql, array_merge( array( $table ), $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_list_failed', __( 'Certloom could not load templates.', 'certloom' ) ); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, r.revision_number AS current_revision_number, r.layout_json AS current_layout_json, r.layout_sha256, pr.revision_number AS published_revision_number, pr.layout_sha256 AS published_layout_sha256 FROM %i t LEFT JOIN %i r ON r.id=t.current_revision_id LEFT JOIN %i pr ON pr.id=t.published_revision_id' . $where_sql . ' ORDER BY t.updated_at DESC,t.id DESC LIMIT %d OFFSET %d', array_merge( array( $table, $revisions, $revisions ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_template_list_failed', __( 'Certloom could not load templates.', 'certloom' ) ); }
		foreach ( $rows as &$row ) {
			$raw_layout = (string) ( $row['current_layout_json'] ?? '' );
			if ( '' === $raw_layout ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A certificate design is missing its current layout.', 'certloom' ) ); }
			$decoded = json_decode( $raw_layout, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A certificate design contains invalid current layout data.', 'certloom' ) ); }
			try {
				$layout = $this->layouts->normalize( $decoded );
				$hash_matches = $this->json->matches_hash( $layout, $row['layout_sha256'] ?? '' );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A certificate design does not satisfy Certloom’s canonical layout contract.', 'certloom' ) );
			}
			if ( ! $hash_matches ) { return new \WP_Error( 'certloom_template_revision_hash_mismatch', __( 'A certificate design failed its current-layout integrity check.', 'certloom' ) ); }
			$row['current_layout'] = $layout;
			unset( $row['current_layout_json'] );
		}
		unset( $row );
		return array( 'items' => $rows, 'total' => $total );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function export_package( $template_id, $plugin_version ) {
		$template = $this->find( $template_id );
		if ( is_wp_error( $template ) || ! is_array( $template ) || empty( $template['current_revision'] ) ) {
			return is_wp_error( $template )
				? $template
				: new \WP_Error( 'certloom_template_export_missing', __( 'The design cannot be exported because its current revision is unavailable.', 'certloom' ) );
		}

		$portable = ( new PortableDesignAssets() )->prepare_certificate_export( (array) $template['current_revision']['layout'] );
		if ( is_wp_error( $portable ) ) {
			return $portable;
		}

		$revision = $template['current_revision'];
		$revision['layout'] = $portable['layout'];
		$template['recommended_profiles'] = ( new TemplateRecommendationRepository() )->get( $template_id );
		return ( new TemplatePackage( $this->layouts, $this->json ) )->build(
			$template,
			$revision,
			$plugin_version,
			$portable['assets']
		);
	}

	/** @return int|\WP_Error */
	public function import_package( $json, $actor_user_id = 0 ) {
		$decoded = ( new TemplatePackage( $this->layouts, $this->json ) )->decode( $json );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$media = new PortableDesignAssets();
		$installed = $media->install_certificate_assets(
			(array) $decoded['layout'],
			(array) ( $decoded['assets'] ?? array() )
		);
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$decoded['layout'] = $installed['layout'];
		$recommended_profiles = isset( $decoded['recommended_profiles'] ) && is_array( $decoded['recommended_profiles'] ) ? $decoded['recommended_profiles'] : array();
		unset( $decoded['assets'], $decoded['layout_sha256'], $decoded['recommended_profiles'] );
		$created = $this->create( $decoded, $actor_user_id, self::SOURCE_IMPORT );
		if ( is_wp_error( $created ) ) {
			$media->cleanup( $installed['created_ids'] );
			return $created;
		}
		/* Recommendation tags are non-authoritative. Never delete imported media after the canonical Template has already been created. */
		( new TemplateRecommendationRepository() )->save( $created, $recommended_profiles );
		return $created;
	}

	/**
	 * Sets one direct assignment with optimistic-concurrency proof.
	 *
	 * @return true|\WP_Error
	 */
	public function assign( $template_id, $scope_type, $scope_id = 0, $priority = 100, $expected_assignment_id = 0, $expected_updated_at = '' ) {
		$template_id = absint( $template_id );
		$priority = absint( $priority );
		$scope = ( new TemplateAssignmentPolicy() )->normalize_scope( $scope_type, $scope_id );
		if ( is_wp_error( $scope ) || ! $template_id || $priority > 1000 ) { return is_wp_error( $scope ) ? $scope : new \WP_Error( 'certloom_template_assignment_invalid', __( 'Choose a valid template assignment.', 'certloom' ) ); }
		$scope_type = $scope['scope_type']; $scope_id = $scope['scope_id'];
		$template = $this->find( $template_id );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['current_revision_id'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The template is unavailable for assignment.', 'certloom' ) ); }
		$exists = $this->scope_exists( $scope_type, $scope_id );
		if ( is_wp_error( $exists ) ) { return $exists; }
		if ( ! $exists ) { return new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The template assignment target no longer exists.', 'certloom' ) ); }
		$compatibility = $this->validate_assignment_field_contract( $scope_type, $scope_id, (array) ( $template['current_revision']['layout'] ?? array() ) );
		if ( is_wp_error( $compatibility ) ) { return $compatibility; }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $scope_type, $scope_id, $priority, $expected_assignment_id, $expected_updated_at ) {
			global $wpdb;
			$locked_template = $this->lock_template( $template_id );
			if ( is_wp_error( $locked_template ) ) { return $locked_template; }
			if ( ! is_array( $locked_template ) || ! empty( $locked_template['archived_at'] ) || empty( $locked_template['current_revision_id'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The template became unavailable before the assignment could be saved.', 'certloom' ) ); }
			$existing = $this->lock_assignment( $scope_type, $scope_id );
			if ( is_wp_error( $existing ) ) { return $existing; }
			$expected_assignment_id = absint( $expected_assignment_id );
			if ( $expected_assignment_id ) {
				if ( ! is_array( $existing ) || absint( $existing['id'] ?? 0 ) !== $expected_assignment_id || '' === (string) $expected_updated_at || ! hash_equals( (string) ( $existing['updated_at'] ?? '' ), (string) $expected_updated_at ) ) {
					return new \WP_Error( 'certloom_template_assignment_stale', __( 'This template assignment changed after you opened it. Reload before saving.', 'certloom' ) );
				}
			} elseif ( is_array( $existing ) ) {
				return new \WP_Error( 'certloom_template_assignment_stale', __( 'This template assignment changed after you opened it. Reload before saving.', 'certloom' ) );
			}
			$now = current_time( 'mysql', true );
			$table = SchemaManager::table( 'template_assignments' );
			$wpdb->last_error = '';
			if ( is_array( $existing ) ) {
				$updated = $wpdb->update( $table, array( 'template_id' => $template_id, 'priority' => $priority, 'active' => 1, 'updated_at' => $now ), array( 'id' => absint( $existing['id'] ) ) );
				return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_assignment_save_failed', __( 'Certloom could not save the template assignment.', 'certloom' ) ) : true;
			}
			$inserted = $wpdb->insert( $table, array( 'template_id' => $template_id, 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'priority' => $priority, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
			if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
				if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) { return new \WP_Error( 'certloom_template_assignment_stale', __( 'This template assignment changed while you were saving it. Reload before trying again.', 'certloom' ) ); }
				return new \WP_Error( 'certloom_template_assignment_save_failed', __( 'Certloom could not save the template assignment.', 'certloom' ) );
			}
			return true;
		} );
	}

	/** @return true|\WP_Error */
	public function clear_assignment( $scope_type, $scope_id = 0, $expected_assignment_id = 0, $expected_updated_at = '' ) {
		$scope = ( new TemplateAssignmentPolicy() )->normalize_scope( $scope_type, $scope_id );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$scope_type = $scope['scope_type']; $scope_id = $scope['scope_id'];
		$exists = $this->scope_exists( $scope_type, $scope_id );
		if ( is_wp_error( $exists ) ) { return $exists; }
		if ( ! $exists ) { return new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The template assignment target no longer exists.', 'certloom' ) ); }
		if ( 'batch' === $scope_type ) {
			$fallback = $this->validate_batch_inherited_fallback_field_contract( $scope_id );
			if ( is_wp_error( $fallback ) ) { return $fallback; }
		}
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $scope_type, $scope_id, $expected_assignment_id, $expected_updated_at ) {
			global $wpdb;
			$existing = $this->lock_assignment( $scope_type, $scope_id );
			if ( is_wp_error( $existing ) ) { return $existing; }
			$expected_assignment_id = absint( $expected_assignment_id );
			if ( ! is_array( $existing ) ) {
				return $expected_assignment_id ? new \WP_Error( 'certloom_template_assignment_stale', __( 'This template assignment changed after you opened it. Reload before saving.', 'certloom' ) ) : true;
			}
			if ( $expected_assignment_id !== absint( $existing['id'] ?? 0 ) || '' === (string) $expected_updated_at || ! hash_equals( (string) ( $existing['updated_at'] ?? '' ), (string) $expected_updated_at ) ) {
				return new \WP_Error( 'certloom_template_assignment_stale', __( 'This template assignment changed after you opened it. Reload before saving.', 'certloom' ) );
			}
			if ( empty( $existing['active'] ) ) { return true; }
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'template_assignments' ), array( 'active' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $existing['id'] ) ) );
			return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_assignment_clear_failed', __( 'Certloom could not clear the direct template assignment.', 'certloom' ) ) : true;
		} );
	}

	/**
	 * Before a Batch switches from its direct design to inheritance, verify every
	 * published Program-level design that may become effective for that Batch.
	 * Draft/unconfigured parent scopes remain allowed during staged setup, while
	 * published incompatible fallbacks are rejected before they can affect
	 * issuance.
	 *
	 * @return true|\WP_Error
	 */
	private function validate_batch_inherited_fallback_field_contract( $batch_id ) {
		$batch = ( new BatchRepository() )->find( $batch_id );
		if ( is_wp_error( $batch ) ) { return $batch; }
		if ( ! is_array( $batch ) ) { return new \WP_Error( 'certloom_template_assignment_batch_missing', __( 'The Batch for this Template assignment no longer exists.', 'certloom' ) ); }
		$program = ( new ProgramRepository() )->find( absint( $batch['program_id'] ?? 0 ) );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_template_assignment_program_missing', __( 'The Batch Program for this Template assignment no longer exists.', 'certloom' ) ); }

		$catalog = class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		$program_contract = is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : array();
		$batch_contract = is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : array();
		$compatibility = new TemplateFieldCompatibility();

		// Locale variants are Program-level and therefore become eligible again
		// as soon as the Batch stops overriding its design.
		foreach ( (array) ( $program['profile']['template_variants'] ?? array() ) as $locale => $machine_key ) {
			$machine_key = sanitize_key( (string) $machine_key );
			if ( '' === $machine_key ) { continue; }
			$template = $this->find_published_by_machine_key( $machine_key );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || empty( $template['published_revision']['layout'] ) ) {
				return new \WP_Error(
					'certloom_template_batch_inherit_variant_unavailable',
					sprintf(
						/* translators: %s: credential locale code. */
						__( 'This Batch cannot inherit yet because the Program language design for %s is no longer published. Fix or remove that Program language variant first.', 'certloom' ),
						(string) $locale
					)
				);
			}
			$valid = $compatibility->validate( (array) $template['published_revision']['layout'], $program_contract, $batch_contract, $catalog );
			if ( is_wp_error( $valid ) ) {
				return new \WP_Error(
					'certloom_template_batch_inherit_variant_incompatible',
					sprintf(
						/* translators: 1: credential locale code, 2: field compatibility error. */
						__( 'This Batch cannot inherit the Program language design for %1$s: %2$s Keep the Batch-specific design or adjust the field/design configuration.', 'certloom' ),
						(string) $locale,
						$valid->get_error_message()
					),
					array( 'locale' => (string) $locale, 'reason' => $valid->get_error_code() )
				);
			}
		}

		$resolved = $this->resolve_published_assignment_details( array(
			'issuer_id' => absint( $program['issuer_id'] ?? 0 ),
			'program_id' => absint( $program['id'] ?? 0 ),
			'batch_id' => 0,
		) );
		if ( is_wp_error( $resolved ) ) {
			// Staged configuration may temporarily have a draft parent design. The
			// issuance path still fails closed until it is published.
			return 'certloom_template_not_published' === $resolved->get_error_code() ? true : $resolved;
		}
		if ( ! is_array( $resolved ) || empty( $resolved['published_layout'] ) ) { return true; }
		$valid = $compatibility->validate( (array) $resolved['published_layout'], $program_contract, $batch_contract, $catalog );
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error(
				'certloom_template_batch_inherit_incompatible',
				sprintf(
					/* translators: %s: field compatibility error. */
					__( 'This Batch cannot switch to inherited design because the Program → Issuer → Global fallback is incompatible: %s', 'certloom' ),
					$valid->get_error_message()
				),
				array( 'reason' => $valid->get_error_code() )
			);
		}
		return true;
	}


	/** Bounded active assignment inventory for administration and usage summaries. @return array<int,array<string,mixed>>|\WP_Error */
	public function list_assignments( $limit = 5000 ) {
		global $wpdb;
		$limit = max( 1, min( 5000, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT a.id,a.template_id,a.scope_type,a.scope_id,a.priority,a.active,a.updated_at,t.name AS template_name,t.archived_at AS template_archived_at FROM %i a LEFT JOIN %i t ON t.id=a.template_id WHERE a.active=1 ORDER BY a.scope_type ASC,a.scope_id ASC LIMIT %d',
			SchemaManager::table( 'template_assignments' ), SchemaManager::table( 'templates' ), $limit
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_template_assignment_list_failed', __( 'Certloom could not load certificate design rules.', 'certloom' ) ); }
		foreach ( $rows as &$row ) { $row['id']=absint($row['id']??0); $row['template_id']=absint($row['template_id']??0); $row['scope_id']=absint($row['scope_id']??0); $row['priority']=absint($row['priority']??0); $row['active']=!empty($row['active']); $row['template_archived']=!empty($row['template_archived_at']); }
		unset( $row );
		return $rows;
	}

	/** @return int|\WP_Error */
	public function usage_count( $template_id ) {
		global $wpdb; $wpdb->last_error='';
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE template_id=%d AND active=1', SchemaManager::table( 'template_assignments' ), absint( $template_id ) ) );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_usage_count_failed', __( 'Certloom could not inspect certificate design usage.', 'certloom' ) ) : $count;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function get_assignment( $scope_type, $scope_id = 0 ) {
		$scope = ( new TemplateAssignmentPolicy() )->normalize_scope( $scope_type, $scope_id );
		if ( is_wp_error( $scope ) ) { return $scope; }
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT a.*, t.name AS template_name, t.machine_key, t.archived_at AS template_archived_at, t.current_revision_id, r.revision_number AS current_revision_number, r.source_type AS current_revision_source, r.layout_sha256 FROM %i a LEFT JOIN %i t ON t.id=a.template_id LEFT JOIN %i r ON r.id=t.current_revision_id WHERE a.scope_type=%s AND a.scope_id=%d LIMIT 1',
			SchemaManager::table( 'template_assignments' ), SchemaManager::table( 'templates' ), SchemaManager::table( 'template_revisions' ), $scope['scope_type'], $scope['scope_id']
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not inspect the template assignment.', 'certloom' ) ); }
		if ( ! is_array( $row ) ) { return null; }
		if ( empty( $row['template_name'] ) || empty( $row['current_revision_id'] ) || empty( $row['current_revision_number'] ) ) { return new \WP_Error( 'certloom_template_assignment_corrupt', __( 'A stored template assignment points to an invalid Template or Revision.', 'certloom' ) ); }
		$row['id'] = absint( $row['id'] ?? 0 ); $row['template_id'] = absint( $row['template_id'] ?? 0 ); $row['scope_id'] = absint( $row['scope_id'] ?? 0 ); $row['priority'] = absint( $row['priority'] ?? 0 ); $row['active'] = ! empty( $row['active'] ); $row['current_revision_id'] = absint( $row['current_revision_id'] ?? 0 ); $row['current_revision_number'] = absint( $row['current_revision_number'] ?? 0 ); $row['template_archived'] = ! empty( $row['template_archived_at'] );
		return $row;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_candidates( $search = '', $page = 1, $per_page = 12, $kind = 'all' ) {
		global $wpdb;
		$page = max( 1, absint( $page ) ); $per_page = max( 1, min( 250, absint( $per_page ) ) ); $offset = ( $page - 1 ) * $per_page;
		$kind = in_array( $kind, array( 'all', 'factory', 'custom' ), true ) ? $kind : 'all';
		$where = array( 't.archived_at IS NULL' ); $args = array(); $search = trim( (string) $search );
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(t.name LIKE %s OR t.machine_key LIKE %s OR t.description LIKE %s)'; $args = array_merge( $args, array( $like, $like, $like ) ); }
		if ( 'factory' === $kind ) { $where[] = "t.machine_key LIKE 'certloom-preset-%'"; }
		if ( 'custom' === $kind ) { $where[] = "t.machine_key NOT LIKE 'certloom-preset-%'"; }
		$where_sql = ' WHERE ' . implode( ' AND ', $where ); $templates = SchemaManager::table( 'templates' ); $revisions = SchemaManager::table( 'template_revisions' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t' . $where_sql, array_merge( array( $templates ), $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_list_failed', __( 'Certloom could not load assignable templates.', 'certloom' ) ); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.id,t.machine_key,t.name,t.description,t.current_revision_id,t.published_revision_id,r.revision_number,r.source_type,r.layout_json,r.layout_sha256 FROM %i t INNER JOIN %i r ON r.id=t.current_revision_id' . $where_sql . ' ORDER BY t.name ASC,t.id ASC LIMIT %d OFFSET %d', array_merge( array( $templates, $revisions ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_template_list_failed', __( 'Certloom could not load assignable templates.', 'certloom' ) ); }
		foreach ( $rows as &$row ) {
			$decoded = json_decode( (string) ( $row['layout_json'] ?? '' ), true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'An assignable template contains invalid canonical layout data.', 'certloom' ) ); }
			try { $layout = $this->layouts->normalize( $decoded ); $hash_matches = $this->json->matches_hash( $layout, $row['layout_sha256'] ?? '' ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'An assignable template does not satisfy Certloom’s canonical layout contract.', 'certloom' ) ); }
			if ( ! $hash_matches ) { return new \WP_Error( 'certloom_template_revision_hash_mismatch', __( 'An assignable template failed its layout integrity check.', 'certloom' ) ); }
			$row['id'] = absint( $row['id'] ?? 0 ); $row['current_revision_id'] = absint( $row['current_revision_id'] ?? 0 ); $row['published_revision_id'] = absint( $row['published_revision_id'] ?? 0 ); $row['revision_number'] = absint( $row['revision_number'] ?? 0 ); $row['lifecycle_state'] = TemplateLifecycle::state( $row ); $row['factory'] = 0 === strpos( (string) $row['machine_key'], 'certloom-preset-' ); $row['page_size'] = (string) ( $layout['page']['size'] ?? '' ); $row['orientation'] = (string) ( $layout['page']['orientation'] ?? '' ); $row['element_count'] = count( $layout['elements'] ?? array() ); $row['layout'] = $layout; unset( $row['layout_json'] );
		}
		unset( $row );
		return array( 'items' => $rows, 'total' => $total );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_assignment_details( array $context ) {
		$policy = new TemplateAssignmentPolicy();
		foreach ( $policy->candidates( $context ) as $candidate ) {
			$assignment = $this->get_assignment( $candidate['scope_type'], $candidate['scope_id'] );
			if ( is_wp_error( $assignment ) ) { return $assignment; }
			if ( ! is_array( $assignment ) || empty( $assignment['active'] ) ) { continue; }
			if ( ! empty( $assignment['template_archived'] ) ) { return new \WP_Error( 'certloom_template_assignment_corrupt', __( 'An active template assignment points to an archived Template.', 'certloom' ) ); }
			$template = $this->find( absint( $assignment['template_id'] ) );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['current_revision'] ) ) { return new \WP_Error( 'certloom_template_assignment_corrupt', __( 'An active template assignment points to an unavailable Template.', 'certloom' ) ); }
			$layout = $template['current_revision']['layout'];
			$assignment['fallback_depth'] = absint( $candidate['depth'] );
			$assignment['page_size'] = (string) ( $layout['page']['size'] ?? '' );
			$assignment['orientation'] = (string) ( $layout['page']['orientation'] ?? '' );
			$assignment['element_count'] = count( $layout['elements'] ?? array() );
			return $assignment;
		}
		return null;
	}

	/** @return int|null|\WP_Error */
	public function resolve_assignment( array $context ) {
		$resolved = $this->resolve_assignment_details( $context );
		return is_wp_error( $resolved ) || ! is_array( $resolved ) ? $resolved : absint( $resolved['template_id'] ?? 0 );
	}


	/** Published-only assignment resolution for future issuance/artifact authority. @return array<string,mixed>|null|\WP_Error */
	public function resolve_published_assignment_details( array $context ) {
		return $this->resolve_published_assignment_details_internal( $context, null );
	}

	/**
	 * Resolve the hierarchy while pretending one direct assignment is absent.
	 * Used only for configuration preflight before an administrator switches a
	 * scope back to inheritance; it never changes stored assignment state.
	 *
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function resolve_published_assignment_details_excluding( array $context, $scope_type, $scope_id = 0 ) {
		$scope = ( new TemplateAssignmentPolicy() )->normalize_scope( $scope_type, $scope_id );
		if ( is_wp_error( $scope ) ) { return $scope; }
		return $this->resolve_published_assignment_details_internal( $context, $scope );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function resolve_published_assignment_details_internal( array $context, ?array $excluded_scope ) {
		$policy = new TemplateAssignmentPolicy();
		foreach ( $policy->candidates( $context ) as $candidate ) {
			if ( is_array( $excluded_scope )
				&& (string) $candidate['scope_type'] === (string) $excluded_scope['scope_type']
				&& absint( $candidate['scope_id'] ) === absint( $excluded_scope['scope_id'] ) ) {
				continue;
			}
			$assignment = $this->get_publication_assignment_candidate( $candidate['scope_type'], $candidate['scope_id'] );
			if ( is_wp_error( $assignment ) ) { return $assignment; }
			if ( ! is_array( $assignment ) || empty( $assignment['active'] ) ) { continue; }
			if ( ! empty( $assignment['template_archived'] ) ) { return new \WP_Error( 'certloom_template_assignment_corrupt', __( 'An active template assignment points to an archived Template.', 'certloom' ) ); }
			$template = $this->find_published( absint( $assignment['template_id'] ) );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['published_revision'] ) ) { return new \WP_Error( 'certloom_template_assignment_corrupt', __( 'An active template assignment does not resolve to an available published Template.', 'certloom' ) ); }
			$revision = $template['published_revision'];
			$layout = $revision['layout'];
			$assignment['fallback_depth'] = absint( $candidate['depth'] );
			$assignment['published_revision_id'] = absint( $revision['id'] ?? 0 );
			$assignment['published_revision_number'] = absint( $revision['revision_number'] ?? 0 );
			$assignment['published_layout_sha256'] = (string) ( $revision['layout_sha256'] ?? '' );
			$assignment['published_layout'] = $layout;
			$assignment['page_size'] = (string) ( $layout['page']['size'] ?? '' );
			$assignment['orientation'] = (string) ( $layout['page']['orientation'] ?? '' );
			$assignment['element_count'] = count( $layout['elements'] ?? array() );
			return $assignment;
		}
		return null;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_identity( array $data, $allow_partial = false ) {
		$out = array();
		foreach ( array( 'machine_key', 'name', 'description' ) as $field ) {
			if ( $allow_partial && ! array_key_exists( $field, $data ) ) { continue; }
			$value = isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
			if ( 'machine_key' === $field ) { $clean = sanitize_key( $value ); if ( '' === $clean || $clean !== $value || strlen( $clean ) > 191 ) { return new \WP_Error( 'certloom_template_key_invalid', __( 'Template machine keys must use a stable lowercase WordPress key.', 'certloom' ) ); } $out[ $field ] = $clean; }
			elseif ( 'name' === $field ) { $value = trim( $value ); if ( '' === $value || $this->length( $value ) > 255 ) { return new \WP_Error( 'certloom_template_name_invalid', __( 'Enter a template name up to 255 characters.', 'certloom' ) ); } $out[ $field ] = $value; }
			else { if ( $this->length( $value ) > 20000 ) { return new \WP_Error( 'certloom_template_description_invalid', __( 'The template description is too large.', 'certloom' ) ); } $out[ $field ] = $value; }
		}
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_layout( $layout ) {
		if ( ! is_array( $layout ) ) { return new \WP_Error( 'certloom_template_layout_required', __( 'A canonical Certloom layout is required.', 'certloom' ) ); }
		try { return $this->layouts->normalize( $layout ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_template_layout_invalid', __( 'The template layout does not satisfy Certloom’s canonical layout contract.', 'certloom' ) ); }
	}

	/** @return string|\WP_Error */
	private function normalize_source( $source_type ) {
		$source_type = sanitize_key( (string) $source_type );
		return in_array( $source_type, array( self::SOURCE_MANUAL, self::SOURCE_IMPORT, self::SOURCE_PRESET ), true ) ? $source_type : new \WP_Error( 'certloom_template_source_invalid', __( 'The template revision source is invalid.', 'certloom' ) );
	}

	/** @return int|\WP_Error */
	private function insert_revision( $template_id, $revision_number, $parent_revision_id, array $layout, $actor_user_id, $source_type, $now ) {
		global $wpdb;
		try { $layout_json = $this->json->encode( $layout ); $hash = $this->json->hash( $layout ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_template_layout_encode_failed', __( 'Certloom could not encode the template revision safely.', 'certloom' ) ); }
		if ( strlen( $layout_json ) > TemplatePackage::MAX_BYTES ) { return new \WP_Error( 'certloom_template_layout_too_large', __( 'The template layout exceeds Certloom’s safe storage limit.', 'certloom' ) ); }
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'template_revisions' ), array(
			'template_id' => absint( $template_id ), 'revision_number' => absint( $revision_number ), 'parent_revision_id' => $parent_revision_id ? absint( $parent_revision_id ) : null,
			'layout_schema' => LayoutSchema::NAME, 'layout_schema_version' => absint( $layout['_meta']['schema_version'] ?? 0 ), 'layout_json' => $layout_json, 'layout_sha256' => $hash,
			'source_type' => $source_type, 'created_by_user_id' => absint( $actor_user_id ) ?: null, 'created_at' => (string) $now,
		) );
		return false === $inserted || '' !== (string) $wpdb->last_error || absint( $wpdb->insert_id ) < 1 ? new \WP_Error( 'certloom_template_revision_create_failed', __( 'Certloom could not create the immutable template revision.', 'certloom' ) ) : absint( $wpdb->insert_id );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function lock_template( $template_id ) {
		global $wpdb; $wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', SchemaManager::table( 'templates' ), absint( $template_id ) ), ARRAY_A );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_lock_failed', __( 'Certloom could not lock the template for a safe update.', 'certloom' ) ) : ( is_array( $row ) ? $row : null );
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function find_revision_row( $revision_id, $for_update ) {
		global $wpdb; $wpdb->last_error = '';
		$sql = 'SELECT * FROM %i WHERE id=%d' . ( $for_update ? ' FOR UPDATE' : '' );
		$row = $wpdb->get_row( $wpdb->prepare( $sql, SchemaManager::table( 'template_revisions' ), absint( $revision_id ) ), ARRAY_A );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_revision_read_failed', __( 'Certloom could not read the template revision.', 'certloom' ) ) : ( is_array( $row ) ? $row : null );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function verify_revision_row( array $row ) {
		$decoded = json_decode( (string) ( $row['layout_json'] ?? '' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A stored template revision contains invalid layout data.', 'certloom' ) ); }
		try { $layout = $this->layouts->normalize( $decoded ); $hash_matches = $this->json->matches_hash( $layout, $row['layout_sha256'] ?? '' ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A stored template revision does not satisfy Certloom’s canonical layout contract.', 'certloom' ) ); }
		if ( LayoutSchema::NAME !== (string) ( $row['layout_schema'] ?? '' ) || absint( $row['layout_schema_version'] ?? 0 ) !== (int) $layout['_meta']['schema_version'] ) { return new \WP_Error( 'certloom_template_revision_schema_mismatch', __( 'A stored Template revision has schema metadata that does not match its canonical layout document.', 'certloom' ) ); }
		if ( ! $hash_matches ) { return new \WP_Error( 'certloom_template_revision_hash_mismatch', __( 'A stored template revision failed its integrity check.', 'certloom' ) ); }
		$row['layout'] = $layout;
		return $row;
	}

	/** @return bool|\WP_Error */
	private function has_preset_revision( $template_id ) {
		global $wpdb; $wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE template_id=%d AND source_type=%s LIMIT 1', SchemaManager::table( 'template_revisions' ), absint( $template_id ), self::SOURCE_PRESET ) );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_revision_read_failed', __( 'Certloom could not inspect factory-template revision provenance.', 'certloom' ) ) : (bool) $id;
	}

	/** Published resolver reads assignment identity without consulting mutable draft revision state. @return array<string,mixed>|null|\WP_Error */
	private function get_publication_assignment_candidate( $scope_type, $scope_id ) {
		$scope = ( new TemplateAssignmentPolicy() )->normalize_scope( $scope_type, $scope_id );
		if ( is_wp_error( $scope ) ) { return $scope; }
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT a.*,t.name AS template_name,t.machine_key,t.archived_at AS template_archived_at,t.published_revision_id FROM %i a LEFT JOIN %i t ON t.id=a.template_id WHERE a.scope_type=%s AND a.scope_id=%d LIMIT 1',
			SchemaManager::table( 'template_assignments' ), SchemaManager::table( 'templates' ), $scope['scope_type'], $scope['scope_id']
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not inspect the template assignment.', 'certloom' ) ); }
		if ( ! is_array( $row ) ) { return null; }
		if ( empty( $row['template_name'] ) ) { return new \WP_Error( 'certloom_template_assignment_corrupt', __( 'A stored template assignment points to an invalid Template.', 'certloom' ) ); }
		$row['id'] = absint( $row['id'] ?? 0 ); $row['template_id'] = absint( $row['template_id'] ?? 0 ); $row['scope_id'] = absint( $row['scope_id'] ?? 0 ); $row['priority'] = absint( $row['priority'] ?? 0 ); $row['active'] = ! empty( $row['active'] ); $row['published_revision_id'] = absint( $row['published_revision_id'] ?? 0 ); $row['template_archived'] = ! empty( $row['template_archived_at'] );
		return $row;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function lock_assignment( $scope_type, $scope_id ) {
		global $wpdb; $wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE scope_type=%s AND scope_id=%d FOR UPDATE', SchemaManager::table( 'template_assignments' ), (string) $scope_type, absint( $scope_id ) ), ARRAY_A );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_assignment_lock_failed', __( 'Certloom could not lock the template assignment for a safe update.', 'certloom' ) ) : ( is_array( $row ) ? $row : null );
	}

	/**
	 * FLD-02 compatibility preflight for direct Program/Batch assignments.
	 * Templates consume the Program/Batch contract; they never create or alter it.
	 *
	 * @param string              $scope_type
	 * @param int                 $scope_id
	 * @param array<string,mixed> $layout
	 * @return true|\WP_Error
	 */
	private function validate_assignment_field_contract( $scope_type, $scope_id, array $layout ) {
		$scope_type = (string) $scope_type;
		$catalog = class_exists( '\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		if ( in_array( $scope_type, array( 'global', 'issuer' ), true ) ) {
			// Broad-scope designs cannot promise Program/Batch credential fields because
			// there is no single effective field contract at Global or Issuer scope.
			// Use universal merge tokens here; scoped fields belong on Program/Batch
			// assignments where Certloom can prove their availability.
			$valid = ( new TemplateFieldCompatibility() )->validate( $layout, ScopedFieldContract::empty_program( true ), null, $catalog );
			if ( ! is_wp_error( $valid ) ) { return true; }
			return new \WP_Error(
				'certloom_template_broad_scope_field_incompatible',
				__( 'Global and Issuer certificate designs can use universal credential tokens, but Program/Batch Credential fields must be assigned at Program or Batch scope where Certloom can prove the field contract.', 'certloom' ),
				$valid->get_error_data()
			);
		}
		if ( ! in_array( $scope_type, array( 'program', 'batch' ), true ) ) {
			return new \WP_Error( 'certloom_template_assignment_scope_invalid', __( 'Choose a valid Template assignment scope.', 'certloom' ) );
		}
		$program_contract = array();
		$batch_contract = null;
		if ( 'program' === $scope_type ) {
			$program = ( new ProgramRepository() )->find( $scope_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_template_assignment_program_missing', __( 'The Program for this Template assignment no longer exists.', 'certloom' ) ); }
			$program_contract = (array) ( $program['field_contract'] ?? array() );
			$valid = ( new TemplateFieldCompatibility() )->validate( $layout, $program_contract, null, $catalog );
			if ( is_wp_error( $valid ) ) { return $valid; }

			// A Program design is also the effective design for every Batch that
			// inherits from the Program. Validate those consumers now so changing a
			// Program assignment cannot silently make an existing Batch unissuable.
			$batches = new BatchRepository();
			$after_id = 0;
			do {
				$rows = $batches->list_for_program_after( $scope_id, $after_id, 100 );
				if ( is_wp_error( $rows ) ) { return $rows; }
				foreach ( $rows as $batch ) {
					$batch_id = absint( $batch['id'] ?? 0 );
					if ( ! $batch_id ) { continue; }
					$after_id = max( $after_id, $batch_id );
					$direct = $this->get_assignment( 'batch', $batch_id );
					if ( is_wp_error( $direct ) ) { return $direct; }
					if ( is_array( $direct ) && ! empty( $direct['active'] ) ) { continue; }
					$batch_contract = is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : array();
					$valid = ( new TemplateFieldCompatibility() )->validate( $layout, $program_contract, $batch_contract, $catalog );
					if ( is_wp_error( $valid ) ) {
						return new \WP_Error(
							'certloom_template_program_batch_incompatible',
							sprintf(
								/* translators: 1: Batch name, 2: field compatibility error. */
								__( 'This Program design would break the inheriting Batch “%1$s”: %2$s Assign a compatible Batch-specific design first, or adjust the Batch field rules.', 'certloom' ),
								(string) ( $batch['name'] ?? '#' . $batch_id ),
								$valid->get_error_message()
							),
							array( 'batch_id' => $batch_id, 'reason' => $valid->get_error_code() )
						);
					}
				}
			} while ( count( $rows ) === 100 );
			return true;
		} else {
			$batch = ( new BatchRepository() )->find( $scope_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) ) { return new \WP_Error( 'certloom_template_assignment_batch_missing', __( 'The Batch for this Template assignment no longer exists.', 'certloom' ) ); }
			$program = ( new ProgramRepository() )->find( absint( $batch['program_id'] ?? 0 ) );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_template_assignment_program_missing', __( 'The Batch Program for this Template assignment no longer exists.', 'certloom' ) ); }
			$program_contract = (array) ( $program['field_contract'] ?? array() );
			$batch_contract = (array) ( $batch['field_contract'] ?? array() );
		}
		return ( new TemplateFieldCompatibility() )->validate( $layout, $program_contract, $batch_contract, $catalog );
	}

	/**
	 * Revalidates all directly assigned Program/Batch consumers before a draft
	 * becomes published truth. This closes the edit-after-assignment loophole.
	 *
	 * @param int                 $template_id
	 * @param array<string,mixed> $layout
	 * @return true|\WP_Error
	 */
	private function validate_active_assignment_field_contracts( $template_id, array $layout ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT scope_type, scope_id FROM %i WHERE template_id=%d AND active=1 ORDER BY id ASC',
			SchemaManager::table( 'template_assignments' ), absint( $template_id )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not verify Template field compatibility before publishing.', 'certloom' ) );
		}
		foreach ( $rows as $row ) {
			$valid = $this->validate_assignment_field_contract( (string) ( $row['scope_type'] ?? '' ), absint( $row['scope_id'] ?? 0 ), $layout );
			if ( is_wp_error( $valid ) ) { return $valid; }
		}
		return true;
	}

	/** @return bool|\WP_Error */
	private function scope_exists( $scope_type, $scope_id ) {
		if ( 'global' === $scope_type ) { return true; }
		$map = array( 'issuer' => 'issuers', 'program' => 'programs', 'batch' => 'batches' );
		global $wpdb; $wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%d', SchemaManager::table( $map[ $scope_type ] ), absint( $scope_id ) ) );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_assignment_scope_read_failed', __( 'Certloom could not verify the template assignment target.', 'certloom' ) ) : (bool) $id;
	}

	private function length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value ); }
}
