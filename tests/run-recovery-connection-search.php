<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { public $code; public $data; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->data = $data; } }
class Ecapla_CertVerify_Permissions { const CAP_MANAGE_DATA_TOOLS = 'certloom_manage_data_tools'; }
class WP_REST_Server { const READABLE = 'GET'; }
class WP_REST_Request { private $values; public function __construct( array $values ) { $this->values = $values; } public function get_param( $key ) { return $this->values[$key] ?? null; } }
class WP_REST_Response { public $data; public $headers = array(); public function __construct( $data ) { $this->data = $data; } public function header( $name, $value ) { $this->headers[$name] = $value; } }
function __( $message, $domain = '' ) { return $message; }
function add_action() {}
function current_user_can( $cap ) { return Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS === $cap && ! empty( $GLOBALS['authorized'] ); }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['route'] = $args; }
function get_current_blog_id() { return 7; }
function post_type_exists( $type ) { return in_array( $type, array( 'page', 'product', 'courses', 'attachment' ), true ); }
function get_users( $args ) { $GLOBALS['user_args'] = $args; return array( (object) array( 'ID' => 19, 'display_name' => 'Recipient Example', 'user_login' => 'example' ) ); }
function get_posts( $args ) { $GLOBALS['post_args'] = $args; return array( (object) array( 'ID' => 23, 'post_title' => '<b>Course Example</b>' ) ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
require __DIR__ . '/../src/Infrastructure/Admin/RecoveryConnectionRest.php';
$checks = 0;
function check_search( $passed, $message ) { if ( ! $passed ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
$api = new Certloom\Infrastructure\Admin\RecoveryConnectionRest(); $api->routes(); $permission = $GLOBALS['route']['permission_callback'];
check_search( $permission() instanceof WP_Error && 403 === $permission()->data['status'], 'Recipient and unauthenticated access must be denied.' );
$GLOBALS['authorized'] = true; check_search( true === $permission(), 'Organization data-tools permission allows read-only suggestions.' );
check_search( WP_REST_Server::READABLE === $GLOBALS['route']['methods'], 'Connection suggestions never register mutation methods.' );
$response = $api->search( new WP_REST_Request( array( 'kind' => 'accounts', 'type' => '', 'q' => 'example' ) ) );
check_search( 7 === $GLOBALS['user_args']['blog_id'] && 25 === $GLOBALS['user_args']['number'], 'Account suggestions remain bounded to the current website.' );
check_search( array( 'ID', 'display_name', 'user_login' ) === $GLOBALS['user_args']['fields'], 'Search exposes labels without profile fields, passwords or email.' );
check_search( 'private, no-store, max-age=0' === $response->headers['Cache-Control'] && 'Cookie' === $response->headers['Vary'], 'Authenticated search responses cannot enter shared caches.' );
$api->search( new WP_REST_Request( array( 'kind' => 'accounts', 'type' => '', 'q' => '19' ) ) );
check_search( array( 19 ) === $GLOBALS['user_args']['include'] && ! isset( $GLOBALS['user_args']['search'] ), 'Exact account IDs do not become broad wildcard searches.' );
$response = $api->search( new WP_REST_Request( array( 'kind' => 'objects', 'type' => 'courses', 'q' => 'Course' ) ) );
check_search( 'courses' === $GLOBALS['post_args']['post_type'] && 25 === $GLOBALS['post_args']['posts_per_page'] && $GLOBALS['post_args']['no_found_rows'], 'Website searches preserve the reviewed object type and bounded query size.' );
check_search( 'Course Example #23' === $response->data['items'][0]['label'], 'Object title markup is removed from plain suggestion labels.' );
foreach ( array( array( 'kind' => 'other', 'type' => '', 'q' => 'aa' ), array( 'kind' => 'objects', 'type' => 'missing', 'q' => 'aa' ), array( 'kind' => 'accounts', 'type' => '', 'q' => 'a' ), array( 'kind' => 'accounts', 'type' => '', 'q' => str_repeat( 'a', 201 ) ) ) as $invalid ) { check_search( $api->search( new WP_REST_Request( $invalid ) ) instanceof WP_Error, 'Unknown types and malformed search bounds must fail closed.' ); }
echo $checks . " recovery connection search checks, 0 failures\n";
