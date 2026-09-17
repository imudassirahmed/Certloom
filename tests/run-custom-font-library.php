<?php
/** alpha.4.75 custom font library + smooth visual picker contract. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['certloom_test_options'] = array(
	'certloom_custom_font_library_v1' => array(
		'custom-0123456789abcdef' => array(
			'label' => 'Fixture Custom',
			'faces' => array(
				'400-normal' => array( 'sha256' => str_repeat( 'a', 64 ), 'format' => 'ttf', 'file' => str_repeat( 'a', 64 ) . '.ttf' ),
				'700-italic' => array( 'sha256' => str_repeat( 'b', 64 ), 'format' => 'otf', 'file' => str_repeat( 'b', 64 ) . '.otf' ),
			),
		),
	),
);
function get_option($k,$d=array()){return array_key_exists($k,$GLOBALS['certloom_test_options'])?$GLOBALS['certloom_test_options'][$k]:$d;}
function wp_upload_dir(){return array('basedir'=>'/tmp/certloom-uploads','baseurl'=>'https://example.test/wp-content/uploads','error'=>'');}
function esc_url_raw($v){return (string)$v;} function esc_url($v){return (string)$v;} function wp_strip_all_tags($v){return strip_tags((string)$v);} function wp_get_global_settings(){return array();} function sanitize_hex_color($v){return preg_match('/^#[0-9a-fA-F]{6}$/',(string)$v)?strtolower((string)$v):null;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function absint($v){return abs((int)$v);} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html__($v,$d=null){return (string)$v;} function wp_kses_post($v){return (string)$v;}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\CustomFontLibrary;
use Certloom\Application\Rendering\DesignerFontCatalog;
$root=dirname(__DIR__);$checks=0;$fail=0;
function cf($ok,$label){global $checks,$fail;$checks++;echo($ok?'PASS  ':'FAIL  ').$label.PHP_EOL;if(!$ok)$fail++;}
$lib=new CustomFontLibrary();$families=$lib->families();$defs=$lib->designer_definitions();
cf(isset($families['custom-0123456789abcdef']),'custom family metadata is read from the site-local library');
cf(count($families['custom-0123456789abcdef']['faces'])===2,'custom family preserves multiple exact weight/style faces');
cf(isset($defs['custom-0123456789abcdef'])&&!empty($defs['custom-0123456789abcdef']['custom']),'custom family becomes a first-class Studio catalog definition');
cf($defs['custom-0123456789abcdef']['preview_family']==='CertloomCustom_0123456789abcdef','custom preview family uses a stable collision-resistant browser name');
cf(strpos($defs['custom-0123456789abcdef']['faces'][0]['url'],'/certloom-fonts/'.str_repeat('a',64).'.ttf')!==false,'browser preview URL is derived from content-addressed local storage');
$catalog=new DesignerFontCatalog();
cf($catalog->contains('custom-0123456789abcdef'),'shared Designer catalog accepts custom family keys');
cf(strpos($catalog->browser_css('custom-0123456789abcdef'),'CertloomCustom_0123456789abcdef')!==false,'Certificate browser preview resolves custom family CSS');
cf(in_array($defs['custom-0123456789abcdef']['css'],$catalog->css_values(),true),'Email compiler allowlist includes custom family CSS stack');
$compiler=new \Certloom\Infrastructure\Email\EmailDesignCompiler();$compiled=$compiler->compile(array(array('type'=>'heading','text'=>'Custom face','level'=>2,'style'=>array('font_family'=>$defs['custom-0123456789abcdef']['css'],'font_weight'=>700,'italic'=>1))),array_merge(\Certloom\Infrastructure\Email\EmailDesignCompiler::default_theme(),array('font_family'=>$defs['custom-0123456789abcdef']['css'])));
cf(strpos($compiled['html_body'],'@font-face')!==false&&strpos($compiled['html_body'],'CertloomCustom_0123456789abcdef')!==false,'compiled outgoing email emits the used site-local custom family');
cf(strpos($compiled['html_body'],str_repeat('b',64).'.otf')!==false&&strpos($compiled['html_body'],'font-display:swap')!==false,'compiled outgoing email references exact used custom face bytes with non-blocking display');
$library=file_get_contents($root.'/src/Application/Rendering/CustomFontLibrary.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/CustomFontAdmin.php');$picker=file_get_contents($root.'/admin/assets/font-picker.js');$designer=file_get_contents($root.'/admin/assets/designer.js');$email=file_get_contents($root.'/admin/assets/email-designer.js');
cf(strpos($library,'is_uploaded_file')!==false&&strpos($library,'MAX_BYTES')!==false,'custom uploads require real upload provenance and a bounded byte size');
cf(strpos($library,"'ttf', 'otf'")!==false&&strpos($library,'hash_file')!==false,'custom uploads are restricted to exact local TTF/OTF bytes and content-addressed');
cf(strpos($admin,'check_ajax_referer')!==false&&strpos($admin,'CAP_MANAGE_CREDENTIALS')!==false,'custom-font AJAX route is nonce and capability guarded');
cf(strpos($picker,'const ROW=48')!==false&&strpos($picker,'OVERSCAN=4')!==false,'visual picker uses a fixed-row virtualized viewport');
cf(strpos($picker,'maxRowsRendered')!==false&&strpos($picker,'replaceChildren')!==false,'visual picker exposes bounded render metrics and replaces only its visible window');
cf(strpos($picker,'setTimeout(()=>{inst.query=search.value.trim();rebuild();},70)')!==false,'font search is debounced instead of rebuilding on every keystroke');
cf(strpos($picker,'loadCustom(def,key,true)')!==false,'scrolling the font list loads only a preview face for custom families');
cf(strpos($picker,"display:'swap'")!==false,'custom preview faces use non-blocking font-display swap semantics');
cf(strpos($designer,'requestIdleCallback')!==false&&strpos($email,'requestIdleCallback')!==false,'both Studios move availability probing to browser idle time');
cf(strpos($designer,"certloom:fonts-updated")!==false&&strpos($email,"certloom:fonts-updated")!==false,'local font scans notify the shared visual picker without page reload');
cf(strpos($email,'usedCustomFontDefinitions')!==false&&strpos($email,'usedCustomFontFaceCss')!==false,'Email Studio loads/embeds only custom fonts actually referenced by the current design');
cf(strpos($email,"font-src data: https: http:")!==false,'full email preview permits site-local custom font bytes inside its sandboxed srcdoc');
echo "\n{$checks} custom font checks, {$fail} failures\n";exit($fail?1:0);
