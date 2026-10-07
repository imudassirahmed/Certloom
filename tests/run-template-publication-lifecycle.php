<?php
/** 1.6.54 Template publication/lifecycle contract tests. */

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

use Certloom\Application\Templates\TemplateLifecycle;

$checks=0;
function t54($ok,$message){ global $checks; if(!$ok){ throw new RuntimeException($message); } $checks++; }
function t54has($source,$needle,$message){ t54(false!==strpos($source,$needle),$message); }
function t54not($source,$needle,$message){ t54(false===strpos($source,$needle),$message); }
function t54same($expected,$actual,$message){ t54($expected===$actual,$message.' expected '.var_export($expected,true).' got '.var_export($actual,true)); }

// Lifecycle is pointer-derived; no mutable status column is introduced.
t54same(TemplateLifecycle::DRAFT,TemplateLifecycle::state(array('current_revision_id'=>7,'published_revision_id'=>0,'archived_at'=>null)),'unpublished active Template is draft');
t54same(TemplateLifecycle::PUBLISHED,TemplateLifecycle::state(array('current_revision_id'=>7,'published_revision_id'=>7,'archived_at'=>null)),'matching pointers are published');
t54same(TemplateLifecycle::CHANGES_PENDING,TemplateLifecycle::state(array('current_revision_id'=>8,'published_revision_id'=>7,'archived_at'=>null)),'newer draft preserves last published authority');
t54same(TemplateLifecycle::ARCHIVED,TemplateLifecycle::state(array('current_revision_id'=>8,'published_revision_id'=>7,'archived_at'=>'2026-08-26 12:00:00')),'archive overrides active lifecycle presentation');

$root=dirname(__DIR__);
$files=array(
	'repo'=>file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'),
	'admin'=>file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'),
	'lifecycle'=>file_get_contents($root.'/src/Application/Templates/TemplateLifecycle.php'),
	'css'=>file_get_contents($root.'/admin/assets/admin.css'),
);
foreach($files as $key=>$source){ t54(is_string($source)&&''!==$source,'source '.$key.' available'); }

// Publication is exact-review-bound; artifact generation remains renderer-gated elsewhere.
t54not($files['repo'],'certloom_template_publish_renderer_gate','design publication is not blocked by renderer artifact authority');
t54not($files['repo'],'publication_status()','Template repository does not conflate design availability with renderer release authority');
t54has($files['repo'],'$expected_revision_sha256','publication accepts exact reviewed layout hash');
t54has($files['repo'],'certloom_template_publish_review_required','direct publication callers cannot omit review proof');
t54has($files['repo'],'certloom_template_publish_review_stale','hash drift/stale review fails closed');
t54has($files['repo'],'verify_revision_row( $current )','publication revalidates stored revision bytes before pointer advance');
t54has($files['repo'],'hash_equals( strtolower( (string) $verified[\'layout_sha256\'] ), $expected_revision_sha256 )','publication binds exact reviewed SHA-256');
t54has($files['admin'],'expected_revision_sha256','admin carries exact revision hash into publication');
t54has($files['admin'],'Publish design','admin exposes a single plain-language publish action');
t54not($files['admin'],'Publish reviewed revision','technical review wording is not the primary customer publish action');
t54not($files['admin'],'Publishing is unavailable until renderer authority is available.','admin no longer blocks design publication because production artifacts are not yet authorized');
t54has($files['admin'],'Save a valid design revision before publishing.','admin only disables publication when there is no valid revision to publish');

