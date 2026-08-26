<?php
/** 1.6.24 release/runtime renderer authority regression, including JPEG. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;

$checks=0;
function rag_check($c,$m){global $checks;$checks++;if(!$c){throw new RuntimeException($m);}}
function rag_throws(callable $fn){try{$fn();}catch(InvalidArgumentException $e){return true;}throw new RuntimeException('Expected InvalidArgumentException.');}
final class RagRenderer implements RendererInterface {
    private $id,$version,$formats,$candidate,$missing;
    public function __construct($id,$version,array $formats,$candidate,$missing=false){$this->id=$id;$this->version=$version;$this->formats=$formats;$this->candidate=$candidate;$this->missing=$missing;}
    public function id(){return $this->id;} public function version(){return $this->version;} public function formats(){return $this->formats;}
    public function requirements(){return array('php_min'=>'7.4','extensions'=>$this->missing?array('certloom_missing_ext'):array(),'classes'=>array(),'functions'=>array());}
    public function context(){return array('candidate_manifest_sha256'=>$this->candidate);} public function render(array $p,$f){return 'unused';}
}

$fixtures=array(); foreach(RendererAcceptanceContract::required_fixtures() as $name){$fixtures[$name]=array('render_plan_sha256'=>hash('sha256','plan-'.$name),'pdf_sha256'=>hash('sha256','pdf-'.$name),'png_sha256'=>hash('sha256','png-'.$name),'jpeg_sha256'=>hash('sha256','jpeg-'.$name));}
$required_checks=array(); foreach(RendererAcceptanceContract::required_checks() as $key){$required_checks[$key]=true;}
$evidence=array(
    'schema'=>RendererAcceptanceContract::SCHEMA,'version'=>RendererAcceptanceContract::VERSION,'qualification'=>'production-candidate','plugin_version'=>'1.6.24',
    'fixture_manifest_sha256'=>str_repeat('a',64),'render_plan_manifest_sha256'=>str_repeat('b',64),'bundle_manifest_sha256'=>str_repeat('c',64),'verification_report_sha256'=>str_repeat('d',64),'verifier_environment_sha256'=>str_repeat('e',64),
    'renderers'=>array(
        'pdf'=>array('id'=>'mpdf-pdf','version'=>'8.3.1+certloom.1','context_hash'=>str_repeat('1',64),'candidate_manifest_sha256'=>str_repeat('2',64)),
        'png'=>array('id'=>'certloom-raster','version'=>'1.0.0','context_hash'=>str_repeat('3',64),'candidate_manifest_sha256'=>str_repeat('4',64)),
        'jpeg'=>array('id'=>'certloom-raster','version'=>'1.0.0','context_hash'=>str_repeat('5',64),'candidate_manifest_sha256'=>str_repeat('4',64)),
    ),
    'checks'=>$required_checks,'fixtures'=>$fixtures,
);
$release_contract=new RendererReleaseManifest();
$payload_contract=new RuntimePayloadFingerprint();
$synthetic_payload=array('schema'=>RuntimePayloadFingerprint::SCHEMA,'version'=>RuntimePayloadFingerprint::VERSION,'sha256'=>str_repeat('9',64),'file_count'=>2,'total_bytes'=>128);
$manifest=$release_contract->from_acceptance($evidence,$synthetic_payload);
rag_check(3===RendererReleaseManifest::VERSION && RendererReleaseManifest::VERSION===$manifest['version'],'Renderer release authority manifest must be v3 for exact runtime-payload identity.');
rag_check($synthetic_payload===$manifest['runtime_payload'],'Renderer release authority must bind an exact normalized production-payload fingerprint.');
rag_check(RendererAcceptanceContract::VERSION===$manifest['acceptance']['contract_version'],'Release authority must bind current acceptance contract.');
rag_check(true===$manifest['authority']['template_publication'] && array('jpeg','pdf','png')===$manifest['authority']['artifact_formats'],'Template authority must require accepted PDF, PNG and JPEG.');
rag_check(isset($manifest['renderers']['jpeg']) && 'certloom-raster'===$manifest['renderers']['jpeg']['id'],'Release authority must bind an exact JPEG renderer identity.');
rag_check(hash_equals((new RendererAcceptanceContract())->hash($evidence),$manifest['acceptance']['acceptance_sha256']),'Release authority must bind exact accepted evidence hash.');
$bad=$manifest;$bad['acceptance']['contract_version']=RendererAcceptanceContract::VERSION-1;rag_check(rag_throws(function()use($release_contract,$bad){$release_contract->normalize($bad);}), 'Stale acceptance authority must be rejected.');
$bad=$manifest;$bad['authority']['artifact_formats']=array('pdf','png');rag_check(rag_throws(function()use($release_contract,$bad){$release_contract->normalize($bad);}), 'Template publication authority must not accept evidence missing JPEG.');

$tmp=sys_get_temp_dir().'/certloom-renderer-authority-'.bin2hex(random_bytes(6));mkdir($tmp.'/resources/renderer',0777,true);mkdir($tmp.'/src',0777,true);
file_put_contents($tmp.'/certloom.php',"<?php /* runtime seed */\n");
file_put_contents($tmp.'/src/runtime.php',"<?php return 'exact';\n");
$missing_gate=new RendererAuthorityGate(null,$tmp,'1.6.24'); $status=$missing_gate->manifest_status();
rag_check(false===$status['accepted']&&'manifest_missing'===$status['reason'],'No bundled acceptance manifest must fail closed.');
$runtime_manifest=$release_contract->from_acceptance($evidence,$payload_contract->fingerprint($tmp));
file_put_contents($tmp.'/resources/renderer/accepted.json',json_encode($runtime_manifest,JSON_UNESCAPED_SLASHES));
$gate=new RendererAuthorityGate(null,$tmp,'1.6.24'); $status=$gate->manifest_status();
rag_check(true===$status['accepted']&&'manifest_valid'===$status['reason'],'Exact release-bound manifest must validate.');
$original_runtime=file_get_contents($tmp.'/src/runtime.php');
file_put_contents($tmp.'/src/runtime.php',"<?php return 'mutated';\n");
$mutated_gate=new RendererAuthorityGate(null,$tmp,'1.6.24');
rag_check('runtime_payload_mismatch'===$mutated_gate->manifest_status()['reason'],'Release authority must fail closed when an existing production file changes without a version bump.');
file_put_contents($tmp.'/src/runtime.php',$original_runtime);
file_put_contents($tmp.'/unexpected.php',"<?php return 'extra';\n");
$extra_gate=new RendererAuthorityGate(null,$tmp,'1.6.24');
rag_check('runtime_payload_mismatch'===$extra_gate->manifest_status()['reason'],'Release authority must fail closed when an unexpected production file is added.');
unlink($tmp.'/unexpected.php');
$restored_gate=new RendererAuthorityGate(null,$tmp,'1.6.24');
rag_check(true===$restored_gate->manifest_status()['accepted'],'Release authority must recover when the exact reviewed production payload is restored.');
$wrong_version=new RendererAuthorityGate(null,$tmp,'9.9.9'); rag_check('plugin_version_mismatch'===$wrong_version->manifest_status()['reason'],'Acceptance authority must not survive a plugin-version change.');

