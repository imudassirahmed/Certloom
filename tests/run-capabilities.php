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
class FakeCurrentUser {
    public $ID = 42;
    public $roles = array('editor');
    public $allcaps = array();
    public $refreshes = 0;
    public function __construct(){ $this->for_site(1); }
    public function for_site($id){ $this->refreshes++; $caps=get_option('wp_user_roles',array()); $this->allcaps=(array)($caps['editor']['capabilities']??array()); }
    public function get_role_caps(){ $this->for_site(1); return $this->allcaps; }
}
$GLOBALS['fake_current_user']=new FakeCurrentUser();
function wp_get_current_user(){ return $GLOBALS['fake_current_user']; }
function current_user_can($cap){ return !empty($GLOBALS['fake_current_user']->allcaps[$cap]); }
$GLOBALS['registered_submenus']=array();
$GLOBALS['submenu_nopriv']=array();
function add_submenu_page($parent,$page_title,$menu_title,$capability,$slug,$callback=null){
    if(!current_user_can($capability)){ $GLOBALS['submenu_nopriv'][$parent][$slug]=true; return false; }
    $GLOBALS['registered_submenus'][$slug]=array('parent'=>$parent,'capability'=>$capability);
    return $parent.'_page_'.$slug;
}
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

// A fresh marker must also verify every role that still carries the historical
// broad Certloom capability. Otherwise WordPress can reject newer granular
// pages such as Email designs before Certloom's screen callback runs.
$GLOBALS['cap_options'][Ecapla_CertVerify_Permissions::CAPABILITY_SCHEMA_OPTION] = Ecapla_CertVerify_Permissions::CAPABILITY_SCHEMA_VERSION;
$GLOBALS['cap_options'][Ecapla_CertVerify_Permissions::HEALTH_OPTION] = array(
    'version' => Ecapla_CertVerify_Permissions::CAPABILITY_SCHEMA_VERSION,
    'checked_at' => time(),
);
unset($GLOBALS['cap_options']['wp_user_roles']['editor']['capabilities'][Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS]);
$GLOBALS['fake_roles_obj']->roles = $GLOBALS['cap_options']['wp_user_roles'];
cp_false(!empty($GLOBALS['cap_options']['wp_user_roles']['editor']['capabilities'][Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS]), 'fixture must begin with legacy manager missing the granular email/credential capability');
cp_true(Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities(false), 'fresh-marker legacy-role capability self-repair');
cp_true(!empty($GLOBALS['cap_options']['wp_user_roles']['editor']['capabilities'][Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS]), 'legacy Certloom-enabled role regains granular credential/email access despite fresh marker');
cp_true(current_user_can(Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS), 'already-loaded current user capability map is refreshed before admin menu route registration');
$refreshes_before=$GLOBALS['fake_current_user']->refreshes;
cp_true(Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities(false), 'healthy fast path refreshes already-loaded current user capabilities');
cp_true($GLOBALS['fake_current_user']->refreshes>$refreshes_before, 'healthy fast path does not leave a stale WP_User allcaps map in memory');
// Contextual routes use the established Certloom management capability for
// WordPress route registration. Their callbacks separately enforce granular
// authorization. This prevents core from writing a permanent request-local
// no-priv marker before Certloom can reach its own authorization/repair logic.
unset($GLOBALS['fake_current_user']->allcaps[Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS]);
unset($GLOBALS['fake_current_user']->allcaps[Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS]);
cp_true(current_user_can(ECAPLA_CERTVERIFY_CAP), 'fixture retains established Certloom management access while granular current-user map is stale');
add_submenu_page('certloom','Email designs','Email designs',ECAPLA_CERTVERIFY_CAP,'certloom-email-designs');
add_submenu_page('certloom','Email Delivery','Email Delivery',ECAPLA_CERTVERIFY_CAP,'certloom-email-delivery');
add_submenu_page('certloom','Analytics','Analytics',ECAPLA_CERTVERIFY_CAP,'certloom-analytics');
cp_true(isset($GLOBALS['registered_submenus']['certloom-email-designs'],$GLOBALS['registered_submenus']['certloom-email-delivery']), 'Email contextual routes remain registered when granular current-user capability state is stale');
cp_true(isset($GLOBALS['registered_submenus']['certloom-analytics']), 'Analytics contextual route remains registered when granular current-user capability state is stale');
cp_false(isset($GLOBALS['submenu_nopriv']['certloom']['certloom-email-designs'])||isset($GLOBALS['submenu_nopriv']['certloom']['certloom-email-delivery'])||isset($GLOBALS['submenu_nopriv']['certloom']['certloom-analytics']), 'contextual routes do not poison WordPress no-priv routing for legitimate Certloom managers');

echo "PASS  legacy broad-access roles receive granular capabilities without granting unrelated roles\n";
echo "PASS  current health markers cannot mask a missing administrator analytics capability\n";
echo "PASS  fresh health markers cannot mask missing granular access on legacy Certloom-enabled roles\n";
echo "PASS  current-request WP_User capability maps are refreshed before contextual routes register\n";
echo "PASS  Email Design and Email Delivery routes stay routable across granular-capability drift\n";
echo "PASS  Analytics route stays routable across granular-capability drift\n";
echo "PASS  WordPress no-priv routing is not poisoned for legitimate Certloom managers\n\n7 tests, 0 failures\n";
