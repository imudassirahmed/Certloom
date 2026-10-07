<?php
/** Source-only validator/exporter for packaging reviewed normalized-domain authority. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Migration\DomainAcceptanceImporter;
use Certloom\Application\Migration\DomainReleaseManifest;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;

final class CertloomDomainAuthorizedRelease {
	const INDEX_SCHEMA = 'certloom-domain-authority-finalization';
	const INDEX_VERSION = 1;
	const MAX_JSON_BYTES = 16777216;

	private $root;
	private $canonical;
	private $importer;
	private $manifest_contract;
	private $payloads;

	public function __construct( $source_root ) {
		$this->assert_non_symbolic_components( (string) $source_root, 'Certloom source root' );
		$root = realpath( (string) $source_root );
		if ( false === $root || ! is_dir($root) || ! is_readable($root) || ! is_file($root.'/certloom.php') ) throw new InvalidArgumentException('Certloom source root is invalid for normalized-domain authority packaging.');
		$this->root=rtrim($root,'/\\');$this->canonical=new CanonicalJson();$this->importer=new DomainAcceptanceImporter();$this->manifest_contract=new DomainReleaseManifest();$this->payloads=new RuntimePayloadFingerprint();
	}

	/** @return array<string,mixed> */
	public function validate_authority_directory( $authority_dir ) {
		$dir=$this->resolve_external_directory($authority_dir);
		$expected=array('acceptance-index.json','accepted.json','authority-index.json','normalized-domain-acceptance.json');
		$actual=array();foreach(scandir($dir)?:array() as $name){if('.'===$name||'..'===$name)continue;$path=$dir.DIRECTORY_SEPARATOR.$name;if(is_link($path)||!is_file($path))throw new InvalidArgumentException('Normalized-domain authority directory may contain only canonical regular JSON evidence files.');$actual[]=$name;}sort($actual,SORT_STRING);if($actual!==$expected)throw new InvalidArgumentException('Normalized-domain authority directory file set is incomplete or contains unexpected entries.');
		$bundle=$this->decode_json_file($dir.'/normalized-domain-acceptance.json','normalized-domain acceptance bundle');
		$acceptance_index=$this->decode_json_file($dir.'/acceptance-index.json','normalized-domain acceptance index');
		$manifest=$this->manifest_contract->normalize($this->decode_json_file($dir.'/accepted.json','accepted normalized-domain release manifest'));
		$authority_index=$this->normalize_authority_index($this->decode_json_file($dir.'/authority-index.json','normalized-domain authority index'));
		$imported=$this->importer->import($bundle,$acceptance_index);$runtime=$this->payloads->fingerprint($this->root);$version=$this->plugin_version();$release_hash=$this->manifest_contract->hash($manifest);
		if($manifest['plugin_version']!==$version||$imported['bundle']['plugin_version']!==$version||$authority_index['plugin_version']!==$version)throw new InvalidArgumentException('Normalized-domain authority does not target this exact Certloom source version.');
		if($manifest['runtime_payload']!==$runtime||$imported['bundle']['runtime_payload']!==$runtime||$authority_index['runtime_payload']!==$runtime)throw new InvalidArgumentException('Normalized-domain authority does not target these exact Certloom production bytes.');
		if(!hash_equals($release_hash,$authority_index['release_manifest_sha256']))throw new InvalidArgumentException('Normalized-domain authority index does not bind the exact accepted.json release manifest.');
		if(!hash_equals($imported['acceptance_sha256'],$authority_index['acceptance_sha256'])||!hash_equals($imported['acceptance_sha256'],$manifest['acceptance']['acceptance_sha256']))throw new InvalidArgumentException('Normalized-domain authority does not bind the exact acceptance bundle.');
		if(!hash_equals($imported['index_sha256'],$authority_index['acceptance_index_sha256'])||!hash_equals($imported['index_sha256'],$manifest['acceptance']['acceptance_index_sha256']))throw new InvalidArgumentException('Normalized-domain authority does not bind the exact acceptance index.');
		foreach(array('environment_sha256','domain_state_sha256') as $key){if(!hash_equals($imported['bundle'][$key],$authority_index[$key])||!hash_equals($imported['bundle'][$key],$manifest['acceptance'][$key]))throw new InvalidArgumentException('Normalized-domain authority does not bind the exact '.$key.'.');}
		return array('authority_dir'=>$dir,'plugin_version'=>$version,'runtime_payload'=>$runtime,'release_manifest'=>$manifest,'release_manifest_sha256'=>$release_hash,'accepted_json_bytes'=>$this->canonical->encode($manifest)."\n");
	}

	/** @return array<string,mixed> */
	public function validate_manifest_file( $manifest_path ) {
		$manifest=$this->manifest_contract->normalize($this->decode_json_file($manifest_path,'accepted normalized-domain release manifest'));$runtime=$this->payloads->fingerprint($this->root);$version=$this->plugin_version();
		if($manifest['plugin_version']!==$version)throw new InvalidArgumentException('Bundled normalized-domain authority belongs to a different Certloom version.');
		if($manifest['runtime_payload']!==$runtime)throw new InvalidArgumentException('Bundled normalized-domain authority belongs to different Certloom production bytes.');
		return array('plugin_version'=>$version,'runtime_payload'=>$runtime,'release_manifest'=>$manifest,'release_manifest_sha256'=>$this->manifest_contract->hash($manifest),'accepted_json_bytes'=>$this->canonical->encode($manifest)."\n");
	}

	/** @return array<string,mixed> */
	private function normalize_authority_index(array $value){$keys=array('schema','version','plugin_version','runtime_payload','acceptance_sha256','acceptance_index_sha256','release_manifest_sha256','environment_sha256','domain_state_sha256');$a=array_keys($value);sort($a,SORT_STRING);$b=$keys;sort($b,SORT_STRING);if($a!==$b||self::INDEX_SCHEMA!==(string)$value['schema']||self::INDEX_VERSION!==(int)$value['version'])throw new InvalidArgumentException('Normalized-domain authority index schema/version/shape is unsupported.');$version=trim((string)$value['plugin_version']);if(!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/',$version))throw new InvalidArgumentException('Normalized-domain authority index version is invalid.');$out=array('schema'=>self::INDEX_SCHEMA,'version'=>self::INDEX_VERSION,'plugin_version'=>$version,'runtime_payload'=>$this->payloads->normalize($value['runtime_payload']));foreach(array('acceptance_sha256','acceptance_index_sha256','release_manifest_sha256','environment_sha256','domain_state_sha256') as $key){$hash=strtolower(trim((string)$value[$key]));if(1!==preg_match('/^[a-f0-9]{64}$/',$hash))throw new InvalidArgumentException('Normalized-domain authority index hash is invalid.');$out[$key]=$hash;}return $out;}
	private function plugin_version(){ $bytes=file_get_contents($this->root.'/certloom.php');if(!is_string($bytes)||1!==preg_match("/define\\(\\s*'ECAPLA_CERTVERIFY_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/",$bytes,$m))throw new RuntimeException('Could not determine Certloom source version.');return trim($m[1]); }
	private function decode_json_file($path,$label){$this->assert_non_symbolic_components($path,$label);if(!is_file($path)||!is_readable($path)||is_link($path))throw new InvalidArgumentException(ucfirst($label).' is missing or unsafe.');$size=filesize($path);if(false===$size||$size<2||$size>self::MAX_JSON_BYTES)throw new InvalidArgumentException(ucfirst($label).' size is invalid.');$bytes=file_get_contents($path);if(!is_string($bytes)||strlen($bytes)!==$size)throw new RuntimeException(ucfirst($label).' could not be read exactly.');$value=json_decode($bytes,true);if(!is_array($value)||JSON_ERROR_NONE!==json_last_error())throw new InvalidArgumentException(ucfirst($label).' JSON is invalid.');return $value;}
	private function resolve_external_directory($path){$this->assert_non_symbolic_components((string)$path,'Normalized-domain authority directory');$real=realpath((string)$path);if(false===$real||!is_dir($real)||!is_readable($real)||is_link($real))throw new InvalidArgumentException('Normalized-domain authority directory is invalid or unreadable.');$prefix=$this->root.DIRECTORY_SEPARATOR;if($real===$this->root||0===strpos($real,$prefix))throw new InvalidArgumentException('Normalized-domain authority directory must remain outside the Certloom source tree.');return rtrim($real,'/\\');}
	private function assert_non_symbolic_components($path,$label){$path=(string)$path;if(''===$path)return;$current='';$absolute=0===strpos($path,DIRECTORY_SEPARATOR);foreach(preg_split('~[\\\\/]+~',$path,-1,PREG_SPLIT_NO_EMPTY) as $part){$current=($absolute&&''===$current?DIRECTORY_SEPARATOR:'').$current.(''!==$current&&substr($current,-1)!==DIRECTORY_SEPARATOR?DIRECTORY_SEPARATOR:'').$part;if(file_exists($current)&&is_link($current))throw new InvalidArgumentException($label.' may not traverse symbolic links.');}}
}
