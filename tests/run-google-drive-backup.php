<?php
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$tmp = sys_get_temp_dir() . '/certloom-bak03-' . bin2hex( random_bytes( 4 ) );
@mkdir( $tmp . '/webroot/wp-content', 0777, true );
define( 'ABSPATH', $tmp . '/webroot/' );
define( 'WP_CONTENT_DIR', $tmp . '/webroot/wp-content' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

class WP_Error { private $c; private $m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;} function __($t,$d=null){return $t;} function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_file_name($v){return preg_replace('/[^A-Za-z0-9._-]/','-',basename((string)$v));} function absint($v){return abs((int)$v);} function wp_json_encode($v,$o=0){return json_encode($v,$o);} function wp_normalize_path($p){return str_replace('\\','/',(string)$p);} function trailingslashit($p){return rtrim((string)$p,'/\\').'/';} function untrailingslashit($p){return rtrim((string)$p,'/\\');} function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0777,true);} function maybe_serialize($v){return serialize($v);} function wp_parse_url($u){return parse_url($u);} function admin_url($p=''){return 'https://example.test/wp-admin/'.$p;} function get_current_user_id(){return 7;} function wp_salt($s='auth'){return str_repeat($s.'-certloom-',8);} function wp_tempnam($n=''){return tempnam(sys_get_temp_dir(),'cl-gd-');}
$GLOBALS['opts']=[];$GLOBALS['trans']=[];$GLOBALS['fail_option_once']='';
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['opts'])?$GLOBALS['opts'][$k]:$d;} function update_option($k,$v,$a=null){if($GLOBALS['fail_option_once']===$k){$GLOBALS['fail_option_once']='';return false;}$GLOBALS['opts'][$k]=$v;return true;} function delete_option($k){unset($GLOBALS['opts'][$k]);return true;} function set_transient($k,$v,$ttl){$GLOBALS['trans'][$k]=$v;return true;} function get_transient($k){return $GLOBALS['trans'][$k]??false;} function delete_transient($k){unset($GLOBALS['trans'][$k]);return true;} function apply_filters($tag,$v){if('certloom_private_backup_directory'===$tag)return $GLOBALS['private_dir'];if('certloom_allow_private_backup_directory_inside_webroot'===$tag)return false;return $v;}
$GLOBALS['private_dir']=$tmp.'/private';
require_once __DIR__ . '/bootstrap-autoload.php';
$GLOBALS['token_response']=['access_token'=>'access-one','refresh_token'=>'refresh-one','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/drive.file'];
function wp_safe_remote_post($url,$args=[]){return ['response'=>['code'=>200],'body'=>json_encode($GLOBALS['token_response']),'headers'=>[]];} function wp_remote_retrieve_response_code($r){return (int)($r['response']['code']??0);} function wp_remote_retrieve_body($r){return (string)($r['body']??'');} function wp_remote_retrieve_header($r,$h){return (string)($r['headers'][strtolower($h)]??$r['headers'][$h]??'');}

require $root.'/src/Application/Contract/BackupDestinationInterface.php';
require $root.'/src/Infrastructure/Backup/SecretVault.php';
require $root.'/src/Infrastructure/Backup/GoogleDriveOAuth.php';
require $root.'/src/Infrastructure/Backup/GoogleDriveClient.php';
require $root.'/src/Infrastructure/Backup/GoogleDriveDestination.php';
require $root.'/src/Infrastructure/Backup/RemoteBackupCatalog.php';

use Certloom\Application\Contract\BackupDestinationInterface;
use Certloom\Infrastructure\Backup\SecretVault;
use Certloom\Infrastructure\Backup\GoogleDriveOAuth;
use Certloom\Infrastructure\Backup\GoogleDriveClient;
use Certloom\Infrastructure\Backup\GoogleDriveDestination;
use Certloom\Infrastructure\Backup\RemoteBackupCatalog;

$c=0;$f=0;function ck($v,$m){global $c,$f;$c++;if($v)echo"PASS  $m\n";else{$f++;echo"FAIL  $m\n";}}

$sealed=SecretVault::seal(['client_secret'=>'super-secret','refresh_token'=>'refresh-token']);
ck(is_string($sealed)&&false===strpos($sealed,'super-secret')&&false===strpos($sealed,'refresh-token'),'provider secrets are authenticated-encrypted rather than stored as plaintext');
$opened=SecretVault::open($sealed); ck(is_array($opened)&&'super-secret'===$opened['client_secret']&&'refresh-token'===$opened['refresh_token'],'encrypted provider secrets round-trip on the same WordPress salt context');
$tampered=substr($sealed,0,-2).'xx'; ck(is_wp_error(SecretVault::open($tampered)),'tampered encrypted provider secret fails closed');

