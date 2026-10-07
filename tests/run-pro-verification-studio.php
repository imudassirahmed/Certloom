<?php
/** Behavioral entitlement, unsafe-style and shared-renderer contracts. */
namespace Elementor {
	class Widget_Base {
		public $controls = array(); public $settings = array(); public $groups = array();
		public function start_controls_section( $key, $args ) { $this->controls[ $key ] = $args; }
		public function end_controls_section() {}
		public function add_control( $key, $args ) { $this->controls[ $key ] = $args; }
		public function add_responsive_control( $key, $args ) { $args['responsive'] = true; $this->add_control( $key, $args ); }
		public function add_group_control( $key, $args ) { $this->groups[ $args['name'] ] = $args; }
		public function get_settings_for_display() { return $this->settings; }
		public function test_controls() { $this->register_controls(); return $this->controls; }
		public function test_render() { ob_start(); $this->render(); return ob_get_clean(); }
	}
	class Controls_Manager { const SELECT='select', TEXT='text', TEXTAREA='textarea', SWITCHER='switcher', NUMBER='number', COLOR='color', RAW_HTML='raw', TAB_STYLE='style'; }
	class Group_Control_Typography { public static function get_type(){return 'typography';} }
	class Group_Control_Box_Shadow { public static function get_type(){return 'shadow';} }
}
namespace {
if ( PHP_SAPI !== 'cli' ) { http_response_code(404); exit; }
require __DIR__ . '/bootstrap-autoload.php';
function __( $v ) { return $v; }
function esc_html__( $v ) { return htmlspecialchars($v,ENT_QUOTES); }
function esc_attr( $v ) { return htmlspecialchars($v,ENT_QUOTES); }
function wp_unique_id( $prefix='' ) { static $n=0; return $prefix . ++$n; }
function wp_generate_uuid4() { static $n=0; return sprintf('12345678-1234-4123-8123-%012d',++$n); }
function wp_enqueue_style( $handle ) { $GLOBALS['enqueued'][]=$handle; }
function apply_filters( $hook, $default, $feature ) { if(!empty($GLOBALS['provider_fault'])){throw new \RuntimeException('Adapter failure');}$GLOBALS['access_call']=array($hook,$default,$feature); return $GLOBALS['entitlement']??$default; }
function get_block_wrapper_attributes( $attributes ) { return 'class="'.$attributes['class'].'"'; }
class Ecapla_CertVerify_Settings { public static $profile='general_other'; public static function get($key,$default=''){return 'usage_profile'===$key?self::$profile:$default;} }
class Ecapla_CertVerify_Shortcode { public static function enqueue_assets(){} }
class Ecapla_CertVerify_Render {
	public static $calls=array();
	public static function legacy_widget($content){self::$calls[]=$content; return '<div class="shared-verifier">SAFE SHARED VERIFIER</div>';}
	public static function scoped_markup_html($html,$scope=''){return $html;}
	public static function default_atts(){return array_fill_keys(array('eyebrow','heading','description','placeholder','button_text','notfound_text'),'Default');}
}
require dirname(__DIR__) . '/elementor/class-certloom-pro-verification-widget.php';
require dirname(__DIR__) . '/includes/class-ecapla-certverify-block.php';
use Certloom\Infrastructure\Verification\ProVerificationAccess as Access;
use Certloom\Infrastructure\Verification\ProVerificationStudio as Studio;
$tests=0;
function check($value,$label){global $tests; ++$tests;if(!$value){throw new \RuntimeException($label);}echo 'PASS  '.$label."\n";}
check(!Access::enabled(),'Pro defaults closed without a server entitlement');
$_GET['pro']='1'; $_POST['license']='valid';
check(!Access::enabled(),'Request parameters cannot grant Pro');
$GLOBALS['provider_fault']=true;check(!Access::enabled(),'A failing entitlement adapter closes Pro while public lookup remains available');$GLOBALS['provider_fault']=false;
foreach(array('true',1,'1',array(true)) as $value){$GLOBALS['entitlement']=$value; check(!Access::enabled(),'Non-boolean entitlement is denied: '.gettype($value));}
$GLOBALS['entitlement']=false;
$closed=Studio::render(array('preset'=>'custom','desktop'=>array('padding'=>999)));
check(false===strpos($closed,'certloom-pro-frame')&&false===strpos($closed,'<style>'),'Downgrade keeps the shared verifier without Pro markup or CSS');
$locked=new \Certloom_Pro_Verification_Widget();
check(!$locked->show_in_panel()&&isset($locked->test_controls()['pro_required']),'Free Elementor cannot insert Pro controls');
$block=\Ecapla_CertVerify_Block::render_studio(array('preset'=>'campus'));
check(false===strpos($block,'certloom-pro-frame')&&false!==strpos($block,'SAFE SHARED VERIFIER'),'Saved native Pro block retains standard verification on downgrade');
$GLOBALS['entitlement']=true;
check(Access::enabled()&&$GLOBALS['access_call']===array('certloom_pro_feature_enabled',false,'verification_studio'),'Only strict server entitlement opens the named feature');
check(count(Studio::presets())===5&&array_keys(Studio::choices())===array('campus','authority','executive','celebration','essential','custom'),'Design dropdown has exactly five presets plus Custom');
$covered=array();foreach(Studio::presets() as $key=>$preset){foreach($preset['profiles'] as $profile){Ecapla_CertVerify_Settings::$profile=$profile;check(Studio::recommended()===$key,'Profile recommendation: '.$profile);$covered[]=$profile;}}
check(count(array_unique($covered))===count((new \Certloom\Application\Setup\PurposePresetEngine())->profiles()),'All nine existing usage profiles are covered');
Ecapla_CertVerify_Settings::$profile='general_other';
$clean=Studio::sanitize(array('preset'=>'custom','desktop'=>array('width'=>99999,'padding'=>-2,'input_height'=>2,'layout'=>'evil','align'=>'center','gap'=>array(1)),'tablet'=>array('title_size'=>'22'),'mobile'=>array('form_layout'=>'stack'),'colors'=>array('accent'=>'#ABCDEF','background'=>'red;</style><script>'),'font_family'=>'serif','shadow'=>'javascript','heading_weight'=>'600','is_pro'=>true));
check($clean['desktop']['width']===1600&&$clean['desktop']['padding']===0&&$clean['desktop']['input_height']===44,'Dimensions clamp without shrinking accessible controls');
check(!isset($clean['desktop']['layout'],$clean['desktop']['gap'],$clean['shadow'],$clean['is_pro']),'Unsupported enums, nested values and fake entitlement are discarded');
check($clean['colors']===array('accent'=>'#abcdef'),'Only six-digit hex colors reach CSS');
check($clean['tablet']['title_size']===22&&$clean['mobile']['form_layout']==='stack','Device settings remain independent');
$html=Studio::render($clean,array('heading'=>'Example'));
check(false!==strpos($html,'--cls-font:var(--cl-serif,Georgia,serif)')&&false!==strpos($html,'--cls-heading-weight:600'),'Custom typography is serialized through a fixed allowlist');
check(false!==strpos($html,'@media(max-width:767px)')&&false!==strpos($html,'@media(max-width:1024px)')&&false===strpos($html,'@container'),'Custom device settings follow viewport breakpoints without overriding narrow desktop columns');
check(false!==strpos($html,'--cls-button-row:3')&&false===strpos($html,'<script>'),'Stacked form placement and hostile style rejection');
check(end(Ecapla_CertVerify_Render::$calls)['studio_intro']===true,'Studio delegates to the existing renderer with an intro wrapper');
$one=Studio::render(array('preset'=>'campus','desktop'=>array('padding'=>1),'colors'=>array('accent'=>'#000000')));
$two=Studio::render(array('preset'=>'campus'));
check(false===strpos($one,'<style>')&&$one!==$two,'Preset styling ignores stale Custom overrides and scopes each instance');
$widget=new \Certloom_Pro_Verification_Widget();$controls=$widget->test_controls();
check($widget->show_in_panel()&&count($controls['preset']['options'])===6,'Licensed Elementor exposes six design choices');
foreach(array_keys(Studio::numeric_controls()) as $key){check(!empty($controls[$key]['responsive']),'Elementor device control: '.$key);}
check($controls['studio_custom_layout']['condition']===array('preset'=>'custom')&&$controls['studio_custom_style']['condition']===array('preset'=>'custom'),'Advanced controls appear only for Custom');
check(count($widget->groups)===3&&count(array_filter($widget->groups,static function($group){return false!==strpos($group['selector'],'.certloom-pro-custom ');}))===3,'Saved advanced Elementor styles target only Custom and cannot leak into presets');
$widget->settings=array('preset'=>'custom','padding'=>48,'padding_tablet'=>30,'padding_mobile'=>20,'layout_mobile'=>'stack','color_accent'=>'#123456','show_guide'=>'');
$rendered=$widget->test_render();
check(false!==strpos($rendered,'--cls-padding:48px')&&false!==strpos($rendered,'--cls-padding:30px')&&false!==strpos($rendered,'--cls-padding:20px'),'Elementor maps all three device settings into the shared renderer');
check(end(Ecapla_CertVerify_Render::$calls)['show_guide']===false,'Elementor guide toggle respects explicit off');
echo "\n$tests tests, 0 failures\n";
}
