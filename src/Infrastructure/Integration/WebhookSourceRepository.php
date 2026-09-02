<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationMappingResolverInterface;
use Certloom\Application\Integration\IntegrationEventContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, encrypted administrator-created webhook sources. */
final class WebhookSourceRepository implements IntegrationMappingResolverInterface {
	const OPTION='certloom_webhook_sources_v1'; const SCHEMA_VERSION=1; const MAX_SOURCES=30;
	/** @return array<string,array<string,mixed>> */
	public function all(){ $stored=get_option(self::OPTION,array());if(!is_array($stored)||absint($stored['schema_version']??0)!==self::SCHEMA_VERSION||!is_array($stored['sources']??null))return array();$out=array();foreach(array_slice($stored['sources'],0,self::MAX_SOURCES)as$row){if(!is_array($row))continue;$n=$this->normalize($row,false);if(!is_wp_error($n))$out[$n['key']]=$n;}ksort($out,SORT_STRING);return$out; }
	/** @return array<string,mixed>|null */ public function find($key){$all=$this->all();$key=sanitize_key((string)$key);return$all[$key]??null;}
	/** @return array{key:string,secret:string}|\WP_Error */
	public function create( array $row ){
		$all=$this->all();if(count($all)>=self::MAX_SOURCES)return new \WP_Error('certloom_webhook_source_limit',__('Certloom supports up to 30 webhook sources per site.','certloom'));
		try{$key='hook-'.bin2hex(random_bytes(6));$secret=bin2hex(random_bytes(32));}catch(\Throwable$e){return new \WP_Error('certloom_webhook_secret_random_failed',__('Certloom could not generate secure webhook credentials.','certloom'));}
		$sealed=IntegrationSecretVault::seal(array('secret'=>$secret));if(is_wp_error($sealed))return$sealed;$row['key']=$key;$row['secret_sealed']=$sealed;$normalized=$this->normalize($row,true);if(is_wp_error($normalized))return$normalized;$all[$key]=$normalized;$saved=$this->persist($all);if(is_wp_error($saved))return$saved;return array('key'=>$key,'secret'=>$secret);
	}
	/** @return array{key:string,secret:string}|\WP_Error */
	public function rotate( $key ){$key=sanitize_key((string)$key);$all=$this->all();if(!isset($all[$key]))return new \WP_Error('certloom_webhook_source_missing',__('Choose a webhook source that still exists.','certloom'));try{$secret=bin2hex(random_bytes(32));}catch(\Throwable$e){return new \WP_Error('certloom_webhook_secret_random_failed',__('Certloom could not generate secure webhook credentials.','certloom'));}$sealed=IntegrationSecretVault::seal(array('secret'=>$secret));if(is_wp_error($sealed))return$sealed;$all[$key]['secret_sealed']=$sealed;$all[$key]['updated_at']=current_time('mysql',true);$saved=$this->persist($all);if(is_wp_error($saved))return$saved;return array('key'=>$key,'secret'=>$secret);}
	/** @return true|\WP_Error */ public function delete($key){$key=sanitize_key((string)$key);$all=$this->all();unset($all[$key]);return$this->persist($all);}
	/** @return string|\WP_Error */ public function secret($key){$row=$this->find($key);if(!is_array($row))return new \WP_Error('certloom_webhook_source_missing',__('This webhook source no longer exists.','certloom'));$opened=IntegrationSecretVault::open((string)($row['secret_sealed']??''));if(is_wp_error($opened))return$opened;$secret=(string)($opened['secret']??'');return 64===strlen($secret)&&ctype_xdigit($secret)?$secret:new \WP_Error('certloom_webhook_secret_invalid',__('The saved webhook secret is invalid. Rotate the source secret.','certloom'));}
	/** @return array<string,mixed>|null|\WP_Error */ public function resolve_mapping(IntegrationEventContext $c){if(WebhookAdapter::KEY!==$c->adapter_key()||WebhookAdapter::SOURCE_OBJECT_TYPE!==$c->source_object_type())return null;$r=$this->find($c->source_object_id());if(!is_array($r)||empty($r['active']))return null;return array('adapter'=>WebhookAdapter::KEY,'source_object_type'=>WebhookAdapter::SOURCE_OBJECT_TYPE,'source_object_id'=>$r['key'],'program_id'=>absint($r['program_id']),'batch_id'=>absint($r['batch_id']??0),'template_id'=>0,'legacy_template_id'=>absint($r['template_id']??0),'expiry_mode'=>'program','expires_at'=>null,'certificate_threshold'=>100.0,'active'=>true);}
	/** @return array<string,mixed>|\WP_Error */
	private function normalize(array$row,$touch){$key=sanitize_key((string)($row['key']??''));$name=trim(sanitize_text_field((string)($row['name']??'')));$program=absint($row['program_id']??0);$batch=absint($row['batch_id']??0);$template=absint($row['template_id']??0);$sealed=trim((string)($row['secret_sealed']??''));if(!preg_match('/^hook-[a-f0-9]{12}$/',$key)||''===$name||strlen($name)>120||$program<1||''===$sealed)return new \WP_Error('certloom_webhook_source_invalid',__('A webhook source requires a name, Program and secure secret. A Batch is optional; design follows Global → Issuer → Program → Batch.','certloom'));$out=array('key'=>$key,'name'=>$name,'program_id'=>$program,'batch_id'=>$batch,'template_id'=>$template,'secret_sealed'=>$sealed,'active'=>!array_key_exists('active',$row)||!empty($row['active']),'updated_at'=>trim((string)($row['updated_at']??'')));if($touch||''===$out['updated_at'])$out['updated_at']=current_time('mysql',true);return$out;}
	/** @return true|\WP_Error */ private function persist(array$sources){ksort($sources,SORT_STRING);$payload=array('schema_version'=>self::SCHEMA_VERSION,'sources'=>array_values($sources));$saved=update_option(self::OPTION,$payload,false);return$saved||$payload===get_option(self::OPTION,null)?true:new \WP_Error('certloom_webhook_source_save_failed',__('Certloom could not save the webhook source safely.','certloom'));}
}