$saved=GoogleDriveOAuth::save_client_credentials('1234567890-example.apps.googleusercontent.com','client-secret-value');
ck(true===$saved&&false===strpos((string)get_option(GoogleDriveOAuth::SECRETS_OPTION,''),'client-secret-value'),'Google OAuth client secret is sealed in WordPress options');
$auth=GoogleDriveOAuth::authorization_url(); ck(is_string($auth)&&false!==strpos($auth,rawurlencode(GoogleDriveOAuth::SCOPE))&&false!==strpos($auth,'access_type=offline')&&false!==strpos($auth,'prompt=consent'),'OAuth request uses least-privilege Drive file scope with offline consent');
parse_str(parse_url($auth,PHP_URL_QUERY),$q); $ok=GoogleDriveOAuth::complete_authorization($q['state']??'','auth-code');
ck(true===$ok&&GoogleDriveOAuth::is_connected(),'OAuth callback accepts one matching state and stores offline connection');
ck(is_wp_error(GoogleDriveOAuth::complete_authorization($q['state']??'','replay')),'OAuth state is one-time and replay is refused');
$changed=GoogleDriveOAuth::save_client_credentials('9999999999-other.apps.googleusercontent.com','different-secret');ck(true===$changed&&!GoogleDriveOAuth::is_connected()&&empty(GoogleDriveOAuth::config()['enabled']),'changing OAuth client credentials invalidates prior refresh authority and requires reconnect');
$before_sealed=get_option(GoogleDriveOAuth::SECRETS_OPTION,'');$before_config=get_option(GoogleDriveOAuth::CONFIG_OPTION,[]);$GLOBALS['fail_option_once']=GoogleDriveOAuth::CONFIG_OPTION;$tx=GoogleDriveOAuth::save_client_credentials('7777777777-third.apps.googleusercontent.com','third-secret');ck(is_wp_error($tx)&&$before_sealed===get_option(GoogleDriveOAuth::SECRETS_OPTION,'')&&$before_config===get_option(GoogleDriveOAuth::CONFIG_OPTION,[]),'OAuth credential update rolls back the exact encrypted secret when paired config persistence fails');
$auth2=GoogleDriveOAuth::authorization_url();parse_str(parse_url($auth2,PHP_URL_QUERY),$q2);$before_sealed=get_option(GoogleDriveOAuth::SECRETS_OPTION,'');$before_config=get_option(GoogleDriveOAuth::CONFIG_OPTION,[]);$GLOBALS['token_response']=['access_token'=>'access-two','refresh_token'=>'refresh-two','expires_in'=>3600,'scope'=>GoogleDriveOAuth::SCOPE];$GLOBALS['fail_option_once']=GoogleDriveOAuth::CONFIG_OPTION;$tx2=GoogleDriveOAuth::complete_authorization($q2['state']??'','auth-code-two');ck(is_wp_error($tx2)&&$before_sealed===get_option(GoogleDriveOAuth::SECRETS_OPTION,'')&&$before_config===get_option(GoogleDriveOAuth::CONFIG_OPTION,[])&&!GoogleDriveOAuth::is_connected(),'OAuth completion does not retain a new refresh token when local connection-state persistence fails');

class FakeGoogleClient extends GoogleDriveClient {
 public $folder='folder_123'; public $files=[]; public $deleted=[]; public $bad=false; public $downloads=[];
 public function generate_file_id(){return 'file_ABC123';}
 public function create_folder($name,array $props){$this->files[$this->folder]=['id'=>$this->folder,'name'=>$name,'mimeType'=>'application/vnd.google-apps.folder','appProperties'=>$props,'trashed'=>false];return $this->files[$this->folder];}
 public function get_file($id){return $this->files[$id]??new WP_Error('missing','missing');}
 public function delete_file($id){$this->deleted[]=$id;unset($this->files[$id]);return true;}
 public function upload_file($path,$id,$name,$folder,array $props){$bytes=file_get_contents($path);$m=['id'=>$id,'name'=>$name,'mimeType'=>'application/x-certloom-backup','size'=>strlen($bytes),'md5Checksum'=>md5($bytes),'appProperties'=>$props,'parents'=>[$folder],'trashed'=>false];if($this->bad)$m['md5Checksum']=str_repeat('0',32);$this->files[$id]=$m;$this->downloads[$id]=$bytes;return $m;}
 public function download_file($id,$target){if(!isset($this->downloads[$id]))return new WP_Error('missing','missing');file_put_contents($target,$this->downloads[$id]);return true;}
}
$path=$tmp.'/backup.cvbackup';file_put_contents($path,"{\"type\":\"manifest\"}\n{\"type\":\"integrity\"}\n");
$local=['id'=>str_repeat('a',32),'path'=>$path,'purpose'=>'manual','created_ts'=>1000,'created_at'=>'2026-08-29T00:00:00Z','bytes'=>filesize($path),'records'=>2,'file_sha256'=>hash_file('sha256',$path),'content_sha256'=>str_repeat('b',64),'format_version'=>4,'plugin_version'=>'1.6.89','schema_version'=>'1.4.7'];
$fake=new FakeGoogleClient();$dest=new GoogleDriveDestination($fake);$local['remote_file_id']='file_ABC123';$up=$dest->upload($local);
ck(is_array($up)&&'verified'===$up['integrity']&&hash_equals($local['file_sha256'],$up['file_sha256']),'Drive destination verifies exact uploaded size, MD5, SHA metadata and identity');
$download=$dest->download($up);ck(is_string($download)&&hash_equals($local['file_sha256'],hash_file('sha256',$download)),'Drive download re-verifies exact backup bytes before returning a private temporary file');@unlink($download);
$fake2=new FakeGoogleClient();$fake2->bad=true;$badDest=new GoogleDriveDestination($fake2);$local['remote_file_id']='file_BAD123';$badup=$badDest->upload($local);ck(is_wp_error($badup)&&in_array('file_BAD123',$fake2->deleted,true),'post-upload checksum mismatch fails closed and removes the bad remote object');

