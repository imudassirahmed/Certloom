<?php
/** Phase 4 Gamma-02 portable .certloom / .certloom-pack archive contract tests. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $c,$m;
		public function __construct($c='',$m=''){ $this->c=$c; $this->m=$m; }
		public function get_error_code(){ return $this->c; }
		public function get_error_message(){ return $this->m; }
	}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($key){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$key));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Templates\TemplateArchive;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\TemplatePackage;

$checks=0;
function arc_ok($ok,$label){global$checks;if(!$ok){throw new RuntimeException($label);} $checks++;}
function arc_error($value,$code,$label){arc_ok(is_wp_error($value)&&$code===$value->get_error_code(),$label.' got '.(is_wp_error($value)?$value->get_error_code():gettype($value)));}
function arc_package($key,$name,$profiles=array()){
	$built=(new TemplatePackage())->build(array('machine_key'=>$key,'name'=>$name,'description'=>'Portable Studio template','recommended_profiles'=>$profiles),array('layout'=>TemplateDefaults::blank_a4_landscape()),'1.7.0-alpha.4.50-dev');
	if(is_wp_error($built)){throw new RuntimeException($built->get_error_message());}
	return $built;
}

arc_ok(class_exists('PharData'),'PharData is available for portable template archives');
$archive=new TemplateArchive();
$base=sys_get_temp_dir().'/certloom-archive-'.getmypid().'-'.bin2hex(random_bytes(4));
$single=$base.'.certloom';
$pack=$base.'.certloom-pack';
$one=arc_package('gamma-studio-one','Gamma Studio One',array('training_lms','iso_management','not_a_profile'));
$two=arc_package('gamma-studio-two','Gamma Studio Two',array('events_awards'));

$result=$archive->write_single($one,$single);
arc_ok(true===$result&&is_file($single),'single .certloom writes');
$read=$archive->read($single);
arc_ok(is_array($read)&&'single'===($read['type']??''),'single archive is classified correctly');
arc_ok(1===count($read['packages']??array()),'single archive returns one package');
arc_ok('gamma-studio-one'===($read['packages'][0]['template']['machine_key']??''),'single archive preserves template identity');
arc_ok(array('training_lms','iso_management')===($read['packages'][0]['template']['recommended_profiles']??array()),'single archive preserves normalized recommendation profiles');
$phar=new PharData($single);
arc_ok(isset($phar['manifest.json'])&&isset($phar['design.json']),'single archive separates manifest and canonical design JSON');

$result=$archive->write_pack(array($one,$two),$pack,'1.7.0-alpha.4.50-dev');
arc_ok(true===$result&&is_file($pack),'multi-template .certloom-pack writes');
$read=$archive->read($pack);
arc_ok(is_array($read)&&'pack'===($read['type']??''),'package archive is classified correctly');
arc_ok(2===count($read['packages']??array()),'package archive returns every indexed template');
arc_ok('gamma-studio-two'===($read['packages'][1]['template']['machine_key']??''),'package archive preserves ordered template identity');
arc_ok(array('events_awards')===($read['packages'][1]['template']['recommended_profiles']??array()),'package archive preserves recommendation profiles for each template');
$phar=new PharData($pack);
arc_ok(isset($phar['pack.json'])&&isset($phar['templates/gamma-studio-one/manifest.json']),'pack contains bounded index and nested template manifests');

$oversize=$base.'-oversize.certloom';
$phar=new PharData($oversize,0,null,Phar::ZIP);
$phar['manifest.json']=str_repeat('A',TemplateArchive::MAX_ENTRY_BYTES+1);
unset($phar);
$read=$archive->read($oversize);
arc_error($read,'certloom_template_archive_entry_size','oversized expanded entry fails closed before content parsing');

foreach(array($single,$pack,$oversize) as $file){if(is_file($file)){unlink($file);}}
echo "PASS  Gamma-02 portable Certloom template archives round-trip and fail closed\n\n";
echo $checks." checks, 0 failures\n";
