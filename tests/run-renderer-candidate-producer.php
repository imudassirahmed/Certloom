<?php
require_once __DIR__ . '/renderer-candidate/producer-lib.php';

use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Application\Rendering\RenderPlanBuilder;

$checks = 0;
function rcp_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { fwrite( STDERR, "FAIL  {$message}\n" ); exit( 1 ); } }
function rcp_throws( callable $fn ) { try { $fn(); } catch ( Throwable $error ) { return true; } return false; }
function rcp_rm( $path ) { if ( ! file_exists( $path ) && ! is_link( $path ) ) { return; } if ( is_dir( $path ) && ! is_link( $path ) ) { foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $name ) { rcp_rm( $path . '/' . $name ); } rmdir( $path ); } else { unlink( $path ); } }

final class RcpGoldenReplayRenderer implements RendererInterface {
    private $id; private $version; private $formats; private $context; private $root; private $plans; private $requirements;
    public function __construct( $id, $version, array $formats, array $context, $root, array $requirements = null ) { $this->id=$id; $this->version=$version; $this->formats=$formats; $this->context=$context; $this->root=$root; $this->plans=new RenderPlanBuilder(); $this->requirements=$requirements ?: array( 'php_min'=>'7.4', 'extensions'=>array(), 'classes'=>array(), 'functions'=>array() ); }
    public function id(){ return $this->id; }
    public function version(){ return $this->version; }
    public function formats(){ return $this->formats; }
    public function requirements(){ return $this->requirements; }
    public function context(){ return $this->context; }
    public function render( array $plan, $format ) {
        $hash=$this->plans->hash($plan); $manifest=json_decode(file_get_contents($this->root.'/tests/fixtures/rendering/render-plan-manifest.json'),true); $name='';
        foreach($manifest['fixtures'] as $fixture=>$facts){ if(hash_equals($facts['render_plan_sha256'],$hash)){ $name=$fixture; break; } }
        if(''===$name){ throw new RuntimeException('Golden replay test renderer received an unknown plan.'); }
        $path=$this->root.'/tests/fixtures/rendering/reference/'.$name.'/reference.'.strtolower($format);
        $bytes=file_get_contents($path); if(!is_string($bytes)||''===$bytes){ throw new RuntimeException('Golden replay artifact unavailable.'); } return $bytes;
    }
}


final class RcpFailAfterRenderer implements RendererInterface {
    private $inner; private $limit; private $calls=0;
    public function __construct(RendererInterface $inner,$limit){$this->inner=$inner;$this->limit=(int)$limit;}
    public function id(){return $this->inner->id();}
    public function version(){return $this->inner->version();}
    public function formats(){return $this->inner->formats();}
    public function requirements(){return $this->inner->requirements();}
    public function context(){return $this->inner->context();}
    public function render(array $plan,$format){$this->calls++;if($this->calls>$this->limit){throw new RuntimeException('Injected renderer failure after partial candidate output.');}return $this->inner->render($plan,$format);}
}

