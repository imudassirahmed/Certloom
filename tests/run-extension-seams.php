<?php
/** Audit regression: useful core extension seams remain validated and post-commit. */
define( 'ABSPATH', __DIR__ . '/' );
$fail=0;$checks=0; function es($ok,$label){global$fail,$checks;$checks++;echo($ok?'PASS  ':'FAIL  ').$label.PHP_EOL;if(!$ok)$fail++;}
$registry=file_get_contents(dirname(__DIR__).'/src/Application/Integration/IntegrationAdapterRegistry.php');
$lifecycle=file_get_contents(dirname(__DIR__).'/src/Application/CredentialLifecycleService.php');
$elements=file_get_contents(dirname(__DIR__).'/src/Domain/Rendering/ElementRegistry.php');
es(false!==strpos($registry,"apply_filters( 'certloom_integration_adapters'"),'integration adapters expose a normal WordPress extension seam');
es(false!==strpos($registry,'IntegrationAdapterInterface') && false!==strpos($registry,'certloom_integration_adapter_registry_invalid'),'adapter seam remains interface-validated and fails closed');
es(false!==strpos($lifecycle,"do_action( 'certloom_credential_revoked'"),'credential revocation exposes a post-command notification action');
es(false===strpos($elements,'certloom_element_registry_types'),'deterministic renderer registry is not made mutable by an unversioned WordPress filter');
echo "\n{$checks} extension seam checks, {$fail} failures\n";exit($fail?1:0);
