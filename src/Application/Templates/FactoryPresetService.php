<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and safely advances Certloom factory template presets.
 *
 * Presets are drafts until the production renderer gate is accepted. A user
 * edit changes the current revision source to `manual`, which permanently
 * prevents automatic preset advancement until an explicit reset is requested.
 */
final class FactoryPresetService {
	/** @var TemplateRepository */
	private $templates;
	/** @var PresetCatalog */
	private $catalog;
	/** @var CuratedPresetCatalog */
	private $curated_catalog;

	public function __construct( ?TemplateRepository $templates = null, ?PresetCatalog $catalog = null, ?CuratedPresetCatalog $curated_catalog = null ) {
		$this->templates = $templates ?: new TemplateRepository();
		$this->catalog = $catalog ?: new PresetCatalog();
		$this->curated_catalog = $curated_catalog ?: new CuratedPresetCatalog();
	}

	/**
	 * Install all missing presets and safely advance untouched factory drafts.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	/**
	 * Keep the built-in library present without making administrators run a
	 * manual repair action. The marker avoids database work on every Design
	 * visit, while the count check repairs a partially seeded catalog.
	 * Customer-modified preset revisions remain protected by sync_all().
	 *
	 * @return array<string,mixed>|true|\WP_Error
	 */
	public function ensure_current( $actor_user_id = 0 ) {
		$definitions = array_merge( $this->catalog->all(), $this->curated_catalog->all() );
		$expected = count( $definitions );
		$signature = hash( 'sha256', wp_json_encode( array(
			'preset_version' => PresetCatalog::PRESET_VERSION,
			'curated_version' => CuratedPresetCatalog::PRESET_VERSION,
			'count' => $expected,
		) ) );
		$state = get_option( 'certloom_factory_design_catalog_v1', array() );
		$inventory = $this->templates->list_candidates( '', 1, 1, 'factory' );
		if ( ! is_wp_error( $inventory )
			&& is_array( $state )
			&& hash_equals( $signature, (string) ( $state['signature'] ?? '' ) )
			&& absint( $inventory['total'] ?? 0 ) >= $expected ) {
			return true;
		}

		$result = $this->sync_all( $actor_user_id );
		if ( is_wp_error( $result ) ) { return $result; }
		update_option( 'certloom_factory_design_catalog_v1', array(
			'signature' => $signature,
			'count' => $expected,
			'checked_at' => current_time( 'mysql', true ),
		), false );
		return $result;
	}

