<?php
/** 1.6.19 renderer-independent shared render-plan regression. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Infrastructure\Rendering\ArtifactRenderCoordinator;

$checks = 0;
function rpcheck( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function rpsame( $expected, $actual, $message ) { rpcheck( $expected === $actual, $message . ' expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) ); }
function rpnear( $expected, $actual, $epsilon, $message ) { rpcheck( abs( (float) $expected - (float) $actual ) <= $epsilon, $message . ' expected=' . $expected . ' actual=' . $actual ); }
function rpthrows( callable $fn, $message ) { try { $fn(); } catch ( Throwable $e ) { rpcheck( true, $message ); return; } rpcheck( false, $message ); }

function rpfont_manifest() {
	return array(
		'schema' => 'certloom-font-manifest',
		'version' => 2,
		'families' => array(
			'sans-serif' => array(
				'400-normal' => array( 'faces' => array( array( 'sha256' => str_repeat( 'a', 64 ), 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ) ),
				'600-normal' => array( 'faces' => array( array( 'sha256' => str_repeat( 'b', 64 ), 'format' => 'ttf', 'coverage' => array( 'U+0000-D7FF', 'U+E000-10FFFF' ) ) ) ),
			),
		),
	);
}
function rppage() { return array( 'size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0 ); }
function rpbase( $id, $type, $z = 1 ) { return array( 'id'=>$id,'type'=>$type,'x_mm'=>20.0,'y_mm'=>20.0,'width_mm'=>80.0,'height_mm'=>30.0,'z'=>$z,'rotation_deg'=>0.0,'opacity'=>1.0 ); }

$builder = new RenderPlanBuilder();
$manifest = rpfont_manifest();

// Physical text alignment and unit conversions are shared before adapters.
$text = rpbase( '11111111-1111-4111-8111-111111111111', 'text' );
$text['text'] = 'محمد علی';
$text['style'] = array( 'font_family'=>'sans-serif','font_size_pt'=>36.0,'font_weight'=>400,'italic'=>false,'color'=>'#112233CC','align'=>'start','vertical_align'=>'middle','direction'=>'rtl','line_height'=>1.25,'letter_spacing_pt'=>1.5,'overflow'=>'shrink' );
$doc = array( '_meta'=>array('schema'=>'certloom-layout','schema_version'=>1), 'page'=>rppage(), 'elements'=>array($text) );
$plan = $builder->build( $doc, array( 'font_manifest'=>$manifest ) );
rpsame( 'certloom-render-plan', $plan['_meta']['schema'], 'Render plan schema must be stable.' );
rpsame( 1, $plan['_meta']['version'], 'Render plan version must be stable.' );
rpsame( 'right', $plan['operations'][0]['text']['align'], 'RTL start must resolve to physical right alignment.' );
rpsame( 'rtl', $plan['operations'][0]['text']['direction'], 'Resolved RTL direction must survive the plan.' );
rpnear( 12.7, $plan['operations'][0]['text']['font']['size_mm'], 0.0000001, '36pt must convert to 12.7mm.' );
rpnear( 15.875, $plan['operations'][0]['text']['font']['line_height_mm'], 0.0000001, 'Line height must be physical and deterministic.' );
rpnear( 0.529166666667, $plan['operations'][0]['text']['font']['letter_spacing_mm'], 0.0000001, 'Letter spacing must convert from points once.' );
rpsame( 204, $plan['operations'][0]['text']['color']['alpha'], 'Hex alpha must be decoded exactly.' );
rpsame( str_repeat('a',64), $plan['operations'][0]['text']['font']['faces'][0]['sha256'], 'Text operation must bind the exact ordered font face identities.' );

// Rotation geometry is centralized around the box centre.
$rot = $text; $rot['id']='22222222-2222-4222-8222-222222222222'; $rot['text']='Rotation'; $rot['rotation_deg']=17.5; $rot['style']['direction']='ltr'; $rot['style']['align']='center';
$plan_rot = $builder->build( array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($rot)), array('font_manifest'=>$manifest) );
$g = $plan_rot['operations'][0]['geometry'];
rpnear( 60.0, $g['center']['x_mm'], 0.0000001, 'Rotation pivot X must be box centre.' );
rpnear( 35.0, $g['center']['y_mm'], 0.0000001, 'Rotation pivot Y must be box centre.' );
rpnear( 17.5, $g['rotation_deg'], 0.0000001, 'Arbitrary 17.5 degree rotation must survive shared planning.' );
rpcheck( $g['rotated_bounds']['width_mm'] > 80.0, 'Rotated bounds must expand beyond the unrotated width.' );
rpcheck( $g['rotated_bounds']['height_mm'] > 30.0, 'Rotated bounds must expand beyond the unrotated height.' );

// Logical LTR end resolves physically before the backend.
$ltr = $text; $ltr['id']='33333333-3333-4333-8333-333333333333'; $ltr['text']='Hello'; $ltr['style']['direction']='ltr'; $ltr['style']['align']='end';
$ltr_plan=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($ltr)),array('font_manifest'=>$manifest));
rpsame('right',$ltr_plan['operations'][0]['text']['align'],'LTR end must resolve to physical right.');

// QR module geometry is derived once from the central matrix and quiet zone.
$matrix=array_fill(0,21,array_fill(0,21,0));
for($i=0;$i<21;$i++){ $matrix[$i][$i]=1; }
$qr=rpbase('44444444-4444-4444-8444-444444444444','qr'); $qr['width_mm']=29.0; $qr['height_mm']=29.0; $qr['data']='https://example.test/verify/X'; $qr['matrix']=$matrix; $qr['encoder']=array('id'=>'certloom-local-qr-model2','version'=>'1.0.0'); $qr['error_correction']='M'; $qr['quiet_zone_modules']=4;
$qplan=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($qr)));
rpsame(21,$qplan['operations'][0]['qr']['matrix_size'],'QR matrix size must be recorded.');
rpsame(29,$qplan['operations'][0]['qr']['total_modules'],'QR total modules must include quiet zone.');
rpnear(1.0,$qplan['operations'][0]['qr']['module_size_mm'],0.0000001,'29mm / 29 modules must produce 1mm modules.');
rpsame(21,count($qplan['operations'][0]['qr']['dark_runs']),'Diagonal QR must compress into one dark run per row.');
rpnear(24.0,$qplan['operations'][0]['qr']['dark_runs'][0]['x_mm'],0.0000001,'QR dark modules must begin after the 4-module quiet zone.');
rpnear(24.0,$qplan['operations'][0]['qr']['dark_runs'][0]['y_mm'],0.0000001,'QR dark modules must begin after the 4-module quiet zone.');
$bad=$qr; $bad['matrix'][0][0]=2; rpthrows(function()use($builder,$bad){$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($bad)));},'Render plan must fail closed on non-binary QR matrix values.');

// Image contain/cover/fill math is shared rather than library-specific.
$img=rpbase('55555555-5555-4555-8555-555555555555','image'); $img['width_mm']=100.0; $img['height_mm']=100.0; $img['asset']=array('path'=>'/private/path/a.png','sha256'=>str_repeat('c',64),'mime'=>'image/png','size'=>1234,'width_px'=>400,'height_px'=>200); $img['fit']='contain';
$iplan=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($img)));
$placement=$iplan['operations'][0]['image']['placement'];
rpnear(100.0,$placement['destination']['width_mm'],0.0000001,'Contain must use full target width for a 2:1 image.');
rpnear(50.0,$placement['destination']['height_mm'],0.0000001,'Contain must preserve source aspect ratio.');
rpnear(45.0,$placement['destination']['y_mm'],0.0000001,'Contain must center the reduced height.');
rpsame('/private/path/a.png',$iplan['_runtime']['assets'][str_repeat('c',64)]['path'],'Image filesystem path must live only in runtime state.');
rpcheck(!isset($iplan['operations'][0]['image']['asset']['path']),'Portable image operation must not expose the filesystem path.');
$img_cover=$img; $img_cover['fit']='cover'; $cplan=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($img_cover)));
rpnear(0.25,$cplan['operations'][0]['image']['placement']['source_crop']['x'],0.0000001,'Cover must crop equal horizontal quarters for a 2:1 source into square target.');
rpnear(0.5,$cplan['operations'][0]['image']['placement']['source_crop']['width'],0.0000001,'Cover must expose the central half of a 2:1 source.');
$img_fill=$img; $img_fill['fit']='fill'; $fplan=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($img_fill)));
rpnear(100.0,$fplan['operations'][0]['image']['placement']['destination']['height_mm'],0.0000001,'Fill must use the exact target box.');

// Portable render-plan hashes must ignore local runtime paths.
$img_b=$img; $img_b['asset']['path']='/different/machine/path/a.png';
$iplan_b=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($img_b)));
rpsame($builder->hash($iplan),$builder->hash($iplan_b),'Render-plan hash must be portable across local asset paths.');
rpcheck(1===preg_match('/^[0-9a-f]{64}$/',$builder->hash($iplan)),'Render-plan hash must be SHA-256.');

// Shapes and diagonal lines share color/geometry interpretation.
$shape=rpbase('66666666-6666-4666-8666-666666666666','shape',2); $shape['shape']='ellipse'; $shape['fill']='#AABBCC80'; $shape['stroke']='#010203'; $shape['stroke_width_mm']=0.5;
$line=rpbase('77777777-7777-4777-8777-777777777777','line',3); $line['stroke']='#445566'; $line['stroke_width_mm']=0.7;
$splan=$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($line,$shape)));
rpsame('shape',$splan['operations'][0]['type'],'Operations must be sorted by canonical z-order.');
rpsame('ellipse',$splan['operations'][0]['shape']['kind'],'Shape primitive must be explicit.');
rpsame(128,$splan['operations'][0]['shape']['fill']['alpha'],'RGBA fill alpha must be explicit.');
rpnear(100.0,$splan['operations'][1]['line']['to']['x_mm'],0.0000001,'Line endpoint X must be box x + width.');
rpnear(50.0,$splan['operations'][1]['line']['to']['y_mm'],0.0000001,'Line endpoint Y must be box y + height.');

// Unresolved merge data cannot leak into an adapter.
$merge=rpbase('88888888-8888-4888-8888-888888888888','merge'); $merge['token']='recipient.name'; $merge['style']=$text['style'];
rpthrows(function()use($builder,$merge,$manifest){$builder->build(array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($merge)),array('font_manifest'=>$manifest));},'Render plan must reject unresolved merge elements.');

// The plan boundary independently re-proves canonical invariants rather than trusting callers.
$bad_page = $doc;
$bad_page['page']['width_mm'] = 296.0;
rpthrows( function() use ( $builder, $bad_page, $manifest ) { $builder->build( $bad_page, array( 'font_manifest' => $manifest ) ); }, 'Render plan must reject page geometry that disagrees with the canonical page standard.' );
$bad_id = $ltr;
$bad_id['id'] = 'not-a-uuid';
rpthrows( function() use ( $builder, $bad_id, $manifest ) { $builder->build( array( '_meta' => array( 'schema' => 'certloom-layout', 'schema_version' => 1 ), 'page' => rppage(), 'elements' => array( $bad_id ) ), array( 'font_manifest' => $manifest ) ); }, 'Render plan must reject non-UUID element identities.' );
$duplicate_z = $shape;
$duplicate_z['id'] = '99999999-9999-4999-8999-999999999999';
$duplicate_z['z'] = $line['z'];
rpthrows( function() use ( $builder, $line, $duplicate_z ) { $builder->build( array( '_meta' => array( 'schema' => 'certloom-layout', 'schema_version' => 1 ), 'page' => rppage(), 'elements' => array( $line, $duplicate_z ) ) ); }, 'Render plan must reject duplicate z-order values.' );
$bad_overflow = $ltr;
$bad_overflow['id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$bad_overflow['style']['overflow'] = 'ellipsis';
rpthrows( function() use ( $builder, $bad_overflow, $manifest ) { $builder->build( array( '_meta' => array( 'schema' => 'certloom-layout', 'schema_version' => 1 ), 'page' => rppage(), 'elements' => array( $bad_overflow ) ), array( 'font_manifest' => $manifest ) ); }, 'Render plan must reject overflow modes outside wrap/shrink/clip.' );
$bad_qr_size = $qr;
$bad_qr_size['id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$bad_qr_size['matrix'] = array_fill( 0, 23, array_fill( 0, 23, 0 ) );
rpthrows( function() use ( $builder, $bad_qr_size ) { $builder->build( array( '_meta' => array( 'schema' => 'certloom-layout', 'schema_version' => 1 ), 'page' => rppage(), 'elements' => array( $bad_qr_size ) ) ); }, 'Render plan must reject QR matrices that are not valid Model 2 dimensions.' );

// Cover behavior must also be deterministic for portrait sources.
$portrait = $img;
$portrait['id'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$portrait['fit'] = 'cover';
$portrait['asset']['width_px'] = 200;
$portrait['asset']['height_px'] = 400;
$pplan = $builder->build( array( '_meta' => array( 'schema' => 'certloom-layout', 'schema_version' => 1 ), 'page' => rppage(), 'elements' => array( $portrait ) ) );
rpnear( 0.25, $pplan['operations'][0]['image']['placement']['source_crop']['y'], 0.0000001, 'Cover must crop equal vertical quarters for a 1:2 source into a square target.' );
rpnear( 0.5, $pplan['operations'][0]['image']['placement']['source_crop']['height'], 0.0000001, 'Cover must expose the central half of a 1:2 source.' );

// Portable hashes ignore runtime font paths as well as asset paths.
$text_runtime_a = $doc;
$text_runtime_a['_runtime'] = array( 'fonts' => array( str_repeat( 'a', 64 ) => array( 'path' => '/machine-a/font.ttf' ) ) );
$text_runtime_b = $doc;
$text_runtime_b['_runtime'] = array( 'fonts' => array( str_repeat( 'a', 64 ) => array( 'path' => '/machine-b/font.ttf' ) ) );
$text_plan_a = $builder->build( $text_runtime_a, array( 'font_manifest' => $manifest ) );
$text_plan_b = $builder->build( $text_runtime_b, array( 'font_manifest' => $manifest ) );
rpsame( $builder->hash( $text_plan_a ), $builder->hash( $text_plan_b ), 'Render-plan hash must be portable across local font paths.' );

// Acceptance evidence is versioned so old proof cannot certify the shared-plan architecture.
rpsame(3,RendererAcceptanceContract::VERSION,'Renderer acceptance evidence must advance to v3 after independent artifact-bundle/provenance binding.');
rpcheck(in_array('shared_render_plan',RendererAcceptanceContract::required_checks(),true),'PDF-04 must require proof that both backends use the shared render plan.');
$reference_manifest = file_get_contents(__DIR__.'/fixtures/rendering/render-plan-manifest.json');
rpcheck(is_string($reference_manifest) && 1===preg_match('/^[0-9a-f]{64}$/',hash('sha256',$reference_manifest)),'Stored shared render-plan manifest must itself be content-addressed.');
rpsame( '3070631a405c471fd663373b7440243cfa915d65eb38c8ddb4746ed35f2e1279', hash( 'sha256', $reference_manifest ), 'Stored shared render-plan manifest must remain frozen for the six permanent fixtures.' );

// The coordinator must hand adapters the shared plan and return its portable hash.
final class RPTestRenderer implements RendererInterface {
	public $received;
	public function id(){return 'rp-test';}
	public function version(){return '1';}
	public function formats(){return array('pdf');}
	public function requirements(){return array();}
	public function context(){return array();}
	public function render(array $render_plan,$format){$this->received=$render_plan;return "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\n";}
}
$renderer=new RPTestRenderer(); $registry=new RendererRegistry(); $registry->register($renderer); $coordinator=new ArtifactRenderCoordinator($registry);
$shape_only=array('_meta'=>array('schema'=>'certloom-layout','schema_version'=>1),'page'=>rppage(),'elements'=>array($shape));
$out=$coordinator->render('pdf',str_repeat('9',64),$shape_only);
rpsame('certloom-render-plan',$renderer->received['_meta']['schema'],'Coordinator must pass render-plan schema to adapters.');
rpcheck(isset($out['render_plan_hash'])&&1===preg_match('/^[0-9a-f]{64}$/',$out['render_plan_hash']),'Coordinator must expose portable render-plan SHA-256 for acceptance evidence.');
try {
	$invalid_shape_only = $shape_only;
	$invalid_shape_only['page']['width_mm'] = 296.0;
	$coordinator->render( 'pdf', str_repeat( '9', 64 ), $invalid_shape_only );
	rpcheck( false, 'Coordinator must reject malformed render-plan input before adapter execution.' );
} catch ( RuntimeException $error ) {
	rpcheck( 0 === strpos( $error->getMessage(), 'Certloom render plan preparation failed' ), 'Coordinator must distinguish render-plan preparation failure from renderer execution failure.' );
}

echo "PASS  Shared renderer-independent render plan centralizes cross-adapter geometry\n";
echo "\n{$checks} checks, 0 failures\n";
