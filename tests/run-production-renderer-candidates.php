<?php
/** Concrete PDF-04 candidate adapter tests without requiring mPDF/ext-gd. */
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\GdDriverInterface;
use Certloom\Application\Rendering\ArabicPresentationShaper;
use Certloom\Application\Rendering\DeterministicPdfNormalizer;
use Certloom\Infrastructure\Rendering\GdRasterRenderer;
use Certloom\Infrastructure\Rendering\MpdfPdfRenderer;

$checks = 0;
function prc_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function prc_throws( callable $fn ) { try { $fn(); } catch ( Throwable $e ) { return true; } return false; }

if ( ! class_exists( 'CertloomVendor\\Mpdf\\Mpdf', false ) ) {
	eval( <<<'PHPSTUB'
namespace CertloomVendor\Mpdf;
class Mpdf {
	public static $lastConfig = array();
	public $autoScriptToLang = true;
	public $autoLangToFont = true;
	public $useSubstitutions = true;
	public $calls = array();
	public function __construct(array $config=array()){self::$lastConfig=$config;}
	public function SetCreator($v){$this->calls[]=array('creator',$v);}
	public function SetAlpha($v){$this->calls[]=array('alpha',$v);}
	public function WriteFixedPosHTML($html,$x,$y,$w,$h,$overflow='visible'){$this->calls[]=array('html',$html,$x,$y,$w,$h,$overflow);}
	public function SetFillColor($r,$g,$b){$this->calls[]=array('fill',$r,$g,$b);}
	public function SetDrawColor($r,$g,$b){$this->calls[]=array('draw',$r,$g,$b);}
	public function SetLineWidth($w){$this->calls[]=array('width',$w);}
	public function Rect($x,$y,$w,$h,$style=''){$this->calls[]=array('rect',$x,$y,$w,$h,$style);}
	public function Ellipse($x,$y,$w,$h,$style=''){$this->calls[]=array('ellipse',$x,$y,$w,$h,$style);}
	public function Line($x1,$y1,$x2,$y2){$this->calls[]=array('line',$x1,$y1,$x2,$y2);}
	public function Image($path,$x,$y,$w,$h,$type='',$link='',$paint=true,$constrain=true){$this->calls[]=array('image',$path,$x,$y,$w,$h);}
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
prc_check(prc_throws(function()use($mpdf,$font){$mpdf->render(prc_plan($font),'png');}),'mPDF candidate must reject non-PDF formats.');
$multi=prc_plan($font);$multi['operations'][0]['text']['font']['faces'][]=$multi['operations'][0]['text']['font']['faces'][0];
prc_check(prc_throws(function()use($mpdf,$multi){$mpdf->render($multi,'pdf');}),'mPDF candidate must fail closed for unresolved multi-face fallback.');

$driver=new PrcFakeGdDriver();$gd=new GdRasterRenderer('1.0.0',$candidate_hash,$manifest,$driver);
prc_check(GdRasterRenderer::ID===$gd->id()&&array('png','jpeg')===$gd->formats(),'GD candidate identity/formats must be stable.');
prc_check(array('gd')===$gd->requirements()['extensions']&&in_array('imagettftext',$gd->requirements()['functions'],true),'GD candidate must declare exact raster/FreeType runtime requirements.');
$png=$gd->render(prc_plan($font),'png');$jpg=$gd->render(prc_plan($font,'محمد علی','rtl'),'jpeg');
prc_check(0===strpos($png,"\x89PNG")&&0===strpos($jpg,"\xFF\xD8"),'GD candidate must produce requested raster formats through its driver.');
$seen_text=false;$seen_qr=false;foreach($driver->calls as $call){if('text'===$call[0]){$seen_text=true;}if('filled-rect'===$call[0]){$seen_qr=true;}}
prc_check($seen_text&&$seen_qr,'GD candidate must consume text and exact QR dark-run operations.');
$rot=prc_plan($font,'Rotated credential text','ltr',17.5,'clip');$gd->render($rot,'png');$seen_rotate=false;foreach($driver->calls as $call){if('rotate'===$call[0]&&abs($call[1]-17.5)<0.001){$seen_rotate=true;}}
prc_check($seen_rotate,'GD candidate must rotate around an isolated operation canvas for arbitrary-angle fixtures.');
prc_check(prc_throws(function()use($gd,$font){$gd->render(prc_plan($font,'שלום','ltr'),'png');}),'GD candidate must fail closed for unsupported complex scripts rather than silently degrade them.');
$spacing=prc_plan($font);$spacing['operations'][0]['text']['font']['letter_spacing_pt']=1.0;
prc_check(prc_throws(function()use($gd,$spacing){$gd->render($spacing,'png');}),'GD candidate must fail closed for non-zero letter spacing until glyph-run spacing is centralized.');
prc_check(prc_throws(function()use($gd,$font){$gd->render(prc_plan($font),'pdf');}),'GD candidate must reject non-raster formats.');

prc_check(!is_file(__DIR__.'/../resources/renderer/accepted.json'),'Concrete candidate implementations must not imply release acceptance.');
echo "PASS  Concrete mPDF/GD PDF-04 candidate adapters are bounded, deterministic at their portable boundary, and fail closed\n\n{$checks} checks, 0 failures\n";
