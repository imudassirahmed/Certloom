<?php
/** 1.6.23 release-bound PDF-04 runtime authority regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;

$checks = 0;
function rag_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rag_throws( callable $fn ) { try { $fn(); } catch ( InvalidArgumentException $e ) { return true; } throw new RuntimeException( 'Expected InvalidArgumentException.' ); }

final class RagRenderer implements RendererInterface {
	private $id; private $version; private $formats; private $candidate; private $missing;
	public function __construct( $id, $version, array $formats, $candidate, $missing = false ) { $this->id=$id; $this->version=$version; $this->formats=$formats; $this->candidate=$candidate; $this->missing=$missing; }
	public function id(){ return $this->id; }
	public function version(){ return $this->version; }
	public function formats(){ return $this->formats; }
	public function requirements(){ return array( 'php_min'=>'7.4', 'extensions'=> $this->missing ? array('certloom_missing_ext') : array(), 'classes'=>array(), 'functions'=>array() ); }
	public function context(){ return array( 'candidate_manifest_sha256'=>$this->candidate ); }
	public function render( array $render_plan, $format ){ return 'unused'; }
}

$fixtures = array(); foreach ( RendererAcceptanceContract::required_fixtures() as $name ) { $fixtures[$name]=array( 'render_plan_sha256'=>hash('sha256','plan-'.$name), 'pdf_sha256'=>hash('sha256','pdf-'.$name), 'png_sha256'=>hash('sha256','png-'.$name) ); }
$required_checks = array(); foreach ( RendererAcceptanceContract::required_checks() as $key ) { $required_checks[$key]=true; }
$evidence = array(
	'schema'=>RendererAcceptanceContract::SCHEMA, 'version'=>RendererAcceptanceContract::VERSION, 'qualification'=>'production-candidate', 'plugin_version'=>'1.6.23',
	'fixture_manifest_sha256'=>str_repeat('a',64), 'render_plan_manifest_sha256'=>str_repeat('b',64), 'bundle_manifest_sha256'=>str_repeat('c',64), 'verification_report_sha256'=>str_repeat('d',64), 'verifier_environment_sha256'=>str_repeat('e',64),
	'renderers'=>array(
		'pdf'=>array('id'=>'mpdf-pdf','version'=>'8.3.1+certloom.1','context_hash'=>str_repeat('1',64),'candidate_manifest_sha256'=>str_repeat('2',64)),
		'png'=>array('id'=>'certloom-raster','version'=>'1.0.0','context_hash'=>str_repeat('3',64),'candidate_manifest_sha256'=>str_repeat('4',64)),
	),
	'checks'=>$required_checks, 'fixtures'=>$fixtures,
);
$release_contract = new RendererReleaseManifest();
$manifest = $release_contract->from_acceptance( $evidence );
rag_check( RendererReleaseManifest::VERSION === $manifest['version'], 'Renderer release authority manifest must be v1.' );
rag_check( RendererAcceptanceContract::VERSION === $manifest['acceptance']['contract_version'], 'Release authority must bind the current acceptance-contract version.' );
rag_check( true === $manifest['authority']['template_publication'] && array('pdf','png') === $manifest['authority']['artifact_formats'], 'Template authority must require both accepted PDF and PNG.' );
rag_check( hash_equals( (new RendererAcceptanceContract())->hash($evidence), $manifest['acceptance']['acceptance_sha256'] ), 'Release authority must bind the exact accepted evidence hash.' );
$bad=$manifest; $bad['acceptance']['contract_version']=RendererAcceptanceContract::VERSION-1;
rag_check( rag_throws(function() use($release_contract,$bad){$release_contract->normalize($bad);}), 'Stale acceptance-contract authority must be rejected.' );
$bad=$manifest; $bad['authority']['artifact_formats']=array('pdf');
rag_check( rag_throws(function() use($release_contract,$bad){$release_contract->normalize($bad);}), 'Template publication authority must not accept PDF-only evidence.' );

$tmp = sys_get_temp_dir() . '/certloom-renderer-authority-' . bin2hex(random_bytes(6));
mkdir($tmp . '/resources/renderer', 0777, true);
$missing_gate = new RendererAuthorityGate(null, $tmp, '1.6.23');
$status=$missing_gate->manifest_status();
rag_check( false === $status['accepted'] && 'manifest_missing' === $status['reason'], 'No bundled acceptance manifest must fail closed.' );
file_put_contents($tmp . '/resources/renderer/accepted.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));
$gate = new RendererAuthorityGate(null, $tmp, '1.6.23');
$status=$gate->manifest_status();
rag_check( true === $status['accepted'] && 'manifest_valid' === $status['reason'], 'Exact release-bound manifest must validate.' );
$wrong_version = new RendererAuthorityGate(null, $tmp, '1.6.24');
$status=$wrong_version->manifest_status();
rag_check( false === $status['accepted'] && 'plugin_version_mismatch' === $status['reason'], 'Acceptance authority must not survive a plugin-version change.' );

$registry=new RendererRegistry();
$registry->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('2',64)));
$registry->register(new RagRenderer('certloom-raster','1.0.0',array('png'),str_repeat('4',64)));
$status=$gate->runtime_status($registry);
rag_check( true === $status['accepted'] && 'runtime_authorized' === $status['reason'], 'Exact accepted renderer identities with available requirements must authorize runtime.' );
rag_check( $gate->can_publish_templates(), 'Template publication gate must open from exact release acceptance without depending on this host runtime.' );
rag_check( $gate->can_render('pdf',$registry) && $gate->can_render('png',$registry) && ! $gate->can_render('jpeg',$registry), 'Artifact authority must be restricted to formats actually accepted.' );
$pdf_only=new RendererRegistry(); $pdf_only->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('2',64)));
rag_check( $gate->can_render('pdf',$pdf_only), 'PDF artifact authority must inspect the requested accepted renderer without requiring the PNG runtime on the same host path.' );
rag_check( false === $gate->can_render('png',$pdf_only), 'PNG authority must fail closed when its accepted renderer is not registered.' );

$wrong=new RendererRegistry();
$wrong->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.2',array('pdf'),str_repeat('2',64)));
$wrong->register(new RagRenderer('certloom-raster','1.0.0',array('png'),str_repeat('4',64)));
$status=$gate->runtime_status($wrong);
rag_check( false === $status['accepted'] && 'renderer_identity_mismatch' === $status['reason'], 'Different renderer version must fail closed even with a valid release manifest.' );
$wrong_hash=new RendererRegistry();
$wrong_hash->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('f',64)));
$wrong_hash->register(new RagRenderer('certloom-raster','1.0.0',array('png'),str_repeat('4',64)));
rag_check( 'renderer_identity_mismatch' === $gate->runtime_status($wrong_hash)['reason'], 'Different candidate manifest hash must fail closed.' );
$missing_runtime=new RendererRegistry();
$missing_runtime->register(new RagRenderer('mpdf-pdf','8.3.1+certloom.1',array('pdf'),str_repeat('2',64),true));
$missing_runtime->register(new RagRenderer('certloom-raster','1.0.0',array('png'),str_repeat('4',64)));
rag_check( 'renderer_runtime_unavailable' === $gate->runtime_status($missing_runtime)['reason'], 'Accepted candidate must still fail closed when host runtime requirements are missing.' );
rag_check( $gate->can_publish_templates(), 'Release-accepted Template publication must remain distinct from per-host artifact runtime availability.' );
rag_check( false === $gate->can_render('pdf',$missing_runtime), 'Artifact execution must remain closed when the selected accepted renderer runtime is unavailable.' );

file_put_contents($tmp . '/resources/renderer/accepted.json', '{broken');
rag_check( 'manifest_invalid' === $gate->manifest_status()['reason'], 'Malformed bundled authority JSON must fail closed.' );
unlink($tmp . '/resources/renderer/accepted.json'); rmdir($tmp . '/resources/renderer'); rmdir($tmp . '/resources'); rmdir($tmp);

$repo_source=file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Repository/TemplateRepository.php');
$admin_source=file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Admin/TemplateAdmin.php');
$status_source=file_get_contents(dirname(__DIR__) . '/includes/class-ecapla-certverify-system-status.php');
$coordinator_source=file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Rendering/ArtifactRenderCoordinator.php');
rag_check( false !== strpos($repo_source,'RendererAuthorityGate') && false !== strpos($repo_source,'publication_status()'), 'Template publication must consult release-bound renderer authority instead of a mutable option.' );
rag_check( false !== strpos($repo_source,'published_revision_id') && false !== strpos($repo_source,'certloom_template_stale_revision'), 'Accepted publication path must advance only the reviewed immutable current revision.' );
rag_check( false !== strpos($admin_source,'admin_post_certloom_template_publish') && false !== strpos($admin_source,'expected_revision_id'), 'Gated Template admin must carry nonce/capability/revision proof into future publication.' );
rag_check( false !== strpos($status_source,'renderer_authority_check') && false !== strpos($status_source,'no WordPress option can bypass'), 'System Status must expose renderer authority without suggesting an admin toggle can enable it.' );
rag_check( false !== strpos($coordinator_source,'ArtifactAuthorityGateInterface') && false !== strpos($coordinator_source,'artifact_status( $format, $this->registry )'), 'Artifact coordinator must enforce release/runtime authority before any renderer execution.' );
rag_check( is_file(dirname(__DIR__) . '/resources/renderer/README.txt'), 'Production package must document that no mutable runtime toggle can substitute for accepted release authority.' );
$readme=file_get_contents(dirname(__DIR__) . '/resources/renderer/README.txt');
rag_check( false !== strpos($readme,'no accepted.json in 1.6.23') && false !== strpos($readme,'never stored as a mutable'), 'Current release must explicitly remain renderer-authority closed.' );

echo "PASS  Renderer authority is release-bound, version-bound, runtime-matched and fail-closed\n";
echo "\n{$checks} checks, 0 failures\n";
