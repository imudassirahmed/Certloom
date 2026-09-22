<?php
/** UX-C01B — complete truthful mapper + auto-mapping presentation contract. */
$root=dirname(__DIR__);$pass=0;$fail=0;
function cmx($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
function cms($root,$path){return file_get_contents($root.'/'.$path);}
$view=cms($root,'admin/views/import-mapping.php');$fields=cms($root,'includes/class-ecapla-certverify-fields.php');$import=cms($root,'includes/class-ecapla-certverify-import.php');$mapper=cms($root,'src/Application/Migration/LegacyCertificateMapper.php');$css=cms($root,'admin/assets/admin.css');
foreach(['first_name','middle_name','last_name','email','external_id','grade'] as $key){cmx(false!==strpos($fields,"self::field( '$key'"),"$key has current-authority field definition");}
cmx(false!==strpos($view,"__( 'Recipient', 'certloom' )")&&false!==strpos($view,"__( 'Credential', 'certloom' )")&&false!==strpos($view,"__( 'Administrative', 'certloom' )")&&false!==strpos($view,"__( 'Verification', 'certloom' )")&&false!==strpos($view,"__( 'Custom fields', 'certloom' )"),'mapper groups choices by customer concept');
cmx(false!==strpos($view,"'holder_name' === \$field['key'] ? __( 'Full name', 'certloom' )"),'legacy holder field is presented as Full name in mapper');
cmx(false!==strpos($view,'certloom-mapping-status')&&false!==strpos($view,"esc_html_e( 'Auto-mapped', 'certloom' )"),'mapper identifies automatic matches');
cmx(false!==strpos($view,"submit_button( __( 'Import certificates', 'certloom' )"),'mapper ends with task-oriented Import certificates action');
cmx(false!==strpos($import,"'displayname'       => 'holder_name'")&&false!==strpos($import,"'fullname'          => 'holder_name'")&&false!==strpos($import,"'firstname'         => 'first_name'")&&false!==strpos($import,"'lastname'          => 'last_name'")&&false!==strpos($import,"'externalid'        => 'external_id'")&&false!==strpos($import,"'grade'             => 'grade'"),'common CSV aliases auto-map deterministically');
cmx(false!==strpos($mapper,"array( 'first_name', 'middle_name', 'last_name', 'email', 'external_id' )")&&false!==strpos($mapper,"'grade'                  => '' === \$grade ? null : \$grade"),'current profile exchange fields promote into normalized Recipient/Version migration');
cmx(false!==strpos($css,'.certloom-mapping-status')&&false!==strpos($css,'forced-colors:active'),'mapping feedback has responsive/high-contrast styling');
echo "\n$pass UX-C01B checks, $fail failures\n";exit($fail?1:0);
