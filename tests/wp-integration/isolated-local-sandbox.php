<?php
/** Disposable real MySQL tables. Never changes the configured WordPress prefix. CLI only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) ) { exit; }

final class CertloomIsolatedLocalSandbox {
	private $journal;
	private $original_prefix;
	private $cache;
	private $roles;
	private $user;

	public function __construct( $journal ) {
		if ( 'local' !== wp_get_environment_type() || is_multisite() || wp_using_ext_object_cache() || 'local' !== DB_NAME || realpath( ABSPATH ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) ) { throw new RuntimeException( 'Exact single-site Local with a process-local cache required.' ); }
		$this->journal = $journal;
	}
	public static function entities() { return array_merge( \Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types(), array( 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map' ) ); }
	public function create() {
		global $wpdb;
		$dir = realpath( dirname( $this->journal ) );
		if ( ! $dir || realpath( dirname( $dir ) ) !== realpath( sys_get_temp_dir() ) || ! preg_match( '/^certloom-isolated-[a-f0-9]{16}$/D', basename( $dir ) ) || file_exists( $this->journal ) ) { throw new RuntimeException( 'New private owned journal required.' ); }
		$prefix = 'clqa_' . bin2hex( random_bytes( 6 ) ) . '_'; $tables = array();
		foreach ( array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links' ) as $core ) { $tables[ $prefix . $core ] = $wpdb->$core; }
		foreach ( self::entities() as $entity ) { $tables[ $prefix . 'certloom_' . $entity ] = \Certloom\Infrastructure\Database\SchemaManager::table( $entity ); }
		$tables[ $prefix . ECAPLA_CERTVERIFY_TABLE ] = \Ecapla_CertVerify_Database::table_name();
		$data = array( 'version' => 1, 'prefix' => $prefix, 'source_prefix' => $wpdb->prefix, 'tables' => $tables, 'before' => $this->original_fingerprint() );
		$this->write( $data ); // Ownership exists before the first nontransactional DDL.
		foreach ( $tables as $target => $source ) {
			$wpdb->last_error = '';
			if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $target, $source ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Disposable schema creation failed.' ); }
		}
		$minimum = array( 'home', 'siteurl', 'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'WPLANG', 'date_format', 'time_format', 'start_of_week', 'template', 'stylesheet', 'certloom_domain_schema_version', 'certloom_domain_schema_health' );
		foreach ( $minimum as $key ) {
			$wpdb->last_error = '';
			if ( false === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) SELECT option_name,option_value,autoload FROM %i WHERE option_name=%s', $prefix . 'options', $wpdb->options, $key ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Disposable option seed failed.' ); }
		}
		$roles = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $wpdb->prefix . 'user_roles' ) );
		foreach ( array( $prefix . 'user_roles' => $roles, 'default_role' => 'subscriber', 'blog_public' => '0' ) as $key => $value ) { if ( ! is_string( $value ) || 1 !== $wpdb->insert( $prefix . 'options', array( 'option_name' => $key, 'option_value' => $value, 'autoload' => 'off' ) ) ) { throw new RuntimeException( 'Disposable roles seed failed.' ); } }
		return $data;
	}
	public function enter() {
		global $wpdb;
		$data = $this->read();
		if ( $wpdb->prefix !== $data['source_prefix'] || $wpdb->base_prefix !== $data['source_prefix'] ) { throw new RuntimeException( 'Original prefix changed; refusing QA namespace.' ); }
		$this->original_prefix = $wpdb->prefix; $this->cache = $GLOBALS['wp_object_cache']; $this->roles = $GLOBALS['wp_roles']; $this->user = $GLOBALS['current_user'] ?? null;
		if ( is_wp_error( $wpdb->set_prefix( $data['prefix'], true ) ) || $wpdb->users !== $data['prefix'] . 'users' || $wpdb->options !== $data['prefix'] . 'options' ) { throw new RuntimeException( 'Disposable namespace selection failed.' ); }
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
		$GLOBALS['wp_roles'] = new WP_Roles(); unset( $GLOBALS['current_user'] ); wp_set_current_user( 0 );
		\Certloom\Infrastructure\Data\LegacyWriteGuard::invalidate_cache();
		return $data;
	}
	public function leave() {
		global $wpdb;
		if ( null === $this->original_prefix ) { return; }
		$wpdb->set_prefix( $this->original_prefix, true ); $GLOBALS['wp_object_cache'] = $this->cache; $GLOBALS['wp_roles'] = $this->roles; $GLOBALS['current_user'] = $this->user;
		\Certloom\Infrastructure\Data\LegacyWriteGuard::invalidate_cache(); $this->original_prefix = null;
	}
	/** Register the complete exact DDL inventory before an actual restore starts. */
	public function register_domain_workspace( $owner, $whole = false ) {
		global $wpdb;
		$data = $this->read();
		if ( $wpdb->prefix !== $data['prefix'] || ! is_string( $owner ) || ! preg_match( '/^[a-f0-9]{32}$/D', $owner ) ) { throw new RuntimeException( 'Owned disposable workspace required.' ); }
		$data['workspace_owners'] = $data['workspace_owners'] ?? array();
		if ( ! in_array( $owner, $data['workspace_owners'], true ) ) { $data['workspace_owners'][] = $owner; }
		if ( $whole ) { $data['whole_workspace_owners'] = $data['whole_workspace_owners'] ?? array(); if ( ! in_array( $owner, $data['whole_workspace_owners'], true ) ) { $data['whole_workspace_owners'][] = $owner; } }
		if ( count( $data['workspace_owners'] ) > 10 ) { throw new RuntimeException( 'Disposable workspace limit exceeded.' ); }
		$next = $this->journal . '.next';
		$handle = fopen( $next, 'xb' );
		if ( ! $handle ) { throw new RuntimeException( 'Owned journal update unavailable.' ); }
		$bytes = wp_json_encode( $data ); $ok = strlen( $bytes ) === fwrite( $handle, $bytes ) && fflush( $handle ); fclose( $handle );
		if ( ! $ok || ! rename( $next, $this->journal ) ) { throw new RuntimeException( 'Workspace inventory was not saved before DDL.' ); }
	}
	public function cleanup() {
		global $wpdb;
		$this->leave(); $data = $this->read();
		if ( $data['source_prefix'] !== $wpdb->prefix || $data['before']['business'] !== $this->original_fingerprint()['business'] ) { throw new RuntimeException( 'Original business changed; preserve journal for review.' ); }
		// Only exact journaled tables; no prefix-generated deletion and no SQL restore.
		$tables = array_merge( array_keys( $data['tables'] ), $this->workspace_tables( $data ) );
		foreach ( $tables as $table ) { $wpdb->last_error = ''; if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Disposable table cleanup failed.' ); } }
		$after = $this->original_fingerprint();
		if ( $data['before']['business'] !== $after['business'] ) { throw new RuntimeException( 'Original business preservation failed.' ); }
		if ( ! unlink( $this->journal ) ) { throw new RuntimeException( 'Private journal cleanup failed.' ); }
		$changed_options = array();
		if ( isset( $data['before']['option_rows'] ) ) {
			foreach ( $data['before']['option_rows'] + $after['option_rows'] as $name => $row ) {
				if ( ( $data['before']['option_rows'][$name] ?? null ) !== ( $after['option_rows'][$name] ?? null ) ) { $changed_options[] = $name; }
			}
		}
		return array( 'original_business_preserved' => true, 'original_options_unchanged' => $data['before']['options'] === $after['options'], 'changed_original_option_names' => $changed_options, 'qa_tables_removed' => count( $data['tables'] ), 'registered_workspace_names_removed' => count( $tables ) - count( $data['tables'] ) );
	}
	public function read() {
		global $wpdb;
		if ( ! is_file( $this->journal ) || is_link( $this->journal ) || filesize( $this->journal ) > 1048576 ) { throw new RuntimeException( 'Owned bounded journal required.' ); }
		$data = json_decode( file_get_contents( $this->journal ), true );
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ! preg_match( '/^clqa_[a-f0-9]{12}_$/D', $data['prefix'] ?? '' ) || ! is_array( $data['tables'] ?? null ) || count( $data['tables'] ) !== 36 || ! is_array( $data['before'] ?? null ) ) { throw new RuntimeException( 'Exact disposable inventory required.' ); }
		$allowed = array_merge( array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links', ECAPLA_CERTVERIFY_TABLE ), array_map( static function ( $e ) { return 'certloom_' . $e; }, self::entities() ) );
		foreach ( $data['tables'] as $target => $source ) { $suffix = substr( $target, strlen( $data['prefix'] ) ); if ( $target !== $data['prefix'] . $suffix || ! in_array( $suffix, $allowed, true ) || ! is_string( $source ) || $source !== $data['source_prefix'] . $suffix || strlen( $target ) > 64 ) { throw new RuntimeException( 'Unowned table refused.' ); } }
		$this->workspace_tables( $data );
		return $data;
	}
	private function workspace_tables( array $data ) {
		$owners = $data['workspace_owners'] ?? array();
		if ( ! is_array( $owners ) || count( $owners ) > 10 || array_values( $owners ) !== $owners || count( array_unique( $owners ) ) !== count( $owners ) ) { throw new RuntimeException( 'Invalid workspace ownership inventory.' ); }
		$codes = array( 'i', 'p', 'r', 'g', 'b', 'm', 'a', 't', 'u', 'y', 's', 'n', 'v', 'e', 'pr', 'fs', 'ir', 'ie' );
		$whole_owners = $data['whole_workspace_owners'] ?? array();
		if ( ! is_array( $whole_owners ) || array_values( $whole_owners ) !== $whole_owners || array_diff( $whole_owners, $owners ) || count( array_unique( $whole_owners ) ) !== count( $whole_owners ) ) { throw new RuntimeException( 'Invalid complete workspace ownership inventory.' ); }
		$tables = array();
		foreach ( $owners as $owner ) {
			if ( ! is_string( $owner ) || ! preg_match( '/^[a-f0-9]{32}$/D', $owner ) ) { throw new RuntimeException( 'Invalid disposable workspace owner.' ); }
			$owner_codes = in_array( $owner, $whole_owners, true ) ? array_merge( $codes, array( 'ed', 'dd', 'lr' ) ) : $codes;
			foreach ( array( 'certloom_rs_', 'certloom_rm_', 'certloom_rb_' ) as $kind ) { foreach ( $owner_codes as $code ) { $table = $data['prefix'] . $kind . substr( $owner, 0, 12 ) . '_' . $code; if ( strlen( $table ) > 64 ) { throw new RuntimeException( 'Workspace table exceeds MySQL identifier bound.' ); } $tables[] = $table; } }
		}
		return array_values( array_unique( $tables ) );
	}
	private function write( array $data ) { $handle = fopen( $this->journal, 'xb' ); if ( ! $handle ) { throw new RuntimeException( 'Private journal unavailable.' ); } $bytes = wp_json_encode( $data ); $ok = strlen( $bytes ) === fwrite( $handle, $bytes ) && fflush( $handle ); fclose( $handle ); @chmod( $this->journal, 0600 ); if ( ! $ok ) { throw new RuntimeException( 'Private journal not durable.' ); } }
	private function original_fingerprint() {
		global $wpdb; $hashes = array();
		$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, \Ecapla_CertVerify_Database::table_name() ), array_map( array( \Certloom\Infrastructure\Database\SchemaManager::class, 'table' ), self::entities() ) );
		foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Original preservation read failed.' ); } $hashes[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,SHA2(option_value,256) AS sha256,autoload FROM %i ORDER BY option_name', $wpdb->options ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > 5000 ) { throw new RuntimeException( 'Original options preservation read failed.' ); }
		$option_rows = array();
		foreach ( $rows as $row ) { $option_rows[$row['option_name']] = array( 'sha256' => $row['sha256'], 'autoload' => $row['autoload'] ); }
		return array( 'business' => $hashes, 'options' => hash( 'sha256', wp_json_encode( $rows ) ), 'option_rows' => $option_rows );
	}
}
