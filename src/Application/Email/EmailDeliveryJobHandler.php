<?php
namespace Certloom\Application\Email;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\EmailDeliveryRepositoryInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\MailGatewayInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Email\EmailEnvelopeStore;
use Certloom\Infrastructure\Email\WpMailGateway;
use Certloom\Infrastructure\Repository\EmailDeliveryRepository;

/** One external side-effect slice. Interrupted sends are never safe for automatic replay. */
final class EmailDeliveryJobHandler implements JobHandlerInterface {
	/** @var EmailDeliveryRepositoryInterface */ private $repository; /** @var EmailEnvelopeStore */ private $envelopes; /** @var MailGatewayInterface */ private $mail;
	public function __construct(?EmailDeliveryRepositoryInterface$repository=null,?EmailEnvelopeStore$envelopes=null,?MailGatewayInterface$mail=null){$this->repository=$repository?:new EmailDeliveryRepository();$this->envelopes=$envelopes?:new EmailEnvelopeStore();$this->mail=$mail?:new WpMailGateway();}
	public function handle(array$job,$worker_token){$payload=is_array($job['payload']??null)?$job['payload']:array();$key=(string)($payload['delivery_key']??'');$path=(string)($payload['envelope_path']??'');$delivery=$this->repository->find_delivery($key);if(is_wp_error($delivery)){return$delivery;}if(!is_array($delivery)){return new \WP_Error('certloom_email_delivery_missing',__('The credential email delivery record is unavailable.','certloom'));}
		if('sent'===(string)($delivery['status']??'')){$this->envelopes->delete($path);return JobSliceResult::succeeded(array('delivery_key'=>$key,'status'=>'sent','idempotent_terminal'=>true),1);}if('uncertain'===(string)($delivery['status']??'')){return JobSliceResult::failed(array('code'=>'certloom_email_delivery_uncertain','message'=>__('This email delivery is uncertain after an interrupted worker and requires administrator review before any resend.','certloom')),array('delivery_key'=>$key,'status'=>'uncertain'),0);}
		$env=$this->envelopes->read($path);if(is_wp_error($env)){$this->repository->mark_failed($key,'envelope_unavailable',array(),false);return$env;}if('certloom-email-envelope'!==(string)($env['schema']??'')||$key!==(string)($env['delivery_key']??'')||!hash_equals((string)($delivery['composition_sha256']??''),(string)($env['composition_sha256']??''))||!hash_equals((string)($delivery['destination_sha256']??''),(string)($env['destination_sha256']??''))){$this->repository->mark_failed($key,'envelope_evidence_mismatch',array(),false);return new \WP_Error('certloom_email_envelope_evidence_mismatch',__('Certloom refused an email envelope that no longer matches its reviewed delivery evidence.','certloom'));}
		try{$attempt=bin2hex(random_bytes(16));}catch(\Throwable$e){return new \WP_Error('certloom_email_attempt_key_failed',__('Certloom could not create a secure email attempt identifier.','certloom'));}$sending=$this->repository->mark_sending($key,$attempt);if(is_wp_error($sending)){return$sending;}
		$sent=$this->mail->send((string)($env['to']??''),(string)($env['subject']??''),(string)($env['html_body']??''),(string)($env['text_body']??''),is_array($env['attachments']??null)?$env['attachments']:array());if(is_wp_error($sent)){$this->repository->mark_failed($key,$sent->get_error_code(),array('transport'=>'wp_mail','transport_result'=>'rejected'),false);return JobSliceResult::failed(array('code'=>$sent->get_error_code(),'message'=>$sent->get_error_message()),array('delivery_key'=>$key,'status'=>'failed'),1);}
		$recorded=$this->repository->mark_sent($key,array('transport'=>'wp_mail','transport_result'=>'accepted','attempt_token_sha256'=>hash('sha256',$attempt)));if(is_wp_error($recorded)){/* External side effect may already have occurred: deliberately surface an error and let stale recovery become uncertain, never replay automatically. */return$recorded;}$deleted=$this->envelopes->delete($path);return JobSliceResult::succeeded(array('delivery_key'=>$key,'status'=>'sent','private_envelope_deleted'=>!is_wp_error($deleted)&&false!==$deleted),1);}
}
