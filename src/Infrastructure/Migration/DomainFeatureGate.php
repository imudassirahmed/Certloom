<?php
namespace Certloom\Infrastructure\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Single production-facing API for effective normalized-domain release authority. */
final class DomainFeatureGate {
	/** @var array<string,bool>|null */
	private static $scopes;

	/** @return bool */ public static function write_paths_ready() { $scopes=self::scopes(); return ! empty($scopes['write_paths']); }
	/** @return bool */ public static function admin_preview_ready() { $scopes=self::scopes(); return ! empty($scopes['admin_preview']); }
	/** @return bool */ public static function data_tools_ready() { $scopes=self::scopes(); return ! empty($scopes['data_tools']); }
	/** @return bool */ public static function any_ready() { $scopes=self::scopes(); return !empty($scopes['write_paths']) || !empty($scopes['admin_preview']) || !empty($scopes['data_tools']); }

	/** @return array<string,bool> */
	public static function scopes() {
		if ( is_array( self::$scopes ) ) { return self::$scopes; }
		$gate = new DomainAuthorityGate();
		self::$scopes = array(
			'write_paths' => $gate->allows( 'write_paths' ),
			'admin_preview' => $gate->allows( 'admin_preview' ),
			'data_tools' => $gate->allows( 'data_tools' ),
		);
		/*
		 * Alpha review builds expose implemented product workflows so they can be
		 * exercised end to end on staging. Admin workspaces and normalized write
		 * paths are operational in review builds; destructive migration/restore/
		 * cutover Data Tools still require exact release authority.
		 */
		if ( defined( 'CERTLOOM_REVIEW_ADMIN_PREVIEW' ) && true === CERTLOOM_REVIEW_ADMIN_PREVIEW ) {
			self::$scopes['admin_preview'] = true;
			self::$scopes['write_paths'] = true;
		}
		return self::$scopes;
	}

	/**
	 * Request-local cache reset for source-only tests and controlled bootstrap
	 * transitions. Production authority is immutable for the lifetime of a
	 * loaded plugin request, so normal product code never needs this method.
	 */
	public static function reset_cache() { self::$scopes = null; }
}