// Published revision is loaded independently and integrity checked.
t54has($files['repo'],'public function find_published','published-only read boundary exists');
t54has($files['repo'],'$published = $this->find_revision( $published_id )','published boundary uses integrity-checked revision loader');
t54has($files['repo'],'$row[\'published_revision\'] = $published','verified published revision is returned explicitly');
t54has($files['repo'],'public function resolve_published_assignment_details','future issuance has separate published-only assignment resolver');
t54has($files['repo'],'get_publication_assignment_candidate','published resolver avoids mutable draft revision join');
t54has($files['repo'],'$template = $this->find_published','published resolver selects published boundary, not current draft');
t54has($files['repo'],'$assignment[\'published_revision_id\']','resolved issuance boundary exposes exact published revision');
t54has($files['repo'],'$assignment[\'published_layout_sha256\']','resolved issuance boundary exposes exact published hash');
t54has($files['repo'],'$assignment[\'published_layout\']','resolved issuance boundary carries reviewed published layout');

// Archive/restore/duplicate behavior is non-destructive and concurrency-aware.
t54not($files['repo'],'certloom_template_archive_published','published Templates can be archived without deleting published history');
t54has($files['repo'],'certloom_template_archive_assigned','active assignments still block archive');
t54has($files['repo'],'public function restore','archived Template can be restored');
t54has($files['repo'],'array( \'archived_at\' => null, \'updated_at\' => $now )','restore changes only archive/lifecycle metadata');
t54has($files['repo'],'certloom_template_archived_read_only','archived metadata edits fail closed');
t54has($files['repo'],'public function duplicate','duplicate command exists');
t54has($files['repo'],'$identity[\'layout\'] = $template[\'current_revision\'][\'layout\']','duplicate copies exact reviewed current revision');
t54has($files['repo'],'$this->create( $identity, $actor_user_id, self::SOURCE_MANUAL )','duplicate creates a fresh Template identity and draft');
t54has($files['repo'],'$current_id !== absint( $expected_revision_id )','duplicate rejects stale source revision');

// Active / Archived / All administration and premium interaction quality.
t54has($files['repo'],"array( 'active', 'archived', 'all' )",'repository owns bounded lifecycle list filters');
t54has($files['admin'],'template_status','admin persists lifecycle filter');
t54has($files['admin'],'Design status filter','lifecycle tabs are accessible with customer-facing language');
t54has($files['admin'],'Archive is non-destructive','admin explains safe archive semantics');
t54has($files['admin'],'certloom_template_archive','archive action is nonce-routed');
t54has($files['admin'],'certloom_template_restore','restore action is nonce-routed');
t54has($files['admin'],'certloom_template_duplicate','duplicate action is nonce-routed');
t54has($files['admin'],'Duplicate as a new draft','duplicate UX is explicit');
t54has($files['admin'],'Archived templates are read-only','archived editor is intentionally read-only');
t54has($files['admin'],'template_list_pagination( $page','main Template library actually renders pagination');
foreach ( array( "TemplateLifecycle::DRAFT => __( 'Draft'", "TemplateLifecycle::PUBLISHED => __( 'Published'", "TemplateLifecycle::CHANGES_PENDING => __( 'Changes pending'", "TemplateLifecycle::ARCHIVED => __( 'Archived'" ) as $lifecycle_copy ) { t54has( $files['admin'], $lifecycle_copy, 'admin presents the explicit lifecycle state' ); }
t54has($files['css'],'.certloom-template-state-changes_pending','lifecycle has a distinct changes-pending badge class');
t54has($files['css'],'.certloom-template-lifecycle-tabs','lifecycle filters have dedicated component styling');
t54has($files['css'],'.certloom-template-lifecycle-summary','editor lifecycle summary has dedicated layout');
t54has($files['css'],'.certloom-template-publication-card','publication review has dedicated visual boundary');
t54has($files['css'],'focus-visible','new controls retain visible keyboard focus');
t54has($files['css'],'@media (max-width: 782px)','new lifecycle UI has mobile rules');
t54has($files['css'],'prefers-reduced-motion','new lifecycle UI respects reduced motion');

echo "PASS  1.6.54 Template lifecycle is explicit, non-destructive and separates publication from artifact authority\n";
echo "PASS  publication is revision+SHA reviewed, duplicates are fresh drafts, archived Templates are read-only, and issuance has a published-only resolver\n\n";
echo $checks . " checks, 0 failures\n";
