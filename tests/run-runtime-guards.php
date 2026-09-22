<?php
/** AUD-4.74 runtime direct-access guard contract. */
$root=dirname(__DIR__); $checks=0;$fail=0;
function rg($ok,$label){global $checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
$paths=array('certloom.php','src','includes','admin','public','elementor','blocks'); $missing=array();$php=0;
foreach($paths as $rel){$path=$root.'/'.$rel;if(is_file($path)){$files=array($path);}else{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));$files=[];foreach($it as $file){if($file->isFile()&&'php'===strtolower($file->getExtension()))$files[]=$file->getPathname();}}foreach($files as $file){$php++;$src=file_get_contents($file);if(!is_string($src)||false===strpos($src,"defined( 'ABSPATH' )")&&false===strpos($src,'defined( "ABSPATH" )')){$missing[]=str_replace($root.'/','',$file);}}}
rg($php>300,'runtime guard scan covers the production PHP surface'); rg(empty($missing),'every browser-executable runtime PHP file has an ABSPATH direct-access guard'); if($missing){foreach($missing as $file)echo "MISS  $file\n";}
$uninstall=file_get_contents($root.'/uninstall.php'); rg(is_string($uninstall)&&false!==strpos($uninstall,'WP_UNINSTALL_PLUGIN'),'uninstall entry point retains its WordPress uninstall-specific guard');
echo "\n{$checks} runtime-guard checks, {$fail} failures\n";exit($fail?1:0);
