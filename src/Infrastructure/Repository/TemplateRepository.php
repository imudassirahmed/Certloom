<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplatePackage;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Versioned template persistence. Published authority remains renderer-gated. */
final class TemplateRepository {
	const SOURCE_MANUAL = 'manual';
	const SOURCE_IMPORT = 'import';
	const SOURCE_PRESET = 'preset';

	/** @var LayoutValidator */ private $layouts;
	/** @var CanonicalJson */ private $json;
	/** @var Transaction */ private $transaction;

	public function __construct( LayoutValidator $layouts = null, CanonicalJson $json = null, Transaction $transaction = null ) {
		$this->layouts      = $layouts ?: new LayoutValidator();
		$this->json         = $json ?: new CanonicalJson();
		$this->transaction  = $transaction ?: new Transaction();
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
	public function save_revision( $template_id, $layout, $actor_user_id = 0, $expected_revision_id = 0, $source_type = self::SOURCE_MANUAL ) {
		$template_id = absint( $template_id );
		if ( ! $template_id ) { return new \WP_Error( 'certloom_template_id_required', __( 'A valid template is required.', 'certloom' ) ); }
		$layout = $this->normalize_layout( $layout );
		if ( is_wp_error( $layout ) ) { return $layout; }
		$source_type = $this->normalize_source( $source_type );
		if ( is_wp_error( $source_type ) ) { return $source_type; }
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }
		return $this->transaction->run( function () use ( $template_id, $layout, $actor_user_id, $expected_revision_id, $source_type ) {
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
			$hash = $this->json->hash( $layout );
			if ( is_array( $current ) && hash_equals( (string) $current['layout_sha256'], $hash ) ) { return $current_id; }
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

	/** Publish is intentionally blocked until the production renderer/template-authority gate passes. @return \WP_Error */
	public function publish( $template_id ) {
		return new \WP_Error( 'certloom_template_publish_renderer_gate', __( 'Template publishing remains disabled until Certloom’s production renderer acceptance gate passes.', 'certloom' ), array( 'template_id' => absint( $template_id ) ) );
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
			if ( ! empty( $template['published_revision_id'] ) ) { return new \WP_Error( 'certloom_template_archive_published', __( 'A published template cannot be archived by the draft-only template foundation.', 'certloom' ) ); }
			if ( ! empty( $template['archived_at'] ) ) { return true; }
			$now = current_time( 'mysql', true );
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'templates' ), array( 'archived_at' => $now, 'updated_at' => $now ), array( 'id' => $template_id ) );
			return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_archive_failed', __( 'Certloom could not archive the template.', 'certloom' ) ) : true;
		} );
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
			$published = $this->find_revision_row( absint( $row['published_revision_id'] ), false );
			if ( is_wp_error( $published ) ) { return $published; }
			if ( ! is_array( $published ) || absint( $published['template_id'] ?? 0 ) !== absint( $row['id'] ) ) { return new \WP_Error( 'certloom_template_published_pointer_corrupt', __( 'The template published pointer is invalid.', 'certloom' ) ); }
		}
		return $row;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_revision( $revision_id ) {
		$row = $this->find_revision_row( $revision_id, false );
		if ( is_wp_error( $row ) || ! is_array( $row ) ) { return $row; }
		$decoded = json_decode( (string) $row['layout_json'], true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A stored template revision contains invalid layout data.', 'certloom' ) ); }
		try { $layout = $this->layouts->normalize( $decoded ); $hash = $this->json->hash( $layout ); } catch ( \Throwable $error ) { return new \WP_Error( 'certloom_template_revision_corrupt', __( 'A stored template revision does not satisfy Certloom’s canonical layout contract.', 'certloom' ) ); }
		if ( ! hash_equals( strtolower( (string) $row['layout_sha256'] ), $hash ) ) { return new \WP_Error( 'certloom_template_revision_hash_mismatch', __( 'A stored template revision failed its integrity check.', 'certloom' ) ); }
		$row['layout'] = $layout;
		return $row;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 20, $status = 'active' ) {
		global $wpdb;
		$page = max( 1, absint( $page ) ); $per_page = max( 1, min( 100, absint( $per_page ) ) ); $offset = ( $page - 1 ) * $per_page;
		$status = in_array( $status, array( 'active', 'archived', 'all' ), true ) ? $status : 'active';
		$where = array(); $args = array(); $search = trim( (string) $search );
		if ( 'active' === $status ) { $where[] = 't.archived_at IS NULL'; } elseif ( 'archived' === $status ) { $where[] = 't.archived_at IS NOT NULL'; }
		if ( '' !== $search ) { $like = '%' . $wpdb->esc_like( $search ) . '%'; $where[] = '(t.name LIKE %s OR t.machine_key LIKE %s OR t.description LIKE %s)'; $args = array_merge( $args, array( $like, $like, $like ) ); }
		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$table = SchemaManager::table( 'templates' ); $revisions = SchemaManager::table( 'template_revisions' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t' . $where_sql, array_merge( array( $table ), $args ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_list_failed', __( 'Certloom could not load templates.', 'certloom' ) ); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, r.revision_number AS current_revision_number, r.layout_sha256 FROM %i t LEFT JOIN %i r ON r.id=t.current_revision_id' . $where_sql . ' ORDER BY t.updated_at DESC,t.id DESC LIMIT %d OFFSET %d', array_merge( array( $table, $revisions ), $args, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_template_list_failed', __( 'Certloom could not load templates.', 'certloom' ) ); }
		return array( 'items' => $rows, 'total' => $total );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function export_package( $template_id, $plugin_version ) {
		$template = $this->find( $template_id );
		if ( is_wp_error( $template ) || ! is_array( $template ) || empty( $template['current_revision'] ) ) { return is_wp_error( $template ) ? $template : new \WP_Error( 'certloom_template_export_missing', __( 'The template cannot be exported because its current revision is unavailable.', 'certloom' ) ); }
		return ( new TemplatePackage( $this->layouts, $this->json ) )->build( $template, $template['current_revision'], $plugin_version );
	}

	/** @return int|\WP_Error */
	public function import_package( $json, $actor_user_id = 0 ) {
		$decoded = ( new TemplatePackage( $this->layouts, $this->json ) )->decode( $json );
		if ( is_wp_error( $decoded ) ) { return $decoded; }
		return $this->create( $decoded, $actor_user_id, self::SOURCE_IMPORT );
	}

	/** @return true|\WP_Error */
	public function assign( $template_id, $scope_type, $scope_id = 0, $priority = 100 ) {
		$template_id = absint( $template_id ); $scope_type = sanitize_key( (string) $scope_type ); $scope_id = absint( $scope_id ); $priority = absint( $priority );
		if ( ! $template_id || ! in_array( $scope_type, array( 'global', 'issuer', 'program', 'batch' ), true ) || $priority > 1000 || ( 'global' === $scope_type ? 0 !== $scope_id : $scope_id < 1 ) ) { return new \WP_Error( 'certloom_template_assignment_invalid', __( 'Choose a valid template assignment scope.', 'certloom' ) ); }
		$template = $this->find( $template_id ); if ( is_wp_error( $template ) ) { return $template; } if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) ) { return new \WP_Error( 'certloom_template_unavailable', __( 'The template is unavailable for assignment.', 'certloom' ) ); }
		$exists = $this->scope_exists( $scope_type, $scope_id ); if ( is_wp_error( $exists ) ) { return $exists; } if ( ! $exists ) { return new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The template assignment target no longer exists.', 'certloom' ) ); }
		$generation = ( new DomainGeneration() )->mark_before_mutation(); if ( is_wp_error( $generation ) ) { return $generation; }
		global $wpdb; $now = current_time( 'mysql', true ); $table = SchemaManager::table( 'template_assignments' );
		$wpdb->last_error = '';
		$existing_id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE scope_type=%s AND scope_id=%d', $table, $scope_type, $scope_id ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not inspect the template assignment.', 'certloom' ) ); }
		if ( $existing_id ) {
			$updated = $wpdb->update( $table, array( 'template_id' => $template_id, 'priority' => $priority, 'active' => 1, 'updated_at' => $now ), array( 'id' => absint( $existing_id ) ) );
			return false === $updated || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_assignment_save_failed', __( 'Certloom could not save the template assignment.', 'certloom' ) ) : true;
		}
		$inserted = $wpdb->insert( $table, array( 'template_id' => $template_id, 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'priority' => $priority, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		return false === $inserted || '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_assignment_save_failed', __( 'Certloom could not save the template assignment.', 'certloom' ) ) : true;
	}

	/** @return int|null|\WP_Error */
	public function resolve_assignment( array $context ) {
		global $wpdb; $table = SchemaManager::table( 'template_assignments' );
		$candidates = array();
		foreach ( array( 'batch', 'program', 'issuer' ) as $scope ) { $id = absint( $context[ $scope . '_id' ] ?? 0 ); if ( $id ) { $candidates[] = array( $scope, $id ); } }
		$candidates[] = array( 'global', 0 );
		foreach ( $candidates as $candidate ) {
			$wpdb->last_error = '';
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT template_id FROM %i WHERE scope_type=%s AND scope_id=%d AND active=1 ORDER BY priority ASC,id ASC LIMIT 1', $table, $candidate[0], $candidate[1] ) );
			if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_template_assignment_read_failed', __( 'Certloom could not resolve the template assignment.', 'certloom' ) ); }
			if ( $id ) { return absint( $id ); }
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
			'layout_schema' => 'certloom-layout', 'layout_schema_version' => 1, 'layout_json' => $layout_json, 'layout_sha256' => $hash,
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

	/** @return bool|\WP_Error */
	private function has_preset_revision( $template_id ) {
		global $wpdb; $wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE template_id=%d AND source_type=%s LIMIT 1', SchemaManager::table( 'template_revisions' ), absint( $template_id ), self::SOURCE_PRESET ) );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_template_revision_read_failed', __( 'Certloom could not inspect factory-template revision provenance.', 'certloom' ) ) : (bool) $id;
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
