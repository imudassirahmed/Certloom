<?php
/** TPLUX-01 visual Template Library discovery + authority boundary contract. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Templates\TemplateLibraryMetadata;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Domain\Rendering\LayoutSchema;

$pass=0;$fail=0; function tl($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$meta=new TemplateLibraryMetadata();
$base=TemplateDefaults::blank(LayoutSchema::PAGE_A4,LayoutSchema::ORIENTATION_LANDSCAPE);
$classic=$meta->describe(array('machine_key'=>'certloom-preset-classic-a4-landscape','layout'=>$base));
tl(true===($classic['factory']??false),'factory preset recognized from stable machine key');
tl('classic'===($classic['family']??''),'classic family derived deterministically');
tl('formal'===($classic['style']??''),'classic maps to formal browsing style');
tl('navy_gold'===($classic['color']??''),'classic maps to bounded color family');
tl(in_array('certification_standards',$classic['purposes']??array(),true),'classic includes certification browsing guidance');
tl('en_base'===($classic['language']??''),'current factory set is honestly labeled English base');
tl('landscape'===($classic['orientation']??''),'orientation comes from canonical layout');
tl('A4'===($classic['page_size']??''),'page size comes from canonical layout');
tl(TemplateLibraryMetadata::RTL_LAYOUT_READY===($classic['rtl']??''),'empty canonical layout is RTL layout-ready');
$forced=$base;$forced['elements'][]=array('type'=>'text','style'=>array('direction'=>'ltr'));
$custom=$meta->describe(array('machine_key'=>'customer-template','layout'=>$forced));
tl(false===($custom['factory']??true)&&'custom'===($custom['style']??''),'customer Template stays custom rather than acquiring factory identity');
tl(TemplateLibraryMetadata::RTL_NEEDS_REVIEW===($custom['rtl']??''),'forced LTR content is surfaced as needing RTL review');
tl($meta->matches($classic,array('style'=>'formal','orientation'=>'landscape','purpose'=>'certification_standards')),'visual facets match bounded metadata');
tl(!$meta->matches($classic,array('style'=>'academic')),'visual facets fail closed on mismatched style');
tl($meta->fits_program($classic,array('profile'=>array('purpose'=>'certification_standards'))),'Program purpose-fit uses canonical Program Profile purpose');
tl(!$meta->fits_program($classic,array('profile'=>array('purpose'=>'academic_credentials'))),'Program purpose-fit does not invent cross-purpose compliance claims');
tl(isset(TemplateLibraryMetadata::purpose_labels()['training_courses']),'library reuses Program Profile purpose taxonomy');
$portrait=TemplateDefaults::blank(LayoutSchema::PAGE_LETTER,LayoutSchema::ORIENTATION_PORTRAIT);
tl('Letter'===($portrait['page']['size']??'')&&'portrait'===($portrait['page']['orientation']??''),'blank creation supports Letter portrait canonically');
tl(215.9===(float)($portrait['page']['width_mm']??0)&&279.4===(float)($portrait['page']['height_mm']??0),'Letter portrait uses LayoutSchema dimensions');
$fallback=TemplateDefaults::blank('future-size','diagonal');
tl('A4'===($fallback['page']['size']??'')&&'landscape'===($fallback['page']['orientation']??''),'invalid blank page choices fall back safely');

$root=dirname(__DIR__);$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');$repo=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php');$domain=file_get_contents($root.'/src/Infrastructure/Admin/DomainAdmin.php');$css=file_get_contents($root.'/admin/assets/template-library.css');$js=file_get_contents($root.'/admin/assets/template-library.js');$plugin=file_get_contents($root.'/certloom.php');
tl(false!==strpos($admin,'Certificate library')&&false!==strpos($admin,"'Library', 'certloom'")&&false!==strpos($admin,"'My designs', 'certloom'"),'Design exposes the approved Library and My designs visual workspaces');
tl(false!==strpos($admin,'TemplatePreviewService')&&false!==strpos($admin,'RenderPlanHtmlPreview')&&false!==strpos($admin,'visual_library_preview'),'thumbnail Preview reuses the shared certificate preview service and presenter');
tl(false===strpos(substr($admin, strpos($admin, 'private function visual_library_card'), strpos($admin, 'private function visual_library_preview')-strpos($admin, 'private function visual_library_card')), 'certloom_template_assignment_save')&&false!==strpos($admin,'Design Rules'),'visual Library is assignment-free and points users to centralized Design Rules');
tl(false!==strpos($admin,'expected_assignment_id')&&false!==strpos($admin,'expected_updated_at'),'Program assignment preserves optimistic-concurrency proofs');
tl(false!==strpos($admin,'New design')&&false!==strpos($admin,'Customize')&&false!==strpos($admin,'Export')&&false!==strpos($admin,'data-certloom-card-publish'),'certificate workspaces expose restrained create/customize/export/publication actions');
tl(false!==strpos($admin,'Start from background')&&false!==strpos($admin,'certloom_template_background_create'),'background-first creation flow remains available through progressive disclosure');
tl(substr_count($admin,"'letter' === \$size_key ? LayoutSchema::PAGE_LETTER : LayoutSchema::PAGE_A4")===2,'blank and background creation normalize Letter without falling back to A4');
tl(false!==strpos($admin,"in_array( \$mime, array( 'image/png', 'image/jpeg' )")&&false!==strpos($admin,"'asset' => 'attachment:'"),'background upload remains local PNG/JPEG canonical image element');
tl(false!==strpos($admin,'id="certloom-template-library-filters"')&&false!==strpos($admin,'library_purpose')&&false!==strpos($admin,'library_orientation'),'visual filters are addressable and focused on design discovery rather than assignment context');
tl(false!==strpos($admin,'purpose_labels')&&false!==strpos($admin,"'Purpose', 'certloom'"),'Template Library reuses one canonical purpose authority');
tl(false!==strpos($admin,'design_package')&&false!==strpos($admin,'certloom_template_import')&&false!==strpos($admin,'certloom_template_export_'),'certificate Import and Export are first-class while retaining the strict package engine');
tl(false!==strpos($admin,'TemplatePackage::MAX_BYTES')&&false!==strpos($admin,'wp_max_upload_size'),'certificate Import derives its upload ceiling from the package contract and the site upload limit');
tl(false!==strpos($admin,'( new TemplatePackage() )->encode( $result )'),'certificate Export uses the bounded package encoder so downloaded packages remain importable');
tl(false!==strpos($admin,'Assignment stays in Design Rules')&&false!==strpos($admin,'Used by %d rule'),'My designs keeps assignment management out of the design workspace while preserving usage visibility');
tl(false!==strpos($repo,"\$row['layout'] = \$layout")&&false!==strpos($repo,'min( 250'),'repository supplies validated canonical layout through bounded discovery pool');
tl(false!==strpos($domain,'DesignRulesAdmin::PAGE')&&false!==strpos($domain,"'rule_scope' => 'program'")&&false!==strpos($domain,"'rule_scope_id' => \$program_id"),'Program design continuation deep-links into centralized Design Rules for that Program');
tl(false!==strpos($css,'forced-colors:active')&&false!==strpos($css,'prefers-reduced-motion:reduce')&&false!==strpos($css,'focus-visible'),'Template Library CSS protects forced colors, reduced motion and focus visibility');
tl(false!==strpos($css,'minmax(304px,1fr)')&&false!==strpos($css,'max-width:100%'),'Template cards are responsive and bounded');
tl(false!==strpos($js,'cloneNode(true)')&&false!==strpos($js,'replaceChildren')&&false===strpos($js,'innerHTML'),'large Preview clones trusted DOM without unsafe HTML insertion');
tl(false!==strpos($js,"mime !== 'image/png' && mime !== 'image/jpeg'")&&false!==strpos($js,'wp.media'),'Media Library picker fails closed to current PNG/JPEG contract');
tl(!file_exists($root.'/resources/renderer/accepted.json'),'TPLUX-01 does not manufacture renderer authority');
tl(false!==strpos($plugin,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")&&false!==strpos($plugin,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'TPLUX-01 leaves normalized production authority closed');

echo "\n$pass Template Library checks, $fail failures\n"; exit($fail?1:0);