$registry=new RendererRegistry();
$registry->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('2',64)));
$registry->register(new RagRenderer('certloom-raster','1.0.0',array('png','jpeg'),str_repeat('4',64)));
$status=$gate->runtime_status($registry);
rag_check(true===$status['accepted']&&'runtime_authorized'===$status['reason'],'Exact three-format accepted renderer identities must authorize runtime.');
rag_check($gate->can_publish_templates(),'Template publication gate must open from exact release acceptance without host-runtime coupling.');
rag_check($gate->can_render('pdf',$registry)&&$gate->can_render('png',$registry)&&$gate->can_render('jpeg',$registry),'Artifact authority must authorize every accepted product format.');
$pdf_only=new RendererRegistry();$pdf_only->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('2',64)));
rag_check($gate->can_render('pdf',$pdf_only),'PDF authority must not require raster runtime.');
rag_check(false===$gate->can_render('png',$pdf_only)&&false===$gate->can_render('jpeg',$pdf_only),'Raster formats must fail closed when their accepted runtime is unavailable.');
$wrong=new RendererRegistry();$wrong->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.2',array('pdf'),str_repeat('2',64)));$wrong->register(new RagRenderer('certloom-raster','1.0.0',array('png','jpeg'),str_repeat('4',64)));
rag_check('renderer_identity_mismatch'===$gate->runtime_status($wrong)['reason'],'Different renderer version must fail closed.');
$wrong_hash=new RendererRegistry();$wrong_hash->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('f',64)));$wrong_hash->register(new RagRenderer('certloom-raster','1.0.0',array('png','jpeg'),str_repeat('4',64)));
rag_check('renderer_identity_mismatch'===$gate->runtime_status($wrong_hash)['reason'],'Different candidate manifest hash must fail closed.');
$missing_runtime=new RendererRegistry();$missing_runtime->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('2',64),true));$missing_runtime->register(new RagRenderer('certloom-raster','1.0.0',array('png','jpeg'),str_repeat('4',64)));
rag_check('renderer_runtime_unavailable'===$gate->runtime_status($missing_runtime)['reason'],'Accepted candidate must fail closed when host requirements are missing.');
rag_check($gate->can_publish_templates(),'Release-accepted publication remains distinct from host artifact runtime.');
rag_check(false===$gate->can_render('pdf',$missing_runtime),'Artifact execution remains closed when selected runtime is unavailable.');

