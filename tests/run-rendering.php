<?php
/** Premium renderer-foundation tests. No WordPress or third-party renderer required. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Contract\QrEncoderInterface;
use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\FontManifest;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Application\Rendering\RenderRequestFingerprint;
use Certloom\Application\Rendering\TextDirectionResolver;
use Certloom\Infrastructure\Rendering\RendererCapabilityDetector;
use Certloom\Infrastructure\Rendering\ArtifactRenderCoordinator;
use Certloom\Domain\Rendering\ElementId;

$tests = array();
function certloom_render_test( $name, callable $fn ) { global $tests; $tests[] = array( $name, $fn ); }
function certloom_render_same( $expected, $actual, $message = '' ) { if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); } }
function certloom_render_true( $actual, $message = '' ) { certloom_render_same( true, (bool) $actual, $message ); }
function certloom_render_throws( callable $fn, $class = InvalidArgumentException::class ) { try { $fn(); } catch ( Throwable $e ) { if ( $e instanceof $class ) { return; } throw $e; } throw new RuntimeException( 'Expected exception ' . $class ); }
function certloom_render_fixture( $name ) { $json = file_get_contents( __DIR__ . '/fixtures/rendering/' . $name . '.json' ); $data = json_decode( $json, true ); if ( ! is_array( $data ) ) { throw new RuntimeException( 'Bad fixture: ' . $name ); } return $data; }

final class CertloomTestAssetResolver implements LocalAssetResolverInterface {
	private $map;
	public function __construct( array $map ) { $this->map = $map; }
	public function resolve( $reference ) {
		if ( ! isset( $this->map[ $reference ] ) ) { throw new InvalidArgumentException( 'Unknown test asset.' ); }
		$path = $this->map[ $reference ];
		return array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ), 'mime' => 'image/png', 'size' => filesize( $path ) );
	}
}


final class CertloomTestQrEncoder implements QrEncoderInterface {
	public function id(){ return 'qr-test'; }
	public function version(){ return '1.0'; }
	public function encode( $data, $error_correction ) {
		$matrix=array();
		for($y=0;$y<21;$y++){
			$row=array();
			for($x=0;$x<21;$x++){$row[]=(($x+$y)%3===0)?1:0;}
			$matrix[]=$row;
		}
		return $matrix;
	}
}

final class CertloomTestRenderer implements RendererInterface {
	private $id; private $version; private $formats; private $requirements; private $context;
	public function __construct( $id, $version, array $formats, array $requirements = array(), array $context = array() ) { $this->id=$id; $this->version=$version; $this->formats=$formats; $this->requirements=$requirements; $this->context=$context; }
	public function id(){ return $this->id; }
	public function version(){ return $this->version; }
	public function formats(){ return $this->formats; }
	public function requirements(){ return $this->requirements; }
	public function context(){ return $this->context; }
	public function render( array $resolved_document, $format ){ return 'artifact:' . $format . ':' . hash( 'sha256', json_encode( $resolved_document ) ); }
}

$validator = new LayoutValidator();
$canonical = new CanonicalJson();
$direction = new TextDirectionResolver();

certloom_render_test( 'A4 landscape geometry is canonical', function () use ( $validator ) { $l=$validator->normalize(certloom_render_fixture('english')); certloom_render_same(297.0,$l['page']['width_mm']); certloom_render_same(210.0,$l['page']['height_mm']); } );
certloom_render_test( 'Letter landscape geometry is canonical', function () use ( $validator ) { $l=$validator->normalize(certloom_render_fixture('long-name-shrink')); certloom_render_same(279.4,$l['page']['width_mm']); certloom_render_same(215.9,$l['page']['height_mm']); } );
certloom_render_test( 'canonical JSON ignores associative insertion order', function () use ( $canonical ) { certloom_render_same($canonical->encode(array('a'=>1,'b'=>2)),$canonical->encode(array('b'=>2,'a'=>1))); } );
certloom_render_test( 'canonical JSON collapses negative zero', function () use ( $canonical ) { certloom_render_same($canonical->encode(array('x'=>0.0)),$canonical->encode(array('x'=>-0.0))); } );
certloom_render_test( 'canonical JSON rejects object values', function () use ( $canonical ) { certloom_render_throws(function()use($canonical){$canonical->encode(array('x'=>(object)array('a'=>1)));}); } );
certloom_render_test( 'layout hash is deterministic', function () use ( $validator ) { $a=certloom_render_fixture('english'); $b=array('elements'=>$a['elements'],'page'=>$a['page'],'_meta'=>$a['_meta']); certloom_render_same($validator->hash($a),$validator->hash($b)); } );
certloom_render_test( 'element ID generator produces valid UUIDv4 values', function () { $ids=new ElementId(); for($i=0;$i<50;$i++){ certloom_render_true($ids->is_valid($ids->generate())); } } );
certloom_render_test( 'element IDs require UUIDv4', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['id']='not-a-uuid'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'duplicate element IDs fail', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][]=$l['elements'][0]; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'duplicate z-order values fail closed', function () use ( $validator ) { $l=certloom_render_fixture('english'); $copy=$l['elements'][0]; $copy['id']='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $copy['x_mm']=100; $copy['z']=$l['elements'][0]['z']; $l['elements'][]=$copy; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'elements normalize into deterministic z-order', function () use ( $validator ) { $l=certloom_render_fixture('english'); $a=$l['elements'][0]; $a['id']='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $a['x_mm']=10; $a['z']=20; $b=$l['elements'][0]; $b['id']='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'; $b['x_mm']=70; $b['z']=-2; $l['elements']=array($a,$b); $n=$validator->normalize($l); certloom_render_same(-2,$n['elements'][0]['z']); certloom_render_same(20,$n['elements'][1]['z']); } );
certloom_render_test( 'out-of-page geometry fails', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['x_mm']=290; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'arbitrary non-quarter rotation remains in canonical layout', function () use ( $validator ) { $l=$validator->normalize(certloom_render_fixture('rotation')); certloom_render_same(17.5,$l['elements'][0]['rotation_deg']); } );
certloom_render_test( 'rotation outside supported canonical range fails', function () use ( $validator ) { $l=certloom_render_fixture('rotation'); $l['elements'][0]['rotation_deg']=361; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'semantically equivalent full rotation normalizes to zero', function () use ( $validator ) { $l=certloom_render_fixture('rotation'); $l['elements'][0]['rotation_deg']=360; $n=$validator->normalize($l); certloom_render_same(0.0,$n['elements'][0]['rotation_deg']); } );
certloom_render_test( 'rotated geometry may not escape page bounds', function () use ( $validator ) { $l=certloom_render_fixture('rotation'); $l['elements'][0]['x_mm']=0; $l['elements'][0]['y_mm']=0; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'Urdu first strong character resolves RTL', function () use ( $direction ) { certloom_render_same('rtl',$direction->resolve('محمد علی','auto')); } );
certloom_render_test( 'mixed English first strong character resolves LTR', function () use ( $direction ) { certloom_render_same('ltr',$direction->resolve('Certificate — محمد علی','auto')); } );
certloom_render_test( 'explicit direction always wins', function () use ( $direction ) { certloom_render_same('rtl',$direction->resolve('Certificate','rtl')); } );
certloom_render_test( 'long-name shrink policy is retained', function () use ( $validator ) { $l=$validator->normalize(certloom_render_fixture('long-name-shrink')); certloom_render_same('shrink',$l['elements'][0]['style']['overflow']); } );

certloom_render_test( 'font family is a registered machine key rather than raw CSS', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['style']['font_family']='Arial; color:red'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'font manifest hash ignores family/variant insertion order', function () { $f=new FontManifest(); $a=array('schema'=>'certloom-font-manifest','version'=>1,'families'=>array('sans-serif'=>array('600-normal'=>array('sha256'=>str_repeat('a',64),'format'=>'ttf'),'400-normal'=>array('sha256'=>str_repeat('b',64),'format'=>'ttf')))); $b=array('families'=>array('sans-serif'=>array('400-normal'=>array('format'=>'ttf','sha256'=>str_repeat('b',64)),'600-normal'=>array('format'=>'ttf','sha256'=>str_repeat('a',64)))),'version'=>1,'schema'=>'certloom-font-manifest'); certloom_render_same($f->hash($a),$f->hash($b)); } );
certloom_render_test( 'font manifest requires the exact weight/style variant', function () { $f=new FontManifest(); $doc=array('elements'=>array(array('type'=>'text','style'=>array('font_family'=>'sans-serif','font_weight'=>600,'italic'=>false)))); $manifest=array('schema'=>'certloom-font-manifest','version'=>1,'families'=>array('sans-serif'=>array('400-normal'=>array('sha256'=>str_repeat('a',64),'format'=>'ttf')))); certloom_render_throws(function()use($f,$doc,$manifest){$f->assert_document_supported($doc,$manifest);}); } );
certloom_render_test( 'unknown element properties fail closed', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['html']='<b>unsafe</b>'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'remote asset references are prohibited', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]=array('id'=>'77777777-7777-4777-8777-777777777777','type'=>'image','x_mm'=>10,'y_mm'=>10,'width_mm'=>30,'height_mm'=>30,'asset'=>'https://example.com/logo.png'); certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'merge token machine keys are validated', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['token']='Recipient Name {{x}}'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'QR source is verification URL only in schema v1', function () use ( $validator ) { $l=certloom_render_fixture('qr'); $l['elements'][0]['source']='arbitrary_html'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'QR box must be square', function () use ( $validator ) { $l=certloom_render_fixture('qr'); $l['elements'][0]['width_mm']=20; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'QR quiet zone is bounded', function () use ( $validator ) { $l=certloom_render_fixture('qr'); $l['elements'][0]['quiet_zone_modules']=2; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'future schema versions fail instead of being guessed', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['_meta']['schema_version']=2; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'malformed integer-like schema values fail instead of coercing', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['z']='4x'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );
certloom_render_test( 'text italic requires a real boolean', function () use ( $validator ) { $l=certloom_render_fixture('english'); $l['elements'][0]['style']['italic']='false'; certloom_render_throws(function()use($validator,$l){$validator->normalize($l);}); } );

$tmp1=tempnam(sys_get_temp_dir(),'certloom-render-'); $tmp2=tempnam(sys_get_temp_dir(),'certloom-render-'); $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl3sAAAAASUVORK5CYII='); file_put_contents($tmp1,$png); file_put_contents($tmp2,$png);
$image_layout=certloom_render_fixture('english'); $image_layout['elements'][0]=array('id'=>'88888888-8888-4888-8888-888888888888','type'=>'image','x_mm'=>10,'y_mm'=>10,'width_mm'=>30,'height_mm'=>30,'asset'=>'asset:issuer-logo','fit'=>'contain');

certloom_render_test( 'local asset resolution carries verified content identity', function () use ( $image_layout, $tmp1 ) { $r=(new LayoutResolver())->resolve($image_layout,array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array('asset:issuer-logo'=>$tmp1))); certloom_render_same(hash_file('sha256',$tmp1),$r['elements'][0]['asset']['sha256']); certloom_render_same(1,$r['elements'][0]['asset']['width_px']); certloom_render_same(1,$r['elements'][0]['asset']['height_px']); } );
certloom_render_test( 'asset MIME is independently verified against content', function () use ( $image_layout, $tmp1 ) { $bad=new class($tmp1) implements LocalAssetResolverInterface { private $p; public function __construct($p){$this->p=$p;} public function resolve($r){return array('path'=>$this->p,'sha256'=>hash_file('sha256',$this->p),'mime'=>'image/jpeg','size'=>filesize($this->p));} }; certloom_render_throws(function()use($image_layout,$bad){(new LayoutResolver())->resolve($image_layout,array(),'https://example.test/verify/ABC',$bad);}); } );

certloom_render_test( 'unsupported local asset MIME fails before a renderer sees it', function () use ( $image_layout, $tmp1 ) { $bad=new class($tmp1) implements LocalAssetResolverInterface { private $p; public function __construct($p){$this->p=$p;} public function resolve($r){return array('path'=>$this->p,'sha256'=>hash_file('sha256',$this->p),'mime'=>'image/svg+xml','size'=>filesize($this->p));} }; certloom_render_throws(function()use($image_layout,$bad){(new LayoutResolver())->resolve($image_layout,array(),'https://example.test/verify/ABC',$bad);}); } );
certloom_render_test( 'tampered asset resolver metadata is rejected', function () use ( $image_layout, $tmp1 ) { $bad=new class($tmp1) implements LocalAssetResolverInterface { private $p; public function __construct($p){$this->p=$p;} public function resolve($r){return array('path'=>$this->p,'sha256'=>str_repeat('0',64),'mime'=>'image/png','size'=>filesize($this->p));} }; certloom_render_throws(function()use($image_layout,$bad){(new LayoutResolver())->resolve($image_layout,array(),'https://example.test/verify/ABC',$bad);}); } );
certloom_render_test( 'missing immutable merge value fails closed', function () use ( $tmp1 ) { certloom_render_throws(function()use($tmp1){(new LayoutResolver())->resolve(certloom_render_fixture('english'),array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'verification URL is mandatory and validated', function () { certloom_render_throws(function(){(new LayoutResolver())->resolve(certloom_render_fixture('english'),array('recipient.name'=>'Ali'),'javascript:alert(1)',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'verification URL rejects embedded credentials', function () { certloom_render_throws(function(){(new LayoutResolver())->resolve(certloom_render_fixture('english'),array('recipient.name'=>'Ali'),'https://user:pass@example.test/verify/ABC',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'verification URL rejects fragments', function () { certloom_render_throws(function(){(new LayoutResolver())->resolve(certloom_render_fixture('english'),array('recipient.name'=>'Ali'),'https://example.test/verify/ABC#fragment',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'merge fields resolve once to primitive text with shared direction', function () { $r=(new LayoutResolver())->resolve(certloom_render_fixture('urdu-rtl'),array('recipient.name'=>'محمد علی'),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array())); certloom_render_same('text',$r['elements'][0]['type']); certloom_render_same('محمد علی',$r['elements'][0]['text']); certloom_render_same('rtl',$r['elements'][0]['style']['direction']); certloom_render_true(!isset($r['elements'][0]['token'])); } );
certloom_render_test( 'QR data and module matrix resolve centrally before renderers', function () { $r=(new LayoutResolver(null,null,null,new CertloomTestQrEncoder()))->resolve(certloom_render_fixture('qr'),array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array())); certloom_render_same('https://example.test/verify/ABC',$r['elements'][0]['data']); certloom_render_same(21,count($r['elements'][0]['matrix'])); certloom_render_same('qr-test',$r['elements'][0]['encoder']['id']); certloom_render_true(!isset($r['elements'][0]['source'])); } );
certloom_render_test( 'QR-bearing layout fails closed without a central encoder', function () { certloom_render_throws(function(){(new LayoutResolver())->resolve(certloom_render_fixture('qr'),array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'malformed QR matrices fail before any renderer sees them', function () { $bad=new class implements QrEncoderInterface { public function id(){return 'bad';} public function version(){return '1';} public function encode($d,$e){return array(array(1));} }; certloom_render_throws(function()use($bad){(new LayoutResolver(null,null,null,$bad))->resolve(certloom_render_fixture('qr'),array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'QR matrix size must match a real Model 2 version', function () { $bad=new class implements QrEncoderInterface { public function id(){return 'bad-size';} public function version(){return '1';} public function encode($d,$e){$m=array();for($y=0;$y<23;$y++){$m[]=array_fill(0,23,0);}return $m;} }; certloom_render_throws(function()use($bad){(new LayoutResolver(null,null,null,$bad))->resolve(certloom_render_fixture('qr'),array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'QR encoder identity must be stable and versioned', function () { $bad=new class implements QrEncoderInterface { public function id(){return 'Bad Encoder';} public function version(){return '';} public function encode($d,$e){$m=array();for($y=0;$y<21;$y++){$m[]=array_fill(0,21,0);}return $m;} }; certloom_render_throws(function()use($bad){(new LayoutResolver(null,null,null,$bad))->resolve(certloom_render_fixture('qr'),array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array()));}); } );
certloom_render_test( 'same asset bytes at a different filesystem path keep request fingerprint stable', function () use ( $validator, $image_layout, $tmp1, $tmp2 ) { $resolver=new LayoutResolver(); $a=$resolver->resolve($image_layout,array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array('asset:issuer-logo'=>$tmp1))); $b=$resolver->resolve($image_layout,array(),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array('asset:issuer-logo'=>$tmp2))); $h=$validator->hash($image_layout); $f=new RenderRequestFingerprint(); certloom_render_same($f->hash($h,$a),$f->hash($h,$b)); } );
certloom_render_test( 'changed merge value changes render-request fingerprint', function () use ( $validator ) { $layout=certloom_render_fixture('english'); $resolver=new LayoutResolver(); $a=$resolver->resolve($layout,array('recipient.name'=>'Ali'),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array())); $b=$resolver->resolve($layout,array('recipient.name'=>'Sara'),'https://example.test/verify/ABC',new CertloomTestAssetResolver(array())); $f=new RenderRequestFingerprint(); $h=$validator->hash($layout); certloom_render_true($f->hash($h,$a)!==$f->hash($h,$b)); } );
certloom_render_test( 'renderer registry routes PDF and image outputs without presentation-library coupling', function () { $pdf=new CertloomTestRenderer('pdf-test','1.0',array('pdf')); $img=new CertloomTestRenderer('image-test','1.0',array('png','jpeg')); $r=new RendererRegistry(); $r->register($pdf); $r->register($img); certloom_render_same('pdf-test',$r->for_format('PDF')->id()); certloom_render_same('image-test',$r->for_format('png')->id()); } );
certloom_render_test( 'renderer registry rejects ambiguous duplicate format ownership', function () { $r=new RendererRegistry(); $r->register(new CertloomTestRenderer('a','1',array('pdf'))); certloom_render_throws(function()use($r){$r->register(new CertloomTestRenderer('b','1',array('pdf')));}); } );
certloom_render_test( 'renderer registry rejects empty format ownership', function () { $r=new RendererRegistry(); certloom_render_throws(function()use($r){$r->register(new CertloomTestRenderer('empty','1',array()));}); } );
certloom_render_test( 'renderer registry rejects unstable renderer IDs', function () { $r=new RendererRegistry(); certloom_render_throws(function()use($r){$r->register(new CertloomTestRenderer('Bad Renderer','1',array('pdf')));}); } );
certloom_render_test( 'renderer capability detector fails soft on missing requirements', function () { $renderer=new CertloomTestRenderer('needs-fictional','1',array('pdf'),array('extensions'=>array('certloom_extension_that_does_not_exist'))); $report=(new RendererCapabilityDetector())->inspect($renderer); certloom_render_same(false,$report['available']); certloom_render_true(in_array('ext:certloom_extension_that_does_not_exist',$report['missing'],true)); } );
certloom_render_test( 'artifact receipt verifies bytes and renderer environment', function () { $renderer=new CertloomTestRenderer('pdf-test','1.2.3',array('pdf'),array(),array('fonts'=>array('Noto Sans'=>'abc123'),'config'=>array('dpi'=>144))); $bytes='premium-pdf-bytes'; $receipt=(new ArtifactReceipt())->create('pdf',$bytes,str_repeat('a',64),str_repeat('b',64),$renderer); certloom_render_same(strlen($bytes),$receipt['bytes']); certloom_render_same(hash('sha256',$bytes),$receipt['sha256']); certloom_render_true((new ArtifactReceipt())->verify($receipt,$bytes,$renderer)); } );
certloom_render_test( 'artifact receipt detects byte tampering and renderer-context drift', function () { $a=new CertloomTestRenderer('pdf-test','1',array('pdf'),array(),array('font_manifest'=>'A')); $b=new CertloomTestRenderer('pdf-test','1',array('pdf'),array(),array('font_manifest'=>'B')); $receipt=(new ArtifactReceipt())->create('pdf','bytes',str_repeat('a',64),str_repeat('b',64),$a); certloom_render_same(false,(new ArtifactReceipt())->verify($receipt,'changed',$a)); certloom_render_same(false,(new ArtifactReceipt())->verify($receipt,'bytes',$b)); } );
certloom_render_test( 'artifact receipt detects metadata tampering', function () { $r=new CertloomTestRenderer('pdf-test','1',array('pdf')); $receipt=(new ArtifactReceipt())->create('pdf','bytes',str_repeat('a',64),str_repeat('b',64),$r); $receipt['layout_hash']=str_repeat('c',64); certloom_render_same(false,(new ArtifactReceipt())->verify($receipt,'bytes',$r)); } );
certloom_render_test( 'artifact receipt rejects type-coerced metadata', function () { $r=new CertloomTestRenderer('pdf-test','1',array('pdf')); $receipt=(new ArtifactReceipt())->create('pdf','bytes',str_repeat('a',64),str_repeat('b',64),$r); $receipt['version']='2'; $meta=$receipt; unset($meta['receipt_hash']); $receipt['receipt_hash']=(new CanonicalJson())->hash($meta); certloom_render_same(false,(new ArtifactReceipt())->verify($receipt,'bytes',$r)); } );
certloom_render_test( 'artifact coordinator blocks unavailable renderer before execution', function () { $r=new RendererRegistry(); $r->register(new CertloomTestRenderer('missing-ext','1',array('pdf'),array('extensions'=>array('certloom_missing_extension')))); $c=new ArtifactRenderCoordinator($r); certloom_render_throws(function()use($c){$c->render('pdf',str_repeat('a',64),array('page'=>array()));},RuntimeException::class); } );
certloom_render_test( 'artifact coordinator refuses text rendering without exact font manifest', function () { $r=new RendererRegistry(); $r->register(new CertloomTestRenderer('pdf-test','1',array('pdf'))); $c=new ArtifactRenderCoordinator($r); $doc=array('elements'=>array(array('type'=>'text','style'=>array('font_family'=>'sans-serif','font_weight'=>600,'italic'=>false)))); certloom_render_throws(function()use($c,$doc){$c->render('pdf',str_repeat('a',64),$doc);},RuntimeException::class); } );
certloom_render_test( 'artifact coordinator accepts text when exact font bytes are declared', function () { $manifest=array('schema'=>'certloom-font-manifest','version'=>1,'families'=>array('sans-serif'=>array('600-normal'=>array('sha256'=>str_repeat('a',64),'format'=>'ttf')))); $r=new RendererRegistry(); $renderer=new CertloomTestRenderer('pdf-test','1',array('pdf'),array(),array('font_manifest'=>$manifest)); $r->register($renderer); $c=new ArtifactRenderCoordinator($r); $doc=array('elements'=>array(array('type'=>'text','style'=>array('font_family'=>'sans-serif','font_weight'=>600,'italic'=>false)))); $out=$c->render('pdf',str_repeat('a',64),$doc); certloom_render_true((new ArtifactReceipt())->verify($out['receipt'],$out['bytes'],$renderer)); } );
certloom_render_test( 'artifact coordinator returns bytes plus verified receipt', function () { $r=new RendererRegistry(); $renderer=new CertloomTestRenderer('pdf-test','1',array('pdf')); $r->register($renderer); $c=new ArtifactRenderCoordinator($r); $out=$c->render('pdf',str_repeat('a',64),array('page'=>array('width_mm'=>297.0))); certloom_render_true(isset($out['bytes'],$out['request_hash'],$out['receipt'])); certloom_render_true((new ArtifactReceipt())->verify($out['receipt'],$out['bytes'],$renderer)); } );

$failed=0; foreach($tests as $test){ list($name,$fn)=$test; try{$fn(); echo "PASS  $name\n";}catch(Throwable $e){$failed++; echo "FAIL  $name\n      ".$e->getMessage()."\n";} }
@unlink($tmp1); @unlink($tmp2);
echo "\n" . (count($tests)-$failed) . '/' . count($tests) . " rendering tests passed.\n";
exit($failed?1:0);
