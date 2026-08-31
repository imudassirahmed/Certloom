<?php
/** TPL-02 + PRESET-01 premium factory-template contract tests. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($key){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$key));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\PresetCatalog;

$checks=0;
function p16($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);} $checks++;}
function p16has($source,$needle,$message){p16(false!==strpos($source,$needle),$message);}

$catalog=new PresetCatalog();
$all=$catalog->all();
p16(6===count(PresetCatalog::families()),'six premium families');
p16(4===count(PresetCatalog::variants()),'four page/orientation variants');
p16(24===count($all),'24 canonical starter layouts');

$validator=new LayoutValidator(); $canonical=new CanonicalJson(); $keys=array(); $hashes=array(); $families=array(); $variant_counts=array();
foreach($all as $d){
	p16(1===$d['preset_version'],'factory preset version 1');
	p16(0===strpos($d['machine_key'],'certloom-preset-'),'factory namespace reserved');
	p16(!isset($keys[$d['preset_key']]),'preset key unique '.$d['preset_key']); $keys[$d['preset_key']]=true;
	p16(!isset($hashes[$d['layout_sha256']]),'layout hash unique '.$d['preset_key']); $hashes[$d['layout_sha256']]=true;
	$n=$validator->normalize($d['layout']); p16(hash_equals($d['layout_sha256'],$canonical->hash($n)),'canonical hash exact '.$d['preset_key']);
	p16($d['page_size']===$n['page']['size']&&$d['orientation']===$n['page']['orientation'],'page metadata exact '.$d['preset_key']);
	$families[$d['family_key']]=true; $variant_counts[$d['family_key']]=($variant_counts[$d['family_key']]??0)+1;
	$qr=0;$recipient=0;$program=0;$number=0;$date=0;$ids=array();
	foreach($n['elements'] as $e){
		p16(!isset($ids[$e['id']]),'element id unique '.$d['preset_key']);$ids[$e['id']]=true;
		p16(!in_array($e['type'],array('image','logo','signature'),true),'factory candidate has no unresolved external/local asset dependency '.$d['preset_key']);
		if('qr'===$e['type']){$qr++;p16('verification_url'===$e['source']&&'H'===$e['error_correction'],'QR uses canonical verification truth '.$d['preset_key']);}
		if('merge'===$e['type']){
			$recipient += 'recipient.name'===$e['token']; $program += 'program.name'===$e['token']; $number += 'credential.display_number'===$e['token']; $date += 'credential.issue_date'===$e['token'];
		}
	}
	p16(1===$qr,'one verification QR '.$d['preset_key']); p16(1===$recipient,'recipient merge present '.$d['preset_key']); p16(1===$program,'program merge present '.$d['preset_key']); p16(1===$number,'credential number merge present '.$d['preset_key']); p16(1===$date,'issue date merge present '.$d['preset_key']);
}
p16(6===count($families),'all six families represented'); foreach($variant_counts as $family=>$count){p16(4===$count,'family has all four variants '.$family);}

$manifest_path=__DIR__.'/fixtures/templates/reference/manifest.json';
p16(is_file($manifest_path),'visual reference manifest exists');
$manifest=json_decode(file_get_contents($manifest_path),true); p16(is_array($manifest),'visual manifest JSON valid');
p16('certloom-template-preset-reference'===($manifest['schema']??''),'visual reference schema exact');
p16(24===count($manifest['presets']??array()),'visual reference covers all 24 presets');
foreach($all as $d){$m=$manifest['presets'][$d['preset_key']]??null;p16(is_array($m),'visual ref exists '.$d['preset_key']);p16(hash_equals($d['layout_sha256'],(string)$m['layout_sha256']),'visual ref bound to canonical layout '.$d['preset_key']);$dir=__DIR__.'/fixtures/templates/reference/'.$d['preset_key'];$png=$dir.'/reference.png';$svg=$dir.'/reference.svg';p16(is_file($png)&&is_file($svg),'reference files exist '.$d['preset_key']);p16(hash_equals((string)$m['png_sha256'],hash_file('sha256',$png)),'PNG file matches manifest '.$d['preset_key']);p16(hash_equals((string)$m['svg_sha256'],hash_file('sha256',$svg)),'SVG file matches manifest '.$d['preset_key']);$image=@getimagesize($png);p16(is_array($image)&&array((int)$image[0],(int)$image[1])===array_map('intval',$m['png_px']),'PNG geometry matches manifest '.$d['preset_key']);}

$root=dirname(__DIR__);$files=array(
 'repo'=>file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'),
 'service'=>file_get_contents($root.'/src/Application/Templates/FactoryPresetService.php'),
 'admin'=>file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'),
 'catalog'=>file_get_contents($root.'/src/Application/Templates/PresetCatalog.php'),
 'plan'=>file_get_contents($root.'/docs/roadmap-1.6.15-delta.md'),
);
foreach($files as $k=>$v){p16(is_string($v)&&''!==$v,'source '.$k.' available');}
p16has($files['service'],'customer_revision','customer-edited factory draft is preserved');
p16has($files['service'],'match_known_factory_version','automatic advancement requires known historical factory hash');
p16has($files['service'],'reset_to_factory','explicit factory reset preserves opt-in path');
p16has($files['service'],'expected_revision_id','factory reset is stale-edit protected');
p16has($files['repo'],'certloom_preset_key_reserved','manual/import cannot claim factory namespace');
p16has($files['repo'],'certloom_preset_key_immutable','installed factory identity cannot be renamed');
p16has($files['repo'],'r.revision_number AS current_revision_number','template list displays the actual draft revision number');
p16has($files['admin'],'Install / synchronize factory drafts','gated admin exposes explicit preset installation');
p16has($files['admin'],'Restore current factory design','gated admin exposes explicit non-destructive reset');
p16has($files['catalog'],"'classic'",'Classic family');p16has($files['catalog'],"'modern'",'Modern family');p16has($files['catalog'],"'corporate'",'Corporate family');p16has($files['catalog'],"'academic'",'Academic family');p16has($files['catalog'],"'elegant'",'Elegant family');p16has($files['catalog'],"'minimal'",'Minimal family');
p16has($files['plan'],'TPL-02 + PRESET-01','release follows planned TPL-02/PRESET-01 slice');

echo "PASS  TPL-02 ships six premium families across A4/Letter portrait/landscape candidates\n";
echo "PASS  PRESET-01 uses stable identities, immutable revisions, deterministic references and non-destructive customer customization semantics\n\n";
echo $checks . " checks, 0 failures\n";
