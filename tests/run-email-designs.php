<?php
/** Email Design library, package and designer separation contract tests. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['certloom_test_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;} function esc_html__($t){return $t;}
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_hex_color($v){return preg_match('/^#[0-9a-fA-F]{6}$/',(string)$v)?strtolower((string)$v):null;}
function esc_url_raw($v){$v=trim((string)$v);return preg_match('#^(https?://|\{\{verification_url\}\})#',$v)?$v:'';} function esc_url($v){return esc_url_raw($v);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function wp_kses_post($v){return preg_replace('#<script\b[^>]*>.*?</script>#is','',(string)$v);} function wp_kses($v,$allowed=array()){ $v=preg_replace('#<script\b[^>]*>.*?</script>#is','',(string)$v); $v=preg_replace('/\son[a-z]+=(?:"[^"]*"|\'[^\']*\')/i','',$v); return $v; } function wp_strip_all_tags($v,$remove_breaks=false){return strip_tags((string)$v);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_bloginfo($key){return 'UTF-8';} function current_time($type,$gmt=false){return '2026-09-04 01:02:03';}
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['certloom_test_options'])?$GLOBALS['certloom_test_options'][$key]:$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['certloom_test_options'][$key]=$value;return true;}
function wp_generate_password($length=12,$special=true,$extra=false){return substr(str_repeat('abcdef',$length),0,$length);}

class Ecapla_CertVerify_Fields {
	public static function get_manageable_fields(){ return array(
		array('key'=>'address','label'=>'Postal address','type'=>'text'),
		array('key'=>'department','label'=>'Department','type'=>'text'),
		array('key'=>'grade','label'=>'Grade','type'=>'text'),
		array('key'=>'internal_notes','label'=>'Internal notes','type'=>'textarea','system_only'=>1),
	); }
}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailDesignPackage;
use Certloom\Infrastructure\Email\EmailDesignPresetCatalog;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailMergeTokenCatalog;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;

$checks=0;
function ed_ok($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);}$checks++;}
function ed_same($a,$b,$message){ed_ok($a===$b,$message.' expected '.var_export($a,true).' got '.var_export($b,true));}
function ed_has($source,$needle,$message){ed_ok(false!==strpos($source,$needle),$message);}
function ed_lacks($source,$needle,$message){ed_ok(false===strpos($source,$needle),$message);}

$compiler=new EmailDesignCompiler();
$default_theme=EmailDesignCompiler::default_theme();
ed_same('#1369fc',$default_theme['button_background'],'default email CTA uses canonical Certloom logo blue');
ed_same('#ffffff',$default_theme['button_text'],'default email CTA uses white text');
$legacy_emerald=$compiler->normalize_theme(array('button_background'=>'#01C174','button_text'=>'#01133D'));
ed_same('#1369fc',$legacy_emerald['button_background'],'former emerald/navy product default migrates to Certloom blue');
ed_same('#ffffff',$legacy_emerald['button_text'],'former emerald/navy product default migrates to white text');
$legacy_forest=$compiler->normalize_theme(array('button_background'=>'#0F6E56','button_text'=>'#FFFFFF'));
ed_same('#1369fc',$legacy_forest['button_background'],'former forest/white product default migrates to Certloom blue');
ed_same('#ffffff',$legacy_forest['button_text'],'former forest/white product default keeps white text');
$custom_emerald=$compiler->normalize_theme(array('button_background'=>'#01C174','button_text'=>'#FFFFFF'));
ed_same('#01c174',$custom_emerald['button_background'],'deliberate custom emerald/white button remains unchanged');
$token_catalog=new EmailMergeTokenCatalog();
$token_groups=$token_catalog->groups();
ed_ok(isset($token_groups['recipient']['tokens']['recipient.name']),'Data catalog exposes canonical Recipient fields');
ed_ok(isset($token_groups['credential']['tokens']['credential.display_number']),'Data catalog exposes canonical Credential fields');
ed_ok(isset($token_groups['program']['tokens']['batch.name']),'Data catalog exposes Program and Batch fields');
ed_ok(isset($token_groups['issuer']['tokens']['signer.email']),'Data catalog exposes Issuer and Signer fields');
ed_ok(isset($token_groups['verification']['tokens']['verification.url']),'Data catalog exposes verification fields');
ed_same('Postal address',$token_groups['custom']['tokens']['custom:address'],'Data catalog derives configured custom fields');
ed_same('Department',$token_groups['custom']['tokens']['custom:department'],'Data catalog derives every configured non-system custom field');
ed_ok(!isset($token_groups['custom']['tokens']['custom:internal_notes']),'system-only fields are not exposed to email merge UI');
ed_ok(in_array('custom:address',\Certloom\Application\Email\EmailTemplateService::tokens(),true),'Email Template Service accepts the same dynamic custom-field vocabulary exposed by Studio');
$presets=(new EmailDesignPresetCatalog())->all();
ed_same(count(EmailDesignPurposes::all()),count($presets),'one calm starter exists for every supported message purpose');
$ids=[];$purposes=[];
foreach($presets as $preset){
	ed_ok('factory'===($preset['source']??''),'starter is product-owned factory content');
	ed_ok(!empty($preset['active']),'starter ships Published');
	ed_ok(EmailDesignPurposes::is_valid($preset['purpose']??''),'starter purpose is canonical');
	ed_ok(!isset($ids[$preset['id']]),'starter ID is unique');$ids[$preset['id']]=1;$purposes[$preset['purpose']]=1;
	$compiled=$compiler->compile($preset['blocks']??array(),$preset['theme']??array());
	ed_ok(''!==trim($compiled['html_body']),'starter compiles to HTML');
	ed_ok(''!==trim($compiled['text_body']),'starter compiles to plain text');
}
ed_same(EmailDesignPurposes::all(),array_values(array_filter(EmailDesignPurposes::all(),fn($p)=>isset($purposes[$p]))),'every purpose is represented');

$styled=$compiler->compile(array(
	array('type'=>'heading','text'=>'Styled heading','level'=>1,'style'=>array('font_family'=>'Georgia, Times New Roman, serif','font_size'=>38,'font_weight'=>800,'italic'=>1,'underline'=>1,'color'=>'#123456','align'=>'center','line_height'=>1.4)),
	array('type'=>'text','html'=>'<p><strong>Rich</strong> <em>message</em></p>','style'=>array('font_size'=>18,'font_weight'=>500,'color'=>'#334455','align'=>'right','line_height'=>1.8)),
	array('type'=>'button','text'=>'Open','url'=>'{{verification_url}}','align'=>'center','style'=>array('font_size'=>17,'font_weight'=>600,'italic'=>1,'color'=>'#ffffff','background_color'=>'#2255aa','background_opacity'=>65,'border_radius'=>18,'padding_x'=>28,'padding_y'=>14,'margin_y'=>10,'border_color'=>'#112233','border_width'=>2,'border_style'=>'dashed','full_width'=>1,'letter_spacing'=>1.5,'text_transform'=>'uppercase')),
	array('type'=>'image','url'=>'https://example.test/badge.png','alt'=>'Badge','style'=>array('align'=>'center','width'=>72,'border_radius'=>14,'opacity'=>45,'border_color'=>'#445566','border_width'=>2,'border_style'=>'dotted')),
	array('type'=>'divider','style'=>array('color'=>'#778899','width'=>68,'thickness'=>3,'margin_y'=>18,'line_style'=>'dashed','align'=>'right')),
), EmailDesignCompiler::default_theme());
ed_same('#123456',$styled['blocks'][0]['style']['color'],'heading block color survives canonical sanitization');
ed_same(800,$styled['blocks'][0]['style']['font_weight'],'heading font weight survives canonical sanitization');
ed_same(18,$styled['blocks'][1]['style']['font_size'],'text block font size survives canonical sanitization');
ed_same('#2255aa',$styled['blocks'][2]['style']['background_color'],'button background survives canonical sanitization');
ed_has($styled['html_body'],'font-size:38px','compiled heading applies block font size');
ed_has($styled['html_body'],'font-style:italic','compiled styled blocks support italic');
ed_has($styled['html_body'],'text-decoration:underline','compiled styled blocks support underline');
ed_has($styled['html_body'],'background:#2255aa','compiled button applies block background color');
ed_same(65,$styled['blocks'][2]['style']['background_opacity'],'button background opacity survives canonical sanitization');
ed_same(true,$styled['blocks'][2]['style']['full_width'],'button full-width mode survives canonical sanitization');
ed_same('uppercase',$styled['blocks'][2]['style']['text_transform'],'button text transform survives canonical sanitization');
ed_has($styled['html_body'],'background-color:rgba(','compiled button applies background opacity without fading foreground content');
ed_has($styled['html_body'],'border:2px dashed #112233','compiled button applies advanced border');
ed_has($styled['html_body'],'width:100%;box-sizing:border-box','compiled full-width button remains bounded');
ed_has($styled['html_body'],'<strong>Rich</strong>','compiled text keeps safe rich-text markup');
ed_same(72,$styled['blocks'][3]['style']['width'],'image width survives canonical sanitization');
ed_same(14,$styled['blocks'][3]['style']['border_radius'],'image radius survives canonical sanitization');
ed_same(45,$styled['blocks'][3]['style']['opacity'],'image opacity survives canonical sanitization');
ed_has($styled['html_body'],'opacity:0.45','compiled image applies bounded opacity');
ed_has($styled['html_body'],'border:2px dotted #445566','compiled image applies advanced border');
ed_same('#778899',$styled['blocks'][4]['style']['color'],'divider color survives canonical sanitization');
ed_has($styled['html_body'],'width:72%','compiled image applies bounded width');
ed_has($styled['html_body'],'border-top:3px dashed #778899','compiled divider applies visual style');
ed_has($styled['html_body'],'margin:18px auto 0','compiled divider supports right alignment');

$advanced=$compiler->compile(array(
	array('type'=>'html','html'=>'<table role="presentation"><tr><td style="padding:8px">{{custom:address}}</td></tr></table><script>alert(1)</script>'),
	array('type'=>'columns','count'=>3,'ratio'=>'25-50-25','gap'=>18,'columns'=>array(
		array('kind'=>'image','url'=>'https://example.test/photo.png','alt'=>'Portrait','link'=>'{{verification.url}}','width'=>80,'align'=>'center'),
		array('kind'=>'text','html'=>'<p><strong>Main text</strong></p>'),
		array('kind'=>'html','html'=>'<p style="text-align:right">Custom HTML</p>'),
	)),
),array_merge(EmailDesignCompiler::default_theme(),array('background_image'=>'https://example.test/page.jpg','content_background_image'=>'https://example.test/card.jpg','background_size'=>'cover','content_background_size'=>'contain','background_opacity'=>80,'background_overlay_color'=>'#102030','background_overlay_opacity'=>35,'content_background_opacity'=>90,'content_background_overlay_color'=>'#405060','content_background_overlay_opacity'=>25)));
ed_same('html',$advanced['blocks'][0]['type'],'Custom HTML survives canonical block normalization');
ed_lacks($advanced['html_body'],'<script','Custom HTML strips executable script content');
ed_same('container',$advanced['blocks'][1]['type'],'Legacy Columns normalize into the generic Container model');
ed_same('horizontal',$advanced['blocks'][1]['direction'],'Legacy multi-column layout becomes a horizontal Container');
ed_same(3,count($advanced['blocks'][1]['children']),'Legacy three-column composition keeps three child Containers');
ed_same(25,$advanced['blocks'][1]['children'][0]['basis'],'Legacy 25/50/25 ratio migrates into child width/basis');
ed_same('image',$advanced['blocks'][1]['children'][0]['children'][0]['type'],'Legacy image column migrates into a normal Image block');
ed_same('html',$advanced['blocks'][1]['children'][2]['children'][0]['type'],'Legacy HTML column migrates into a normal Custom HTML block');
ed_has($advanced['html_body'],'width="25%"','compiled migrated Container uses the selected child proportion');
ed_has($advanced['html_body'],'background="https://example.test/page.jpg"','page background image compiles with an email fallback attribute');
ed_has($advanced['html_body'],'background="https://example.test/card.jpg"','content background image compiles with an email fallback attribute');
ed_has($advanced['html_body'],'linear-gradient(rgba(','page/content overlay compiles independently from foreground content');
ed_same(35,$advanced['theme']['background_overlay_opacity'],'page overlay opacity survives canonical theme normalization');
ed_same(25,$advanced['theme']['content_background_overlay_opacity'],'content overlay opacity survives canonical theme normalization');

$containers=$compiler->compile(array(
	array('type'=>'container','direction'=>'horizontal','gap'=>12,'stack_mobile'=>1,'background_color'=>'#f7f9fb','background_opacity'=>72,'background_image'=>'https://example.test/hero.jpg','overlay_color'=>'#112233','overlay_opacity'=>28,'background_size'=>'cover','background_position'=>'center','background_repeat'=>'no-repeat','border_color'=>'#ccddee','border_width'=>1,'border_style'=>'solid','border_radius'=>12,'padding'=>array('top'=>16,'right'=>18,'bottom'=>16,'left'=>18),'children'=>array(
		array('type'=>'container','direction'=>'vertical','basis'=>40,'children'=>array(array('type'=>'image','url'=>'https://example.test/photo.png','alt'=>'Photo'))),
		array('type'=>'container','direction'=>'vertical','basis'=>60,'children'=>array(array('type'=>'heading','text'=>'Nested title','level'=>2),array('type'=>'text','html'=>'<p>Nested body</p>'))),
	)),
),EmailDesignCompiler::default_theme());
ed_same('container',$containers['blocks'][0]['type'],'generic Container survives canonical normalization');
ed_same(2,count($containers['blocks'][0]['children']),'generic Container supports nested child Containers');
ed_same(40,$containers['blocks'][0]['children'][0]['basis'],'child basis survives canonical normalization');
ed_same('https://example.test/hero.jpg',$containers['blocks'][0]['background_image'],'Container background image survives canonical normalization');
ed_same(72,$containers['blocks'][0]['background_opacity'],'Container background color opacity survives canonical normalization');
ed_same(28,$containers['blocks'][0]['overlay_opacity'],'Container image overlay opacity survives canonical normalization');
ed_has($containers['html_body'],'background="https://example.test/hero.jpg"','Container background image compiles with an email fallback attribute');
ed_has($containers['html_body'],'background-image:linear-gradient(rgba(','Container image overlay compiles without whole-container opacity');
ed_has($containers['html_body'],'certloom-email-stack-cell','horizontal Container emits bounded mobile-stack table cells');
ed_has($containers['html_body'],'<table role="presentation"','Container composition compiles to email-safe presentation tables');

$deep=array('type'=>'text','html'=>'<p>Too deep</p>');
for($i=0;$i<7;$i++){$deep=array('type'=>'container','direction'=>'vertical','children'=>array($deep));}
$bounded_depth=$compiler->sanitize_blocks(array($deep));
$cursor=$bounded_depth[0]??array();$container_levels=0;
while(is_array($cursor)&&'container'===($cursor['type']??'')){$container_levels++;$cursor=$cursor['children'][0]??null;}
ed_same(EmailDesignCompiler::MAX_CONTAINER_DEPTH+1,$container_levels,'Container nesting is bounded consistently from top-level depth zero');
ed_ok(null===$cursor,'content beyond the supported Container nesting depth is discarded fail-closed');

$many_children=array();for($i=0;$i<100;$i++){$many_children[]=array('type'=>'divider');}
$bounded_count=$compiler->sanitize_blocks(array(array('type'=>'container','children'=>$many_children)));
ed_same(EmailDesignCompiler::MAX_BLOCKS-1,count($bounded_count[0]['children']??array()),'Container reserves its own block slot before recursively enforcing the global block limit');

$sample=$presets[0];$sample['id']='private-id';$sample['source']='customer';$sample['active']=1;$sample['assignment']=array('scope_type'=>'program','scope_id'=>9);
$package=(new EmailDesignPackage($compiler))->build($sample,'1.6.99-qa.23');
ed_same(EmailDesignPackage::FORMAT,$package['format'],'package format identity');
ed_same(EmailDesignPackage::VERSION,$package['format_version'],'package format version');
ed_ok(isset($package['sha256'])&&64===strlen($package['sha256']),'package has SHA-256 integrity proof');
ed_lacks(json_encode($package),'private-id','stored design identity is not exported');
ed_lacks(json_encode($package),'assignment','Design Rules are not exported with email design');
ed_lacks(json_encode($package),'scope_id','assignment scope is absent from package');
$decoded=(new EmailDesignPackage($compiler))->decode(json_encode($package));
ed_ok(is_array($decoded),'valid package round-trips');
ed_same(0,$decoded['active'],'import is deliberately Unpublished');
ed_same('import',$decoded['source'],'import source is explicit');
ed_same($sample['purpose'],$decoded['purpose'],'purpose survives package round-trip');
ed_same($sample['subject'],$decoded['subject'],'subject survives package round-trip');
$styled_design=$sample;
$styled_design['blocks']=array(array('type'=>'heading','text'=>'Portable styled heading','level'=>1,'style'=>array('font_size'=>36,'font_weight'=>800,'color'=>'#234567','align'=>'center')));
$styled_package=(new EmailDesignPackage($compiler))->build($styled_design,'1.7.0-alpha.4.68');
$styled_decoded=(new EmailDesignPackage($compiler))->decode(json_encode($styled_package));
ed_same(36,$styled_decoded['blocks'][0]['style']['font_size'],'block typography survives email design package round-trip');
ed_same('#234567',$styled_decoded['blocks'][0]['style']['color'],'block color survives email design package round-trip');
$with_attachment=$sample;$with_attachment['attachments']=array(42);$blocked_package=(new EmailDesignPackage($compiler))->build($with_attachment,'1.7.0-alpha.4.68');
ed_ok(is_wp_error($blocked_package)&&'certloom_email_design_package_static_attachments'===$blocked_package->get_error_code(),'site-local static attachments are explicitly excluded from portable design packages');

$tampered=$package;$tampered['design']['subject']='Tampered';$bad=(new EmailDesignPackage($compiler))->decode(json_encode($tampered));
ed_ok(is_wp_error($bad)&&'certloom_email_design_package_integrity'===$bad->get_error_code(),'tampered package fails integrity check');
$future=$package;$future['format_version']=99;$bad=(new EmailDesignPackage($compiler))->decode(json_encode($future));
ed_ok(is_wp_error($bad)&&'certloom_email_design_package_version'===$bad->get_error_code(),'unsupported package version fails closed');

// Factory library repair/upgrade preserves site-owned Published state.
$catalog=new EmailTemplateCatalogRepository();
$result=$catalog->ensure_factory_presets();
ed_same(true,$result,'factory library seeds automatically');
$all=$catalog->all();
$factory=array_values(array_filter($all,fn($d)=>'factory'===($d['source']??'')));
ed_same(count($presets),count($factory),'all built-in email designs are present after seeding');
$first_id=$factory[0]['id'];
foreach($GLOBALS['certloom_test_options'][EmailTemplateCatalogRepository::OPTION]['templates'] as &$row){if(($row['id']??'')===$first_id){$row['active']=0;$row['subject']='Old but valid subject';$row['factory_version']=0;}}
unset($row);
$result=$catalog->ensure_factory_presets();ed_same(true,$result,'factory library repairs an older product-owned record');
$repaired=$catalog->get($first_id);$canonical=array_values(array_filter($presets,fn($d)=>$d['id']===$first_id))[0];
ed_same(0,$repaired['active'],'factory repair preserves administrator Unpublished choice');
ed_same($canonical['subject'],$repaired['subject'],'factory repair restores canonical product content');
ed_same(EmailDesignPresetCatalog::VERSION,$repaired['factory_version'],'factory repair advances product factory version');

// Reserved built-in identity must never overwrite a custom record.
$GLOBALS['certloom_test_options']=array();
$custom=$canonical;$custom['source']='customer';$custom['active']=1;
$repo=new EmailTemplateCatalogRepository();$repo->save($custom);
$conflict=$repo->ensure_factory_presets();
ed_ok(is_wp_error($conflict)&&'certloom_email_factory_identity_conflict'===$conflict->get_error_code(),'custom record using reserved factory identity is never overwritten');

$root=dirname(__DIR__);
$view=file_get_contents($root.'/admin/views/email-designs.php');
$designer=file_get_contents($root.'/admin/views/email-designer.php');
$admin=file_get_contents($root.'/src/Infrastructure/Admin/EmailDesignAdmin.php');
$repo_source=file_get_contents($root.'/src/Infrastructure/Email/EmailTemplateCatalogRepository.php');
$package_source=file_get_contents($root.'/src/Infrastructure/Email/EmailDesignPackage.php');
$token_catalog_source=file_get_contents($root.'/src/Infrastructure/Email/EmailMergeTokenCatalog.php');
$template_service_source=file_get_contents($root.'/src/Application/Email/EmailTemplateService.php');
$js=file_get_contents($root.'/admin/assets/email-designer.js');
$css=file_get_contents($root.'/admin/assets/email-designs.css');
foreach(compact('view','designer','admin','repo_source','package_source','token_catalog_source','template_service_source','js','css') as $name=>$source){ed_ok(is_string($source)&&''!==$source,'source '.$name.' available');}

ed_has($view,"'Library'",'Email Designs has Library subtab');
ed_has($view,"'My Templates'",'Email Designs has My Templates subtab');
ed_has($view,"'New Template'",'New Template is a primary page action');
ed_has($view,"'Import'",'Import is a primary page action');
ed_has($view,"'Publish'",'library exposes Publish lifecycle');
ed_has($view,"'Unpublish'",'library exposes Unpublish lifecycle');
ed_has($view,"'Export'",'library exposes Export');
ed_has($view,"'View usage'",'library links usage to Design Rules');
ed_has($view,'DesignRulesAdmin::PAGE','usage routes to centralized Design Rules');
ed_lacks($view,'scope_type','Email library has no Issuer/Program/Batch assignment selector');
ed_lacks($view,'issuer_id','Email library does not manage Issuer assignment');
ed_lacks($view,'program_id','Email library does not manage Program assignment');
ed_lacks($view,'batch_id','Email library does not manage Batch assignment');

ed_has($designer,'certloom-designer-palette','designer has Blocks palette');
ed_has($designer,'certloom-designer-stage','designer has visual Canvas');
ed_has($designer,'certloom-designer-inspector','designer has contextual Inspector');
ed_has($designer,'data-certloom-device="desktop"','designer exposes desktop preview mode');
ed_has($designer,'data-certloom-device="mobile"','designer exposes mobile preview mode');
ed_has($designer,'data-certloom-token-list','designer exposes dynamic-data insertion');
ed_has($designer,'data-certloom-theme','designer exposes visual theme controls');
ed_has($designer,"'Plain-text fallback'",'designer retains explicit plain-text fallback');
ed_has($designer,'data-certloom-designer-feedback','designer exposes inline focusable validation feedback');
ed_lacks($designer,'scope_type','designer contains no assignment controls');
ed_has($js,'beforeunload','designer protects unsaved work');
ed_has($js,'wp.media','designer uses WordPress Media Library');
ed_has($js,"draggable=\"true\"",'designer supports pointer drag-and-drop reordering without removing keyboard controls');
ed_has($js,'showFeedback(','empty-content validation is inline instead of a browser alert');
ed_lacks($js,'window.alert(','designer does not use blocking browser alerts for validation');
ed_has($css,'@media (max-width:1100px)','designer has responsive layout');
ed_has($css,'prefers-reduced-motion','designer respects reduced motion');
ed_has($css,'forced-colors','designer supports forced-colors mode');

ed_has($designer,'Certloom Email Studio','designer uses the shared Studio product identity');
ed_has($designer,'data-certloom-sidebar-tab="insert"','designer exposes an Insert tool tab');
ed_has($designer,'data-certloom-sidebar-tab="structure"','designer exposes a Structure tool tab');
ed_has($designer,'data-certloom-sidebar-tab="data"','designer exposes a Data tool tab');
ed_has($designer,'data-certloom-sidebar-tab="styles"','designer exposes a Styles tool tab');
ed_has($designer,'data-certloom-email-stage-viewport','designer exposes an independent fixed canvas viewport');
ed_has($designer,'data-certloom-data-mode="sample"','designer exposes sample-data preview mode');
ed_has($designer,'data-certloom-data-mode="tokens"','designer exposes token preview mode');
ed_has($js,'renderStructure()','designer mirrors the block model into the Structure panel');
ed_has($js,'certloom-email-studio-active','designer activates focused Email Studio viewport ownership only on the designer route');
ed_has($css,'body.certloom-email-studio-active #wpfooter','designer suppresses the WordPress footer only while the focused Email Studio is active');
ed_has($css,'flex: 1 1 0','designer gives the three-pane workspace bounded remaining-height ownership');
ed_has($css,'.certloom-product-subnav[data-certloom-product-subnav]','focused Email Studio hides the redundant Design product tabs');
ed_has($css,'.certloom-email-designer-wrap.is-viewport-framed','focused Email Studio bounds one editor frame to the remaining desktop viewport');
ed_has($js,"studioRoot.classList.add( 'is-viewport-framed' )",'focused Email Studio frames the editor root instead of pinning the three panes');
ed_lacks($designer,'data-certloom-email-workspace-anchor','Email Studio no longer needs a separate hard-pin workspace anchor');
ed_lacks($css,'.certloom-designer-shell.is-viewport-pinned','Email Studio does not fixed-position the shared three-pane shell');
ed_has($css,'.certloom-email-sidebar-panel','left tool content owns its own internal scroll region');
ed_has($css,'.certloom-email-inspector-scroll','right Properties content owns its own internal scroll region');
ed_has($css,'--certloom-email-fit-scale','center email canvas has an explicit fit-to-stage scale');
ed_has($js,'syncCanvasFit()','Email Studio recomputes the 100% fit canvas scale from the available center stage');
ed_has($js,'scheduleCanvasFit()','Email Studio reschedules fit after content and viewport changes');
ed_has($js,'syncStudioViewport()','focused Email Studio resynchronizes the bounded editor frame from the live WordPress layout');
ed_has($js,"window.addEventListener( 'resize', scheduleStudioViewportSync )",'focused Email Studio resyncs its bounded frame on viewport resize');
ed_has($js,'data-rich-command="bold"','text blocks expose direct bold formatting controls');
ed_has($js,'data-rich-command="italic"','text blocks expose direct italic formatting controls');
ed_has($js,'data-rich-command="underline"','text blocks expose direct underline formatting controls');
ed_has($js,'data-rich-text-editor','text blocks use a visual rich-text editor rather than raw HTML-only editing');
ed_has($js,'data-style-color-picker','block-level colors expose native color pickers paired with canonical hex values');
ed_has($js,"L.typographySection || 'Typography'",'contextual inspector exposes Typography');
ed_has($js,"L.colorsSection || 'Colors'",'contextual inspector exposes Colors');
ed_has($js,"L.spacingSection || 'Spacing'",'contextual inspector exposes Spacing');
ed_has($css,'.certloom-email-rich-toolbar','Email Studio styles the rich-text formatting toolbar');
ed_has($css,'.certloom-email-color-control','Email Studio styles paired color picker and hex controls');
ed_has($designer,'data-certloom-name-edit','Email Studio renames the design inline in the document header');
ed_lacks($designer,"Template name', 'certloom'",'redundant Template name field is removed from the message metadata strip');
ed_has($designer,'certloom-message-settings--compact','Purpose, Subject and Preheader use the compact metadata row');
ed_has($designer,'data-certloom-token-search','Data browser supports field search');
ed_has($designer,'data-certloom-attachments-manager','Data panel exposes site-local email attachments');
ed_has($designer,'data-certloom-add-block="html"','Insert exposes a Custom HTML block');
ed_has($designer,'data-certloom-theme-media-card','Email style exposes page/content background image controls');
ed_has($js,'sanitizeCustomHtml(','Custom HTML has a client-side safe-preview boundary');
ed_has($js,'sampleHtml(','dynamic sample values are escaped before insertion into HTML previews');
ed_has($designer,'data-certloom-add-block="container"','Insert exposes generic Container authoring instead of fixed Columns');
ed_has($designer,'data-certloom-container-preset="50-50"','Container presets provide fast 50/50 composition');
ed_has($js,'legacyColumnsToContainer(','legacy Columns are migrated into normal nested Containers in Studio');
ed_has($js,'data-container-background-image','Container inspector exposes background media');
ed_has($js,'data-container-padding','Container inspector exposes per-side padding');
ed_has($js,'containsBlock(source,block)','nested drag/drop guards against moving a Container into itself');
ed_has($js,'wrap.style.flex=`${basis} 1 0px`','horizontal Container children use proportional shrinkable flex tracks so gaps and extra children cannot push content outside the parent');
ed_has($js,"event.stopPropagation();if(event.target.closest('[data-up]'))",'nested block action controls stop at the selected block instead of bubbling into ancestor Containers');
ed_has($css,'.certloom-email-container-children { display:flex;width:100%;max-width:100%;min-width:0','Container child tracks are bounded to their parent width in the Studio canvas');
ed_has($designer,'certloom-container-preset__preview','Container presets use readable visual layout previews instead of tiny fractional text-only controls');
ed_has($js,'certloom-email-container-empty__add','empty Containers expose a direct Add block action while remaining bounded drop targets');
ed_has($js,'inspectorPathText(','selected nested blocks expose their hierarchy path in Properties');
ed_has($css,'.certloom-container-background-settings','Container background image size/position/repeat controls use a compact grouped control row');
ed_has($css,'.certloom-container-subsection','Container Background and Border controls are visually grouped into compact subsections');
ed_has($js,'renderTokenBrowser(','Data browser is grouped/searchable instead of a fixed token list');
ed_has($template_service_source,"snapshot['merge']['values']",'Email composition resolves the immutable issued merge map');
ed_has($template_service_source,'validate_static_attachment_ids','static attachments are validated before Email Studio accepts them');
ed_has($token_catalog_source,"'custom:' . \$key",'dynamic custom fields are generated from the configured field catalog');

ed_has($repo_source,'certloom_email_design_assigned_pause_blocked','assigned designs cannot be silently unpublished');
ed_has($repo_source,'certloom_email_design_assigned_purpose_blocked','assigned designs cannot silently change purpose');
ed_has($repo_source,'certloom_email_design_in_use','assigned designs cannot be deleted');
ed_has($package_source,"'active'",'package import contract carries explicit lifecycle state');
ed_has($repo_source,'install_email_assets','email package import installs embedded local media through the shared portable-media boundary');
ed_has($repo_source,'cleanup( $installed[\'created_ids\'] )','failed email design persistence cleans media created earlier in the import');
ed_lacks($package_source,'EmailDesignAssignmentRepository','package layer has no assignment authority dependency');
ed_has($admin,'EmailDesignPackage::MAX_BYTES','email import UI/handler derives its upload ceiling from the portable package contract');
ed_has($admin,'wp_max_upload_size','email import respects the site upload ceiling when it is lower than the Certloom package ceiling');
ed_has($admin,'->encode( $result )','email export uses the bounded package encoder instead of emitting unchecked JSON');

echo "PASS  Email Designs separates Library/My Templates/Designer from centralized Design Rules\n";
echo "PASS  email package round-trip is integrity-checked and assignment-free, and built-in designs self-repair without overriding Published state\n\n";
echo $checks." checks, 0 failures\n";
