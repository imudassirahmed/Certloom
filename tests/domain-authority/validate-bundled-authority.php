<?php
require_once __DIR__ . '/authorized-release-lib.php';
if(2!==$argc){fwrite(STDERR,"usage: validate-bundled-authority.php ACCEPTED.json\n");exit(2);}try{$v=new CertloomDomainAuthorizedRelease(dirname(__DIR__,2));$r=$v->validate_manifest_file($argv[1]);echo json_encode(array('schema'=>'certloom-domain-bundled-authority-validation','version'=>1,'plugin_version'=>$r['plugin_version'],'runtime_payload'=>$r['runtime_payload'],'release_manifest_sha256'=>$r['release_manifest_sha256']),JSON_UNESCAPED_SLASHES)."\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(3);}