file_put_contents($tmp.'/resources/renderer/accepted.json','{broken');rag_check('manifest_invalid'===$gate->manifest_status()['reason'],'Malformed authority JSON must fail closed.');
unlink($tmp.'/resources/renderer/accepted.json');unlink($tmp.'/src/runtime.php');unlink($tmp.'/certloom.php');rmdir($tmp.'/src');rmdir($tmp.'/resources/renderer');rmdir($tmp.'/resources');rmdir($tmp);

$repo_source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Repository/TemplateRepository.php');
$admin_source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Admin/TemplateAdmin.php');
$status_source=file_get_contents(dirname(__DIR__).'/includes/class-ecapla-certverify-system-status.php');
$coordinator_source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Rendering/ArtifactRenderCoordinator.php');
rag_check(false!==strpos($repo_source,'RendererAuthorityGate')&&false!==strpos($repo_source,'publication_status()'),'Template publication must consult release-bound renderer authority.');
rag_check(false!==strpos($repo_source,'published_revision_id')&&false!==strpos($repo_source,'certloom_template_stale_revision'),'Accepted publication must advance only reviewed immutable current revision.');
rag_check(false!==strpos($admin_source,'admin_post_certloom_template_publish')&&false!==strpos($admin_source,'expected_revision_id')&&false!==strpos($admin_source,'expected_revision_sha256'),'Gated Template admin must carry exact revision ID + hash proof into publication.');
rag_check(false!==strpos($status_source,'renderer_authority_check')&&false!==strpos($status_source,'no WordPress option can bypass'),'System Status must expose renderer authority without mutable bypass.');
rag_check(false!==strpos($coordinator_source,'ArtifactAuthorityGateInterface')&&false!==strpos($coordinator_source,'artifact_status( $format, $this->registry )'),'Artifact coordinator must enforce runtime authority before renderer execution.');
rag_check(is_file(dirname(__DIR__).'/resources/renderer/README.txt'),'Production package must document immutable authority.');

echo "PASS  Renderer authority is release-bound, three-format, runtime-matched and fail-closed\n";
echo "\n{$checks} checks, 0 failures\n";
