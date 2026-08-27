<?php
namespace Certloom\Application\Email;

use Certloom\Application\Contract\EmailDeliveryRepositoryInterface;
use Certloom\Application\Job\JobQueue;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Email\EmailEnvelopeStore;
use Certloom\Infrastructure\Email\EmailTemplateSettingsRepository;
use Certloom\Infrastructure\Repository\EmailDeliveryRepository;

/** Review/queue boundary for one explicit credential email delivery. */
final class EmailDeliveryCoordinator {
	/** @var JobQueue */ private $queue;
	/** @var EmailDeliveryRepositoryInterface */ private $repository;
	/** @var EmailEnvelopeStore */ private $envelopes;
	/** @var EmailTemplateService */ private $templates;
	/** @var EmailTemplateSettingsRepository */ private $settings;
	public function __construct(JobQueue $queue,?EmailDeliveryRepositoryInterface $repository=null,?EmailEnvelopeStore $envelopes=null,?EmailTemplateService $templates=null,?EmailTemplateSettingsRepository $settings=null){$this->queue=$queue;$this->repository=$repository?:new EmailDeliveryRepository();$this->envelopes=$envelopes?:new EmailEnvelopeStore();$this->templates=$templates?:new EmailTemplateService();$this->settings=$settings?:new EmailTemplateSettingsRepository();}
	/** @return array<string,mixed>|\WP_Error */
	public function preview($version_id,$destination=''){
		$version=$this->repository->find_version(absint($version_id));if(is_wp_error($version)){return$version;}if(!is_array($version)||'issued'!==(string)($version['issuance_state']??'')){return new \WP_Error('certloom_email_version_invalid',__('Choose an issued immutable credential Version for Email Delivery.','certloom'));}$snapshot=is_array($version['snapshot']??null)?$version['snapshot']:array();if(isset($snapshot['credential'])&&is_array($snapshot['credential'])){$snapshot['credential']['version_id']=absint($version['id']??0);}
		$destination=$this->destination($destination,$snapshot);if(is_wp_error($destination)){return$destination;}$authoritative=(new DomainCutoverState())->is_domain_authoritative();$composed=$this->templates->compose($snapshot,$this->settings->get(),$authoritative);if(is_wp_error($composed)){return$composed;}
		return array('version_id'=>absint($version['id']??0),'series_id'=>absint($version['series_id']??0),'destination'=>$destination,'destination_mask'=>$this->mask($destination),'destination_sha256'=>hash('sha256',strtolower($destination)),'composed'=>$composed,'snapshot'=>$snapshot,'verification_authoritative'=>$authoritative,'pdf_attachment_authoritative'=>false);
	}
	/** @return string|\WP_Error */
	public function enqueue($version_id,$destination,$owner_user_id,$delivery_kind='issued',$confirmed=false){if(!$confirmed){return new \WP_Error('certloom_email_confirmation_required',__('Review and explicitly confirm the credential email before queueing delivery.','certloom'));}$delivery_kind=sanitize_key((string)$delivery_kind);if(!in_array($delivery_kind,array('issued','resend','test'),true)){return new \WP_Error('certloom_email_kind_invalid',__('The credential email delivery type is invalid.','certloom'));}$review=$this->preview($version_id,$destination);if(is_wp_error($review)){return$review;}$c=$review['composed'];$key=$this->repository->create_delivery(array('series_id'=>$review['series_id'],'version_id'=>$review['version_id'],'delivery_kind'=>$delivery_kind,'destination_mask'=>$review['destination_mask'],'destination_sha256'=>$review['destination_sha256'],'subject_sha256'=>$c['subject_sha256'],'html_sha256'=>$c['html_sha256'],'text_sha256'=>$c['text_sha256'],'composition_sha256'=>$c['composition_sha256'],'template_version'=>EmailTemplateService::TEMPLATE_VERSION,'evidence'=>array('verification_authoritative'=>(bool)$c['verification_authoritative'],'attachment_status'=>(string)$c['attachment_status'],'requested_by'=>absint($owner_user_id))));if(is_wp_error($key)){return$key;}
		$envelope=array('schema'=>'certloom-email-envelope','schema_version'=>1,'delivery_key'=>$key,'version_id'=>$review['version_id'],'to'=>$review['destination'],'subject'=>$c['subject'],'html_body'=>$c['html_body'],'text_body'=>$c['text_body'],'attachments'=>array(),'composition_sha256'=>$c['composition_sha256'],'destination_sha256'=>$review['destination_sha256']);$path=$this->envelopes->write($key,$envelope);if(is_wp_error($path)){$this->repository->mark_failed($key,'envelope_write_failed',array(),false);return$path;}$job=$this->queue->enqueue(JobTypes::EMAIL_DELIVERY,absint($owner_user_id),array('delivery_key'=>$key,'envelope_path'=>$path));if(is_wp_error($job)){$this->repository->mark_failed($key,'schedule_failed',array('schedule_error'=>$job->get_error_code()),false);$this->envelopes->delete($path);return$job;}$attached=$this->repository->attach_job($key,$job);if(is_wp_error($attached)){return$attached;}return$job;}
	/** @return array<string,mixed>|\WP_Error */ public function delivery_for_job($job_key){return$this->repository->find_delivery_by_job($job_key);}
	/** @return array<int,array<string,mixed>>|\WP_Error */ public function recent($limit=50){return$this->repository->recent($limit);}
	/** @return array<string,mixed> */ public function template(){return$this->settings->get();}
	/** @return true|\WP_Error */ public function save_template(array$template){return$this->settings->save($template);}
	/** @return string|\WP_Error */ private function destination($requested,array$snapshot){$requested=trim((string)$requested);if(''===$requested){$requested=(string)($snapshot['recipient']['email']??'');}$email=sanitize_email($requested);return''!==$email&&(!function_exists('is_email')||is_email($email))?$email:new \WP_Error('certloom_email_destination_required',__('This credential needs a valid delivery email address. Enter one explicitly or add it to the immutable issued Recipient snapshot.','certloom'));}
	private function mask($email){$email=(string)$email;$p=strrpos($email,'@');if(false===$p){return'***';}$local=substr($email,0,$p);$domain=substr($email,$p+1);$shown=''!==$local?substr($local,0,1):'';return$shown.str_repeat('*',max(3,min(12,max(0,strlen($local)-1)))).'@'.$domain;}
}
