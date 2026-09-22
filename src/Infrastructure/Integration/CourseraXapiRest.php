<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Site-local OAuth token + Standard xAPI statement endpoints for Coursera. */
final class CourseraXapiRest {
	const NAMESPACE = 'certloom/v1';
	public static function register(){ add_action('rest_api_init',array(__CLASS__,'register_routes')); }
	public static function register_routes(){
		register_rest_route(self::NAMESPACE,'/integrations/coursera/token',array('methods'=>'POST','callback'=>array(__CLASS__,'token'),'permission_callback'=>'__return_true'));
		register_rest_route(self::NAMESPACE,'/integrations/coursera/xapi/statements',array('methods'=>'POST','callback'=>array(__CLASS__,'statement'),'permission_callback'=>'__return_true'));
	}
	public static function token($request){
		$grant=sanitize_key((string)$request->get_param('grant_type'));if('client_credentials'!==$grant){return new \WP_REST_Response(array('error'=>'unsupported_grant_type'),400);}
		$auth=trim((string)$request->get_header('authorization'));if(0!==stripos($auth,'Basic ')){return new \WP_REST_Response(array('error'=>'invalid_client'),401);}
		$decoded=base64_decode(substr($auth,6),true);if(!is_string($decoded)||false===strpos($decoded,':')){return new \WP_REST_Response(array('error'=>'invalid_client'),401);}list($id,$secret)=explode(':',$decoded,2);
		$result=CourseraXapiCredentials::issue_token($id,$secret);if(is_wp_error($result)){return new \WP_REST_Response(array('error'=>'invalid_client'),401);}return new \WP_REST_Response($result,200);
	}
	public static function statement($request){
		$auth=trim((string)$request->get_header('authorization'));$token=0===stripos($auth,'Bearer ')?trim(substr($auth,7)):'';if(!CourseraXapiCredentials::verify_bearer($token)){return new \WP_REST_Response(array('error'=>'invalid_token'),401);}
		$body=$request->get_json_params();if(!is_array($body)||isset($body[0])){return new \WP_REST_Response(array('error'=>'invalid_statement','message'=>__('Send one Coursera xAPI statement per request.','certloom')),400);}
		$result=CourseraXapiBridge::handle_statement($body);if(is_wp_error($result)){return new \WP_REST_Response(array('error'=>$result->get_error_code(),'message'=>$result->get_error_message()),409);}return new \WP_REST_Response($result,202);
	}
}
