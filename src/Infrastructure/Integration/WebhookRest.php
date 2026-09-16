<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if(!defined('ABSPATH')){exit;}
/** REST receiver for administrator-created signed webhook sources. */
final class WebhookRest{const NAMESPACE='certloom/v1';public static function register(){add_action('rest_api_init',array(__CLASS__,'routes'));}public static function routes(){register_rest_route(self::NAMESPACE,'/integrations/webhook/(?P<source>[a-z][a-z0-9\-]{1,63})',array('methods'=>'POST','callback'=>array(__CLASS__,'receive'),'permission_callback'=>'__return_true'));}public static function receive($request){$source=sanitize_key((string)$request->get_param('source'));$timestamp=(string)$request->get_header('x-certloom-timestamp');$signature=(string)$request->get_header('x-certloom-signature');$raw=(string)$request->get_body();$result=WebhookBridge::handle($source,$timestamp,$signature,$raw);if(is_wp_error($result))return new \WP_REST_Response(array('error'=>$result->get_error_code(),'message'=>$result->get_error_message()),409);return new \WP_REST_Response($result,202);}}
