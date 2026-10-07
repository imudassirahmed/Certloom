<?php
namespace Certloom\Application\Issuance;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\BulkRecipientResolverInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Issuance\BulkIssuanceCsvReader;
use Certloom\Infrastructure\Issuance\BulkIssuancePlanStore;
use Certloom\Infrastructure\Issuance\BulkRecipientResolver;

/** Mutation-free, resumable Bulk Issuance CSV review. */
final class BulkIssuancePreflightJobHandler implements JobHandlerInterface {
	const MAX_ERRORS = 100;
	private $reader; private $plans; private $issuance; private $resolver; private $files; private $json; private $batch_size;
	public function __construct( ?BulkIssuanceCsvReader $reader = null, ?BulkIssuancePlanStore $plans = null, ?ManualIssuanceService $issuance = null, ?BulkRecipientResolverInterface $resolver = null, ?PrivateJobFileStore $files = null, ?CanonicalJson $json = null, $batch_size = 100 ) {
		$this->files=$files?:new PrivateJobFileStore(); $this->json=$json?:new CanonicalJson(); $this->reader=$reader?:new BulkIssuanceCsvReader($this->files); $this->plans=$plans?:new BulkIssuancePlanStore($this->files,$this->json); $this->issuance=$issuance?:new ManualIssuanceService(); $this->resolver=$resolver?:new BulkRecipientResolver(); $this->batch_size=max(1,min(250,absint($batch_size)));
	}
	public function handle( array $job, $worker_token ) {
		$payload=is_array($job['payload']??null)?$job['payload']:array(); $result=is_array($job['result']??null)?$job['result']:array(); $job_key=(string)($job['job_key']??''); $path=(string)($payload['path']??'');
		if(!empty($result['review_complete'])){return $this->finish_cleanup($result);}
		if(!$this->files->owns($path)||!is_file($path)){return new \WP_Error('certloom_bulk_source_missing',__('The protected Bulk Issuance source is unavailable.','certloom'));}
		$actual=@hash_file('sha256',$path); if(!is_string($actual)||!hash_equals((string)($payload['source_sha256']??''),$actual)){return new \WP_Error('certloom_bulk_source_changed',__('The protected Bulk Issuance CSV changed after upload. Review was stopped.','certloom'));}
		$state=is_array($result['reader_state']??null)?$result['reader_state']:null; if(null===$state){$state=$this->reader->start($path,(array)($payload['shared']??array()),absint($payload['max_rows']??BulkIssuanceCsvReader::MAX_ROWS)); if(is_wp_error($state)){return $state;}}
		$summary=$this->summary($result['summary']??array()); $plan_path=(string)($result['plan_path']??''); if(''===$plan_path){$plan_path=$this->plans->create($job_key); if(is_wp_error($plan_path)){return $plan_path;}}
		$plan_offset=absint($result['plan_offset']??0); $seen=$this->plans->identity_set($plan_path,$plan_offset); if(is_wp_error($seen)){return $seen;} $handle=$this->plans->open_append($plan_path,$plan_offset); if(is_wp_error($handle)){return $handle;}
		$slice=$this->reader->continue_read($state,$this->batch_size); if(is_wp_error($slice)){$this->plans->close_append($handle);return $slice;} $state=$slice['state'];
		foreach((array)$slice['records'] as $record){$row=absint($record['row_number']??0);$summary['processed']++;$summary['last_row']=max($summary['last_row'],$row); if(isset($record['error'])&&is_wp_error($record['error'])){$this->error($summary,$row,$record['error']);continue;}
			$request=$this->resolver->resolve((array)($record['request']??array())); if(is_wp_error($request)){$this->error($summary,$row,$request);continue;} $keys=$this->reader->identity_keys($request); $duplicate=false; foreach($keys as $key){if(isset($seen[$key])){$duplicate=true;break;}} if($duplicate){$this->error($summary,$row,new \WP_Error('certloom_bulk_duplicate_recipient_row',__('The same Recipient identity appears more than once in this Bulk Issuance review.','certloom')));continue;}
			$review=$this->issuance->review($request); $waiting = false;
			if ( is_wp_error( $review ) && 'customer' === ( $payload['shared']['waiting_policy'] ?? 'stop' ) && in_array( $review->get_error_code(), array( 'certloom_manual_issue_required_field_missing', 'certloom_manual_issue_design_data_missing' ), true ) ) {
				$review = ( new BulkWaitingRequestService() )->review( $request ); $waiting = ! is_wp_error( $review );
			}
			if(is_wp_error($review)){$this->error($summary,$row,$review);continue;} $entry=array('row_number'=>$row,'request'=>$review['request'],'review_fingerprint'=>(string)$review['review_fingerprint'],'identity_keys'=>array_values($keys),'recipient_label'=>(string)($review['recipient']['display_name']??''),'program_id'=>absint($review['program']['id']??0),'template_id'=>absint($review['template']['id']??0),'credential_locale'=>isset($review['credential_locale'])&&is_array($review['credential_locale'])?$review['credential_locale']:array(),'template_resolution'=>(string)($review['template_resolution']??'explicit'),'template_locale_variant'=>isset($review['template_locale_variant'])&&is_array($review['template_locale_variant'])?$review['template_locale_variant']:null);
			if ( $waiting ) { $entry['action'] = 'wait_for_customer'; $entry['qualified_at'] = $job['created_at'] ?? null; }
			$offset=$this->plans->append($handle,$entry); if(is_wp_error($offset)){$this->plans->close_append($handle);return $offset;} $plan_offset=$offset; foreach($keys as $key){$seen[$key]=true;} $summary[ $waiting ? 'waiting' : 'ready' ]++;
		}
		$this->plans->close_append($handle); $next=array('reader_state'=>$state,'summary'=>$summary,'plan_path'=>$plan_path,'plan_offset'=>$plan_offset);
		if(empty($state['complete'])){return JobSliceResult::progress($summary['processed'],'bulk_review',$next);} $plan_sha=$this->plans->sha256($plan_path); if(is_wp_error($plan_sha)){return $plan_sha;} $review_payload=array('contract'=>'certloom-bulk-issuance-review','version'=>2,'owner_user_id'=>absint($job['owner_user_id']??0),'source_sha256'=>$actual,'plan_sha256'=>$plan_sha,'shared'=>(array)($payload['shared']??array()),'summary'=>array('processed'=>$summary['processed'],'ready'=>$summary['ready'],'waiting'=>$summary['waiting'],'errors'=>$summary['errors'])); try{$fingerprint=$this->json->hash($review_payload);}catch(\Throwable $e){return new \WP_Error('certloom_bulk_review_hash_failed',__('Certloom could not fingerprint the Bulk Issuance review.','certloom'));}
		$final=$next+array('plan_sha256'=>$plan_sha,'source_sha256'=>$actual,'review_fingerprint'=>$fingerprint,'ready_for_issue'=>($summary['ready']+$summary['waiting'])>0&&0===$summary['errors'],'review_complete'=>true,'source_path'=>$path); $final['summary']=$summary;
		/* Persist the complete review before deleting private source/blocked-plan data. If the process dies during cleanup, the next slice can safely retry or accept an already-deleted file. */
		return JobSliceResult::progress($summary['processed'],'bulk_review_cleanup',$final);
	}
	/** @return JobSliceResult */
	private function finish_cleanup(array $result){
		$summary=$this->summary($result['summary']??array());
		$source=(string)($result['source_path']??'');
		if(''!==$source&&$this->files->owns($source)){$deleted=$this->files->delete($source);if(is_wp_error($deleted)||false===$deleted){$summary['warnings'][]=__('Bulk review completed, but the protected CSV source could not be removed immediately. Retention cleanup will retry.','certloom');}}
		if(empty($result['ready_for_issue'])){$plan=(string)($result['plan_path']??'');if(''!==$plan&&$this->files->owns($plan)){$deleted=$this->files->delete($plan);if(is_wp_error($deleted)||false===$deleted){$summary['warnings'][]=__('The blocked Bulk Issuance review plan could not be removed immediately. Retention cleanup will retry.','certloom');}else{$result['plan_path']='';}}}
		$result['summary']=$summary;unset($result['review_complete'],$result['source_path'],$result['reader_state']);
		return $summary['errors']?JobSliceResult::partially_succeeded($result,$summary['processed']):JobSliceResult::succeeded($result,$summary['processed']);
	}
	private function summary($s){$s=is_array($s)?$s:array();return array('processed'=>absint($s['processed']??0),'ready'=>absint($s['ready']??0),'waiting'=>absint($s['waiting']??0),'errors'=>absint($s['errors']??0),'last_row'=>absint($s['last_row']??0),'error_details'=>array_slice(is_array($s['error_details']??null)?$s['error_details']:array(),0,self::MAX_ERRORS),'warnings'=>array_slice(is_array($s['warnings']??null)?$s['warnings']:array(),0,20));}
	private function error(array &$summary,$row,\WP_Error $e){$summary['errors']++;if(count($summary['error_details'])<self::MAX_ERRORS){$summary['error_details'][]=array('row'=>absint($row),'code'=>sanitize_key($e->get_error_code()),'message'=>sanitize_text_field($e->get_error_message()));}}
}
