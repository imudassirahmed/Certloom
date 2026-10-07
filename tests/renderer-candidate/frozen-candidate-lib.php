<?php
/** Source-only validator for deterministic renderer-candidate freeze directories. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RendererCandidateManifest;

final class CertloomFrozenCandidateEvidence {
    private $root; private $canonical; private $contract;
    public function __construct($source_root){$real=realpath((string)$source_root);if(false===$real||!is_file($real.'/certloom.php'))throw new InvalidArgumentException('Certloom source root is invalid.');$this->root=$real;$this->canonical=new CanonicalJson();$this->contract=new RendererCandidateManifest();}
    public function validate($directory){
        if(!is_string($directory)||''===trim($directory)||false!==strpos($directory,"\0")||is_link($directory))throw new InvalidArgumentException('Candidate freeze directory is invalid.');
        $dir=realpath($directory);if(false===$dir||!is_dir($dir))throw new InvalidArgumentException('Candidate freeze directory is unavailable.');
        $index=$this->json($dir.'/freeze-index.json','candidate freeze index');$allowed=array('schema','version','plugin_version','renderer_id','renderer_version','recipe_sha256','candidate_manifest_sha256','source_archive_sha256','dependency_lock_sha256','dependency_inventory_sha256','source_file_count','runtime_tree_sha256');
        if(array_diff(array_keys($index),$allowed)||array_diff($allowed,array_keys($index))||'certloom-renderer-candidate-freeze-index'!==($index['schema']??'')||1!==($index['version']??null))throw new InvalidArgumentException('Candidate freeze index schema/version/shape is invalid.');
        if(!hash_equals($this->plugin_version(),(string)$index['plugin_version']))throw new InvalidArgumentException('Candidate freeze belongs to a different Certloom version.');
        $manifest_path=$this->file($dir.'/candidate.json','candidate manifest');$source=$this->file($dir.'/candidate-source.zip','candidate source archive');$lock=$this->file($dir.'/dependency.lock','candidate dependency lock');$recipe=$this->file($dir.'/recipe.json','candidate freeze recipe');
        $manifest=$this->contract->normalize($this->json($manifest_path,'candidate manifest'));$manifest_hash=$this->contract->hash($manifest);
        if(!hash_equals((string)$index['candidate_manifest_sha256'],$manifest_hash)||!hash_equals($manifest['implementation']['source_sha256'],hash_file('sha256',$source))||!hash_equals($manifest['implementation']['dependency_lock_sha256'],hash_file('sha256',$lock)))throw new InvalidArgumentException('Candidate freeze manifest/source/lock hashes are inconsistent.');
        if(!hash_equals((string)$index['source_archive_sha256'],hash_file('sha256',$source))||!hash_equals((string)$index['dependency_lock_sha256'],hash_file('sha256',$lock))||!hash_equals((string)$index['recipe_sha256'],$this->canonical->hash($this->json($recipe,'candidate freeze recipe'))))throw new InvalidArgumentException('Candidate freeze index provenance hashes are inconsistent.');
        if(!hash_equals((string)$index['renderer_id'],(string)$manifest['renderer_id'])||!hash_equals((string)$index['renderer_version'],(string)$manifest['renderer_version']))throw new InvalidArgumentException('Candidate freeze identity is inconsistent.');
        $runtime=$dir.'/runtime';if(is_link($runtime)||!is_dir($runtime))throw new InvalidArgumentException('Candidate frozen runtime tree is unavailable.');$rows=array();$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime,FilesystemIterator::SKIP_DOTS));foreach($iterator as $item){$path=$item->getPathname();if($item->isLink())throw new InvalidArgumentException('Candidate frozen runtime tree may not contain symbolic files.');if(!$item->isFile())continue;$rel=str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($runtime)+1));$rows[]=array('path'=>$rel,'sha256'=>hash_file('sha256',$path),'size'=>$item->getSize());}usort($rows,function($a,$b){return strcmp($a['path'],$b['path']);});
        if((int)$index['source_file_count']!==count($rows)||!hash_equals((string)$index['runtime_tree_sha256'],$this->canonical->hash($rows)))throw new InvalidArgumentException('Candidate frozen runtime tree drifted from the freeze index.');
        return array('manifest'=>$manifest,'manifest_path'=>$manifest_path,'source_archive'=>$source,'dependency_lock'=>$lock,'runtime_root'=>$runtime,'freeze_index'=>$index);
    }
    private function plugin_version(){$bytes=file_get_contents($this->root.'/certloom.php');if(!is_string($bytes)||1!==preg_match('/^\s*\*\s*Version:\s*([^\s]+)/m',$bytes,$m))throw new RuntimeException('Certloom plugin version is unavailable.');return trim($m[1]);}
    private function file($path,$label){if(is_link($path))throw new InvalidArgumentException($label.' may not be symbolic.');$real=realpath($path);if(false===$real||!is_file($real)||!is_readable($real))throw new InvalidArgumentException($label.' is unavailable.');return $real;}
    private function json($path,$label){$real=$this->file($path,$label);$bytes=file_get_contents($real);$data=is_string($bytes)?json_decode($bytes,true):null;if(!is_array($data))throw new InvalidArgumentException($label.' is invalid JSON.');return $data;}
}
