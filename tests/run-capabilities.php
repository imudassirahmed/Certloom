<?php
/** Pure granular capability migration tests for 1.5.3. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ECAPLA_CERTVERIFY_CAP', 'manage_certverify' );
$GLOBALS['cap_options'] = array(
	'wp_user_roles' => array(
		'administrator' => array('name'=>'Administrator','capabilities'=>array('read'=>true)),
		'editor' => array('name'=>'Editor','capabilities'=>array('read'=>true,'manage_certverify'=>true)),
		'subscriber' => array('name'=>'Subscriber','capabilities'=>array('read'=>true)),
	),
);
class FakeRoles {
	public $role_key = 'wp_user_roles';
	public $roles = array();
	public function __construct(){ $this->for_site(1); }
	public function for_site($id){ $this->roles = get_option($this->role_key,array()); }
	public function reinit(){ $this->for_site(1); }
}
class FakeRole {
	private $key;
	public function __construct($key){ $this->key=$key; }
	public function has_cap($cap){ return !empty($GLOBALS['cap_options']['wp_user_roles'][$this->key]['capabilities'][$cap]); }
}
$GLOBALS['fake_roles_obj']=new FakeRoles();
function wp_roles(){ return $GLOBALS['fake_roles_obj']; }
function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['cap_options'])?$GLOBALS['cap_options'][$k]:$d; }
function update_option($k,$v){ $GLOBALS['cap_options'][$k]=$v; if($k==='wp_user_roles'){$GLOBALS['fake_roles_obj']->roles=$v;} return true; }
function delete_option($k){ unset($GLOBALS['cap_options'][$k]); return true; }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v)); }
function absint($v){ return abs((int)$v); }
function get_current_blog_id(){ return 1; }
function __( $t ){ return $t; }
function current_user_can($cap){ return false; }
function get_role($key){ return isset($GLOBALS['cap_options']['wp_user_roles'][$key]) ? new FakeRole($key) : null; }
function add_role($key,$name,$caps){ $r=get_option('wp_user_roles',array()); $r[$key]=array('name'=>$name,'capabilities'=>$caps); update_option('wp_user_roles',$r); return new FakeRole($key); }

require_once dirname(__DIR__).'/includes/class-ecapla-certverify-permissions.php';

function cp_true($v,$m=''){ if(!$v){ throw new RuntimeException($m?:'Expected true'); } }
function cp_false($v,$m=''){ if($v){ throw new RuntimeException($m?:'Expected false'); } }

cp_true(Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities(true),'cap migration');
$roles=get_option('wp_user_roles',array());
foreach(Ecapla_CertVerify_Permissions::all_capabilities() as $cap){
	cp_true(!empty($roles['administrator']['capabilities'][$cap]),'administrator '.$cap);
	cp_true(!empty($roles['editor']['capabilities'][$cap]),'legacy editor '.$cap);
	cp_false(!empty($roles['subscriber']['capabilities'][$cap]),'subscriber '.$cap);
}
cp_true(!empty($roles['administrator']['capabilities']['manage_certverify']),'admin legacy cap preserved');
cp_true(!empty($roles['editor']['capabilities']['manage_certverify']),'editor legacy cap preserved');

// Existing installations may already have the current schema/health marker while
// missing a capability introduced by an incomplete older upgrade. The health
// fast path must verify the administrator's persisted capability set, not trust
// the marker alone.
$GLOBALS['cap_options'][Ecapla_CertVerify_Permissions::CAPABILITY_SCHEMA_OPTION] = Ecapla_CertVerify_Permissions::CAPABILITY_SCHEMA_VERSION;
$GLOBALS['cap_options'][Ecapla_CertVerify_Permissions::HEALTH_OPTION] = array(
    'version' => Ecapla_CertVerify_Permissions::CAPABILITY_SCHEMA_VERSION,
    'checked_at' => time(),
);
unset($GLOBALS['cap_options']['wp_user_roles']['administrator']['capabilities'][Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS]);
$GLOBALS['fake_roles_obj']->roles = $GLOBALS['cap_options']['wp_user_roles'];
cp_false(!empty($GLOBALS['cap_options']['wp_user_roles']['administrator']['capabilities'][Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS]), 'fixture must begin with analytics capability missing');
cp_true(Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities(false), 'current-marker capability self-repair');
cp_true(!empty($GLOBALS['cap_options']['wp_user_roles']['administrator']['capabilities'][Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS]), 'administrator analytics capability restored despite current health marker');

echo "PASS  legacy broad-access roles receive granular capabilities without granting unrelated roles\n";
echo "PASS  current health markers cannot mask a missing administrator analytics capability\n\n2 tests, 0 failures\n";
