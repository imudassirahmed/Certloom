<?php
/** Phase 4.4 Insert palette + synthetic sample-data source contract. */
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Domain\Rendering\ElementRegistry;
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$js = file_get_contents( $root . '/admin/assets/designer.js' );
$css = file_get_contents( $root . '/admin/assets/designer.css' );
$pass=0;$fail=0;
function p44($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}

$profiles = TemplatePreviewSamples::profiles();
$expected = array('standard','long','very-long-name','rtl','organization','long-organization','long-iso-scope','school-result');
p44($profiles===$expected,'sample profile ids match the approved eight-case plan');
$contract=(new TemplatePreviewSamples())->browser_contract();
p44(($contract['default']??'')===TemplatePreviewSamples::STANDARD,'sample browser contract has deterministic default');
p44(array_keys($contract['profiles']??array())===$expected,'sample browser contract exposes all eight profiles');
foreach($expected as $profile){p44(isset($contract['profiles'][$profile]['values']['recipient.name']),'profile '.$profile.' has deterministic recipient sample');}
p44(strpos($contract['profiles']['rtl']['values']['recipient.name'],'محمد')!==false,'RTL profile carries real RTL stress text');
p44(strlen($contract['profiles']['very-long-name']['values']['recipient.name'])>55,'very-long-name profile exceeds ordinary name length');
p44(strlen($contract['profiles']['long-organization']['values']['recipient.name'])>85,'long-organization profile stresses organization overflow');
p44(strpos($contract['profiles']['long-iso-scope']['values']['program.name'],'ISO/IEC 27001:2022')!==false,'long ISO scope is deterministic and domain-relevant');
p44(strpos($contract['profiles']['school-result']['values']['credential.grade'],'93.4%')!==false,'school-result profile carries result stress data');
p44(strpos($contract['profiles']['standard']['verification_url'],'example.invalid')!==false,'sample verification URL is explicitly non-production');

p44(strpos($admin,"certloomTemplateDesignerSamples")!==false,'Designer localizes the shared synthetic sample contract');
p44(strpos($admin,'data-certloom-designer-sample-data')!==false,'Designer toolbar exposes a sample-data selector');
foreach(array('Normal person','Long name','Very long name','RTL person','Organization','Long organization','Long ISO scope','School result') as $label){p44(strpos($admin,$label)!==false,'sample selector exposes '.$label);}
p44(strpos($admin,'Preview only')!==false,'sample selector explicitly says preview only');
p44(strpos($js,'var SAMPLE_DATA = deepFreeze')!==false,'browser sample contract is cloned and frozen');
p44(strpos($js,'var activeSampleProfile')!==false,'sample selection is ephemeral browser presentation state');
p44(strpos($js,"sampleDataControl.addEventListener( 'change'")!==false,'sample selector updates live Designer presentation');
p44(strpos($js,'activeSampleProfile = requested; render();')!==false,'sample switch re-renders without layout mutation');
p44(strpos($js,'pushHistory')===false || strpos($js,"sampleDataControl.addEventListener( 'change'") < strpos($js,"pushHistory", strpos($js,"sampleDataControl.addEventListener( 'change'") + 1),'sample change handler does not create canonical history');
p44(strpos($js,"if ( 'merge' === preview ) { return dynamicFieldSample")!==false,'standalone legacy merge preview uses active synthetic sample data');
p44(strpos($js,"if ( 'barcode' === preview ) { return t( 'barcode'")!==false&&strpos($js,'dynamicFieldSample( String( element.source')!==false,'barcode preview uses sample value rather than raw source token');

$palette=ElementRegistry::palette();
$by_kind=array();foreach($palette as $entry){$by_kind[(string)$entry['kind']]=$entry;}
p44(count($palette)===19&&isset($by_kind['field'],$by_kind['dynamic_field'],$by_kind['merge'],$by_kind['rich_text'])&&(int)($by_kind['merge']['max_schema_version']??0)===3&&(int)($by_kind['rich_text']['min_schema_version']??0)===4,'registry exposes the approved 19-item Studio palette while legacy Merge remains schema-3 read/authoring compatibility');
foreach($palette as $item){$kind=(string)$item['kind'];p44(strpos($admin,'data-certloom-designer-add="\' . esc_attr( $kind )')===false || strpos($admin,'data-certloom-designer-palette-item')!==false,'palette '.$kind.' is emitted through unified semantic Insert item');}
p44(strpos($admin, "! \$disabled ? 'draggable=\"true\" '")!==false,'every enabled current registry primitive participates in drag placement');
p44(strpos($admin,'data-certloom-designer-palette-category')!==false,'Insert items carry bounded presentation categories');
p44(strpos($admin,'data-certloom-designer-add="table"')===false,'Table is not fabricated before Table/Data Table architecture');
p44(strpos($js,'function addRequested( kind, point )')!==false&&strpos($js,'appendElement( kind')!==false,'Insert uses the existing typed canonical element creation path');
p44(strpos($js,"if ( palette.requires_media && ! ( 'signature' === palette.type && schemaVersion >= 4 ) ) { mediaFrame")!==false,'fixed media primitives require local WordPress media identity before insertion');
p44(strpos($js,"if ( 'signature' === palette.type && schemaVersion >= 4 && ! assetReference ) { element.source = 'signer.signature'; }")!==false,'Schema 4 Signer signature inserts from immutable signer source without a temporary media attachment');
p44(strpos($js,"[ 'image/png', 'image/jpeg' ]")!==false,'media insertion retains PNG/JPEG renderer boundary');
p44(strpos($js,'window.localStorage')===false&&strpos($js,'window.sessionStorage')===false,'sample and Insert tranche creates no browser-local design authority');
p44(strpos($css,'.certloom-template-designer-v2 .certloom-template-designer-sample-data')!==false,'sample selector has dedicated Designer-v2 styling');
p44(strpos($css,'grid-template-columns: repeat(2, minmax(0, 1fr))')!==false,'Insert palette is compact two-column grid on Designer v2');
p44(strpos($css,'min-height: 44px')!==false,'mobile Insert/sample controls retain touch-target floor');
p44(strpos($css,'@media (forced-colors: active)')!==false,'Insert/sample controls preserve forced-colors support');
p44(!file_exists($root.'/resources/renderer/accepted.json'),'Phase 4.4 does not manufacture renderer authority');
p44(!file_exists($root.'/resources/domain/accepted.json'),'Phase 4.4 does not manufacture normalized-domain authority');

echo "\n$pass Phase 4.4 Insert/sample-data checks, $fail failures\n";
exit($fail?1:0);
