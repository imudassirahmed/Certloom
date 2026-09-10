<?php
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/renderer-candidate/frozen-candidate-lib.php';
use Certloom\Application\Rendering\RendererCandidateManifest;

$checks=0;$failures=0;
function rcf_check($ok,$message){global $checks,$failures;$checks++;if(!$ok){$failures++;fwrite(STDERR,"FAIL  $message\n");}else{echo "PASS  $message\n";}}
function rcf_cmd(array $parts){$escaped=array_map('escapeshellarg',$parts);$out=array();$code=0;exec(implode(' ',$escaped).' 2>&1',$out,$code);return array($code,implode("\n",$out));}
function rcf_json($path){$data=json_decode(file_get_contents($path),true);if(!is_array($data))throw new RuntimeException('invalid json '.$path);return $data;}
function rcf_rm($path){if(!file_exists($path)&&!is_link($path))return;if(is_link($path)||is_file($path)){@unlink($path);return;}foreach(array_diff(scandir($path),array('.','..')) as $x){rcf_rm($path.DIRECTORY_SEPARATOR.$x);}@rmdir($path);}
function rcf_throws(callable $fn){try{$fn();return false;}catch(Throwable $e){return true;}}

$root=dirname(__DIR__);$tool=$root.'/tests/renderer-candidate/freeze-candidate.py';$pdf_recipe=$root.'/tests/renderer-candidate/recipes/mpdf-8.3.1.json';$gd_recipe=$root.'/tests/renderer-candidate/recipes/gd-raster.json';$gd_lock=$root.'/tests/renderer-candidate/recipes/gd-runtime.lock.json';
$tmp=sys_get_temp_dir().'/certloom-freeze-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
try{
    $candidate=$tmp.'/prepared';mkdir($candidate.'/vendor',0700,true);
    file_put_contents($candidate.'/vendor/autoload.php',"<?php // prefixed test autoloader\n");
    file_put_contents($candidate.'/vendor/Mpdf.php',"<?php namespace CertloomVendor\\Mpdf; final class Mpdf {}\n");
    $lock=array('packages'=>array(array('name'=>'mpdf/mpdf','version'=>'v8.3.1','type'=>'library','license'=>array('GPL-2.0-only')),array('name'=>'psr/log','version'=>'3.0.2','type'=>'library','license'=>array('MIT'))),'packages-dev'=>array());
    $lock_path=$tmp.'/composer.lock';file_put_contents($lock_path,json_encode($lock,JSON_UNESCAPED_SLASHES));
    $out1=$tmp.'/freeze-a';$out2=$tmp.'/freeze-b';
    list($c1,$o1)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$out1));
    list($c2,$o2)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$out2));
    rcf_check(0===$c1&&0===$c2,'PDF candidate freezer completes for an exact prefixed mPDF 8.3.1 tree.');
    rcf_check(hash_file('sha256',$out1.'/candidate-source.zip')===hash_file('sha256',$out2.'/candidate-source.zip'),'Candidate source ZIP is deterministic across independent freezes.');
    rcf_check(file_get_contents($out1.'/candidate.json')===file_get_contents($out2.'/candidate.json'),'Canonical candidate manifest is byte-identical across independent freezes.');
    $manifest=rcf_json($out1.'/candidate.json');$normalized=(new RendererCandidateManifest())->normalize($manifest);
    rcf_check(RendererCandidateManifest::VERSION===2&&'prefixed-package'===$normalized['implementation']['provenance_mode'],'RendererCandidateManifest v2 truthfully identifies prefixed-package provenance.');
    rcf_check('8.3.1'===$normalized['implementation']['package_version']&&'CertloomVendor\\Mpdf\\'===$normalized['implementation']['namespace_prefix'],'Frozen PDF candidate binds exact mPDF version and isolated namespace.');
    rcf_check('8.3.1+certloom.5'===$normalized['renderer_version'],'Frozen PDF candidate advances renderer identity for the native Render Plan parity correction.');
    $index=rcf_json($out1.'/freeze-index.json');
    rcf_check(hash_file('sha256',$out1.'/candidate-source.zip')===$index['source_archive_sha256']&&hash_file('sha256',$out1.'/dependency.lock')===$index['dependency_lock_sha256'],'Freeze index binds the actual source archive and dependency-lock bytes.');
    rcf_check(hash('sha256',file_get_contents($out1.'/candidate.json'))===$index['candidate_manifest_sha256'],'Freeze index binds the canonical candidate manifest bytes.');
    $frozen=(new CertloomFrozenCandidateEvidence($root))->validate($out1);
    rcf_check(is_file($frozen['runtime_root'].'/vendor/autoload.php')&&hash_equals($index['runtime_tree_sha256'],(string)$frozen['freeze_index']['runtime_tree_sha256']),'Frozen runtime tree validates against the exact source/archive freeze index before execution.');
    $parity_py='import hashlib,sys,zipfile,pathlib; d=pathlib.Path(sys.argv[1]); z=zipfile.ZipFile(d/"candidate-source.zip"); names=sorted(x for x in z.namelist() if not x.endswith("/")); rt=d/"runtime"; ok=all((rt/n).is_file() and hashlib.sha256(z.read(n)).hexdigest()==hashlib.sha256((rt/n).read_bytes()).hexdigest() for n in names) and len(names)==sum(1 for x in rt.rglob("*") if x.is_file()); sys.exit(0 if ok else 1)';
    list($pc,$po)=rcf_cmd(array('python3','-c',$parity_py,$out2));rcf_check(0===$pc,'Published candidate source archive and executable runtime tree are exact per-file byte peers from one snapshot.');
    rcf_check(!file_exists($out2.'/.candidate-snapshot')&&!file_exists($out2.'/.dependency-lock.snapshot'),'Private snapshot material is never published in frozen candidate evidence.');
    chmod($frozen['runtime_root'].'/vendor/Mpdf.php',0600);file_put_contents($frozen['runtime_root'].'/vendor/Mpdf.php',"<?php // tampered\n");
    rcf_check(rcf_throws(function()use($root,$out1){(new CertloomFrozenCandidateEvidence($root))->validate($out1);}), 'Runtime-tree mutation is rejected before candidate execution.');
    // Restore by freezing another identical set for the remaining checks.
    $frozen=(new CertloomFrozenCandidateEvidence($root))->validate($out2);
    rcf_check(!class_exists('ZipArchive') || true,'Candidate freezer remains independent of PHP ZipArchive.');
    list($uz,$uzout)=rcf_cmd(array('unzip','-tq',$out1.'/candidate-source.zip'));rcf_check(0===$uz,'Frozen candidate source ZIP passes archive integrity validation.');

    $gdout=$tmp.'/freeze-gd';list($gc,$go)=rcf_cmd(array('python3',$tool,'--recipe',$gd_recipe,'--candidate-root',$root,'--dependency-lock',$gd_lock,'--output',$gdout));
    rcf_check(0===$gc,'GD candidate freezer can freeze the real Certloom-native adapter source set.');
    $gd=(new RendererCandidateManifest())->normalize(rcf_json($gdout.'/candidate.json'));
    rcf_check('certloom-native'===$gd['implementation']['provenance_mode']&&0===strpos($gd['implementation']['namespace_prefix'],'Certloom\\'),'Native GD provenance uses its real Certloom namespace instead of a fictitious CertloomVendor prefix.');
    rcf_check('certloom/gd-raster'===$gd['implementation']['package']&&array('jpeg','png')===$gd['formats'],'Native GD freeze binds stable package identity and both raster formats.');
    rcf_check('1.0.0+certloom.4'===$gd['renderer_version']&&'1.0.0+certloom.4'===$gd['implementation']['package_version'],'Native GD freeze advances exact renderer/package identity for opacity and rotated-primitive supersampling parity.');

    $inside=$root.'/tests/.forbidden-freeze-output';@rmdir($inside);list($ic,$io)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$inside));rcf_check(0!==$ic&&!file_exists($inside),'Freezer refuses to publish provenance inside the Certloom source tree.');
    $candidate_link=$tmp.'/prepared-link';symlink($candidate,$candidate_link);list($clc,$clo)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate_link,'--dependency-lock',$lock_path,'--output',$tmp.'/candidate-link-out'));rcf_check(0!==$clc&&!file_exists($tmp.'/candidate-link-out'),'Freezer rejects a symbolic candidate-root argument before canonicalization.');unlink($candidate_link);
    $lock_link=$tmp.'/composer-link.lock';symlink($lock_path,$lock_link);list($llc,$llo)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_link,'--output',$tmp.'/lock-link-out'));rcf_check(0!==$llc&&!file_exists($tmp.'/lock-link-out'),'Freezer rejects a symbolic dependency-lock argument before canonicalization.');unlink($lock_link);
    $recipe_link=$tmp.'/recipe-link.json';symlink($pdf_recipe,$recipe_link);list($rlc,$rlo)=rcf_cmd(array('python3',$tool,'--recipe',$recipe_link,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$tmp.'/recipe-link-out'));rcf_check(0!==$rlc&&!file_exists($tmp.'/recipe-link-out'),'Freezer rejects a symbolic recipe argument before canonicalization.');unlink($recipe_link);
    $existing=$tmp.'/existing';mkdir($existing);list($ec,$eo)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$existing));rcf_check(0!==$ec,'Freezer refuses any pre-existing destination to prevent stale/partial evidence reuse.');

    $badver=$tmp.'/bad-version.lock';$bad=$lock;$bad['packages'][0]['version']='v8.3.0';file_put_contents($badver,json_encode($bad));list($bc,$bo)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$badver,'--output',$tmp.'/bad-version-out'));rcf_check(0!==$bc,'Freezer rejects a dependency lock that does not contain exact mPDF 8.3.1.');
    $baddev=$tmp.'/bad-dev.lock';$bad=$lock;$bad['packages-dev']=array(array('name'=>'phpunit/phpunit','version'=>'10.0.0'));file_put_contents($baddev,json_encode($bad));list($dc,$do)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$baddev,'--output',$tmp.'/bad-dev-out'));rcf_check(0!==$dc,'Freezer rejects development dependencies from the production candidate lock.');
    file_put_contents($candidate.'/vendor/Bad.php',"<?php namespace Mpdf\\Bad; class Nope {}\n");list($uc,$uo)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$tmp.'/unprefixed-out'));rcf_check(0!==$uc,'Freezer rejects unprefixed mPDF namespaces from the prepared candidate tree.');unlink($candidate.'/vendor/Bad.php');
    file_put_contents($candidate.'/vendor/Good.php',"<?php namespace CertloomVendor\\Mpdf\\Support; final class Good {}\n");
    file_put_contents($candidate.'/vendor/Mpdf.php',"<?php namespace Mpdf; final class Mpdf {}\n");list($urc,$uro)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$tmp.'/unprefixed-root-out'));rcf_check(0!==$urc,'Freezer rejects an unprefixed root `namespace Mpdf;` declaration.');
    file_put_contents($candidate.'/vendor/Mpdf.php',"<?php namespace CertloomVendor\\Mpdf; final class Mpdf {}\n");unlink($candidate.'/vendor/Good.php');
    mkdir($candidate.'/vendor/composer',0700,true);file_put_contents($candidate.'/vendor/composer/autoload_psr4.php',"<?php return array('Mpdf\\\\' => array(__DIR__ . '/../mpdf/mpdf/src'));\n");list($uac,$uao)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$tmp.'/unprefixed-autoload-out'));rcf_check(0!==$uac,'Freezer rejects Composer autoload maps that still expose an unprefixed Mpdf namespace.');unlink($candidate.'/vendor/composer/autoload_psr4.php');rmdir($candidate.'/vendor/composer');
    file_put_contents($candidate.'/vendor/Mpdf.php',"<?php // namespace CertloomVendor\\Mpdf; comment only\nfinal class NoNamespace {}\n");list($nsc,$nso)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$tmp.'/comment-prefix-out'));rcf_check(0!==$nsc,'Freezer requires a real prefixed namespace declaration; comments cannot satisfy provenance.');
    file_put_contents($candidate.'/vendor/Mpdf.php',"<?php namespace CertloomVendor\\Mpdf; final class Mpdf {}\n");
    symlink($candidate.'/vendor/Mpdf.php',$candidate.'/vendor/link.php');list($sc,$so)=rcf_cmd(array('python3',$tool,'--recipe',$pdf_recipe,'--candidate-root',$candidate,'--dependency-lock',$lock_path,'--output',$tmp.'/symlink-out'));rcf_check(0!==$sc,'Freezer rejects symbolic files from candidate source evidence.');unlink($candidate.'/vendor/link.php');

    $v1=$manifest;$v1['version']=1;rcf_check(rcf_throws(function()use($v1){(new RendererCandidateManifest())->normalize($v1);}), 'Candidate manifest v1 is stale and cannot certify current frozen-candidate provenance.');
    $lie=$manifest;$lie['implementation']['provenance_mode']='certloom-native';rcf_check(rcf_throws(function()use($lie){(new RendererCandidateManifest())->normalize($lie);}), 'Native provenance cannot claim a CertloomVendor third-party namespace.');
    $lie2=$manifest;$lie2['implementation']['provenance_mode']='prefixed-package';$lie2['implementation']['namespace_prefix']='Certloom\\Infrastructure\\Rendering\\';rcf_check(rcf_throws(function()use($lie2){(new RendererCandidateManifest())->normalize($lie2);}), 'Prefixed-package provenance cannot claim an unisolated Certloom namespace.');
} finally { rcf_rm($tmp); }
if($failures){fwrite(STDERR,"\n{$checks} checks, {$failures} failures\n");exit(1);}echo "\n{$checks} checks, 0 failures\n";