$catalog=new RemoteBackupCatalog();$reserve=$catalog->reserve($local,'file_RETRY1');ck(is_array($reserve)&&'pending'===$reserve['status']&&'file_RETRY1'===$reserve['remote_file_id'],'remote catalog persists provider file ID before upload');
$failed=$catalog->mark_failed($reserve['id'],'network uncertainty');$retry=$catalog->find_exact_local($local['id'],$local['file_sha256']);ck('failed'===$failed['status']&&'file_RETRY1'===$retry['remote_file_id'],'failed upload keeps exact provider file ID for idempotent retry');
$verified=$catalog->mark_verified($reserve['id'],['remote_file_id'=>'file_RETRY1','name'=>'backup.cvbackup','md5_checksum'=>md5_file($path),'folder_id'=>'folder_123','uploaded_ts'=>2000,'uploaded_at'=>'2026-08-29T00:01:00Z']);ck('verified'===$verified['status']&&2===$verified['attempts']+0,'verified remote catalog entry preserves bounded retry-attempt evidence');
$json=json_encode($catalog->all());ck(false===strpos($json,$tmp)&&false===strpos($json,'client-secret')&&false===strpos($json,'refresh-token'),'remote catalog contains neither private filesystem paths nor OAuth secrets');

class FakeDestination implements BackupDestinationInterface { public $deleted=[]; public function provider(){return 'fake';} public function test_connection(){return true;} public function upload(array $e){return $e;} public function download(array $e){return '';} public function delete(array $e){$this->deleted[]=$e['id'];return true;} }
// Add three scheduled verified records plus one manual; retention must prune scheduled only.
$GLOBALS['opts'][RemoteBackupCatalog::OPTION]=[];$cat=new RemoteBackupCatalog();
for($i=0;$i<4;$i++){ $l=$local;$l['id']=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);$l['purpose']=$i===3?'manual':'scheduled';$l['file_sha256']=hash('sha256','x'.$i);$r=$cat->reserve($l,'remote_'.$i);$cat->mark_verified($r['id'],['remote_file_id'=>'remote_'.$i,'name'=>'x.cvbackup','md5_checksum'=>str_repeat(dechex($i+1),32),'folder_id'=>'folder_123']); usleep(1000); }
$fd=new FakeDestination();$pr=$cat->prune_scheduled(2,$fd);$all=$cat->all();ck(1===$pr&&1===count($fd->deleted)&&1===count(array_filter($all,fn($e)=>'manual'===$e['purpose']))&&2===count(array_filter($all,fn($e)=>'scheduled'===$e['purpose'])),'remote retention deletes only expired scheduled copies and preserves explicit manual copies');

$src=file_get_contents($root.'/src/Infrastructure/Backup/ScheduledBackup.php');$svc=file_get_contents($root.'/src/Infrastructure/Backup/GoogleDriveBackup.php');$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');$view=file_get_contents($root.'/admin/views/import-upload.php');
ck(false!==strpos($src,"'scheduled' === \$purpose")&&false!==strpos($src,'GoogleDriveBackup::queue_scheduled_local')&&false!==strpos($src,'Google never extends the local backup run lock'),'scheduled Drive copy is downstream of verified local backup and explicitly non-authoritative');
ck(false!==strpos($svc,"'safety' ===")&&false!==strpos($svc,'Safety snapshots stay in private local storage'),'safety snapshots intentionally remain local and independent of Google availability');
ck(false!==strpos($svc,'Ecapla_CertVerify_Backup::analyze_file')&&false!==strpos($svc,'$store->store'),'Drive recovery passes downloaded bytes through the real analyzer before local storage');
ck(false!==strpos($admin,'certloom_google_drive_oauth_callback')&&false!==strpos($admin,'certloom_google_drive_download_local')&&false!==strpos($view,'Test Google Drive')&&false!==strpos($view,'Copy to Google Drive'),'Backup Center exposes bounded connect/test/copy/recover controls without changing navigation hierarchy');
ck(false===strpos($src,'CERTLOOM_DOMAIN_WRITE_PATHS_READY = true')&&false===strpos($svc,'accepted.json'),'BAK-03 does not open normalized-domain or renderer production authority');

function rr($d){if(!is_dir($d))return;foreach(scandir($d) as $n){if('.'===$n||'..'===$n)continue;$p="$d/$n";if(is_dir($p)&&!is_link($p))rr($p);else@unlink($p);}@rmdir($d);} rr($tmp);
echo "\n$c checks, $f failures\n";exit($f?1:0);