$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/certloom-candidate-producer-'.bin2hex(random_bytes(6));
mkdir($tmp,0700,true);
try {
    $gold=json_decode(file_get_contents(__DIR__.'/fixtures/rendering/reference/manifest.json'),true); $font_hash=$gold['font']['sha256'];
    $font_source=null;
    foreach(array('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/share/fonts/dejavu/DejaVuSans.ttf','/usr/local/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/local/share/fonts/dejavu/DejaVuSans.ttf') as $candidate){ if(is_file($candidate)&&hash_equals($font_hash,hash_file('sha256',$candidate))){ $font_source=$candidate; break; } }
    rcp_check(is_string($font_source),'Producer regression requires the exact frozen DejaVu Sans fixture font available in a supported local development-font path.');
    $font_root=$tmp.'/fonts'; mkdir($font_root,0700); copy($font_source,$font_root.'/'.$font_hash.'.ttf');

    $setup=certloom_render_plan_reference_setup();
    $contract=new RendererCandidateManifest(); $canonical=new CanonicalJson();
    $make_candidate=function($key,$id,$version,$formats)use($tmp,$contract){
        $source=$tmp.'/'.$key.'-source.txt'; $lock=$tmp.'/'.$key.'-lock.txt';
        file_put_contents($source,"source-only golden replay renderer fixture {$key}\n"); file_put_contents($lock,"fixture-lock {$key}\n");
        $candidate=array(
            'schema'=>RendererCandidateManifest::SCHEMA,'version'=>RendererCandidateManifest::VERSION,
            'renderer_id'=>$id,'renderer_version'=>$version,'formats'=>$formats,
            'implementation'=>array('package'=>'certloom-tests/'.$key,'package_version'=>'1.0.0','provenance_mode'=>'prefixed-package','namespace_prefix'=>'CertloomVendor\\Fixture\\'.('reference-pdf'===$key?'ReferencePdf':'ReferenceRaster').'\\','source_sha256'=>hash_file('sha256',$source),'dependency_lock_sha256'=>hash_file('sha256',$lock),'license'=>'MIT'),
            'security'=>array('execution_mode'=>'php-inprocess','input_schema'=>'certloom-render-plan:1','network'=>'none','processes'=>'none','remote_assets'=>false),
            'requirements'=>array('php_min'=>'7.4','extensions'=>array(),'classes'=>array(),'functions'=>array()),
        );
        $path=$tmp.'/'.$key.'.json'; file_put_contents($path,json_encode($candidate,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return array('manifest'=>$path,'source'=>$source,'lock'=>$lock,'hash'=>$contract->hash($candidate),'data'=>$candidate);
    };
    $pdf=$make_candidate('reference-pdf','fixture-pdf','1.0.0',array('pdf'));
    $raster=$make_candidate('reference-raster','fixture-raster','1.0.0',array('png','jpeg'));
    $pdf_context=array('candidate_manifest_sha256'=>$pdf['hash'],'font_manifest'=>$setup['font_manifest'],'adapter'=>'fixture-replay');
    $raster_context=array('candidate_manifest_sha256'=>$raster['hash'],'font_manifest'=>$setup['font_manifest'],'adapter'=>'fixture-replay');
    $pdf_renderer=new RcpGoldenReplayRenderer('fixture-pdf','1.0.0',array('pdf'),$pdf_context,$root);
    $raster_renderer=new RcpGoldenReplayRenderer('fixture-raster','1.0.0',array('png','jpeg'),$raster_context,$root);
    $formats=array(
        'pdf'=>array('renderer'=>$pdf_renderer,'candidate_manifest'=>$pdf['manifest'],'source_archive'=>$pdf['source'],'dependency_lock'=>$pdf['lock']),
        'png'=>array('renderer'=>$raster_renderer,'candidate_manifest'=>$raster['manifest'],'source_archive'=>$raster['source'],'dependency_lock'=>$raster['lock']),
        'jpeg'=>array('renderer'=>$raster_renderer,'candidate_manifest'=>$raster['manifest'],'source_archive'=>$raster['source'],'dependency_lock'=>$raster['lock']),
    );
    $producer=new CertloomProductionCandidateBundleProducer($root);
    $dev_out=$tmp.'/dev-bundle';
    $manifest=$producer->produce($dev_out,array('font_root'=>$font_root,'formats'=>$formats),'development-reference');
    rcp_check(is_dir($dev_out)&&is_file($dev_out.'/bundle.json'),'Successful producer run must publish a complete final directory.');
    rcp_check(empty(glob($tmp.'/.certloom-candidate-*')?:array()),'Successful producer run must not leak private staging directories.');
    $plugin_bytes=file_get_contents($root.'/certloom.php'); preg_match('/^\s*\*\s*Version:\s*([^\s]+)/m',$plugin_bytes,$plugin_match); $current_version=trim($plugin_match[1]);
    rcp_check('development-reference'===$manifest['qualification']&&$current_version===$manifest['plugin_version'],'Producer must derive current plugin version and support source-only development verification.');
    rcp_check(isset($manifest['runs']['a']['fixtures']['qr']['jpeg'])&&null===$manifest['runs']['a']['fixtures']['qr']['jpeg']['receipt'],'Development producer run must include all three formats without production receipts.');
    rcp_check($manifest['renderers']['png']['source_archive']['path']===$manifest['renderers']['jpeg']['source_archive']['path'],'Content-addressed producer must deduplicate shared raster candidate source evidence.');
    rcp_check(hash_equals($raster['hash'],$manifest['renderers']['png']['candidate_manifest']['sha256'])&&hash_equals($raster['hash'],hash_file('sha256',$dev_out.'/'.$manifest['renderers']['png']['candidate_manifest']['path'])),'Bundled candidate manifest bytes must equal their canonical candidate SHA-256.');
    $cmd='python3 '.escapeshellarg(__DIR__.'/renderer-candidate/verify-bundle.py').' '.escapeshellarg($dev_out).' 2>&1'; exec($cmd,$verify,$rc); rcp_check(0===$rc,'Independently verified development bundle must pass the real PDF-04 bundle verifier.');
    $report=json_decode(implode("\n",$verify),true); rcp_check(is_array($report)&&true===$report['verified']&&false===$report['qualifies_pdf04'],'Development harness output must verify but remain mechanically unable to qualify PDF-04.');

    rcp_check(rcp_throws(function()use($producer,$tmp,$font_root,$formats){$producer->produce($tmp.'/no-ack',array('font_root'=>$font_root,'formats'=>$formats),'production-candidate');}),'Production candidate output must require an explicit acknowledgement.');
    $prod_out=$tmp.'/prod-bundle';
    $prod=$producer->produce($prod_out,array('font_root'=>$font_root,'formats'=>$formats,'production_candidate_ack'=>CertloomProductionCandidateBundleProducer::PRODUCTION_ACK),'production-candidate');
    $receipt=$prod['runs']['a']['fixtures']['english']['pdf']['receipt'];
    rcp_check(is_array($receipt)&&is_file($prod_out.'/'.$receipt['path']),'Production candidate producer must emit ArtifactReceipt v4 files for every artifact.');
    $receipt_data=json_decode(file_get_contents($prod_out.'/'.$receipt['path']),true); rcp_check(4===$receipt_data['version']&&hash_equals($prod['runs']['a']['fixtures']['english']['render_plan_sha256'],$receipt_data['render_plan_hash']),'Production receipt must bind the exact frozen render-plan hash.');
    rcp_check($prod['runs']['a']['fixtures']['english']['pdf']['sha256']===$prod['runs']['b']['fixtures']['english']['pdf']['sha256'],'Producer must enforce byte-identical A/B runs before writing the final bundle manifest.');

    $failure_out=$tmp.'/injected-failure';
    $failing_pdf=new RcpFailAfterRenderer($pdf_renderer,3);
    $failing_formats=$formats; $failing_formats['pdf']['renderer']=$failing_pdf;
    rcp_check(rcp_throws(function()use($producer,$failure_out,$font_root,$failing_formats){$producer->produce($failure_out,array('font_root'=>$font_root,'formats'=>$failing_formats),'development-reference');}),'Producer must surface renderer failure during a staged candidate run.');
    rcp_check(!file_exists($failure_out)&&!is_link($failure_out),'Failed producer run must never publish a partial final evidence directory.');
    rcp_check(empty(glob($tmp.'/.certloom-candidate-*')?:array()),'Failed producer run must remove its private staging directory completely.');

    $empty_output=$tmp.'/empty-existing-output'; mkdir($empty_output,0700);
    rcp_check(rcp_throws(function()use($producer,$empty_output,$font_root,$formats){$producer->produce($empty_output,array('font_root'=>$font_root,'formats'=>$formats),'development-reference');}),'Producer must reject even an empty existing output directory so final publication is an atomic rename to a new path.');

    $bad=$formats; $bad['jpeg']['renderer']=$pdf_renderer;
    rcp_check(rcp_throws(function()use($producer,$tmp,$font_root,$bad){$producer->produce($tmp.'/bad-format',array('font_root'=>$font_root,'formats'=>$bad),'development-reference');}),'Producer must reject a renderer whose exact candidate manifest does not own the configured format.');

    $bad_context_renderer=new RcpGoldenReplayRenderer('fixture-pdf','1.0.0',array('pdf'),array('candidate_manifest_sha256'=>str_repeat('f',64),'font_manifest'=>$setup['font_manifest']),$root);
    $bad=$formats; $bad['pdf']['renderer']=$bad_context_renderer;
    rcp_check(rcp_throws(function()use($producer,$tmp,$font_root,$bad){$producer->produce($tmp.'/bad-context',array('font_root'=>$font_root,'formats'=>$bad),'development-reference');}),'Producer must reject renderer context that does not bind the exact candidate-manifest hash.');

    $bad_requirements_renderer=new RcpGoldenReplayRenderer('fixture-pdf','1.0.0',array('pdf'),$pdf_context,$root,array('php_min'=>'7.4','extensions'=>array('mbstring'),'classes'=>array(),'functions'=>array()));
    $bad=$formats; $bad['pdf']['renderer']=$bad_requirements_renderer;
    rcp_check(rcp_throws(function()use($producer,$tmp,$font_root,$bad){$producer->produce($tmp.'/bad-requirements',array('font_root'=>$font_root,'formats'=>$bad),'development-reference');}),'Producer must reject renderer runtime requirements that differ from the frozen candidate manifest.');

    $bad_portable_context=$pdf_context; $bad_portable_context['runtime_cache']='/tmp/candidate-cache';
    $bad_portable_renderer=new RcpGoldenReplayRenderer('fixture-pdf','1.0.0',array('pdf'),$bad_portable_context,$root);
    $bad=$formats; $bad['pdf']['renderer']=$bad_portable_renderer;
    rcp_check(rcp_throws(function()use($producer,$tmp,$font_root,$bad){$producer->produce($tmp.'/bad-portable-context',array('font_root'=>$font_root,'formats'=>$bad),'development-reference');}),'Producer must reject renderer context containing host-local absolute paths.');

    $bad_url_context=$pdf_context; $bad_url_context['asset_base']='https://example.invalid/assets';
    $bad_url_renderer=new RcpGoldenReplayRenderer('fixture-pdf','1.0.0',array('pdf'),$bad_url_context,$root);
    $bad=$formats; $bad['pdf']['renderer']=$bad_url_renderer;
    rcp_check(rcp_throws(function()use($producer,$tmp,$font_root,$bad){$producer->produce($tmp.'/bad-url-context',array('font_root'=>$font_root,'formats'=>$bad),'development-reference');}),'Producer must reject renderer context containing URLs.');

    $nonempty=$tmp.'/nonempty-output'; mkdir($nonempty,0700); file_put_contents($nonempty.'/sentinel','x');
    rcp_check(rcp_throws(function()use($producer,$nonempty,$font_root,$formats){$producer->produce($nonempty,array('font_root'=>$font_root,'formats'=>$formats),'development-reference');}),'Producer must reject a non-empty output directory instead of mixing evidence from multiple runs.');

    $inside=$root.'/tests/renderer-candidate/.forbidden-production-bundle'; rcp_rm($inside);
    rcp_check(rcp_throws(function()use($producer,$inside,$font_root,$formats){$producer->produce($inside,array('font_root'=>$font_root,'formats'=>$formats),'development-reference');}),'Producer must never write unaccepted renderer evidence inside the Certloom source tree.');
    rcp_check(!file_exists($inside)&&!is_link($inside),'Rejected in-source producer output must leave no candidate evidence behind.');

    $dot_output=$tmp.'/dot-parent/../dot-bundle';
    rcp_check(rcp_throws(function()use($producer,$dot_output,$font_root,$formats){$producer->produce($dot_output,array('font_root'=>$font_root,'formats'=>$formats),'development-reference');}),'Producer must reject ambiguous dot-segment output paths before recursive directory creation.');

    echo "PASS  PDF-04 production-candidate producer executes frozen fixtures through the real coordinator\n";
    echo "\n{$checks} checks, 0 failures\n";
} finally { rcp_rm($tmp); }
