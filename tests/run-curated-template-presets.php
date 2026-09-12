<?php
/** TPLUX-02 curated premium Template breadth contract. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function wp_json_encode($v,$f=0){return json_encode($v,$f);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\CuratedPresetCatalog;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateLibraryMetadata;

$pass=0;$fail=0; function c76($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$legacy=new PresetCatalog(); $catalog=new CuratedPresetCatalog(); $all=$catalog->all(); $validator=new LayoutValidator(); $canonical=new CanonicalJson();
c76(24===count($legacy->all()),'historical six-family x four-variant catalog remains exactly 24 layouts');
c76(18===count(CuratedPresetCatalog::concepts()),'18 additive curated concepts declared');
c76(18===count($all),'18 curated canonical layouts emitted');
$keys=[];$hashes=[];$names=[];$orient=[];$sizes=[];
foreach($all as $d){
 c76(0===strpos($d['preset_key'],'curated-'),'curated preset key has additive namespace '.$d['preset_key']);
 c76(0===strpos($d['machine_key'],'certloom-preset-curated-'),'machine key remains inside reserved Certloom factory namespace '.$d['preset_key']);
 c76(!isset($keys[$d['preset_key']]),'curated key unique '.$d['preset_key']);$keys[$d['preset_key']]=1;
 c76(!isset($hashes[$d['layout_sha256']]),'curated layout hash unique '.$d['preset_key']);$hashes[$d['layout_sha256']]=1;
 c76(!isset($names[$d['name']]),'curated concept name unique '.$d['name']);$names[$d['name']]=1;
 $n=$validator->normalize($d['layout']); c76(hash_equals($d['layout_sha256'],$canonical->hash($n)),'canonical hash exact '.$d['preset_key']);
 c76($d['page_size']===$n['page']['size']&&$d['orientation']===$n['page']['orientation'],'page metadata exact '.$d['preset_key']);
 $orient[$d['orientation']]=1;$sizes[$d['page_size']]=1;
 $merge=['recipient.name'=>0,'program.name'=>0,'credential.display_number'=>0,'credential.issue_date'=>0];$qr=0;$ids=[];
 foreach($n['elements'] as $e){ c76(!isset($ids[$e['id']]),'element id unique '.$d['preset_key']);$ids[$e['id']]=1; c76(!in_array($e['type'],['image','logo','signature'],true),'curated factory design has no unresolved asset dependency '.$d['preset_key']); if('qr'===$e['type']){$qr++;} if('merge'===$e['type']&&isset($merge[$e['token']])){$merge[$e['token']]++;} }
 c76(1===$qr,'one verification QR '.$d['preset_key']); foreach($merge as $token=>$count){c76(1===$count,$token.' exactly once '.$d['preset_key']);}
 c76(is_array($d['purposes'])&&count($d['purposes'])>=1,'bounded purpose guidance present '.$d['preset_key']);
}
c76(isset($orient['landscape'])&&isset($orient['portrait']),'curated breadth includes both orientations');
c76(isset($sizes['A4'])&&isset($sizes['Letter']),'curated breadth includes A4 and Letter');
$meta=new TemplateLibraryMetadata(); $heritage=$catalog->get('curated-heritage'); $m=$meta->describe(['machine_key'=>$heritage['machine_key'],'layout'=>$heritage['layout']]);
c76(true===($m['factory']??false),'curated design remains factory Template'); c76('curated-heritage'===($m['family']??''),'curated family identity discovered'); c76('formal'===($m['style']??''),'curated style metadata discovered'); c76(in_array('certification_standards',$m['purposes']??[],true),'curated purpose metadata discovered');

$manifest_path=__DIR__.'/fixtures/templates/curated-reference/manifest.json';
c76(is_file($manifest_path),'curated visual reference manifest exists');
$manifest=json_decode(file_get_contents($manifest_path),true); c76(is_array($manifest),'curated visual reference manifest JSON valid');
c76('certloom-curated-template-reference'===($manifest['schema']??''),'curated visual reference schema exact');
c76(18===count($manifest['presets']??array()),'curated visual reference covers all 18 concepts');
foreach($all as $d){$m=$manifest['presets'][$d['preset_key']]??null;c76(is_array($m),'curated visual ref exists '.$d['preset_key']);c76(hash_equals($d['layout_sha256'],(string)($m['layout_sha256']??'')),'curated visual ref bound to canonical layout '.$d['preset_key']);$dir=__DIR__.'/fixtures/templates/curated-reference/'.$d['preset_key'];$png=$dir.'/reference.png';$svg=$dir.'/reference.svg';c76(is_file($png)&&is_file($svg),'curated reference files exist '.$d['preset_key']);c76(hash_equals((string)$m['png_sha256'],hash_file('sha256',$png)),'curated PNG matches manifest '.$d['preset_key']);c76(hash_equals((string)$m['svg_sha256'],hash_file('sha256',$svg)),'curated SVG matches manifest '.$d['preset_key']);}
c76(is_file(__DIR__.'/fixtures/templates/curated-reference/contact-sheet.png'),'curated contact sheet review artifact exists');

$root=dirname(__DIR__);$service=file_get_contents($root.'/src/Application/Templates/FactoryPresetService.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');
c76(false!==strpos($service,'array_merge( $this->catalog->all(), $this->curated_catalog->all() )'),'factory synchronization includes legacy plus curated catalogs');
c76(false!==strpos($service,'definition_for_key'),'factory reset resolves both historical and curated stable identities');
c76(false!==strpos($service,'starter_definitions') && false!==strpos($service,'array_slice( $this->curated_catalog->all(), 0, $limit )'),'starter setup set is the first ten stable premium curated designs');
c76(false!==strpos($service,'ensure_starter_publications') && false===strpos($service,'RendererAuthorityGate') && false!==strpos($service,"'starter_publication_ready'"),'starter designs publish for first-use selection independently of production artifact authority');
c76(false!==strpos($service,'TemplateRepository::SOURCE_PRESET') && false!==strpos($service,"'skipped_customized'"),'starter auto-publication protects customer-customized revisions');
c76(false!==strpos($admin,'Certificate library') && false!==strpos($admin,'Built-in Certloom collection') && false===strpos($admin,'TPLUX-02'),'Design workspace uses the approved customer-facing certificate-library identity without milestone-code leakage');

if($fail){echo"\n$pass passed, $fail failed\n";exit(1);} echo"\n$pass checks, 0 failures\n";
