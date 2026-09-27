<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Integration\IntegrationAdapterRegistry;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Application\Integration\IntegrationIssuanceCoordinator;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Validates Coursera Standard xAPI completion statements and feeds canonical issuance. */
final class CourseraXapiBridge {
	const LAST_RESULT_OPTION = 'certloom_coursera_last_result';
	const EXTERNAL_ID_KEY    = 'http://coursera.org/xapi/extensions/externalId';
	const COURSE_ID_KEY      = 'http://coursera.org/xapi/extensions/courseId';

	public static function authority_ready() { return \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready(); }
	public static function status(){ $maps=(new CourseraMappingStore())->all();$last=get_option(self::LAST_RESULT_OPTION,array());return array('available'=>(bool)CourseraXapiCredentials::status()['configured'],'authority_ready'=>self::authority_ready(),'mapping_count'=>count($maps),'active_mapping_count'=>count(array_filter($maps,static function($r){return is_array($r)&&!empty($r['active']);})),'last_result'=>is_array($last)?$last:array()); }

	/** @return array<string,mixed>|\WP_Error */
	public static function handle_statement( array $statement ) {
		$parsed = self::parse_statement( $statement ); if ( is_wp_error( $parsed ) ) { self::record_result('failed','',$parsed->get_error_code()); return $parsed; }
		$recipient = ( new RecipientRepository() )->find_by_external_id_exact( $parsed['external_id'] );
		if ( is_wp_error( $recipient ) ) { self::record_result('blocked',$parsed['course_id'],$recipient->get_error_code(),$parsed['statement_sha256']); return $recipient; }
		if ( ! is_array( $recipient ) || empty( $recipient['id'] ) ) { $error=new \WP_Error('certloom_coursera_recipient_unlinked',__('No Certloom Recipient has this Coursera external ID. Link the learner to a Recipient before the completion can issue a credential.','certloom'));self::record_result('blocked',$parsed['course_id'],$error->get_error_code(),$parsed['statement_sha256']);return $error; }
		if ( ! self::authority_ready() ) { $error=new \WP_Error('certloom_coursera_authority_closed',__('Coursera completion was received, but automatic credential writing is not yet production-authorized in this Certloom build.','certloom'));self::record_result('blocked',$parsed['course_id'],$error->get_error_code(),$parsed['statement_sha256']);return $error; }
		$registry=new IntegrationAdapterRegistry(array(new CourseraXapiAdapter()));$adapter=$registry->get(CourseraXapiAdapter::KEY);if(is_wp_error($adapter)){return$adapter;}
		$event=$adapter->normalize_event(array('statement_id'=>$parsed['statement_id'],'course_id'=>$parsed['course_id'],'recipient_id'=>absint($recipient['id']),'completed_at'=>$parsed['completed_at'])); if(is_wp_error($event)){self::record_result('failed',$parsed['course_id'],$event->get_error_code(),$parsed['statement_sha256']);return$event;}
		$context=IntegrationEventContext::from_array($event);if(is_wp_error($context)){self::record_result('failed',$parsed['course_id'],$context->get_error_code(),$parsed['statement_sha256']);return$context;}
		$coordinator=new IntegrationIssuanceCoordinator(new LinkedRecipientResolver(),new CourseraMappingStore());$review=$coordinator->review($context);if(is_wp_error($review)){self::record_result('failed',$parsed['course_id'],$review->get_error_code(),$parsed['statement_sha256'],$context->sha256());return$review;}
		$result=$coordinator->issue($context,(string)$review['review_fingerprint'],0);if(is_wp_error($result)){self::record_result('failed',$parsed['course_id'],$result->get_error_code(),$parsed['statement_sha256'],$context->sha256());return$result;}
		$status=!empty($result['idempotent_replay'])?'replayed':'issued';self::record_result($status,$parsed['course_id'],'',$parsed['statement_sha256'],$context->sha256(),absint($result['version_id']??0));
		return array('status'=>$status,'version_id'=>absint($result['version_id']??0),'series_id'=>absint($result['series_id']??0));
	}

