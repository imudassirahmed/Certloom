<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RuntimePayloadFingerprint;

$checks=0;
function rpf_check($condition,$message){global $checks;$checks++;if(!$condition){throw new RuntimeException($message);}}
function rpf_throws($fn){try{$fn();}catch(Throwable $e){return true;}return false;}
$base=sys_get_temp_dir().'/certloom-rpf-'.bin2hex(random_bytes(5));
mkdir($base.'/src',0700,true); mkdir($base.'/docs',0700,true); mkdir($base.'/tests',0700,true); mkdir($base.'/resources/renderer',0700,true);
file_put_contents($base.'/certloom.php',"<?php return 'v';\n");
file_put_contents($base.'/src/a.php',"<?php return 'a';\n");
file_put_contents($base.'/docs/dev.md',"ignored docs\n");
file_put_contents($base.'/tests/dev.php',"<?php // ignored tests\n");
file_put_contents($base.'/resources/renderer/accepted.json','{"ignored":true}');
$c=new RuntimePayloadFingerprint(); $a=$c->fingerprint($base); $b=$c->fingerprint($base);
rpf_check($a===$b,'Runtime payload identity must be deterministic.');
rpf_check(RuntimePayloadFingerprint::SCHEMA===$a['schema']&&1===$a['version'],'Runtime payload identity must use the current schema/version.');
rpf_check(2===$a['file_count'],'Only production-intended files excluding accepted.json must be fingerprinted.');
file_put_contents($base.'/docs/dev.md',"changed ignored docs\n");
rpf_check($a===$c->fingerprint($base),'Development-only docs must not change production payload identity.');
foreach(array('audit','output','build','reports','qa-evidence','.playwright-cli') as $directory){
    mkdir($base.'/'.$directory,0700);file_put_contents($base.'/'.$directory.'/generated.txt','local evidence');
    rpf_check($a===$c->fingerprint($base),'Release-excluded development directory cannot alter runtime identity: '.$directory);
}
mkdir($base.'/src/output',0700);file_put_contents($base.'/src/output/real.php','<?php // production implementation');
rpf_check($a['sha256']!==$c->fingerprint($base)['sha256'],'Only root development directories are excluded; nested production paths remain bound.');
unlink($base.'/src/output/real.php');rmdir($base.'/src/output');
file_put_contents($base.'/resources/renderer/accepted.json','{"different":true}');
rpf_check($a===$c->fingerprint($base),'accepted.json must be excluded to avoid self-referential release identity.');
file_put_contents($base.'/src/a.php',"<?php return 'changed';\n");
rpf_check($a['sha256']!==$c->fingerprint($base)['sha256'],'Changing a production file must change runtime payload identity.');
file_put_contents($base.'/src/a.php',"<?php return 'a';\n");
file_put_contents($base.'/extra.php',"<?php return 'extra';\n");
rpf_check($a['sha256']!==$c->fingerprint($base)['sha256'],'Adding a production file must change runtime payload identity.');
unlink($base.'/extra.php');
if(function_exists('symlink')&&@symlink($base.'/src/a.php',$base.'/src/link.php')){
    rpf_check(rpf_throws(function()use($c,$base){$c->fingerprint($base);}), 'Symbolic production entries must fail closed.');
    unlink($base.'/src/link.php');
}
rpf_check(rpf_throws(function()use($c,$a){$bad=$a;$bad['sha256']='bad';$c->normalize($bad);}), 'Malformed runtime payload fingerprints must be rejected.');
foreach(array('audit','output','build','reports','qa-evidence','.playwright-cli') as $directory){unlink($base.'/'.$directory.'/generated.txt');rmdir($base.'/'.$directory);}
foreach(array($base.'/resources/renderer/accepted.json',$base.'/tests/dev.php',$base.'/docs/dev.md',$base.'/src/a.php',$base.'/certloom.php') as $f){@unlink($f);} @rmdir($base.'/resources/renderer'); @rmdir($base.'/resources'); @rmdir($base.'/tests'); @rmdir($base.'/docs'); @rmdir($base.'/src'); @rmdir($base);
echo "PASS  Runtime payload fingerprint binds exact production bytes while excluding only deterministic build omissions and accepted.json\n\n{$checks} checks, 0 failures\n";
