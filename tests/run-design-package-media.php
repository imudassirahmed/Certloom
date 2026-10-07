<?php
/** Portable certificate/email design media package contract tests. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['certloom_test_options'] = array();
$GLOBALS['certloom_media'] = array(
	'next_id' => 1001,
	'files' => array(),
	'mimes' => array(),
	'urls' => array(),
	'url_to_id' => array(),
	'deleted' => array(),
	'upload_calls' => 0,
	'fail_upload_call' => 0,
	'fail_update_option' => false,
);
$GLOBALS['certloom_upload_dir'] = sys_get_temp_dir() . '/certloom-portable-media-' . getmypid();
if ( ! is_dir( $GLOBALS['certloom_upload_dir'] ) ) { mkdir( $GLOBALS['certloom_upload_dir'], 0777, true ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $c,$m;
		public function __construct($c='',$m=''){ $this->c=$c; $this->m=$m; }
		public function get_error_code(){ return $this->c; }
		public function get_error_message(){ return $this->m; }
	}
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($t){ return $t; }
function esc_html__($t){ return $t; }
function absint($v){ return abs((int)$v); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v)); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function sanitize_textarea_field($v){ return trim(strip_tags((string)$v)); }
function sanitize_hex_color($v){ return preg_match('/^#[0-9a-fA-F]{6}$/',(string)$v) ? strtolower((string)$v) : null; }
function sanitize_file_name($v){ return preg_replace('/[^A-Za-z0-9._-]/','-',basename((string)$v)); }
function esc_url_raw($v){ $v=trim((string)$v); return preg_match('#^(https?://|\{\{verification_url\}\})#',$v) ? $v : ''; }
function esc_url($v){ return esc_url_raw($v); }
function esc_attr($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function esc_html($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function wp_kses_post($v){ return (string)$v; }
function wp_strip_all_tags($v,$remove_breaks=false){ return strip_tags((string)$v); }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function get_bloginfo($key){ return 'UTF-8'; }
function current_time($type,$gmt=false){ return '2026-09-04 02:30:00'; }
function wp_generate_password($length=12,$special=true,$extra=false){ return substr(str_repeat('abcdef',$length),0,$length); }
function get_option($key,$default=false){ return array_key_exists($key,$GLOBALS['certloom_test_options']) ? $GLOBALS['certloom_test_options'][$key] : $default; }
function update_option($key,$value,$autoload=null){
	if ( ! empty( $GLOBALS['certloom_media']['fail_update_option'] ) ) { return false; }
	$GLOBALS['certloom_test_options'][$key]=$value;
	return true;
}
function attachment_url_to_postid($url){ return absint($GLOBALS['certloom_media']['url_to_id'][(string)$url] ?? 0); }
function get_attached_file($id,$unfiltered=false){ return $GLOBALS['certloom_media']['files'][absint($id)] ?? ''; }
function get_post_mime_type($id){ return $GLOBALS['certloom_media']['mimes'][absint($id)] ?? ''; }
function wp_upload_bits($filename,$deprecated,$bytes){
	$GLOBALS['certloom_media']['upload_calls']++;
	if ( absint($GLOBALS['certloom_media']['fail_upload_call']) === absint($GLOBALS['certloom_media']['upload_calls']) ) {
		return array('file'=>'','url'=>'','error'=>'synthetic upload failure');
	}
	$path=$GLOBALS['certloom_upload_dir'].'/'.uniqid('import-',true).'-'.sanitize_file_name($filename);
	if ( false === file_put_contents($path,$bytes) ) { return array('file'=>'','url'=>'','error'=>'write failed'); }
	return array('file'=>$path,'url'=>'https://dest.test/uploads/'.basename($path),'error'=>false);
}
function wp_insert_attachment($attachment,$file,$parent=0,$wp_error=false){
	$id=$GLOBALS['certloom_media']['next_id']++;
	$GLOBALS['certloom_media']['files'][$id]=(string)$file;
	$GLOBALS['certloom_media']['mimes'][$id]=(string)($attachment['post_mime_type']??'');
	$GLOBALS['certloom_media']['urls'][$id]='https://dest.test/uploads/'.basename((string)$file);
	$GLOBALS['certloom_media']['url_to_id'][$GLOBALS['certloom_media']['urls'][$id]]=$id;
	return $id;
}
function wp_generate_attachment_metadata($id,$file){ return array('file'=>basename((string)$file)); }
function wp_update_attachment_metadata($id,$metadata){ return true; }
function wp_get_attachment_url($id){ return $GLOBALS['certloom_media']['urls'][absint($id)] ?? ''; }
function wp_delete_attachment($id,$force=false){
	$id=absint($id); $GLOBALS['certloom_media']['deleted'][]=$id;
	$file=$GLOBALS['certloom_media']['files'][$id]??'';
	if($file && is_file($file)){ unlink($file); }
	if(isset($GLOBALS['certloom_media']['urls'][$id])){ unset($GLOBALS['certloom_media']['url_to_id'][$GLOBALS['certloom_media']['urls'][$id]]); }
	unset($GLOBALS['certloom_media']['files'][$id],$GLOBALS['certloom_media']['mimes'][$id],$GLOBALS['certloom_media']['urls'][$id]);
	return true;
}
function wp_delete_file($file){ if(is_file($file)){unlink($file);} }

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Design\PortableAssetManifest;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\TemplatePackage;
use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailDesignPackage;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Media\PortableDesignAssets;

$checks=0;
function pm_ok($ok,$message){ global $checks; if(!$ok){ throw new RuntimeException($message); } $checks++; }
function pm_same($expected,$actual,$message){ pm_ok($expected===$actual,$message.' expected '.var_export($expected,true).' got '.var_export($actual,true)); }
function pm_error($value,$code,$message){ pm_ok(is_wp_error($value)&&$code===$value->get_error_code(),$message.' got '.(is_wp_error($value)?$value->get_error_code():gettype($value))); }
function pm_sorted_hash(array $value){
	$sort=function($v) use (&$sort){ if(!is_array($v)){return $v;} $is_list=array_keys($v)===range(0,count($v)-1); if(!$is_list){ksort($v,SORT_STRING);} foreach($v as $k=>$item){$v[$k]=$sort($item);} return $v;};
	return hash('sha256',wp_json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function pm_asset_descriptor($bytes,$mime='image/png',$filename='image.png'){
	$sha=hash('sha256',$bytes);
	return array('key'=>'media-'.substr($sha,0,24),'descriptor'=>array('filename'=>$filename,'mime'=>$mime,'sha256'=>$sha,'size'=>strlen($bytes),'data'=>base64_encode($bytes)));
}

// Minimal deterministic PNG-like bytes. The package contract intentionally verifies
// the file signature, byte count and SHA-256 rather than relying on an extension.
$png="\x89PNG\r\n\x1a\n" . str_repeat("\x00",32);
$source=sys_get_temp_dir().'/certloom-source-'.getmypid().'.png';
file_put_contents($source,$png);
$GLOBALS['certloom_media']['files'][11]=$source;
$GLOBALS['certloom_media']['mimes'][11]='image/png';
$GLOBALS['certloom_media']['urls'][11]='https://source.test/uploads/source.png';
$GLOBALS['certloom_media']['url_to_id'][$GLOBALS['certloom_media']['urls'][11]]=11;

$manifest=new PortableAssetManifest();
$media=new PortableDesignAssets($manifest);

// Certificate: repeat use of the same attachment must produce one content-addressed package asset.
$layout=array('elements'=>array(
	array('asset'=>'attachment:11','type'=>'image'),
	array('asset'=>'attachment:11','type'=>'logo'),
));
$portable=$media->prepare_certificate_export($layout);
pm_ok(is_array($portable),'certificate local media exports');
pm_same(1,count($portable['assets']),'repeated certificate attachment is deduplicated by content hash');
$asset_key=array_key_first($portable['assets']);
pm_same('asset:'.$asset_key,$portable['layout']['elements'][0]['asset'],'first certificate reference becomes package asset');
pm_same('asset:'.$asset_key,$portable['layout']['elements'][1]['asset'],'second certificate reference reuses same package asset');
$installed=$media->install_certificate_assets($portable['layout'],$portable['assets']);
pm_ok(is_array($installed),'certificate package asset installs');
pm_same(1,count($installed['created_ids']),'certificate import installs one destination attachment for one content asset');
$new_certificate_id=$installed['created_ids'][0];
pm_ok(11!==$new_certificate_id,'certificate import never reuses the source-site attachment identity');
pm_same('attachment:'.$new_certificate_id,$installed['layout']['elements'][0]['asset'],'certificate import rewrites first package reference to destination attachment');
pm_same('attachment:'.$new_certificate_id,$installed['layout']['elements'][1]['asset'],'certificate import rewrites duplicate package reference consistently');
$media->cleanup($installed['created_ids']);

// Email: package a site-local image, import it through the real repository, and prove the URL is destination-local.
$compiler=new EmailDesignCompiler();
$email_design=array(
	'name'=>'Portable media email','purpose'=>EmailDesignPurposes::ISSUED,'preheader'=>'Portable','subject'=>'Your credential','text_body'=>'',
	'blocks'=>array(
		array('type'=>'heading','text'=>'Credential ready','level'=>1),
		array('type'=>'image','url'=>'https://source.test/uploads/source.png','alt'=>'Credential seal'),
		array(
			'type'=>'container','direction'=>'horizontal','gap'=>12,'background_image'=>'https://source.test/uploads/source.png',
			'children'=>array(
				array('type'=>'container','direction'=>'vertical','basis'=>45,'children'=>array(array('type'=>'image','url'=>'https://source.test/uploads/source.png','alt'=>'Nested seal'))),
				array('type'=>'container','direction'=>'vertical','basis'=>55,'children'=>array(array('type'=>'text','html'=>'<p>Nested portable content</p>'))),
			),
		),
	),
	'theme'=>EmailDesignCompiler::default_theme(),'active'=>1,'source'=>'customer',
);
$email_package=(new EmailDesignPackage($compiler,$manifest))->build($email_design,'1.6.99-qa.23');
pm_ok(is_array($email_package),'email local media exports');
pm_same(1,count($email_package['assets']),'email package deduplicates top-level, nested and Container-background references to one local Media Library image');
pm_ok(0===strpos((string)$email_package['design']['blocks'][1]['url'],'asset:media-'),'email package replaces source-site URL with content-addressed asset reference');
pm_ok(0===strpos((string)$email_package['design']['blocks'][2]['background_image'],'asset:media-'),'email package rewrites Container background image as a portable asset');
pm_ok(0===strpos((string)$email_package['design']['blocks'][2]['children'][0]['children'][0]['url'],'asset:media-'),'email package rewrites recursively nested image assets');
pm_ok(false===strpos(wp_json_encode($email_package),'https://source.test/uploads/source.png'),'email package contains no source-site Media Library URL');

$GLOBALS['certloom_test_options']=array();
$GLOBALS['certloom_media']['deleted']=array();
$repo=new EmailTemplateCatalogRepository(null,$compiler);
$imported_id=$repo->import_package(wp_json_encode($email_package));
pm_ok(is_string($imported_id)&&''!==$imported_id,'email package imports through catalog repository');
$imported=$repo->get($imported_id);
pm_ok(is_array($imported),'imported email design is persisted');
pm_same(0,$imported['active'],'imported email design remains Unpublished until reviewed');
pm_same('import',$imported['source'],'imported email design source remains explicit');
$email_image=array_values(array_filter($imported['blocks'],fn($b)=>'image'===($b['type']??'')))[0]??array();
pm_ok(0===strpos((string)($email_image['url']??''),'https://dest.test/uploads/'),'email import rewrites local image to destination Media Library URL');
pm_ok(false===strpos((string)($email_image['url']??''),'source.test'),'email import never retains source-site local media URL');
$dest_id=attachment_url_to_postid((string)$email_image['url']);
pm_ok($dest_id>=1001,'rewritten email image resolves to a newly installed destination attachment');
pm_ok(!in_array($dest_id,$GLOBALS['certloom_media']['deleted'],true),'successful email import retains its installed Media Library asset');
$email_container=array_values(array_filter($imported['blocks'],fn($b)=>'container'===($b['type']??'')))[0]??array();
pm_ok(0===strpos((string)($email_container['background_image']??''),'https://dest.test/uploads/'),'email import rewrites Container background to destination Media Library URL');
pm_same((string)$email_image['url'],(string)($email_container['background_image']??''),'duplicate media references resolve to the same destination URL');
pm_same((string)$email_image['url'],(string)($email_container['children'][0]['children'][0]['url']??''),'nested Container image resolves to the same installed destination asset');

// Persistence failure must clean up media installed earlier in the import transaction.
$GLOBALS['certloom_test_options']=array();
$GLOBALS['certloom_media']['deleted']=array();
$GLOBALS['certloom_media']['fail_update_option']=true;
$failed=$repo->import_package(wp_json_encode($email_package));
pm_error($failed,'certloom_email_template_catalog_save_failed','email import reports final catalog persistence failure');
pm_ok(!empty($GLOBALS['certloom_media']['deleted']),'email import removes newly installed attachments when design persistence fails');
foreach($GLOBALS['certloom_media']['deleted'] as $deleted_id){ pm_ok(!isset($GLOBALS['certloom_media']['files'][$deleted_id]),'failed import leaves no created attachment file registered'); }
$GLOBALS['certloom_media']['fail_update_option']=false;

// Integrity and MIME-spoof failures.
$asset=pm_asset_descriptor($png);
$bad=$asset['descriptor'];
$bad['data']=base64_encode($png.'tamper');
pm_error($manifest->validate(array($asset['key']=>$bad)),'certloom_design_asset_integrity','tampered embedded bytes fail integrity');
$spoof=pm_asset_descriptor($png,'image/jpeg','image.jpg');
pm_error($manifest->validate(array($spoof['key']=>$spoof['descriptor'])),'certloom_design_asset_type_mismatch','MIME spoof fails signature check');

// Count, individual-size, and aggregate-size boundaries.
$many=array();
for($i=0;$i<PortableAssetManifest::MAX_ASSETS+1;$i++){
	$a=pm_asset_descriptor("\x89PNG\r\n\x1a\n".'asset-'.$i,'image/png','asset-'.$i.'.png');
	$many[$a['key']]=$a['descriptor'];
}
pm_error($manifest->validate($many),'certloom_design_assets_count','too many embedded assets fail closed');
$oversize_bytes="\x89PNG\r\n\x1a\n".str_repeat('x',PortableAssetManifest::MAX_ASSET_BYTES+1);
$a=pm_asset_descriptor($oversize_bytes,'image/png','large.png');
pm_error($manifest->validate(array($a['key']=>$a['descriptor'])),'certloom_design_asset_metadata','oversize single asset fails closed');
$total=array();
for($i=0;$i<4;$i++){
	$bytes="\x89PNG\r\n\x1a\n".str_repeat(chr(65+$i),3145728); // ~3 MiB each, each under the per-file cap.
	$a=pm_asset_descriptor($bytes,'image/png','total-'.$i.'.png'); $total[$a['key']]=$a['descriptor'];
}
// Make the aggregate strictly exceed 12 MiB while each individual asset stays below 4 MiB.
$bytes="\x89PNG\r\n\x1a\n".str_repeat('z',1024);
$a=pm_asset_descriptor($bytes,'image/png','total-extra.png'); $total[$a['key']]=$a['descriptor'];
pm_error($manifest->validate($total),'certloom_design_assets_total_size','aggregate embedded-media size fails closed');

// Exact manifest/reference matching: missing and orphan assets are both rejected.
$email_decoded=(new EmailDesignPackage($compiler,$manifest))->decode(wp_json_encode($email_package));
pm_ok(is_array($email_decoded),'baseline v2 email package decodes before mismatch mutations');
$missing=$email_package;
$missing['assets']=array();
$missing['sha256']=pm_sorted_hash(array('design'=>$missing['design'],'assets'=>$missing['assets']));
pm_error((new EmailDesignPackage($compiler,$manifest))->decode(wp_json_encode($missing)),'certloom_email_design_package_asset_mismatch','email package missing referenced embedded asset fails closed');
$orphan=$email_package;
$extra=pm_asset_descriptor("\x89PNG\r\n\x1a\n".'orphan','image/png','orphan.png');
$orphan['assets'][$extra['key']]=$extra['descriptor'];
$orphan['sha256']=pm_sorted_hash(array('design'=>$orphan['design'],'assets'=>$orphan['assets']));
pm_error((new EmailDesignPackage($compiler,$manifest))->decode(wp_json_encode($orphan)),'certloom_email_design_package_asset_mismatch','email package orphan embedded asset fails closed');

// Legacy Email v1: no-image packages remain compatible; image-bearing packages fail with re-export guidance.
$v1_design=array(
	'name'=>'Legacy text email','purpose'=>EmailDesignPurposes::ISSUED,'preheader'=>'Legacy','subject'=>'Legacy','text_body'=>'Legacy',
	'blocks'=>array(array('type'=>'text','html'=>'<p>Legacy</p>')),'theme'=>EmailDesignCompiler::default_theme(),
);
$v1=array('format'=>EmailDesignPackage::FORMAT,'format_version'=>1,'created_by'=>array('product'=>'Certloom','plugin_version'=>'1.6.98'),'design'=>$v1_design,'sha256'=>pm_sorted_hash($v1_design));
pm_ok(is_array((new EmailDesignPackage($compiler,$manifest))->decode(wp_json_encode($v1))),'legacy v1 email package without images remains import-compatible');
$v1_image=$v1_design;
$v1_image['blocks'][]=array('type'=>'image','url'=>'https://legacy.test/uploads/image.png','alt'=>'Legacy');
$v1['design']=$v1_image; $v1['sha256']=pm_sorted_hash($v1_image);
pm_error((new EmailDesignPackage($compiler,$manifest))->decode(wp_json_encode($v1)),'certloom_email_design_package_legacy_media','legacy v1 email package with image fails rather than guessing portability');

// Legacy Certificate v1: no-media packages remain compatible; attachment-based packages fail closed.
$template_package=new TemplatePackage(new LayoutValidator(),new CanonicalJson(),$manifest);
$plain_layout=TemplateDefaults::blank_a4_landscape();
$built=$template_package->build(array('machine_key'=>'portable-plain','name'=>'Portable plain','description'=>''),array('layout'=>$plain_layout),'1.6.99-qa.23');
pm_ok(is_array($built),'baseline certificate package builds');
$v1_cert=$built; unset($v1_cert['assets']); $v1_cert['format_version']=1;
pm_ok(is_array($template_package->decode(wp_json_encode($v1_cert))),'legacy v1 certificate package without local media remains import-compatible');
$media_layout=$plain_layout;
$media_layout['elements'][]=array('id'=>'11111111-1111-4111-8111-111111111111','type'=>'image','x_mm'=>10,'y_mm'=>10,'width_mm'=>60,'height_mm'=>40,'z'=>1,'rotation_deg'=>0,'opacity'=>1,'asset'=>'attachment:11','fit'=>'contain');
$normalized=(new LayoutValidator())->normalize($media_layout);
$v1_cert_media=$v1_cert;
$v1_cert_media['revision']['layout']=$normalized;
$v1_cert_media['revision']['layout_sha256']=(new CanonicalJson())->hash($normalized);
pm_error($template_package->decode(wp_json_encode($v1_cert_media)),'certloom_template_import_legacy_media','legacy v1 certificate package with site-local media fails rather than binding to destination attachment IDs');

// Partial upload failure cleans earlier created attachments inside the media installer.
$GLOBALS['certloom_media']['deleted']=array();
$GLOBALS['certloom_media']['upload_calls']=0;
$GLOBALS['certloom_media']['fail_upload_call']=2;
$bytes1="\x89PNG\r\n\x1a\n".'one'; $bytes2="\x89PNG\r\n\x1a\n".'two';
$a1=pm_asset_descriptor($bytes1,'image/png','one.png'); $a2=pm_asset_descriptor($bytes2,'image/png','two.png');
$blocks=array(array('type'=>'image','url'=>'asset:'.$a1['key'],'alt'=>'One'),array('type'=>'image','url'=>'asset:'.$a2['key'],'alt'=>'Two'));
$partial=$media->install_email_assets($blocks,array($a1['key']=>$a1['descriptor'],$a2['key']=>$a2['descriptor']));
pm_error($partial,'certloom_design_asset_upload_failed','partial media upload failure stops import');
pm_ok(!empty($GLOBALS['certloom_media']['deleted']),'partial media upload failure cleans attachments created before the failure');
$GLOBALS['certloom_media']['fail_upload_call']=0;

@unlink($source);
foreach(glob($GLOBALS['certloom_upload_dir'].'/*')?:array() as $file){ @unlink($file); }
@rmdir($GLOBALS['certloom_upload_dir']);

echo "PASS  portable design packages carry local PNG/JPEG media by content hash and rewrite it to destination Media Library identities\n";
echo "PASS  corrupt, spoofed, mismatched, oversized and unsafe legacy media packages fail closed; failed imports clean created assets\n\n";
echo $checks." checks, 0 failures\n";
