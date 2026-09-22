<?php
namespace Certloom\Application\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pure Template lifecycle projection from immutable/current publication pointers. */
final class TemplateLifecycle {
	const DRAFT           = 'draft';
	const PUBLISHED       = 'published';
	const CHANGES_PENDING = 'changes_pending';
	const ARCHIVED        = 'archived';

	/** @return string */
	public static function state( array $template ) {
		if ( ! empty( $template['archived_at'] ) ) {
			return self::ARCHIVED;
		}
		$current   = absint( $template['current_revision_id'] ?? 0 );
		$published = absint( $template['published_revision_id'] ?? 0 );
		if ( $published && $current === $published ) {
			return self::PUBLISHED;
		}
		if ( $published ) {
			return self::CHANGES_PENDING;
		}
		return self::DRAFT;
	}
}
