<?php
/** Audit regression: Global → Issuer → Program → Batch rules are configurable, not backend-only. */
define( 'ABSPATH', __DIR__ . '/' );
$fail=0;$checks=0; function shui($ok,$label){global$fail,$checks;$checks++;echo($ok?'PASS  ':'FAIL  ').$label.PHP_EOL;if(!$ok)$fail++;}
$domain=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Admin/DomainAdmin.php');
$fields=file_get_contents(dirname(__DIR__).'/admin/views/fields-manager.php');
$admin=file_get_contents(dirname(__DIR__).'/admin/class-ecapla-certverify-admin.php');
$js=file_get_contents(dirname(__DIR__).'/admin/assets/admin.js');
$issuerRepo=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Repository/IssuerRepository.php');
shui(false!==strpos($fields,'Global credential-field defaults'),'Field Manager exposes Global credential-field defaults');
shui(false!==strpos($fields,'global_field_requirement['),'Global UI persists typed Global rules');
shui(false!==strpos($admin,'ScopedFieldGlobalRepository'),'Field Manager save path uses the typed Global repository');
shui(false!==strpos($domain,'issuer_field_editor'),'Issuer profile exposes field defaults');
shui(false!==strpos($domain,'issuer_field_requirement['),'Issuer UI persists typed Issuer rules');
shui(false!==strpos($domain, 'with_scope_metadata( $settings, \'issuer\''),'Issuer save path uses the typed Issuer envelope');
shui(false!==strpos($issuerRepo, 'from_scope_metadata( $row[\'settings\'], \'issuer\''),'Issuer repository separates typed rules from generic settings');
shui(false!==strpos($domain,'Inherit Issuer') && false!==strpos($domain,"'exclude'"),'Program rules can inherit or override Issuer defaults');
shui(false!==strpos($js,"global_field_requirement")&&false!==strpos($js,"issuer_field_requirement")&&false!==strpos($js,"program_field_requirement")&&false!==strpos($js,"batch_field_requirement"),'dynamic scoped-field editor supports all four hierarchy levels');
echo "\n{$checks} scoped-field hierarchy UI checks, {$fail} failures\n";exit($fail?1:0);
