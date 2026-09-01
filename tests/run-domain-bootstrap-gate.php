<?php
/** Regression tests: gated future-domain storage must not block live plugin activation. */
namespace {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
	define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
	define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
	define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
	$GLOBALS['dbg_options'] = array();
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private $code;
			private $message;
			public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
		}
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['dbg_options'] ) ? $GLOBALS['dbg_options'][ $key ] : $default; }
	function is_admin() { return true; }
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
	function dbg_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
		}
	}
}

namespace Certloom\Infrastructure\Database {
	final class SchemaManager {
		public static $activate_calls = 0;
		public static $upgrade_calls = 0;
		public static function activate() { self::$activate_calls++; return true; }
		public static function maybe_upgrade() { self::$upgrade_calls++; return true; }
	}
}

namespace Certloom\Infrastructure\Job {
	final class JobDispatcher {
		public static $activate_calls = 0;
		public static function activate() { self::$activate_calls++; return true; }
		public static function deactivate() { return true; }
	}
	final class JobRuntime {
		public static $register_calls = 0;
		public function register() { self::$register_calls++; }
		public function email_delivery() { return true; }
		public function queue() { return true; }
	}
}


namespace Certloom\Infrastructure\Email {
	final class EmailAutomationListener {
		public function __construct( $delivery ) {}
		public function register() {}
	}
}

namespace Certloom\Infrastructure\Integration {
	final class CredlyPublishingListener {
		public function __construct( $queue ) {}
		public function register() {}
	}
}

namespace Certloom\Infrastructure\Restore {
	final class DomainRestoreWorkspace {
		const BUILD_OPTION = 'certloom_domain_restore_build';
		const JOURNAL_OPTION = 'certloom_domain_restore_journal';
		public static $recover_calls = 0;
		public function recover_interrupted_commit() { self::$recover_calls++; return true; }
	}
}

namespace Certloom\Infrastructure\Data {
	final class DomainMaintenanceLock { const OPTION = 'certloom_domain_maintenance_lock'; }
}

namespace Certloom\Application\Migration {
	final class DomainCutoverState {
		const OPTION = 'certloom_domain_write_authority';
		const RECEIPT_OPTION = 'certloom_domain_cutover_receipt';
		public function is_domain_authoritative() { return 'domain' === (string) \get_option( self::OPTION, 'legacy' ); }
		public function is_corrupt() { $value = \get_option( self::OPTION, 'legacy' ); return ! in_array( $value, array( false, '', 'legacy', 'domain' ), true ); }
		public function has_committed_receipt() {
			$receipt = \get_option( self::RECEIPT_OPTION, array() );
			return is_array( $receipt ) && 'committed' === (string) ( $receipt['state'] ?? '' );
		}
		public function has_any_receipt_state() {
			$receipt = \get_option( self::RECEIPT_OPTION, false );
			return false !== $receipt && '' !== $receipt && array() !== $receipt;
		}
	}
	final class LegacyMigrationState {
		const OPTION = 'certloom_legacy_domain_migration_state';
		const NOT_STARTED = 'not_started';
		public function status() {
			$state = \get_option( self::OPTION, array() );
			return is_array( $state ) && isset( $state['status'] ) ? (string) $state['status'] : self::NOT_STARTED;
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap-autoload.php';
	require dirname( __DIR__ ) . '/src/Plugin.php';

	use Certloom\Plugin;
	use Certloom\Infrastructure\Database\SchemaManager;
	use Certloom\Infrastructure\Job\JobDispatcher;
	use Certloom\Infrastructure\Job\JobRuntime;
	use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
	use Certloom\Application\Migration\DomainCutoverState;
	use Certloom\Application\Migration\LegacyMigrationState;
	use Certloom\Infrastructure\Data\DomainMaintenanceLock;

	/* Fresh/live 1.5 site: future domain must not be an activation dependency. */
	dbg_same( true, Plugin::activate(), 'gated activation succeeds without future-domain provisioning' );
	Plugin::boot();
	dbg_same( 0, SchemaManager::$activate_calls, 'schema not activated while all domain features are gated' );
	dbg_same( 0, SchemaManager::$upgrade_calls, 'schema not upgraded on normal boot while gated' );
	dbg_same( 0, DomainRestoreWorkspace::$recover_calls, 'domain recovery not invoked without durable recovery evidence' );
	dbg_same( 0, JobDispatcher::$activate_calls, 'domain jobs not activated without domain storage' );
	dbg_same( 0, JobRuntime::$register_calls, 'domain runtime not registered without domain storage' );

	/* Official/forced domain authority makes storage mandatory again. */
	$GLOBALS['dbg_options'][ DomainCutoverState::OPTION ] = 'domain';
	dbg_same( true, Plugin::activate(), 'domain-authoritative site provisions mandatory storage' );
	dbg_same( 1, SchemaManager::$activate_calls, 'schema activation required after cutover' );
	dbg_same( 1, DomainRestoreWorkspace::$recover_calls, 'recovery runs before mandatory schema activation' );
	dbg_same( 1, JobDispatcher::$activate_calls, 'jobs activate after mandatory schema activation' );
	Plugin::boot();
	dbg_same( 1, SchemaManager::$upgrade_calls, 'mandatory domain boot checks schema health' );
	dbg_same( 2, DomainRestoreWorkspace::$recover_calls, 'mandatory domain boot performs recovery check' );
	dbg_same( 1, JobRuntime::$register_calls, 'runtime registers after mandatory domain schema check' );

	/* Corrupt non-empty authority is fail-closed and requires domain recovery/bootstrap rather than reopening legacy-only mode. */
	$GLOBALS['dbg_options'][ DomainCutoverState::OPTION ] = 'truncated';
	Plugin::boot();
	dbg_same( 2, SchemaManager::$upgrade_calls, 'corrupt authority forces mandatory domain schema/recovery boot' );
	dbg_same( 3, DomainRestoreWorkspace::$recover_calls, 'corrupt authority runs recovery before any write model can be trusted' );
	dbg_same( 2, JobRuntime::$register_calls, 'corrupt authority keeps domain runtime available for recovery' );

	/* A started official migration has durable future-domain rows/state even before cutover. */
	unset( $GLOBALS['dbg_options'][ DomainMaintenanceLock::OPTION ] );
	$GLOBALS['dbg_options'][ LegacyMigrationState::OPTION ] = array( 'status' => 'migrating' );
	Plugin::boot();
	dbg_same( 3, SchemaManager::$upgrade_calls, 'started migration forces mandatory domain schema check' );
	dbg_same( 4, DomainRestoreWorkspace::$recover_calls, 'started migration also performs domain recovery check' );
	dbg_same( 3, JobRuntime::$register_calls, 'started migration registers the domain runtime' );

	/* Durable maintenance/recovery evidence also makes storage mandatory on legacy authority. */
	$GLOBALS['dbg_options'][ LegacyMigrationState::OPTION ] = array( 'status' => LegacyMigrationState::NOT_STARTED );
	$GLOBALS['dbg_options'][ DomainCutoverState::OPTION ] = 'legacy';
	unset( $GLOBALS['dbg_options'][ DomainCutoverState::RECEIPT_OPTION ] );
	$GLOBALS['dbg_options'][ DomainMaintenanceLock::OPTION ] = 'owner|purpose|expiry';
	Plugin::boot();
	dbg_same( 4, SchemaManager::$upgrade_calls, 'durable maintenance evidence forces recovery-capable domain boot' );

	echo "PASS  gated future-domain storage no longer blocks the live Certloom activation path\n\n1 test, 0 failures\n";
}
