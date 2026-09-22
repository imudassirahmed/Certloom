<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded, relationship-aware choices for Program/Batch admin selectors.
 *
 * Large sites must never rely on "first N rows" as the complete relationship
 * vocabulary. These helpers keep the initial HTML compact while guaranteeing
 * that an already-selected relationship remains visible. ScopedFieldAdminAjax
 * supplies the searchable continuation used by JavaScript-enhanced screens.
 */
final class ScopedContextChoices {
	const INITIAL_LIMIT = 20;

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function programs( $selected_id = 0, $status = 'active' ) {
		$status = in_array( (string) $status, array( 'active', 'inactive', 'all' ), true ) ? (string) $status : 'active';
		$repo = new ProgramRepository();
		$result = $repo->list_page( '', 1, self::INITIAL_LIMIT, $status );
		if ( is_wp_error( $result ) ) { return $result; }
		$items = (array) ( $result['items'] ?? array() );
		$selected_id = absint( $selected_id );
		if ( $selected_id && ! $this->contains_id( $items, $selected_id ) ) {
			$selected = $repo->find( $selected_id );
			if ( is_wp_error( $selected ) ) { return $selected; }
			if ( is_array( $selected ) && ( 'all' === $status || ( 'active' === $status && ! empty( $selected['active'] ) ) || ( 'inactive' === $status && empty( $selected['active'] ) ) ) ) { $items[] = $selected; }
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function batches( $program_id, $selected_id = 0 ) {
		$program_id = absint( $program_id );
		$repo = new BatchRepository();
		$items = $program_id ? $repo->search_active_for_program( $program_id, '', self::INITIAL_LIMIT ) : array();
		if ( is_wp_error( $items ) ) { return $items; }
		$items = (array) $items;
		$selected_id = absint( $selected_id );
		if ( $selected_id && ! $this->contains_id( $items, $selected_id ) ) {
			$selected = $repo->find( $selected_id );
			if ( is_wp_error( $selected ) ) { return $selected; }
			if ( is_array( $selected ) && ! empty( $selected['active'] ) && $program_id === absint( $selected['program_id'] ?? 0 ) ) { $items[] = $selected; }
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function issuers( $selected_id = 0 ) {
		$repo = new IssuerRepository();
		$result = $repo->list_page( '', 1, self::INITIAL_LIMIT );
		if ( is_wp_error( $result ) ) { return $result; }
		$items = (array) ( $result['items'] ?? array() );
		$selected_id = absint( $selected_id );
		if ( $selected_id && ! $this->contains_id( $items, $selected_id ) ) {
			$selected = $repo->find( $selected_id );
			if ( is_wp_error( $selected ) ) { return $selected; }
			if ( is_array( $selected ) ) { $items[] = $selected; }
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function signers( $selected_id = 0 ) {
		$repo = new SignerRepository();
		$result = $repo->list_page( '', 1, self::INITIAL_LIMIT, 'active' );
		if ( is_wp_error( $result ) ) { return $result; }
		$items = (array) ( $result['items'] ?? array() );
		$selected_id = absint( $selected_id );
		if ( $selected_id && ! $this->contains_id( $items, $selected_id ) ) {
			$selected = $repo->find( $selected_id );
			if ( is_wp_error( $selected ) ) { return $selected; }
			if ( is_array( $selected ) && ! empty( $selected['active'] ) ) { $items[] = $selected; }
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function templates( $selected_id = 0 ) {
		$repo = new TemplateRepository();
		$items = $repo->search_published_candidates( '', self::INITIAL_LIMIT );
		if ( is_wp_error( $items ) ) { return $items; }
		$items = (array) $items; $selected_id = absint( $selected_id );
		if ( $selected_id && ! $this->contains_id( $items, $selected_id ) ) {
			$selected = $repo->find_published( $selected_id );
			if ( is_wp_error( $selected ) ) { return $selected; }
			if ( is_array( $selected ) ) {
				$items[] = array(
					'id' => absint( $selected['id'] ?? 0 ),
					'name' => (string) ( $selected['name'] ?? '' ),
					'machine_key' => (string) ( $selected['machine_key'] ?? '' ),
					'published_revision_id' => absint( $selected['published_revision_id'] ?? 0 ),
					'revision_number' => absint( $selected['published_revision']['revision_number'] ?? 0 ),
				);
			}
		}
		return $items;
	}

	/** @return string */
	public static function template_label( array $template ) {
		$label = (string) ( $template['name'] ?? '' );
		if ( ! empty( $template['revision_number'] ) ) { $label .= ' · r' . absint( $template['revision_number'] ); }
		return $label;
	}

	/** @return string */
	public static function program_label( array $program ) {
		$label = (string) ( $program['title'] ?? '' );
		if ( ! empty( $program['code'] ) ) { $label .= ' · ' . (string) $program['code']; }
		return $label;
	}

	/** @return string */
	public static function batch_label( array $batch ) {
		$label = (string) ( $batch['name'] ?? '' );
		if ( ! empty( $batch['code'] ) ) { $label .= ' · ' . (string) $batch['code']; }
		return $label;
	}

	/** @param array<int,array<string,mixed>> $items */
	private function contains_id( array $items, $id ) {
		foreach ( $items as $item ) {
			if ( is_array( $item ) && absint( $item['id'] ?? 0 ) === absint( $id ) ) { return true; }
		}
		return false;
	}
}