	public function sync_all( $actor_user_id = 0 ) {
		$result = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'customized' => 0, 'items' => array() );
		$definitions = array_merge( $this->catalog->all(), $this->curated_catalog->all() );
		foreach ( $definitions as $definition ) {
			$status = $this->sync_one( $definition, $actor_user_id );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
			$result[ $status['status'] ]++;
			$result['items'][] = $status;
		}
		return $result;
	}

	/**
	 * Return the premium starter set used by first-run setup. The stable order is
	 * deliberately sourced from the curated catalog, not from database sorting.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function starter_definitions( $limit = 10 ) {
		$limit = max( 1, min( 10, absint( $limit ) ) );
		return array_slice( $this->curated_catalog->all(), 0, $limit );
	}

	/** @return array<int,string> */
	public function starter_machine_keys( $limit = 10 ) {
		return array_values( array_map( static function ( $definition ) {
			return (string) ( $definition['machine_key'] ?? '' );
		}, $this->starter_definitions( $limit ) ) );
	}

	/**
	 * Publish the initial premium starter set so first-use Program and issuance
	 * selectors are useful immediately. Publication only controls design
	 * availability; production PDF/PNG/JPEG artifact authority remains a
	 * separate fail-closed renderer concern. Existing customer edits and later
	 * unpublished changes are never auto-published.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function ensure_starter_publications( $actor_user_id = 0, $limit = 10 ) {
		$seed = $this->ensure_current( $actor_user_id );
		if ( is_wp_error( $seed ) ) { return $seed; }

		$result = array( 'published' => 0, 'already_published' => 0, 'skipped_customized' => 0, 'deferred' => false, 'reason' => 'starter_publication_ready' );
		foreach ( $this->starter_definitions( $limit ) as $definition ) {
			$template = $this->templates->find_by_machine_key( (string) $definition['machine_key'] );
			if ( is_wp_error( $template ) ) { return $template; }
			if ( ! is_array( $template ) || ! is_array( $template['current_revision'] ?? null ) ) { continue; }
			$current = $template['current_revision'];
			$current_id = absint( $template['current_revision_id'] ?? 0 );
			$published_id = absint( $template['published_revision_id'] ?? 0 );
			if ( $published_id ) {
				$result['already_published']++;
				continue;
			}
			if ( TemplateRepository::SOURCE_PRESET !== (string) ( $current['source_type'] ?? '' ) || ! ( new CanonicalJson() )->matches_hash( $definition['layout'], $current['layout_sha256'] ?? '' ) ) {
				$result['skipped_customized']++;
				continue;
			}
			$published = $this->templates->publish( absint( $template['id'] ?? 0 ), $current_id, (string) ( $current['layout_sha256'] ?? '' ) );
			if ( is_wp_error( $published ) ) { return $published; }
			$result['published']++;
		}
		return $result;
	}

	/**
	 * Explicitly restore one factory template draft to the current built-in
	 * preset. Historical revisions remain immutable and are never deleted.
	 *
	 * @return int|\WP_Error Current revision ID.
	 */
	public function reset_to_factory( $template_id, $actor_user_id = 0, $expected_revision_id = 0 ) {
		$template = $this->templates->find( absint( $template_id ) );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) ) { return new \WP_Error( 'certloom_preset_template_missing', __( 'The factory template no longer exists.', 'certloom' ) ); }
		$preset_key = $this->preset_key_from_machine_key( (string) ( $template['machine_key'] ?? '' ) );
		if ( '' === $preset_key ) { return new \WP_Error( 'certloom_preset_identity_invalid', __( 'This template is not a Certloom factory preset.', 'certloom' ) ); }
		$definition = $this->definition_for_key( $preset_key );
		if ( ! is_array( $definition ) ) { return new \WP_Error( 'certloom_preset_unknown', __( 'This factory preset is not available in the installed Certloom version.', 'certloom' ) ); }
		$current_id = absint( $template['current_revision_id'] ?? 0 );
		if ( $expected_revision_id && $current_id !== absint( $expected_revision_id ) ) {
			return new \WP_Error( 'certloom_preset_stale_reset', __( 'This template changed after you opened it. Reload before restoring the factory design.', 'certloom' ) );
		}
		$revision_id = $this->templates->save_revision( absint( $template['id'] ), $definition['layout'], $actor_user_id, $current_id, TemplateRepository::SOURCE_PRESET );
		return $revision_id;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function sync_one( array $definition, $actor_user_id ) {
		$template = $this->templates->find_by_machine_key( $definition['machine_key'] );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( null === $template ) {
			$id = $this->templates->create(
				array(
					'machine_key' => $definition['machine_key'],
					'name' => $definition['name'],
					'description' => $definition['description'],
					'layout' => $definition['layout'],
				),
				$actor_user_id,
				TemplateRepository::SOURCE_PRESET
			);
			if ( is_wp_error( $id ) ) { return $id; }
			$created = $this->templates->find( absint( $id ) );
			if ( is_wp_error( $created ) ) { return $created; }
			if ( ! is_array( $created ) || absint( $created['current_revision_id'] ?? 0 ) < 1 ) { return new \WP_Error( 'certloom_preset_create_verify_failed', __( 'Certloom created a factory template but could not verify its immutable revision pointer.', 'certloom' ) ); }
			return array( 'preset_key' => $definition['preset_key'], 'template_id' => absint( $id ), 'status' => 'created', 'revision_id' => absint( $created['current_revision_id'] ), 'preset_version' => absint( $definition['preset_version'] ) );
		}
		if ( ! is_array( $template ) || empty( $template['current_revision'] ) || ! is_array( $template['current_revision'] ) ) {
			return new \WP_Error( 'certloom_preset_template_corrupt', __( 'A factory template is missing its current immutable revision.', 'certloom' ) );
		}
		if ( ! empty( $template['archived_at'] ) ) {
			return array( 'preset_key' => $definition['preset_key'], 'template_id' => absint( $template['id'] ), 'status' => 'customized', 'revision_id' => absint( $template['current_revision_id'] ), 'preset_version' => absint( $definition['preset_version'] ), 'reason' => 'archived' );
		}

		$current = $template['current_revision'];
		$current_hash = strtolower( (string) ( $current['layout_sha256'] ?? '' ) );
		if ( ( new CanonicalJson() )->matches_hash( $definition['layout'], $current_hash ) ) {
			return array( 'preset_key' => $definition['preset_key'], 'template_id' => absint( $template['id'] ), 'status' => 'unchanged', 'revision_id' => absint( $template['current_revision_id'] ), 'preset_version' => absint( $definition['preset_version'] ) );
		}
		if ( TemplateRepository::SOURCE_PRESET !== (string) ( $current['source_type'] ?? '' ) ) {
			return array( 'preset_key' => $definition['preset_key'], 'template_id' => absint( $template['id'] ), 'status' => 'customized', 'revision_id' => absint( $template['current_revision_id'] ), 'preset_version' => absint( $definition['preset_version'] ), 'reason' => 'customer_revision' );
		}

		$matched_version = $this->match_known_factory_version( $definition['preset_key'], $current_hash );
		if ( null === $matched_version ) {
			return new \WP_Error( 'certloom_preset_provenance_mismatch', __( 'A factory template claims preset provenance but its current revision does not match a known Certloom preset version.', 'certloom' ) );
		}
		if ( $matched_version >= absint( $definition['preset_version'] ) ) {
			return new \WP_Error( 'certloom_preset_version_mismatch', __( 'A factory template revision is newer than the installed preset catalog.', 'certloom' ) );
		}
		$revision_id = $this->templates->save_revision( absint( $template['id'] ), $definition['layout'], $actor_user_id, absint( $template['current_revision_id'] ), TemplateRepository::SOURCE_PRESET );
		if ( is_wp_error( $revision_id ) ) { return $revision_id; }
		return array( 'preset_key' => $definition['preset_key'], 'template_id' => absint( $template['id'] ), 'status' => 'updated', 'revision_id' => absint( $revision_id ), 'preset_version' => absint( $definition['preset_version'] ) );
	}

	/** @return int|null */
	private function match_known_factory_version( $preset_key, $layout_hash ) {
		$catalog = 0 === strpos( sanitize_key( (string) $preset_key ), CuratedPresetCatalog::KEY_PREFIX ) ? $this->curated_catalog : $this->catalog;
		$max_version = $catalog instanceof CuratedPresetCatalog ? CuratedPresetCatalog::PRESET_VERSION : PresetCatalog::PRESET_VERSION;
		for ( $version = 1; $version <= $max_version; $version++ ) {
			$definition = $catalog->get( $preset_key, $version );
			if ( is_array( $definition ) && ( new CanonicalJson() )->matches_hash( $definition['layout'], $layout_hash ) ) { return $version; }
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	private function definition_for_key( $preset_key ) {
		$preset_key = sanitize_key( (string) $preset_key );
		if ( 0 === strpos( $preset_key, CuratedPresetCatalog::KEY_PREFIX ) ) { return $this->curated_catalog->get( $preset_key ); }
		return $this->catalog->get( $preset_key );
	}

	private function preset_key_from_machine_key( $machine_key ) {
		$prefix = 'certloom-preset-';
		$machine_key = sanitize_key( (string) $machine_key );
		return 0 === strpos( $machine_key, $prefix ) ? substr( $machine_key, strlen( $prefix ) ) : '';
	}
}
