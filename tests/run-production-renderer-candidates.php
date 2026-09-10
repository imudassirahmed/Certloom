<?php
/** Concrete PDF-04 candidate adapter tests without requiring mPDF/ext-gd. */
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/render-plan-reference/reference_lib.php';

use Certloom\Application\Contract\GdDriverInterface;
use Certloom\Application\Rendering\ArabicPresentationShaper;
use Certloom\Application\Rendering\DeterministicPdfNormalizer;
use Certloom\Infrastructure\Rendering\GdRasterRenderer;
use Certloom\Infrastructure\Rendering\MpdfPdfRenderer;

$checks = 0;
function prc_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function prc_throws( callable $fn ) { try { $fn(); } catch ( Throwable $e ) { return true; } return false; }

// Isolated source regression shim: the real acceptance host proves ext-gd/FreeType.
// This process only supplies deterministic geometry/image primitives so the mPDF
// adapter orchestration can be exercised without requiring ext-gd in CI.
eval( <<<'PHPGDSTUB'
namespace Certloom\Infrastructure\Rendering;
function imagettfbbox($size,$angle,$font,$text){$width=max(1,strlen((string)$text))*((float)$size)*0.45;return array(0,-(float)$size,$width,-(float)$size,$width,0,0,0);}
function imagecreatefromstring($bytes){$o=new \stdClass();$o->w=100;$o->h=50;return $o;}
function imagesx($image){return (int)$image->w;}
function imagesy($image){return (int)$image->h;}
function imagecrop($image,$rect){$o=new \stdClass();$o->w=(int)$rect['width'];$o->h=(int)$rect['height'];return $o;}
function imagesavealpha($image,$save){return true;}
function imagepng($image,$path,$quality=9,$filters=-1){return false!==file_put_contents($path,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFUlEQVR4nGP8//8/AwMDEwMDA4ICADkbAwP+wj6MAAAAAElFTkSuQmCC'));}
function imagedestroy($image){return true;}
PHPGDSTUB
);

if ( ! class_exists( 'CertloomVendor\\Mpdf\\Mpdf', false ) ) {
	eval( <<<'PHPSTUB'
namespace CertloomVendor\Mpdf;
class Mpdf {
	public static $lastConfig = array();
	public static $lastInstance;
	public $autoScriptToLang = true;
	public $autoLangToFont = true;
	public $useSubstitutions = true;
	public $calls = array();
	public function __construct(array $config=array()){self::$lastConfig=$config;self::$lastInstance=$this;}
	public function SetCreator($v){$this->calls[]=array('creator',$v);}
	public function AddPage(){ $this->calls[]=array('page'); }
	public function SetAlpha($v){$this->calls[]=array('alpha',$v);}
	public function WriteFixedPosHTML($html,$x,$y,$w,$h,$overflow='visible'){$this->calls[]=array('html',$html,$x,$y,$w,$h,$overflow);}
	public function SetFillColor($r,$g,$b){$this->calls[]=array('fill',$r,$g,$b);}
	public function SetDrawColor($r,$g,$b){$this->calls[]=array('draw',$r,$g,$b);}
	public function SetLineWidth($w){$this->calls[]=array('width',$w);}
	public function Rect($x,$y,$w,$h,$style=''){$this->calls[]=array('rect',$x,$y,$w,$h,$style);}
	public function Ellipse($x,$y,$w,$h,$style=''){$this->calls[]=array('ellipse',$x,$y,$w,$h,$style);}
	public function Line($x1,$y1,$x2,$y2){$this->calls[]=array('line',$x1,$y1,$x2,$y2);}
	public function Image($path,$x,$y,$w,$h,$type='',$link='',$paint=true,$constrain=true){$this->calls[]=array('image',$path,$x,$y,$w,$h);}
	public function StartTransform(){$this->calls[]=array('transform-start');}
	public function transformRotate($angle,$x=0,$y=0){$this->calls[]=array('transform-rotate',$angle,$x,$y);}
	public function SetFont($family,$style='',$size=0){$this->calls[]=array('font',$family,$size);}
	public function SetTextColor($r,$g,$b){$this->calls[]=array('text-color',$r,$g,$b);}
	public function Text($x,$y,$text){$this->calls[]=array('text',$x,$y,$text);}
	public function GetStringWidth($text){return max(0.1,strlen((string)$text)*1.5);}
	public function StopTransform(){$this->calls[]=array('transform-stop');}
	public function Output($name='',$dest='S'){
		$uuid=sprintf('%08x-%04x-%04x-%04x-%012x',random_int(0,0xffffffff),1,2,3,4);
		$id=bin2hex(random_bytes(16));
		return "%PDF-1.7\n<xmp:CreateDate>2026-08-24T05:00:00+05:00</xmp:CreateDate>\n<rdf:Description rdf:about=\"uuid:$uuid\"/>\n/CreationDate (D:20260824050000+05'00')\n/ID [<$id><$id>]\n%%EOF\n";
	}
}
PHPSTUB
	);
}

final class PrcFakeGdDriver implements GdDriverInterface {
	public $calls = array();
	private function image($w,$h){$o=new stdClass();$o->w=(int)$w;$o->h=(int)$h;return $o;}
	public function create_canvas($w,$h){$this->calls[]=array('canvas',$w,$h);return $this->image($w,$h);}
	public function create_transparent_canvas($w,$h){$this->calls[]=array('transparent',$w,$h);return $this->image($w,$h);}
	public function allocate_color($image,array $color,$opacity=1.0){return array('color',$color,$opacity);}
	public function fill($image,$x,$y,$color){}
	public function filled_rectangle($image,$x1,$y1,$x2,$y2,$color){$this->calls[]=array('filled-rect',$x1,$y1,$x2,$y2);}
	public function rectangle($image,$x1,$y1,$x2,$y2,$color,$thickness=1){$this->calls[]=array('rect',$x1,$y1,$x2,$y2,$thickness);}
	public function filled_ellipse($image,$cx,$cy,$w,$h,$color){$this->calls[]=array('filled-ellipse',$cx,$cy,$w,$h);}
	public function ellipse($image,$cx,$cy,$w,$h,$color,$thickness=1){$this->calls[]=array('ellipse',$cx,$cy,$w,$h,$thickness);}
	public function line($image,$x1,$y1,$x2,$y2,$color,$thickness=1){$this->calls[]=array('line',$x1,$y1,$x2,$y2,$thickness);}
	public function text_box($size,$angle,$font,$text){$width=max(1,strlen($text))*$size*0.45;return array(0,-$size,$width,-$size,$width,0,0,0);}
	public function text($image,$size,$angle,$x,$y,$color,$font,$text){$this->calls[]=array('text',$size,$angle,$x,$y,$text);}
	public function load_image($path,$mime){$this->calls[]=array('load-image',$path,$mime);return $this->image(100,50);}
	public function image_size($image){return array($image->w,$image->h);}
	public function multiply_opacity($image,$opacity){$this->calls[]=array('opacity',(float)$opacity);}
	public function copy_resampled($destination,$source,$dx,$dy,$dw,$dh,$sx,$sy,$sw,$sh){$this->calls[]=array('resample',$dx,$dy,$dw,$dh,$sx,$sy,$sw,$sh);}
	public function rotate($image,$angle){$this->calls[]=array('rotate',$angle);return $this->image($image->h+10,$image->w+10);}
	public function copy($destination,$source,$x,$y){$this->calls[]=array('copy',$x,$y);}
	public function encode($image,$format){return 'png'===$format?base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFUlEQVR4nGP8//8/AwMDEwMDA4ICADkbAwP+wj6MAAAAAElFTkSuQmCC'):base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==');}
	public function destroy($image){}
}

function prc_plan($font_path,$text='Amina Rahman',$direction='ltr',$rotation=0.0,$overflow='shrink'){
	$hash=hash_file('sha256',$font_path);
	return array(
		'_meta'=>array('schema'=>'certloom-render-plan','version'=>1),
		'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),
		'operations'=>array(
			array('id'=>'11111111-1111-4111-8111-111111111111','type'=>'text','z'=>1,'opacity'=>1.0,
				'geometry'=>array('box'=>array('x_mm'=>30.0,'y_mm'=>50.0,'width_mm'=>120.0,'height_mm'=>30.0),'center'=>array('x_mm'=>90.0,'y_mm'=>65.0),'rotation_deg'=>$rotation,'rotated_bounds'=>array('x_mm'=>25.0,'y_mm'=>40.0,'width_mm'=>130.0,'height_mm'=>50.0)),
				'text'=>array('value'=>$text,'direction'=>$direction,'align'=>'center','vertical_align'=>'middle','overflow'=>$overflow,'font'=>array('family'=>'dejavu','weight'=>400,'italic'=>false,'faces'=>array(array('sha256'=>$hash,'format'=>'ttf')),'size_pt'=>28.0,'size_mm'=>9.877778,'line_height'=>1.2,'line_height_mm'=>11.853333,'letter_spacing_pt'=>0.0,'letter_spacing_mm'=>0.0),'color'=>array('hex'=>'#000000','r'=>0,'g'=>0,'b'=>0,'alpha'=>255,'alpha_unit'=>1.0))),
			array('id'=>'66666666-6666-4666-8666-666666666666','type'=>'qr','z'=>2,'opacity'=>1.0,'geometry'=>array('box'=>array('x_mm'=>160.0,'y_mm'=>100.0,'width_mm'=>30.0,'height_mm'=>30.0),'center'=>array('x_mm'=>175.0,'y_mm'=>115.0),'rotation_deg'=>0.0,'rotated_bounds'=>array('x_mm'=>160.0,'y_mm'=>100.0,'width_mm'=>30.0,'height_mm'=>30.0)),'qr'=>array('data'=>'https://example.test/credential/X','encoder'=>array('id'=>'local','version'=>'1'),'error_correction'=>'H','matrix_size'=>21,'quiet_zone_modules'=>4,'total_modules'=>29,'module_size_mm'=>1.034483,'dark_runs'=>array(array('x_mm'=>164.0,'y_mm'=>104.0,'width_mm'=>3.0,'height_mm'=>1.0))))
		),
		'_runtime'=>array('fonts'=>array($hash=>array('path'=>$font_path,'sha256'=>$hash,'format'=>'ttf','size'=>filesize($font_path))))
	);
}

function prc_schema4_plan($font_path){
	$hash=hash_file('sha256',$font_path);
	$font=array('family'=>'dejavu','weight'=>400,'italic'=>false,'faces'=>array(array('sha256'=>$hash,'format'=>'ttf')),'size_pt'=>16.0,'size_mm'=>5.644444,'line_height'=>1.2,'line_height_mm'=>6.773333,'letter_spacing_pt'=>0.8,'letter_spacing_mm'=>0.282222);
	$color=array('hex'=>'#111827','r'=>17,'g'=>24,'b'=>39,'alpha'=>255,'alpha_unit'=>1.0);
	$rich=array('id'=>'88888888-8888-4888-8888-888888888801','type'=>'rich_text','z'=>1,'opacity'=>1.0,'geometry'=>array('box'=>array('x_mm'=>20.0,'y_mm'=>20.0,'width_mm'=>130.0,'height_mm'=>26.0),'center'=>array('x_mm'=>85.0,'y_mm'=>33.0),'rotation_deg'=>7.5,'rotated_bounds'=>array('x_mm'=>18.0,'y_mm'=>12.0,'width_mm'=>134.0,'height_mm'=>42.0)),'rich_text'=>array('direction'=>'ltr','align'=>'center','vertical_align'=>'middle','overflow'=>'shrink','base_font'=>$font,'base_color'=>$color,'runs'=>array(array('value'=>'Awarded to ','font'=>$font,'color'=>$color),array('value'=>'Amina Rahman','font'=>array_merge($font,array('weight'=>600,'letter_spacing_pt'=>0.2,'letter_spacing_mm'=>0.070556)),'color'=>array('hex'=>'#1450E0','r'=>20,'g'=>80,'b'=>224,'alpha'=>255,'alpha_unit'=>1.0)))));
	$cell_font=array_merge($font,array('size_pt'=>10.0,'size_mm'=>3.527778,'letter_spacing_pt'=>0.2,'letter_spacing_mm'=>0.070556));
	$cell_text=function($value,$weight=400)use($cell_font,$color){$f=array_merge($cell_font,array('weight'=>$weight));return array('value'=>$value,'direction'=>'ltr','align'=>'left','vertical_align'=>'middle','overflow'=>'shrink','font'=>$f,'color'=>$color);};
	$table=array('id'=>'88888888-8888-4888-8888-888888888802','type'=>'table','z'=>2,'opacity'=>0.95,'geometry'=>array('box'=>array('x_mm'=>30.0,'y_mm'=>70.0,'width_mm'=>150.0,'height_mm'=>36.0),'center'=>array('x_mm'=>105.0,'y_mm'=>88.0),'rotation_deg'=>-4.0,'rotated_bounds'=>array('x_mm'=>27.0,'y_mm'=>64.0,'width_mm'=>156.0,'height_mm'=>48.0)),'table'=>array('columns'=>array(array('key'=>'subject','label'=>'Subject','align'=>'left','width_pct'=>70.0,'x_mm'=>0.0,'width_mm'=>105.0),array('key'=>'score','label'=>'Score','align'=>'left','width_pct'=>30.0,'x_mm'=>105.0,'width_mm'=>45.0)),'rows'=>array(
		array('index'=>0,'header'=>true,'fill'=>array('hex'=>'#F3F4F6','r'=>243,'g'=>244,'b'=>246,'alpha'=>255,'alpha_unit'=>1.0),'cells'=>array(array('column_key'=>'subject','box'=>array('x_mm'=>0.0,'y_mm'=>0.0,'width_mm'=>105.0,'height_mm'=>12.0),'content_box'=>array('x_mm'=>1.5,'y_mm'=>1.5,'width_mm'=>102.0,'height_mm'=>9.0),'text'=>$cell_text('Subject',600)),array('column_key'=>'score','box'=>array('x_mm'=>105.0,'y_mm'=>0.0,'width_mm'=>45.0,'height_mm'=>12.0),'content_box'=>array('x_mm'=>106.5,'y_mm'=>1.5,'width_mm'=>42.0,'height_mm'=>9.0),'text'=>$cell_text('Score',600)))),
		array('index'=>1,'header'=>false,'fill'=>array('hex'=>'#FFFFFF','r'=>255,'g'=>255,'b'=>255,'alpha'=>255,'alpha_unit'=>1.0),'cells'=>array(array('column_key'=>'subject','box'=>array('x_mm'=>0.0,'y_mm'=>12.0,'width_mm'=>105.0,'height_mm'=>12.0),'content_box'=>array('x_mm'=>1.5,'y_mm'=>13.5,'width_mm'=>102.0,'height_mm'=>9.0),'text'=>$cell_text('Mathematics')),array('column_key'=>'score','box'=>array('x_mm'=>105.0,'y_mm'=>12.0,'width_mm'=>45.0,'height_mm'=>12.0),'content_box'=>array('x_mm'=>106.5,'y_mm'=>13.5,'width_mm'=>42.0,'height_mm'=>9.0),'text'=>$cell_text('98'))))
	),'show_header'=>true,'grid'=>array('border_color'=>array('hex'=>'#D1D5DB','r'=>209,'g'=>213,'b'=>219,'alpha'=>255,'alpha_unit'=>1.0),'border_width_mm'=>0.2),'cell_padding_mm'=>1.5,'row_height_mm'=>12.0,'row_slots'=>2));
	$primitive=certloom_render_plan_reference_cases()['primitives']['render_plan'];$signature=null;foreach($primitive['operations'] as $candidate){if('image'===$candidate['type']){$signature=$candidate;break;}}if(!is_array($signature)){throw new RuntimeException('Primitive fixture must expose an image operation.');}$signature['id']='88888888-8888-4888-8888-888888888803';$signature['type']='signature';$signature['z']=3;
	return array('_meta'=>array('schema'=>'certloom-render-plan','version'=>1),'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),'operations'=>array($rich,$table,$signature),'_runtime'=>array('fonts'=>array($hash=>array('path'=>$font_path,'sha256'=>$hash,'format'=>'ttf','size'=>filesize($font_path))),'assets'=>(array)($primitive['_runtime']['assets']??array())));
}

$font_candidates=array('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/share/fonts/dejavu/DejaVuSans.ttf');$font='';foreach($font_candidates as $candidate){if(is_file($candidate)){$font=$candidate;break;}}
if(''===$font){throw new RuntimeException('Candidate tests require the established local DejaVu Sans development fixture font.');}
$manifest=array('schema'=>'certloom-font-manifest','version'=>2,'families'=>array('dejavu'=>array('400-normal'=>array('faces'=>array(array('sha256'=>hash_file('sha256',$font),'format'=>'ttf','coverage'=>array('U+0000-10FFFF')))))));
$candidate_hash=str_repeat('a',64);

$shaper=new ArabicPresentationShaper();
prc_check('ﯽﻠﻋ ﺪﻤﺤﻣ'===$shaper->visual_text('محمد علی','rtl'),'Urdu phrase must be shaped and visually reordered as one RTL phrase.');
prc_check('Certificate — ﯽﻠﻋ ﺪﻤﺤﻣ'===$shaper->visual_text('Certificate — محمد علی','ltr'),'Mixed-script Arabic phrase must preserve LTR prefix while reversing the whole Arabic phrase visually.');

$normalizer=new DeterministicPdfNormalizer();
$pdf1="%PDF-1.7\n<xmp:CreateDate>2026-08-24T05:00:00+05:00</xmp:CreateDate>\n<rdf:Description rdf:about=\"uuid:aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee\"/>\n/CreationDate (D:20260824050000+05'00')\n/ID [<0123456789abcdef><fedcba9876543210>]\n%%EOF\n";
$pdf2=str_replace(array('2026-08-24T05:00:00+05:00','aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee','20260824050000+05\'00\'','0123456789abcdef','fedcba9876543210'),array('2027-09-25T06:01:01+05:00','ffffffff-1111-4222-8333-444444444444','20270925060101+05\'00\'','aaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbb'),$pdf1);
$n1=$normalizer->normalize($pdf1,str_repeat('b',64));$n2=$normalizer->normalize($pdf2,str_repeat('b',64));
prc_check(strlen($pdf1)===strlen($n1)&&strlen($pdf2)===strlen($n2),'PDF normalizer must preserve byte length/xref offsets.');
prc_check($n1===$n2,'PDF normalizer must remove volatile date/UUID/file-ID differences deterministically.');

$mpdf=new MpdfPdfRenderer('8.3.1-adapter1',$candidate_hash,$manifest,sys_get_temp_dir());
prc_check(MpdfPdfRenderer::ID===$mpdf->id()&&array('pdf')===$mpdf->formats(),'mPDF candidate identity/formats must be stable.');
prc_check(array('gd','mbstring')===$mpdf->requirements()['extensions'],'mPDF candidate must declare mPDF runtime extensions explicitly.');
prc_check('none'===$mpdf->context()['network']&&false===$mpdf->context()['remote_assets'],'mPDF candidate context must remain no-network/local-only.');
$mpdf_bytes_a=$mpdf->render(prc_plan($font),'pdf');$mpdf_bytes_b=$mpdf->render(prc_plan($font),'pdf');
prc_check(0===strpos($mpdf_bytes_a,'%PDF-')&&$mpdf_bytes_a===$mpdf_bytes_b,'mPDF adapter must normalize volatile PDF metadata into deterministic bytes.');
prc_check(false!==strpos(CertloomVendor\Mpdf\Mpdf::$lastConfig['fontdata'][array_key_first(CertloomVendor\Mpdf\Mpdf::$lastConfig['fontdata'])]['R'],'DejaVuSans.ttf'),'mPDF adapter must configure the exact runtime font file rather than system fallback.');
$initial_calls=CertloomVendor\Mpdf\Mpdf::$lastInstance->calls;$page_pos=null;$alpha_pos=null;foreach($initial_calls as $i=>$call){if('page'===$call[0]&&null===$page_pos){$page_pos=$i;}if('alpha'===$call[0]&&null===$alpha_pos){$alpha_pos=$i;}}prc_check(null!==$page_pos&&null!==$alpha_pos&&$page_pos<$alpha_pos,'mPDF adapter must create the page before any graphics-state operation.');
prc_check(prc_throws(function()use($mpdf,$font){$mpdf->render(prc_plan($font),'png');}),'mPDF candidate must reject non-PDF formats.');
$multi=prc_plan($font);$multi['operations'][0]['text']['font']['faces'][]=$multi['operations'][0]['text']['font']['faces'][0];
prc_check(prc_throws(function()use($mpdf,$multi){$mpdf->render($multi,'pdf');}),'mPDF candidate must fail closed for unresolved multi-face fallback.');

$primitive_case=certloom_render_plan_reference_cases()['primitives'];
$primitive_plan=$primitive_case['render_plan'];
$primitive_pdf=$mpdf->render($primitive_plan,'pdf');
prc_check(0===strpos($primitive_pdf,'%PDF-'),'mPDF candidate must accept the permanent primitive-coverage Render Plan.');
prc_check(!array_key_exists('fontDir',CertloomVendor\Mpdf\Mpdf::$lastConfig)&&!array_key_exists('fontdata',CertloomVendor\Mpdf\Mpdf::$lastConfig),'Graphics-only mPDF plans must preserve the frozen candidate bundled parser/default font configuration instead of replacing it with empty arrays.');
$primitive_calls=CertloomVendor\Mpdf\Mpdf::$lastInstance->calls;
$seen_rect=false;$seen_ellipse=false;$seen_line=false;$seen_image=false;$seen_native_rotation=false;
foreach($primitive_calls as $call){if('rect'===$call[0]){$seen_rect=true;}if('ellipse'===$call[0]){$seen_ellipse=true;}if('line'===$call[0]){$seen_line=true;}if('image'===$call[0]){$seen_image=true;}if('transform-rotate'===$call[0]){$seen_native_rotation=true;}}
prc_check($seen_rect&&$seen_ellipse&&$seen_line,'mPDF candidate must emit native rectangle, ellipse and line primitives.');
prc_check($seen_image,'mPDF candidate must render verified local images without remote lookup.');
prc_check($seen_native_rotation,'mPDF candidate must preserve non-text primitive rotation through native mPDF transforms.');
$tampered=tempnam(sys_get_temp_dir(),'certloom-asset-tamper-');file_put_contents($tampered,'not-the-frozen-image');$bad_primitive=$primitive_plan;$asset_hash=$bad_primitive['operations'][3]['image']['asset']['sha256'];$bad_primitive['_runtime']['assets'][$asset_hash]['path']=$tampered;
prc_check(prc_throws(function()use($mpdf,$bad_primitive){$mpdf->render($bad_primitive,'pdf');}),'mPDF candidate must re-prove local raster asset bytes at adapter execution time.');@unlink($tampered);
$schema4_plan=prc_schema4_plan($font);$schema4_pdf=$mpdf->render($schema4_plan,'pdf');prc_check(0===strpos($schema4_pdf,'%PDF-'),'mPDF candidate consumes Schema-4 rich text, Table and resolved Signer-signature Render Plan operations.');
$schema4_calls=CertloomVendor\Mpdf\Mpdf::$lastInstance->calls;$schema4_rect=false;$schema4_rotation=false;$schema4_image=false;$schema4_text=array();foreach($schema4_calls as $call){if('rect'===$call[0]){$schema4_rect=true;}if('image'===$call[0]){$schema4_image=true;}if('text'===$call[0]){$schema4_text[]=(string)$call[3];}if('transform-rotate'===$call[0]&&(abs($call[1]-7.5)<0.001||abs($call[1]+4.0)<0.001)){$schema4_rotation=true;}}$schema4_joined=implode('',$schema4_text);$schema4_compact=preg_replace('/\s+/u','',$schema4_joined);prc_check(is_string($schema4_compact)&&false!==strpos($schema4_compact,'Awarded')&&false!==strpos($schema4_compact,'Mathematics')&&$schema4_rect&&$schema4_image&&$schema4_rotation,'mPDF candidate preserves native rich-run text, deterministic Table cells, local signature image and shared complex-operation rotation.');
prc_check(false===strpos(file_get_contents(__DIR__.'/../src/Infrastructure/Rendering/MpdfPdfRenderer.php'),'font-weight:')&&false===strpos(file_get_contents(__DIR__.'/../src/Infrastructure/Rendering/MpdfPdfRenderer.php'),'WriteFixedPosHTML'),'mPDF Schema-4 paths must select exact content-addressed faces and avoid synthetic HTML styling.');

$driver=new PrcFakeGdDriver();$gd=new GdRasterRenderer('1.0.0',$candidate_hash,$manifest,$driver);
prc_check(GdRasterRenderer::ID===$gd->id()&&array('png','jpeg')===$gd->formats(),'GD candidate identity/formats must be stable.');
prc_check(array('gd')===$gd->requirements()['extensions']&&in_array('imagettftext',$gd->requirements()['functions'],true),'GD candidate must declare exact raster/FreeType runtime requirements.');
$png=$gd->render(prc_plan($font),'png');$jpg=$gd->render(prc_plan($font,'محمد علی','rtl'),'jpeg');
$primitive_png=$gd->render($primitive_plan,'png');
prc_check(0===strpos($primitive_png,"\x89PNG"),'GD candidate must accept the permanent primitive-coverage Render Plan.');
$seen_resample=false;$seen_primitive_rotate=false;$seen_opacity=false;$seen_supersample=false;foreach($driver->calls as $call){if('resample'===$call[0]){$seen_resample=true;}if('rotate'===$call[0]&&(abs($call[1]-8.0)<0.001||abs($call[1]-5.0)<0.001||abs($call[1]+4.0)<0.001)){$seen_primitive_rotate=true;}if('opacity'===$call[0]&&$call[1]<0.999999){$seen_opacity=true;}if('transparent'===$call[0]&&($call[1]>500||$call[2]>500)){$seen_supersample=true;}}
prc_check($seen_resample&&$seen_primitive_rotate,'GD candidate must exercise local image placement and rotated non-text primitive composition.');
prc_check($seen_opacity,'GD candidate must multiply image/logo/signature source alpha by Render Plan operation opacity before composition.');
prc_check($seen_supersample,'GD candidate must supersample rotated raster/shape operation canvases before deterministic downsampling.');
$schema4_driver=new PrcFakeGdDriver();$schema4_gd=new GdRasterRenderer('1.0.0',$candidate_hash,$manifest,$schema4_driver);$schema4_png=$schema4_gd->render(prc_schema4_plan($font),'png');$schema4_text=0;$schema4_grid=false;$schema4_grid_quantized=true;$schema4_asset=false;$schema4_rich_rotate=false;$schema4_table_rotate=false;foreach($schema4_driver->calls as $call){if('text'===$call[0]){$schema4_text++;}if('rect'===$call[0]){$schema4_grid=true;$schema4_grid_quantized=$schema4_grid_quantized&&is_int($call[1])&&is_int($call[2])&&is_int($call[3])&&is_int($call[4]);}if('resample'===$call[0]){$schema4_asset=true;}if('rotate'===$call[0]&&abs($call[1]-7.5)<0.001){$schema4_rich_rotate=true;}if('rotate'===$call[0]&&abs($call[1]+4.0)<0.001){$schema4_table_rotate=true;}}prc_check(0===strpos($schema4_png,"\x89PNG")&&$schema4_text>4&&$schema4_grid&&$schema4_grid_quantized&&$schema4_asset&&$schema4_rich_rotate&&$schema4_table_rotate,'GD candidate consumes Schema-4 rich runs, integer-quantized Table borders, deterministic Table cells, local signature image and arbitrary operation rotation.');
prc_check(0===strpos($png,"\x89PNG")&&0===strpos($jpg,"\xFF\xD8"),'GD candidate must produce requested raster formats through its driver.');
$seen_text=false;$seen_qr=false;foreach($driver->calls as $call){if('text'===$call[0]){$seen_text=true;}if('filled-rect'===$call[0]){$seen_qr=true;}}
prc_check($seen_text&&$seen_qr,'GD candidate must consume text and exact QR dark-run operations.');
$rot=prc_plan($font,'Rotated credential text','ltr',17.5,'clip');$gd->render($rot,'png');$seen_rotate=false;foreach($driver->calls as $call){if('rotate'===$call[0]&&abs($call[1]-17.5)<0.001){$seen_rotate=true;}}
prc_check($seen_rotate,'GD candidate must rotate around an isolated operation canvas for arbitrary-angle fixtures.');
prc_check(prc_throws(function()use($gd,$font){$gd->render(prc_plan($font,'שלום','ltr'),'png');}),'GD candidate must fail closed for unsupported complex scripts rather than silently degrade them.');
$spacing=prc_plan($font);$spacing['operations'][0]['text']['font']['letter_spacing_pt']=1.0;
$spacing_png=$gd->render($spacing,'png');prc_check(0===strpos($spacing_png,"\x89PNG"),'GD candidate must render bounded non-zero letter spacing through deterministic grapheme-run spacing.');
prc_check(prc_throws(function()use($gd,$font){$gd->render(prc_plan($font),'pdf');}),'GD candidate must reject non-raster formats.');

prc_check(!is_file(__DIR__.'/../resources/renderer/accepted.json'),'Concrete candidate implementations must not imply release acceptance.');
echo "PASS  Concrete mPDF/GD PDF-04 candidate adapters are bounded, deterministic at their portable boundary, and fail closed\n\n{$checks} checks, 0 failures\n";
