<?php
/** Behavioral/static acceptance for ID-01 dependency-safe human-number strategies. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function absint( $value ) { return abs( (int) $value ); }
$GLOBALS['id_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['id_options'] ) ? $GLOBALS['id_options'][ $key ] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = true ) { if ( array_key_exists( $key, $GLOBALS['id_options'] ) ) { return false; } $GLOBALS['id_options'][ $key ] = $value; return true; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['id_options'][ $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
class IdFakeWpdb { public $options='wp_options'; public $last_error=''; public function prepare($q,...$args){return $q;} public function query($q){return false;} public function get_var($q){return null;} }
$GLOBALS['wpdb'] = new IdFakeWpdb();
class Ecapla_CertVerify_Settings { public static $all=array(); public static function get_all(){return self::$all;} }
class Ecapla_CertVerify_Backup { public static function assert_writes_allowed(){return true;} public static function reserve_mutation_generation(){return true;} }
class Ecapla_CertVerify_Validator { public static function certificate_data_for_generated_number($data){return $data;} }
class Ecapla_CertVerify_Database {
	public static $exists_calls=0;
	public static $force_first_collision=false;
	public static $inserted=array();
	public static function certificate_number_exists($number){ self::$exists_calls++; if(self::$force_first_collision && 1===self::$exists_calls){return true;} return isset(self::$inserted[$number]); }
	public static function insert($payload){ self::$inserted[$payload['cert_number']]=$payload; return count(self::$inserted); }
}
function icheck($condition,$message){global $ic;if(!$condition){throw new RuntimeException($message);} $ic++; echo "PASS  {$message}\n";}
$ic=0;
require dirname(__DIR__).'/includes/class-ecapla-certverify-numbering.php';

$base=Ecapla_CertVerify_Numbering::defaults();
icheck('manual'===$base['numbering_mode'],'upgrade default stays manual');
foreach(array('manual','sequential','random','uuid') as $mode){$p=Ecapla_CertVerify_Numbering::normalize_policy(array_merge($base,array('numbering_mode'=>$mode)));icheck(!is_wp_error($p)&&$p['numbering_mode']===$mode,"{$mode} strategy is accepted");}
icheck(is_wp_error(Ecapla_CertVerify_Numbering::normalize_policy(array_merge($base,array('numbering_mode'=>'hashids')))),'unknown numbering strategy fails closed');

$random_policy=array_merge($base,array('numbering_mode'=>'random','numbering_prefix'=>'CERT-','numbering_suffix'=>'-R'));
Ecapla_CertVerify_Settings::$all=$random_policy;
$GLOBALS['id_options'][Ecapla_CertVerify_Numbering::STATE_OPTION]='777';
Ecapla_CertVerify_Database::$exists_calls=0;Ecapla_CertVerify_Database::$force_first_collision=false;
$rp=Ecapla_CertVerify_Numbering::preview();
icheck(!is_wp_error($rp)&&$rp['mode']==='random'&&!empty($rp['sample']),'Random mode produces a non-reserving sample preview');
icheck((bool)preg_match('/^CERT-(?:[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{4}-){4}[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{4}-R$/',$rp['certificate_number']),'Random strategy is 20 Crockford characters grouped 4x5 with affixes');
icheck('777'===$GLOBALS['id_options'][Ecapla_CertVerify_Numbering::STATE_OPTION],'Random preview does not touch sequential state');

Ecapla_CertVerify_Database::$exists_calls=0;Ecapla_CertVerify_Database::$force_first_collision=true;Ecapla_CertVerify_Database::$inserted=array();
$ri=Ecapla_CertVerify_Numbering::insert_generated(array('holder_name'=>'Ada'));
icheck(!is_wp_error($ri)&&$ri['mode']==='random','Random strategy creates a credential through the shared generated-number insert path');
icheck(Ecapla_CertVerify_Database::$exists_calls>=2,'Random strategy retries when a generated human number collides');
icheck('777'===$GLOBALS['id_options'][Ecapla_CertVerify_Numbering::STATE_OPTION],'Random allocation is stateless and preserves sequential cursor');

$uuid_policy=array_merge($base,array('numbering_mode'=>'uuid','numbering_prefix'=>'ID-','numbering_suffix'=>'-U'));
Ecapla_CertVerify_Settings::$all=$uuid_policy;
Ecapla_CertVerify_Database::$exists_calls=0;Ecapla_CertVerify_Database::$force_first_collision=false;Ecapla_CertVerify_Database::$inserted=array();
$up=Ecapla_CertVerify_Numbering::preview();
icheck(!is_wp_error($up)&&$up['mode']==='uuid'&&!empty($up['sample']),'UUID mode produces a non-reserving sample preview');
icheck((bool)preg_match('/^ID-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-U$/',$up['certificate_number']),'UUID strategy emits RFC-4122 version-4 variant with affixes');
$ui=Ecapla_CertVerify_Numbering::insert_generated(array('holder_name'=>'Grace'));
icheck(!is_wp_error($ui)&&$ui['mode']==='uuid','UUID strategy creates a credential through shared insertion boundary');
icheck('777'===$GLOBALS['id_options'][Ecapla_CertVerify_Numbering::STATE_OPTION],'UUID allocation is stateless and preserves sequential cursor');

$manual_policy=array_merge($base,array('numbering_mode'=>'manual'));
Ecapla_CertVerify_Settings::$all=$manual_policy;
icheck(!Ecapla_CertVerify_Numbering::is_enabled(),'Manual policy disables generated-number insertion');
icheck(is_wp_error(Ecapla_CertVerify_Numbering::preview()),'Manual policy has no generated preview');

$root=dirname(__DIR__);
$numbering=file_get_contents($root.'/includes/class-ecapla-certverify-numbering.php');
$settings=file_get_contents($root.'/admin/views/settings-manager.php');
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$public_id=file_get_contents($root.'/src/Domain/Credential/PublicCredentialId.php');
$rest=file_get_contents($root.'/includes/class-ecapla-certverify-rest.php');
$backup=file_get_contents($root.'/includes/class-ecapla-certverify-backup.php');
icheck(false!==strpos($numbering,"RANDOM_DATA_CHARS = 20")&&false!==strpos($numbering,"random_int"),'Random human-number strategy fixes at least 100 bits of local cryptographic entropy');
icheck(false!==strpos($numbering,'random_bytes( 16 )')&&false!==strpos($numbering,'0x40')&&false!==strpos($numbering,'0x80'),'UUID strategy sets v4 version/variant bits from local cryptographic randomness');
icheck(false!==strpos($settings,'Random non-guessable when blank')&&false!==strpos($settings,'UUID v4 when blank'),'Settings exposes only the reviewed generated human-number strategies');
icheck(false!==strpos($admin,'Ecapla_CertVerify_Numbering::insert_generated')&&false!==strpos($admin,"array_key_exists( 'cert_number', \$data )"),'all generated strategies use the existing blank-new-number insertion boundary');
icheck(false===strpos($numbering,'Ecapla_CertVerify_Database::update'),'ID strategy service cannot renumber an issued credential');
icheck(false===strpos($numbering,'public_id')&&false===strpos($numbering,'PublicCredentialId'),'human-number strategy service does not create or replace normalized public credential IDs');
icheck(false!==strpos($public_id,'DATA_LENGTH')&&false!==strpos($public_id,'CHECKSUM_VERSION = 2')&&false!==strpos($public_id,'checksum_character_v2'),'normalized-domain public credential ID remains a separate versioned checksummed identity type');
icheck(false!==strpos($rest,'verify_public_id'),'normalized public-ID verification path remains separate from legacy certificate-number verification');
icheck(false===strpos($numbering,'program_id')&&false===strpos($numbering,'template_id'),'Program/Template scoped ID strategies remain dependency-gated');
icheck(false!==strpos($backup,'Ecapla_CertVerify_Numbering::normalize_policy( $settings )'),'complete backup validates and normalizes numbering strategy before writing manifest');
icheck(false!==strpos($backup,"'ecapla_certverify_backup_numbering_policy'"),'restore preflight rejects unsupported/tampered numbering strategies before commit');

echo "\n{$ic} checks, 0 failures\n";
