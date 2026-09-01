<?php
namespace Certloom\Infrastructure\Migration;

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
		if ( defined( 'CERTLOOM_QA_UNGATED' ) && true === CERTLOOM_QA_UNGATED ) { self::$scopes = array( 'write_paths' => true, 'admin_preview' => true, 'data_tools' => true ); return self::$scopes; }
		$gate = new DomainAuthorityGate();
		self::$scopes = array(
			'write_paths' => $gate->allows( 'write_paths' ),
			'admin_preview' => $gate->allows( 'admin_preview' ),
			'data_tools' => $gate->allows( 'data_tools' ),
		);
		return self::$scopes;
	}

	/**
	 * Request-local cache reset for source-only tests and controlled bootstrap
	 * transitions. Production authority is immutable for the lifetime of a
	 * loaded plugin request, so normal product code never needs this method.
	 */
	public static function reset_cache() { self::$scopes = null; }
}
