<?php
namespace Certloom\Infrastructure\Repository;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\EmailDeliveryRepositoryInterface;
use Certloom\Application\Data\SafeJson;
use Certloom\Infrastructure\Database\SchemaManager;

/** Privacy-minimized persistent delivery evidence. Message bodies/destination remain in protected short-lived envelopes. */
final class EmailDeliveryRepository implements EmailDeliveryRepositoryInterface {
	/** @var CredentialRepository */ private $credentials;
	public function __construct( ?CredentialRepository $credentials = null ) { $this->credentials = $credentials ?: new CredentialRepository(); }
	public function find_version( $version_id ) { return $this->credentials->find_version( absint( $version_id ) ); }

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_delivery( $delivery_key ) {
		global $wpdb; $delivery_key = self::key( $delivery_key ); if ( is_wp_error( $delivery_key ) ) { return $delivery_key; }
		$wpdb->last_error=''; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE delivery_key = %s',SchemaManager::table('email_deliveries'),$delivery_key),ARRAY_A);
		if(''!==(string)$wpdb->last_error){return new \WP_Error('certloom_email_delivery_read_failed',__('Certloom could not read the credential email delivery record.','certloom'));}
		return is_array($row)?$this->decode($row):null;
	}
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_delivery_by_job( $job_key ) {
		global $wpdb; $job_key=strtolower(trim((string)$job_key)); if(!preg_match('/^[a-f0-9]{32}$/',$job_key)){return null;}
		$wpdb->last_error='';$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE job_key = %s',SchemaManager::table('email_deliveries'),$job_key),ARRAY_A);
		if(''!==(string)$wpdb->last_error){return new \WP_Error('certloom_email_delivery_read_failed',__('Certloom could not read the credential email delivery record.','certloom'));}
		return is_array($row)?$this->decode($row):null;
	}
	/** @return string|\WP_Error */
	public function create_delivery( array $data ) {
		global $wpdb;
		$version_id=absint($data['version_id']??0);$series_id=absint($data['series_id']??0);$kind=sanitize_key((string)($data['delivery_kind']??'issued'));
		if(!$version_id||!$series_id||!in_array($kind,array('issued','resend','test'),true)){return new \WP_Error('certloom_email_delivery_identity_invalid',__('The credential email delivery identity is invalid.','certloom'));}
		try{$key=bin2hex(random_bytes(16));}catch(\Throwable $e){return new \WP_Error('certloom_email_delivery_key_failed',__('Certloom could not create a secure email delivery identifier.','certloom'));}
		$evidence=(new SafeJson())->encode_array(is_array($data['evidence']??null)?$data['evidence']:array(),'certloom_email_delivery_evidence_invalid',__('Certloom could not encode email delivery evidence safely.','certloom'));if(is_wp_error($evidence)){return$evidence;}
		$now=current_time('mysql',true);$row=array('delivery_key'=>$key,'series_id'=>$series_id,'version_id'=>$version_id,'job_key'=>null,'delivery_kind'=>$kind,'status'=>'preparing','destination_mask'=>substr((string)($data['destination_mask']??''),0,320),'destination_sha256'=>(string)($data['destination_sha256']??''),'subject_sha256'=>(string)($data['subject_sha256']??''),'html_sha256'=>(string)($data['html_sha256']??''),'text_sha256'=>(string)($data['text_sha256']??''),'composition_sha256'=>(string)($data['composition_sha256']??''),'template_version'=>max(1,absint($data['template_version']??1)),'attempt_token'=>null,'attempted_at'=>null,'accepted_at'=>null,'failed_at'=>null,'failure_code'=>null,'evidence_json'=>$evidence,'created_at'=>$now,'updated_at'=>$now);
		foreach(array('destination_sha256','subject_sha256','html_sha256','text_sha256','composition_sha256')as$h){if(!preg_match('/^[a-f0-9]{64}$/',$row[$h])){return new \WP_Error('certloom_email_delivery_hash_invalid',__('Credential email delivery evidence requires valid SHA-256 fingerprints.','certloom'));}}
		$wpdb->last_error='';$ok=$wpdb->insert(SchemaManager::table('email_deliveries'),$row);return false!==$ok&&''===(string)$wpdb->last_error?$key:new \WP_Error('certloom_email_delivery_create_failed',__('Certloom could not create the credential email delivery record.','certloom'));
	}
	/** @return true|\WP_Error */
	public function attach_job( $delivery_key, $job_key ) { return $this->update($delivery_key,array('job_key'=>(string)$job_key,'status'=>'queued','updated_at'=>current_time('mysql',true)),array('preparing')); }
	/** @return true|\WP_Error */
	public function mark_sending( $delivery_key, $attempt_token ) {
		$attempt_token=strtolower(trim((string)$attempt_token));if(!preg_match('/^[a-f0-9]{32}$/',$attempt_token)){return new \WP_Error('certloom_email_attempt_token_invalid',__('The credential email attempt identifier is invalid.','certloom'));}
		return $this->update($delivery_key,array('status'=>'sending','attempt_token'=>$attempt_token,'attempted_at'=>current_time('mysql',true),'failed_at'=>null,'failure_code'=>null,'updated_at'=>current_time('mysql',true)),array('queued','failed'));
	}
	/** @return true|\WP_Error */
	public function mark_sent( $delivery_key, array $evidence = array() ) { return $this->finish($delivery_key,'sent',null,$evidence,false); }
	/** @return true|\WP_Error */
	public function mark_failed( $delivery_key, $failure_code, array $evidence = array(), $uncertain = false ) { return $this->finish($delivery_key,$uncertain?'uncertain':'failed',sanitize_key((string)$failure_code),$evidence,true); }
	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function recent( $limit = 50 ) { $page=$this->list_page(1,$limit); return is_wp_error($page)?$page:$page['items']; }
	/** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}|\WP_Error */
	public function list_page( $page = 1, $per_page = 50 ) { global $wpdb; $page=max(1,absint($page)); $per_page=max(1,min(100,absint($per_page))); $offset=($page-1)*$per_page; $table=SchemaManager::table('email_deliveries'); $wpdb->last_error=''; $total=$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i',$table)); if(''!==(string)$wpdb->last_error){return new \WP_Error('certloom_email_delivery_list_failed',__('Certloom could not read credential email delivery history.','certloom'));} $wpdb->last_error=''; $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',$table,$per_page,$offset),ARRAY_A); if(''!==(string)$wpdb->last_error||!is_array($rows)){return new \WP_Error('certloom_email_delivery_list_failed',__('Certloom could not read credential email delivery history.','certloom'));} $out=array(); foreach($rows as$r){$out[]=$this->decode($r);} return array('items'=>$out,'total'=>absint($total),'page'=>$page,'per_page'=>$per_page); }
	/** @return true|\WP_Error */
	private function finish( $key, $status, $failure, array $evidence, $failed ) {
		$delivery = $this->find_delivery( $key );
		if ( is_wp_error( $delivery ) ) { return $delivery; }
		if ( ! is_array( $delivery ) ) { return new \WP_Error( 'certloom_email_delivery_missing', __( 'The credential email delivery record no longer exists.', 'certloom' ) ); }

		$current = (string) ( $delivery['status'] ?? '' );
		/* Terminal delivery truth is idempotent and never moves backwards. */
		if ( 'sent' === $current ) {
			return 'sent' === $status ? true : new \WP_Error( 'certloom_email_delivery_already_sent', __( 'This credential email has already been recorded as accepted by the WordPress mail transport.', 'certloom' ) );
		}
		if ( 'uncertain' === $current ) {
			return 'uncertain' === $status ? true : new \WP_Error( 'certloom_email_delivery_uncertain', __( 'This credential email has uncertain delivery status and requires explicit administrator review before another send.', 'certloom' ) );
		}

		$merged  = is_array( $delivery['evidence'] ?? null ) ? $delivery['evidence'] : array();
		$merged  = array_merge( $merged, $evidence );
		$encoded = ( new SafeJson() )->encode_array( $merged, 'certloom_email_delivery_evidence_invalid', __( 'Certloom could not encode email delivery evidence safely.', 'certloom' ) );
		if ( is_wp_error( $encoded ) ) { return $encoded; }
		$now     = current_time( 'mysql', true );
		$changes = array( 'status' => $status, 'failure_code' => $failure ?: null, 'evidence_json' => $encoded, 'updated_at' => $now );
		if ( $failed ) { $changes['failed_at'] = $now; } else { $changes['accepted_at'] = $now; $changes['failed_at'] = null; $changes['failure_code'] = null; }
		$allowed = 'uncertain' === $status ? array( 'sending', 'queued', 'failed', 'preparing' ) : array( 'sending', 'queued', 'failed', 'preparing' );
		return $this->update( $key, $changes, $allowed );
	}
	/** @return true|\WP_Error */
	private function update( $key, array $changes, array $allowed ) { global $wpdb; $key = self::key( $key ); if ( is_wp_error( $key ) ) { return $key; } $where = array( 'delivery_key' => $key ); $wpdb->last_error = ''; $current = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE delivery_key = %s', SchemaManager::table( 'email_deliveries' ), $key ) ); if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_email_delivery_read_failed', __( 'Certloom could not read the credential email delivery record.', 'certloom' ) ); } if ( ! in_array( (string) $current, $allowed, true ) ) { return new \WP_Error( 'certloom_email_delivery_state_conflict', __( 'The credential email delivery changed before this operation could be saved.', 'certloom' ) ); } $wpdb->last_error = ''; $ok = $wpdb->update( SchemaManager::table( 'email_deliveries' ), $changes, $where ); return '' === (string) $wpdb->last_error && ( 1 === (int) $ok || 0 === (int) $ok ) ? true : new \WP_Error( 'certloom_email_delivery_update_failed', __( 'Certloom could not update the credential email delivery record.', 'certloom' ) ); }
	/** @return array<string,mixed> */ private function decode(array$r){$e=json_decode((string)($r['evidence_json']??''),true);$r['evidence']=is_array($e)?$e:array();foreach(array('id','series_id','version_id','template_version')as$k){$r[$k]=absint($r[$k]??0);}return$r;}
	/** @return string|\WP_Error */ private static function key($v){$v=strtolower(trim((string)$v));return preg_match('/^[a-f0-9]{32}$/',$v)?$v:new \WP_Error('certloom_email_delivery_key_invalid',__('The credential email delivery identifier is invalid.','certloom'));}
}