	/** @return array<string,string>|\WP_Error */
	private static function parse_statement( array $statement ) {
		$id=trim((string)($statement['id']??'')); if(''===$id||strlen($id)>191||!preg_match('/^[A-Za-z0-9._:@\/-]+$/',$id)){return new \WP_Error('certloom_coursera_statement_invalid',__('Coursera sent an invalid xAPI statement identifier.','certloom'));}
		$verb=is_array($statement['verb']??null)?$statement['verb']:array();$verb_id=strtolower(trim((string)($verb['id']??'')));$display=is_array($verb['display']??null)?$verb['display']:array();$display_text=strtoupper(trim((string)($display['en-US']??$display['en-us']??'')));
		if(false===strpos($verb_id,'completed')&&'COMPLETED'!==$display_text){return new \WP_Error('certloom_coursera_not_completion',__('Certloom accepts only Coursera course-completion xAPI statements for automatic issuance.','certloom'));}
		$result = is_array( $statement['result'] ?? null ) ? $statement['result'] : array();
		// xAPI result flags are JSON booleans. PHP truthiness would accept the
		// string "false", a non-empty array, or a numeric value as completion.
		if ( true !== ( $result['completion'] ?? null ) || ( array_key_exists( 'success', $result ) && true !== $result['success'] ) ) {
			return new \WP_Error( 'certloom_coursera_not_eligible', __( 'This Coursera xAPI statement does not confirm successful course completion.', 'certloom' ) );
		}
		$object=is_array($statement['object']??null)?$statement['object']:array();$definition=is_array($object['definition']??null)?$object['definition']:array();$type=(string)($definition['type']??'');if(''!==$type&&false===strpos($type,'activities/course')){return new \WP_Error('certloom_coursera_object_invalid',__('Certloom accepts Coursera course completion statements, not item or video activity, for credential issuance.','certloom'));}
		$context=is_array($statement['context']??null)?$statement['context']:array();$course_id=trim((string)($context[self::COURSE_ID_KEY]??''));$external_id=trim((string)($context[self::EXTERNAL_ID_KEY]??''));
		if(''===$course_id||strlen($course_id)>191||!preg_match('/^[A-Za-z0-9._:@\/-]+$/',$course_id)){return new \WP_Error('certloom_coursera_course_invalid',__('Coursera did not include a valid course ID in the xAPI completion statement.','certloom'));}
		if(''===$external_id||strlen($external_id)>191){return new \WP_Error('certloom_coursera_external_id_missing',__('Coursera did not include the learner External ID required to link this completion to a Certloom Recipient.','certloom'));}
		$timestamp=trim((string)($statement['timestamp']??$statement['stored']??''));if(''===$timestamp){return new \WP_Error('certloom_coursera_completion_time_invalid',__('Coursera did not include a completion time in the xAPI statement.','certloom'));}
		try{$date=new \DateTimeImmutable($timestamp);}catch(\Throwable $e){return new \WP_Error('certloom_coursera_completion_time_invalid',__('Coursera did not include a valid completion time in the xAPI statement.','certloom'));}
		$json=wp_json_encode($statement,JSON_UNESCAPED_SLASHES);$sha=is_string($json)?hash('sha256',$json):'';
		return array('statement_id'=>$id,'course_id'=>$course_id,'external_id'=>$external_id,'completed_at'=>$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),'statement_sha256'=>$sha);
	}
	private static function record_result($status,$course_id,$error_code='',$statement_sha256='',$context_sha256='',$version_id=0){$status=in_array($status,array('issued','replayed','blocked','failed'),true)?$status:'failed';foreach(array('statement_sha256','context_sha256')as$key){$v=$key==='statement_sha256'?$statement_sha256:$context_sha256;if(!preg_match('/^[a-f0-9]{64}$/',(string)$v)){$v='';}if($key==='statement_sha256'){$statement_sha256=$v;}else{$context_sha256=$v;}}update_option(self::LAST_RESULT_OPTION,array('status'=>$status,'course_id'=>substr((string)$course_id,0,191),'error_code'=>sanitize_key((string)$error_code),'statement_sha256'=>$statement_sha256,'context_sha256'=>$context_sha256,'version_id'=>absint($version_id),'finished_at'=>current_time('mysql',true)),false);}
}
