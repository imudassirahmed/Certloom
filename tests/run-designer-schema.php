<?php
/** DSN-05 versioned layout-schema upgrader, barcode and compatibility gate. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_attr__($v){return esc_attr($v);} function esc_html__($v){return esc_html($v);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} 
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutSchemaUpgrader;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Restore\DomainRestoreRowValidator;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\TemplatePackage;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Infrastructure\Rendering\LocalCode128Encoder;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Database\SchemaManager;

final class Dsn5NoAssets implements LocalAssetResolverInterface { public function resolve( $reference ){ throw new RuntimeException('No assets expected.'); } }
$pass=0;$fail=0; function dsn5($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
function dsn5throws($fn,$label){try{$fn();dsn5(false,$label);}catch(Throwable $e){dsn5(true,$label);}}

$validator=new LayoutValidator(); $upgrader=new LayoutSchemaUpgrader($validator); $canonical=new CanonicalJson();
dsn5(LayoutSchema::supported_versions()===array(1,2,3),'layout compatibility boundary retains v1/v2 and adds v3');
dsn5(LayoutSchema::element_types(LayoutSchema::VERSION_V1)===array('text','merge','image','logo','signature','shape','line','qr'),'v1 primitive inventory remains frozen');
dsn5(LayoutSchema::element_types(LayoutSchema::VERSION_V2)===array('text','merge','image','logo','signature','shape','line','qr','barcode'),'v2 adds barcode without changing v1 semantics');

$v1=(new PresetCatalog())->get('classic-a4-landscape')['layout'];
dsn5(1===$v1['_meta']['schema_version'],'factory preset stays explicitly v1');
$v1hash=$canonical->hash($validator->normalize($v1));
$v2=$upgrader->upgrade($v1,2);
dsn5(2===$v2['_meta']['schema_version'],'explicit upgrader produces v2');
dsn5($v2===$upgrader->upgrade($v2,2),'v2 upgrade is idempotent');
$v2again=$upgrader->upgrade($v1,2); dsn5($canonical->hash($v2)===$canonical->hash($v2again),'v1 to v2 upgrade is deterministic');
$withoutMeta=$v2; $withoutMeta['_meta']['schema_version']=1; dsn5($canonical->hash($withoutMeta)===$v1hash,'additive v2 upgrade preserves existing v1 content semantics');
dsn5throws(function()use($upgrader,$v2){$upgrader->upgrade($v2,1);},'schema downgrade is rejected');
$blank=TemplateDefaults::blank_a4_landscape(); dsn5(3===$blank['_meta']['schema_version'],'new blank templates author against latest v3 schema while v2 remains readable');
$all=(new PresetCatalog())->all(); $presetV1=0; foreach($all as $preset){ if(1===(int)$preset['layout']['_meta']['schema_version']){$presetV1++;} }
dsn5(24===count($all)&&24===$presetV1,'all 24 factory layouts remain stable v1 references');

$barcode=array('id'=>'99999999-9999-4999-8999-999999999999','type'=>'barcode','x_mm'=>80,'y_mm'=>160,'width_mm'=>120,'height_mm'=>18,'z'=>99,'rotation_deg'=>0,'opacity'=>1,'source'=>'credential.display_number','symbology'=>'code128','quiet_zone_modules'=>10);
$v2barcode=$v2; $v2barcode['elements'][]=$barcode;
try{$norm=$validator->normalize($v2barcode);$ok=true;}catch(Throwable $e){$ok=false;echo "INFO  validator: {$e->getMessage()}\n";} dsn5($ok,'v2 barcode validates through authoritative LayoutValidator');
$v1barcode=$v1; $v1barcode['elements'][]=$barcode; dsn5throws(function()use($validator,$v1barcode){$validator->normalize($v1barcode);},'v1 refuses barcode primitive');
$bad=$v2barcode; $bad['elements'][count($bad['elements'])-1]['source']='credential.public_id'; dsn5throws(function()use($validator,$bad){$validator->normalize($bad);},'barcode cannot create alternate public-ID identity');
$bad=$v2barcode; $bad['elements'][count($bad['elements'])-1]['symbology']='qr'; dsn5throws(function()use($validator,$bad){$validator->normalize($bad);},'v2 currently accepts Code 128 symbology only');
$bad=$v2barcode; $bad['elements'][count($bad['elements'])-1]['quiet_zone_modules']=9; dsn5throws(function()use($validator,$bad){$validator->normalize($bad);},'barcode quiet zone has scanner-safe lower bound');

$barcodeOnly=$v2barcode; $barcodeOnly['elements']=array($barcode);
$resolver=new LayoutResolver(null,null,null,null,new LocalCode128Encoder());
$resolved=$resolver->resolve($barcodeOnly,array('credential.display_number'=>'CERT-2026-001'),'https://example.test/verify/CERT-2026-001',new Dsn5NoAssets());
$resolvedBarcodes=array_values(array_filter($resolved['elements'],function($e){return 'barcode'===($e['type']??'');}));
dsn5(1===count($resolvedBarcodes)&&!empty($resolvedBarcodes[0]['modules']),'barcode resolves centrally to exact binary modules');
dsn5('certloom-local-code128'===$resolvedBarcodes[0]['encoder']['id'],'resolved barcode carries exact local encoder identity');
dsn5(!isset($resolvedBarcodes[0]['source'])&&'CERT-2026-001'===$resolvedBarcodes[0]['data'],'resolved barcode carries immutable display number rather than unresolved token');
$resolverNoBarcode=new LayoutResolver(); dsn5throws(function()use($resolverNoBarcode,$barcodeOnly){$resolverNoBarcode->resolve($barcodeOnly,array('credential.display_number'=>'CERT-2026-001'),'https://example.test/verify/CERT-2026-001',new Dsn5NoAssets());},'barcode-bearing layout fails closed without central encoder');

$plan=(new RenderPlanBuilder())->build($resolved); $barOps=array_values(array_filter($plan['operations'],function($op){return 'barcode'===($op['type']??'');}));
dsn5(1===count($barOps)&&!empty($barOps[0]['barcode']['dark_runs']),'Render Plan owns exact Code 128 bar placement');
dsn5($barOps[0]['barcode']['module_size_mm']>=0.19,'Render Plan enforces minimum physical barcode module width');
dsn5(10===$barOps[0]['barcode']['quiet_zone_modules'],'Render Plan preserves exact barcode quiet-zone modules');
$small=$resolved; foreach($small['elements'] as &$e){if('barcode'===$e['type']){$e['width_mm']=20;}} unset($e); dsn5throws(function()use($small){(new RenderPlanBuilder())->build($small);},'physically over-dense barcode fails closed before renderer execution');
$preview=(new RenderPlanHtmlPreview())->present($plan); dsn5(false!==strpos($preview['html'],'certloom-template-preview-op-barcode')&&false!==strpos($preview['html'],'fill="#fff"'),'browser Preview consumes barcode Render Plan with scanner-safe white background');

$package=new TemplatePackage(); $built=$package->build(array('machine_key'=>'schema-v2','name'=>'Schema V2','description'=>''),array('layout'=>$v2barcode),'1.6.60'); dsn5(!is_wp_error($built)&&2===$built['revision']['layout_schema_version'],'template export records actual v2 schema version');
$encoded=$package->encode($built); $decoded=$package->decode($encoded); dsn5(!is_wp_error($decoded)&&2===$decoded['layout']['_meta']['schema_version'],'v2 template package round-trips');
$tampered=json_decode($encoded,true); $tampered['revision']['layout_schema_version']=1; $badPkg=$package->decode(json_encode($tampered)); dsn5(is_wp_error($badPkg)&&'certloom_template_import_schema_mismatch'===$badPkg->get_error_code(),'package metadata cannot lie about canonical layout schema version');

$restoreRow=array_fill_keys(SchemaManager::column_names('template_revisions'),null); $restoreRow['id']='900'; $restoreRow['template_id']='42'; $restoreRow['revision_number']='3'; $restoreRow['layout_schema']=LayoutSchema::NAME; $restoreRow['layout_schema_version']='2'; $restoreRow['layout_json']=$canonical->encode($validator->normalize($v2barcode)); $restoreRow['layout_sha256']=$canonical->hash($validator->normalize($v2barcode)); $restoreRow['source_type']='manual'; $restoreRow['created_by_user_id']='7'; $restoreRow['created_at']='2026-08-27 00:00:00';
$restoreValidator=new DomainRestoreRowValidator(); dsn5(true===$restoreValidator->validate('template_revisions',$restoreRow),'real restore row validator accepts canonical v2 Template revision');
$restoreMismatch=$restoreRow; $restoreMismatch['layout_schema_version']='1'; $restoreResult=$restoreValidator->validate('template_revisions',$restoreMismatch); dsn5(is_wp_error($restoreResult)&&'certloom_domain_restore_template_layout_schema_mismatch'===$restoreResult->get_error_code(),'real restore row validator rejects v2 bytes mislabeled as v1');

$root=dirname(__DIR__); $repo=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $restore=file_get_contents($root.'/src/Application/Restore/DomainRestoreRowValidator.php'); $html=file_get_contents($root.'/src/Infrastructure/Rendering/RenderPlanHtmlPreview.php'); $gd=file_get_contents($root.'/src/Infrastructure/Rendering/GdRasterRenderer.php'); $mpdf=file_get_contents($root.'/src/Infrastructure/Rendering/MpdfPdfRenderer.php');
dsn5(false!==strpos($repo,'upgrade_current_layout')&&false!==strpos($repo,'expected_revision_sha256'),'repository upgrade boundary requires exact revision/hash review proof');
dsn5(false!==strpos($repo,"layout_schema_version' => absint( \$layout['_meta']['schema_version']"),'repository persists actual normalized schema version instead of hardcoded v1');
dsn5(false!==strpos($repo,'certloom_template_stale_revision'),'schema upgrades preserve optimistic concurrency failure boundary');
dsn5(false!==strpos($admin,'certloom_template_layout_upgrade')&&false!==strpos($admin,'Upgrade draft to layout schema v%d'),'admin exposes explicit immutable upgrade to the current schema');
dsn5(false!==strpos($admin,'credential.display_number')&&false!==strpos($admin,'Code 128'),'Designer barcode source and symbology are fixed to existing certificate identity');
dsn5(false!==strpos($js,"'barcode' === kind")&&false!==strpos($js,"schemaVersion < 2"),'browser authoring refuses barcode on v1 and enables it on v2+');
dsn5(false!==strpos($restore,'LayoutSchema::supports_version')&&false!==strpos($restore,'certloom_domain_restore_template_layout_schema_mismatch'),'backup restore accepts supported versions but rejects DB/layout metadata mismatch');
dsn5(false!==strpos($html,"case 'barcode'")&&false!==strpos($gd,"case 'barcode'")&&false!==strpos($mpdf,"case 'barcode'"),'all three presentation adapters consume the new Render Plan barcode operation');
dsn5(false!==strpos($mpdf,"draw_machine_code")&&false===strpos($mpdf,'private function draw_qr'),'mPDF QR and barcode share rotation-aware machine-code composition rather than direct QR rectangles');
dsn5(false!==strpos($html,'<rect x="0" y="0"')&&false!==strpos($gd,"'r'=>255")&&false!==strpos($mpdf,'fill="#fff"'),'HTML/GD/mPDF machine-code paths explicitly establish white scanner-safe backgrounds');
dsn5(!file_exists($root.'/resources/renderer/accepted.json'),'DSN-05 does not manufacture production renderer authority');

echo "\n$pass DSN-05 checks, $fail failures\n"; exit($fail?1:0);
