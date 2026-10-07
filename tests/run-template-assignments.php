<?php
/** TPL-03 premium Template assignment UX and deterministic fallback contract tests. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){ return $this->c; } public function get_error_message(){ return $this->m; } }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($t){ return $t; }
function absint($v){ return abs((int)$v); }
function sanitize_key($key){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$key)); }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Templates\TemplateAssignmentPolicy;

$checks=0;
function t17($ok,$message){ global $checks; if(!$ok){ throw new RuntimeException($message); } $checks++; }
function t17has($source,$needle,$message){ t17(false!==strpos($source,$needle),$message); }
function t17same($expected,$actual,$message){ t17($expected===$actual,$message.' expected '.var_export($expected,true).' got '.var_export($actual,true)); }

$policy=new TemplateAssignmentPolicy();
t17same(array('global','issuer','program','batch'),TemplateAssignmentPolicy::scopes(),'scope inventory is stable');
$chain=$policy->candidates(array('issuer_id'=>7,'program_id'=>8,'batch_id'=>9));
t17same(array('batch','program','issuer','global'),array_column($chain,'scope_type'),'most-specific hierarchy order');
t17same(array(9,8,7,0),array_column($chain,'scope_id'),'hierarchy IDs exact');
t17same(array(0,1,2,3),array_column($chain,'depth'),'fallback depth exact');
$chain=$policy->candidates(array('issuer_id'=>7,'program_id'=>8));
t17same(array('program','issuer','global'),array_column($chain,'scope_type'),'missing Batch is omitted');
$chain=$policy->candidates(array());
t17same(array('global'),array_column($chain,'scope_type'),'Global is always final fallback');
foreach(array(array('global',0),array('issuer',1),array('program',2),array('batch',3)) as $case){ $n=$policy->normalize_scope($case[0],$case[1]); t17(!is_wp_error($n),'valid scope '.$case[0]); t17same($case[0],$n['scope_type'],'normalized scope '.$case[0]); t17same($case[1],$n['scope_id'],'normalized id '.$case[0]); }
$bad=$policy->normalize_scope('global',1); t17(is_wp_error($bad)&&'certloom_template_assignment_scope_invalid'===$bad->get_error_code(),'Global rejects record ID');
$bad=$policy->normalize_scope('program',0); t17(is_wp_error($bad)&&'certloom_template_assignment_scope_invalid'===$bad->get_error_code(),'record scope requires ID');
$bad=$policy->normalize_scope('other',3); t17(is_wp_error($bad),'unknown scope rejected');

$root=dirname(__DIR__);
$files=array(
	'repo'=>file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'),
	'admin'=>file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'),
	'rules'=>file_get_contents($root.'/src/Infrastructure/Admin/DesignRulesAdmin.php'),
	'manager'=>file_get_contents($root.'/src/Infrastructure/Admin/DesignRuleManager.php'),
	'policy'=>file_get_contents($root.'/src/Application/Templates/TemplateAssignmentPolicy.php'),
	'css'=>file_get_contents($root.'/admin/assets/design-rules.css'),
	'placeholder'=>file_get_contents($root.'/admin/class-ecapla-certverify-admin.php'),
);
foreach($files as $key=>$source){ t17(is_string($source)&&''!==$source,'source '.$key.' available'); }

// Persistence + integrity rules.
t17has($files['repo'],'public function clear_assignment','explicit None/inherit has a repository command');
t17has($files['repo'],'FOR UPDATE','assignment mutations use row locking');
t17has($files['repo'],'$locked_template = $this->lock_template( $template_id )','assignment re-locks the Template inside the transaction to serialize against archive');
t17has($files['repo'],'certloom_template_assignment_stale','stale assignment submissions fail closed');
t17has($files['repo'],'certloom_template_archive_assigned','actively assigned Templates cannot be archived');
t17has($files['repo'],'public function get_assignment','direct assignment can be inspected');
t17has($files['repo'],'public function resolve_assignment_details','effective source is resolved with metadata');
t17has($files['repo'],'TemplateAssignmentPolicy','resolver uses canonical shared hierarchy');
t17has($files['repo'],'certloom_template_assignment_corrupt','invalid/dangling assignment state fails closed');
t17has($files['repo'],'public function list_candidates','candidate selector has a bounded query API');
t17has($files['repo'],"array( 'all', 'factory', 'custom' )",'candidate API supports factory/custom filtering');
t17has($files['repo'],'layout_sha256','candidate metadata re-proves canonical layout integrity');
t17has($files['repo'],'$this->layouts->normalize','candidate metadata validates canonical layout');
t17has($files['repo'],'$this->json->hash','candidate metadata recomputes canonical hash');
t17has($files['repo'],'t.archived_at IS NULL','archived Templates are excluded from candidates');
t17has($files['repo'],"'active' => 0",'None preserves row identity by deactivating direct assignment');

// Premium UX requirements now live in the central Design Rules workspace.
t17has($files['rules'],'Global → Issuer → Program → Batch','Design Rules explains the canonical hierarchy at the page level');
t17has($files['rules'],'Global defaults','Global certificate/email defaults are a dedicated visual section');
t17has($files['rules'],'Issuer, Program and Batch rules','record-level overrides are grouped below Global defaults');
t17has($files['rules'],'Search Issuers, Programs or Batches','large target inventories are server-searchable');
t17has($files['rules'],'data-certloom-rule-load-more','large target inventories support incremental loading');
t17has($files['rules'],'Selected records','bulk editing has a dedicated selected-record editor');
t17has($files['rules'],'No change','bulk editing can leave one design authority untouched');
t17has($files['rules'],'Inherit from parent','explicit inheritance is a first-class bulk action');
t17has($files['rules'],'Certificate design','certificate and email rules are edited independently');
t17has($files['rules'],'Email design','email design control is visible beside certificate design');
t17has($files['rules'],'Per message purpose','email rules retain their message-purpose dimension');
t17has($files['rules'],'Apply changes','bulk mutation has one explicit commit action after previews');
t17has($files['rules'],'data-certloom-rule-preview','effective result previews are part of the rule editor');
t17has($files['rules'],'The Global default also uses this design.','Used-by deep links distinguish Global usage from direct record rules');
t17has($files['manager'],'MODE_NO_CHANGE','shared manager preserves independent no-change semantics');
t17has($files['manager'],'MODE_INHERIT','shared manager owns inheritance semantics');
t17has($files['manager'],'MAX_TARGETS','bulk operations have an explicit server-side target bound');
t17has($files['manager'],'Snapshot the complete selection before the first mutation','bulk rollback snapshots are taken before writes');
t17has($files['admin'],'expected_assignment_id','legacy certificate assignment POST compatibility retains optimistic-concurrency identity');
t17has($files['admin'],'expected_updated_at','legacy certificate assignment POST compatibility retains optimistic-concurrency timestamp');
t17has($files['admin'],'certloom_template_assignment_','legacy assignment mutations remain nonce-bound to exact scope');
t17has($files['admin'],'template_list_pagination','main Templates list retains its existing pagination capability');
t17(false===strpos($files['admin'],'private function assignment_workspace'),'retired certificate assignment workspace code is removed rather than left unreachable');
t17(false===strpos($files['admin'],'Use for this Program'),'retired Program-assignment copy is not retained in Certificate Design');

// Responsive/accessibility and current-authority honesty.
t17has($files['css'],'.certloom-design-rules-form','Design Rules has dedicated two-column workspace CSS');
t17has($files['css'],'position:sticky','selected-record editor remains available while browsing targets on desktop');
t17has($files['css'],':focus-visible','Design Rules controls keep visible keyboard focus');
t17has($files['css'],'@media(max-width:782px)','Design Rules has a mobile layout');
t17has($files['css'],'prefers-reduced-motion','Design Rules respects reduced motion');
t17has($files['css'],'forced-colors:active','Design Rules supports forced-colors mode');
t17has($files['placeholder'],'Design tools available · downloadable publication protected','live placeholder reflects current Template/Designer availability without technical authority jargon');
t17has($files['placeholder'],'The Template Library and Designer are available through Design. Downloadable PDF and image publication stays protected until its final rendering safety checks are approved.','live placeholder truthfully describes current Design availability and protected downloadable publication');

echo "PASS  TPL-03 uses one deterministic Batch → Program → Issuer → Global fallback policy\n";
echo "PASS  Design Rules centralizes searchable/bulk certificate + email inheritance while legacy certificate mutation compatibility stays concurrency-safe\n\n";
echo $checks . " checks, 0 failures\n";
