<?php
/** Program-scoped batch reads, navigation and context authorization without a live site. */
namespace Certloom\Infrastructure\Repository {
	final class ProgramRepository {
		public static $rows = array( 7 => array( 'id' => 7, 'title' => 'Program A' ), 8 => array( 'id' => 8, 'title' => 'Program B' ) );
		public function find( $id ) { return self::$rows[ $id ] ?? null; }
	}
}
namespace {
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class Ecapla_CertVerify_Permissions { const CAP_MANAGE_PROGRAMS = 'programs'; const CAP_MANAGE_BATCHES = 'batches'; }
class BatchRedirect extends RuntimeException { public $url; public function __construct( $url ) { $this->url = $url; } }
function __( $s ) { return $s; }
function esc_html__( $s ) { return esc_html( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid:' . $action; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . ( $args['response'] ?? 0 ) ); }
function wp_safe_redirect( $url ) { throw new BatchRedirect( $url ); }
function admin_url( $s ) { return 'https://example.test/wp-admin/' . $s; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

final class BatchWorkspaceDb {
	public $prefix = 'wp_'; public $last_error = ''; public $queries = array(); public $fail = '';
	public $rows = array(
		array( 'id' => 11, 'program_id' => 7, 'name' => 'Alpha', 'code' => 'A', 'active' => 1 ),
		array( 'id' => 12, 'program_id' => 7, 'name' => 'Beta', 'code' => 'B', 'active' => 0 ),
		array( 'id' => 13, 'program_id' => 8, 'name' => 'Alpha other', 'code' => 'C', 'active' => 1 ),
	);
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => is_array( $args[0] ?? null ) ? $args[0] : $args ); }
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	private function selection( $query, $offset ) {
		$rows = $this->rows;
		if ( false !== strpos( $query['sql'], 'b.program_id = %d' ) ) {
			$id = $query['args'][ $offset++ ];
			$rows = array_filter( $rows, static function ( $r ) use ( $id ) { return $r['program_id'] === $id; } );
		}
		if ( false !== strpos( $query['sql'], 'b.name LIKE %s' ) ) {
			$needle = trim( $query['args'][ $offset ], '%' );
			$rows = array_filter( $rows, static function ( $r ) use ( $needle ) { return false !== stripos( $r['name'], $needle ); } );
		}
		if ( preg_match( '/b.active = ([01])/', $query['sql'], $m ) ) {
			$active = (int) $m[1];
			$rows = array_filter( $rows, static function ( $r ) use ( $active ) { return $r['active'] === $active; } );
		}
		return array_values( $rows );
	}
	public function get_var( $q ) { $this->queries[] = $q; if ( 'count' === $this->fail ) { $this->last_error = 'count failed'; return null; } return count( $this->selection( $q, 3 ) ); }
	public function get_results( $q, $format ) { $this->queries[] = $q; if ( 'rows' === $this->fail ) { $this->last_error = 'rows failed'; return null; } return array_slice( array_reverse( $this->selection( $q, 5 ) ), $q['args'][ count( $q['args'] ) - 1 ], $q['args'][ count( $q['args'] ) - 2 ] ); }
	public function get_row( $q, $format ) { $this->queries[] = $q; foreach ( $this->rows as $r ) { if ( $r['id'] === $q['args'][1] ) { return $r; } } return null; }
}

use Certloom\Infrastructure\Admin\DomainStructureAdmin;
use Certloom\Infrastructure\Admin\DomainAdmin;
use Certloom\Infrastructure\Repository\BatchRepository;

$checks = 0;
function batch_check( $ok, $message ) { global $checks; if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; }
function batch_private( $object, $name, ...$args ) { return ( new ReflectionMethod( $object, $name ) )->invoke( $object, ...$args ); }
$wpdb = new BatchWorkspaceDb();
$repo = new BatchRepository();
$a = $repo->list_page( '', 1, 50, 'all', 7 );
batch_check( 2 === $a['total'] && array( 12, 11 ) === array_column( $a['items'], 'id' ), 'Program A excludes Program B in count and list.' );
batch_check( array( 7 ) === array_slice( $wpdb->queries[0]['args'], 3 ) && array( 7, 50, 0 ) === array_slice( $wpdb->queries[1]['args'], 5 ), 'Scope binds before limit/offset in both queries.' );
$b = $repo->list_page( '', 1, 50, 'all', 8 );
batch_check( 1 === $b['total'] && 13 === $b['items'][0]['id'], 'Switching Programs selects only the new Program.' );
$a = $repo->list_page( 'Alpha', 1, 50, 'active', 7 );
batch_check( 1 === $a['total'] && 11 === $a['items'][0]['id'], 'Search and status cannot widen Program scope.' );
$a = $repo->list_page( '', 2, 1, 'all', 7 );
batch_check( 2 === $a['total'] && 11 === $a['items'][0]['id'], 'Pagination retains the scoped total and records.' );
batch_check( 3 === $repo->list_page()['total'], 'Omitted scope preserves authorized legacy callers.' );
foreach ( array( 0, -7, '', '7 OR 1=1', array( 7 ), false ) as $bad ) {
	$count = count( $wpdb->queries );
	batch_check( is_wp_error( $repo->list_page( '', 1, 50, 'all', $bad ) ) && $count === count( $wpdb->queries ), 'Invalid explicit scope performs no unscoped read.' );
}
foreach ( array( 'count', 'rows' ) as $failure ) { $wpdb->fail = $failure; batch_check( is_wp_error( $repo->list_page( '', 1, 50, 'all', 7 ) ), 'Database failures remain errors: ' . $failure ); }
$wpdb->fail = '';
$caps = array( 'programs' => true, 'batches' => true );
$admin = new DomainStructureAdmin();
$_POST = array( 'certloom_program_context' => '7', '_certloom_program_nonce' => 'valid:certloom_program_batches_7' );
batch_check( true === batch_private( $admin, 'verify_program_context', array( 'program_id' => 7 ), 7 ), 'Matching nonce, permission, Program and batch context are accepted.' );
$route = batch_private( $admin, 'batch_route_args', array( 'certloom_new' => 1 ) );
batch_check( DomainAdmin::PROGRAMS_PAGE === $route['page'] && 7 === $route['program_id'] && ! isset( $route['program_tab'] ) && isset( $route['batch_new'] ) && ! isset( $route['certloom_new'] ), 'New-batch routing opens a popup on the Program page without creating another Program or tab.' );
batch_check( is_wp_error( batch_private( $admin, 'verify_program_context', array( 'program_id' => 8 ), 7 ) ), 'Batch from another Program is rejected.' );
batch_check( is_wp_error( batch_private( $admin, 'verify_program_context', null, 8 ) ), 'Tampered hidden Program is rejected.' );
$_POST['_certloom_program_nonce'] = 'invalid';
batch_check( is_wp_error( batch_private( $admin, 'verify_program_context', null, 7 ) ), 'Expired context nonce is rejected.' );
$_POST['_certloom_program_nonce'] = array( 'invalid' );
batch_check( is_wp_error( batch_private( $admin, 'verify_program_context', null, 7 ) ), 'Malformed context nonce is rejected without diagnostics.' );
$_POST['_certloom_program_nonce'] = 'valid:certloom_program_batches_7'; $caps['programs'] = false;
batch_check( is_wp_error( batch_private( $admin, 'verify_program_context', null, 7 ) ), 'Embedded writes require Program management as well as the handler batch permission.' );
$_POST = array();
batch_check( true === batch_private( $admin, 'verify_program_context', array( 'program_id' => 7 ), 8 ), 'Legacy batch-only forms preserve their prior authorized contract.' );
$legacy = new DomainStructureAdmin();
batch_check( array( 'page' => DomainStructureAdmin::BATCHES_PAGE ) === batch_private( $legacy, 'batch_route_args' ), 'Batch-only roles retain the legacy route.' );
$_GET = array( 'page' => DomainStructureAdmin::BATCHES_PAGE, 'batch_id' => 13 );
batch_check( null === $legacy->redirect_legacy_batches(), 'A batch-only role is not redirected to an unauthorized Program screen.' );
$caps['programs'] = true;
$_GET = array( 'batch_id' => 13 );
ob_start(); $legacy->render_program_batches( array( 'id' => 7, 'title' => 'Program A' ) ); $html = ob_get_clean();
batch_check( false !== strpos( $html, 'does not belong to this Program' ) && false === strpos( $html, 'certloom_domain_update_batch' ), 'Cross-Program read does not expose an editable batch form.' );
$_GET = array( 'page' => DomainStructureAdmin::BATCHES_PAGE, 'batch_id' => 13, 'program_id' => 7, 'member_s' => 'Alice', 'redirect_to' => 'https://attacker.test' );
try { ( new DomainStructureAdmin() )->redirect_legacy_batches(); throw new RuntimeException( 'Expected a redirect.' ); }
catch ( BatchRedirect $r ) {
	parse_str( parse_url( $r->url, PHP_URL_QUERY ), $q );
	batch_check( '8' === $q['program_id'] && '13' === $q['batch_id'] && 'Alice' === $q['member_s'] && ! isset( $q['redirect_to'] ), 'Legacy links use the actual batch Program and allowlisted member search.' );
}
echo "$checks checks, 0 failures\n";
}
