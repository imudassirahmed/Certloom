<?php
/** DSN-08 Phase 4.7 signer snapshot + dynamic signature-source architecture gate. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_attr__($v){return esc_attr($v);} function esc_html__($v){return esc_html($v);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function get_bloginfo($k=''){return 'Certloom Test Site';}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Issuance\MergeSnapshotBuilder;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Infrastructure\Rendering\TemplatePreviewAssetResolver;
use Certloom\Infrastructure\Rendering\TemplatePreviewService;

$pass=0;$fail=0;
function dsn8s($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
function dsn8sthrows($fn,$label,$contains=''){try{$fn();dsn8s(false,$label);}catch(Throwable $e){dsn8s(''===$contains||false!==strpos($e->getMessage(),$contains),$label);}}
function dsn8ssig($version=4,$dynamic=true){$el=array('id'=>'99999999-9999-4999-8999-999999999999','type'=>'signature','x_mm'=>10,'y_mm'=>10,'width_mm'=>70,'height_mm'=>25,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'fit'=>'contain');if($dynamic){$el['source']='signer.signature';}else{$el['asset']='attachment:77';}return array('_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>$version),'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),'elements'=>array($el));}
function dsn8sissuer($type='logo',$source='issuer.logo',$version=4){$el=array('id'=>'99999999-9999-4999-8999-999999999998','type'=>$type,'x_mm'=>10,'y_mm'=>10,'width_mm'=>40,'height_mm'=>24,'z'=>0,'rotation_deg'=>0,'opacity'=>1,'fit'=>'contain','source'=>$source);return array('_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>$version),'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),'elements'=>array($el));}

final class Dsn8SignerAssetResolver implements LocalAssetResolverInterface {
	private $path; public $references=array(); public function __construct($path){$this->path=$path;}
	public function resolve($reference){$this->references[]=(string)$reference;return array('path'=>$this->path,'sha256'=>hash_file('sha256',$this->path),'mime'=>'image/png','size'=>filesize($this->path));}
}
final class Dsn8RejectingResolver implements LocalAssetResolverInterface { public function resolve($reference){throw new RuntimeException('delegate:'.(string)$reference);} }

$v=new LayoutValidator(); $c=new CanonicalJson(); $dynamic=dsn8ssig(4,true); $norm=$v->normalize($dynamic);
dsn8s('signer.signature'===$norm['elements'][0]['source']&&!isset($norm['elements'][0]['asset']),'schema 4 accepts exactly the immutable credential signer signature source');
foreach(array(1,2,3) as $old){$bad=dsn8ssig($old,true);dsn8sthrows(function()use($v,$bad){$v->normalize($bad);},'schema '.$old.' rejects dynamic signer signatures','require certloom-layout v4');}
$both=$dynamic;$both['elements'][0]['asset']='attachment:77';dsn8sthrows(function()use($v,$both){$v->normalize($both);},'signature cannot mix fixed asset and credential Signer source','either a fixed local asset');
$bad=$dynamic;$bad['elements'][0]['source']='recipient.signature';dsn8sthrows(function()use($v,$bad){$v->normalize($bad);},'unsupported dynamic signature sources fail closed','Unsupported signature source');

$issuerLogo=dsn8sissuer('logo','issuer.logo',4);$issuerSeal=dsn8sissuer('image','issuer.seal',4);
$logoNorm=$v->normalize($issuerLogo);$sealNorm=$v->normalize($issuerSeal);
dsn8s('issuer.logo'===($logoNorm['elements'][0]['source']??'')&&!isset($logoNorm['elements'][0]['asset']),'schema 4 logo accepts immutable issuer.logo source without a fixed asset');
dsn8s('issuer.seal'===($sealNorm['elements'][0]['source']??'')&&!isset($sealNorm['elements'][0]['asset']),'schema 4 image accepts immutable issuer.seal source without a fixed asset');
foreach(array(1,2,3) as $old){$legacy=dsn8sissuer('logo','issuer.logo',$old);dsn8sthrows(function()use($v,$legacy){$v->normalize($legacy);},'schema '.$old.' rejects dynamic issuer media','certloom-layout v4');}
$bothIssuer=$issuerLogo;$bothIssuer['elements'][0]['asset']='attachment:81';dsn8sthrows(function()use($v,$bothIssuer){$v->normalize($bothIssuer);},'issuer media cannot mix a dynamic source with a fixed asset','either a fixed local asset');
$badIssuer=dsn8sissuer('logo','recipient.photo',4);dsn8sthrows(function()use($v,$badIssuer){$v->normalize($badIssuer);},'unsupported dynamic image source fails closed','Unsupported issuer media source');

$expected=array(1=>'9d26ca3c2946736e955e895e18a29832cc8e6156982f47d4957b73b5abae06c8',2=>'ddf5c94237614cd8fbd18ef247ac3e870c7ada3806fd6b4fdf88c9c583316273',3=>'8c1b66671a96c80abd3982cd96396afb85528e2535663f11ffac4917c6113c05');
foreach($expected as $version=>$hash){dsn8s($hash===$c->hash($v->normalize(dsn8ssig($version,false))),'schema '.$version.' static-signature historical canonical hash remains unchanged');}

$builder=new MergeSnapshotBuilder(); $recipient=array('display_name'=>'Alexandria Noor Rahman','first_name'=>'Alexandria','last_name'=>'Rahman','email'=>'alex@example.invalid'); $program=array('title'=>'Professional Credential Program','code'=>'PCP'); $issuer=array('name'=>'Northern Learning Institute','settings'=>array(),'logo_attachment_id'=>81,'seal_attachment_id'=>82); $credential=array('display_number'=>'CERT-001','public_id'=>'PUBLIC-001','valid_from'=>'2026-09-10','expires_at'=>'2028-09-10','verification_url'=>'https://example.invalid/verify/PUBLIC-001');
$required=$builder->required_tokens($dynamic);dsn8s(in_array('signer.signature_asset',$required,true),'dynamic signature adds signer.signature_asset to immutable merge requirements');
$issuerRequired=$builder->required_tokens(array('_meta'=>array('schema'=>LayoutSchema::NAME,'schema_version'=>4),'page'=>$issuerLogo['page'],'elements'=>array($issuerLogo['elements'][0],$issuerSeal['elements'][0])));dsn8s(in_array('issuer.logo_asset',$issuerRequired,true)&&in_array('issuer.seal_asset',$issuerRequired,true),'dynamic issuer media adds immutable issuer logo and seal merge requirements');
$missing=$builder->build($recipient,$program,$issuer,$credential,$dynamic,array(),null);dsn8s(is_wp_error($missing)&&'certloom_manual_issue_merge_missing'===$missing->get_error_code(),'issuance fails closed when selected/resolved Signer has no signature attachment');
$signer=array('id'=>9,'display_name'=>'Dr. Maya Rahman','title'=>'Authorized Signatory','email'=>'maya@example.invalid','signature_attachment_id'=>77,'updated_at'=>'2026-09-10 00:00:00');
$merge=$builder->build($recipient,$program,$issuer,$credential,$dynamic,array(),$signer);dsn8s(!is_wp_error($merge),'signer-backed merge snapshot builds when immutable signature attachment is available');
dsn8s('Dr. Maya Rahman'===$merge['signer.name']&&'Authorized Signatory'===$merge['signer.title']&&'maya@example.invalid'===$merge['signer.email'],'signer text fields enter the same immutable merge snapshot');
dsn8s('attachment:77'===$merge['signer.signature_asset'],'signer signature becomes an immutable local attachment reference, never a remote URL');
dsn8s('attachment:81'===($merge['issuer.logo_asset']??'')&&'attachment:82'===($merge['issuer.seal_asset']??''),'issuer logo and seal become immutable local attachment references in the same merge snapshot');
$asset=__DIR__.'/../admin/assets/certloom-preview-signer-signature.png';$assets=new Dsn8SignerAssetResolver($asset);
$issuerResolved=(new LayoutResolver())->resolve($issuerLogo,$merge,'https://example.invalid/verify/PUBLIC-001',$assets);dsn8s('attachment:81'===($issuerResolved['elements'][0]['asset']['reference']??'')&&!isset($issuerResolved['elements'][0]['source']),'LayoutResolver consumes issuer.logo source before the renderer boundary');
$issuerMissing=$merge;unset($issuerMissing['issuer.logo_asset']);dsn8sthrows(function()use($issuerLogo,$issuerMissing,$assets){(new LayoutResolver())->resolve($issuerLogo,$issuerMissing,'https://example.invalid/verify/PUBLIC-001',$assets);},'renderer resolution fails closed when issuer logo snapshot media is absent','does not provide the media required');

$assets->references=array();$resolved=(new LayoutResolver())->resolve($dynamic,$merge,'https://example.invalid/verify/PUBLIC-001',$assets);$sig=$resolved['elements'][0];
dsn8s(!isset($sig['source'])&&is_array($sig['asset']),'LayoutResolver consumes signer source before renderer boundary');
dsn8s('attachment:77'===$sig['asset']['reference']&&$assets->references===array('attachment:77'),'resolver uses only the snapshotted signer attachment reference');
dsn8s('image/png'===$sig['asset']['mime']&&320===$sig['asset']['width_px']&&96===$sig['asset']['height_px'],'signer signature passes the existing raster AssetInspector boundary');
$missingResolve=$merge;unset($missingResolve['signer.signature_asset']);dsn8sthrows(function()use($dynamic,$missingResolve,$assets){(new LayoutResolver())->resolve($dynamic,$missingResolve,'https://example.invalid/verify/PUBLIC-001',$assets);},'renderer resolution fails closed if signer signature snapshot value is absent','does not provide a usable signature asset');

$samples=new TemplatePreviewSamples();$sample=$samples->snapshot_for_layout($dynamic,TemplatePreviewSamples::STANDARD);dsn8s(TemplatePreviewAssetResolver::SIGNER_SIGNATURE===$sample['signer.signature_asset'],'synthetic Designer preview supplies a deterministic preview-only signer signature key');
$issuerSample=$samples->snapshot_for_layout($issuerLogo,TemplatePreviewSamples::STANDARD);dsn8s(TemplatePreviewAssetResolver::ISSUER_LOGO===($issuerSample['issuer.logo_asset']??''),'synthetic Designer preview supplies deterministic preview-only issuer logo media');
$previewResolver=new TemplatePreviewAssetResolver(new Dsn8RejectingResolver());$previewAsset=$previewResolver->resolve(TemplatePreviewAssetResolver::SIGNER_SIGNATURE);dsn8s(is_file($previewAsset['path'])&&'image/png'===$previewAsset['mime'],'Template Preview resolves only the bundled controlled signer placeholder for its private asset key');
dsn8sthrows(function()use($previewResolver){$previewResolver->resolve('attachment:77');},'Template Preview resolver still delegates real WordPress attachment references','delegate:attachment:77');
$template=array('current_revision_id'=>10,'current_revision'=>array('revision_number'=>1,'layout'=>$dynamic,'layout_sha256'=>$c->hash($dynamic)));$preview=(new TemplatePreviewService())->build($template,'current',TemplatePreviewSamples::STANDARD);dsn8s(!is_wp_error($preview),'canonical Template Preview can render a schema 4 credential-Signer signature without requiring a real credential');
dsn8s('signer.signature_asset'===array_key_first($preview['merge_snapshot'])&&isset($preview['render_plan']['operations'][0]['image']),'Template Preview keeps the synthetic signer asset confined to preview merge data and resolves it before Render Plan');

$contract=ElementRegistry::browser_contract();dsn8s('signature'===$contract['types']['signature']['content_group']&&'image'===$contract['types']['signature']['render_operation'],'browser registry exposes a dedicated signature content group while reusing the image render operation');
$root=dirname(__DIR__);$js=file_get_contents($root.'/admin/assets/designer.js');$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');$service=file_get_contents($root.'/src/Application/Issuance/ManualIssuanceService.php');$repo=file_get_contents($root.'/src/Infrastructure/Repository/SignerRepository.php');
dsn8s(false!==strpos($js,"'signature' === palette.type && schemaVersion >= 4")&&false!==strpos($js,"proposed.source = 'signer.signature'"),'schema 4 Insert defaults Signature to credential Signer and supports canonical source switching');
dsn8s(false!==strpos($js,'delete proposed.source')&&false!==strpos($js,'mediaButtons'),'fixed WordPress signature remains an explicit reversible alternative');
dsn8s(false!==strpos($js,'signatureSourceButtons')&&false!==strpos($js,'immutable credential Signer snapshot'),'Properties exposes signer-source behavior with truthful immutability copy');
dsn8s(false!==strpos($admin,'data-certloom-designer-issuer-media-source="logo"')&&false!==strpos($admin,'data-certloom-designer-issuer-media-source="seal"')&&false!==strpos($admin,'Use credential Issuer logo')&&false!==strpos($admin,'Use credential Issuer seal'),'Properties exposes immutable credential Issuer logo/seal authoring controls');
dsn8s(false!==strpos($service,'const SNAPSHOT_VERSION = 4')&&false!==strpos($service,"'signer' => \$this->signer_snapshot"),'credential immutable snapshot v4 retains signer identity and resolution evidence while adding repeating-data evidence');
dsn8s(false!==strpos($service,'signer_fingerprint')&&false!==strpos($service,"'signer_resolution'"),'review fingerprint includes signer state so changed signer evidence invalidates stale review');
dsn8s(false!==strpos($repo,'signer_updated_at')&&false!==strpos($repo,"CASE a.scope_type WHEN 'batch' THEN 1 WHEN 'program' THEN 2 WHEN 'issuer' THEN 3 ELSE 4 END"),'signer repository exposes profile timestamp and deterministic Batch→Program→Issuer→global hierarchy');
dsn8s(!file_exists($root.'/resources/renderer/accepted.json')&&!file_exists($root.'/resources/domain/accepted.json'),'signer tranche manufactures no renderer/domain production authority manifests');

echo "\n$pass DSN-08 signer-source checks, $fail failures\n";exit($fail?1:0);
