<?php
/** Browser fixture for UX-C01B truthful grouped CSV mapping. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ECAPLA_CERTVERIFY_PATH', dirname( __DIR__, 2 ) . '/' );
function __( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function selected( $a, $b, $echo = true ) { $v = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $v; } return $v; }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function current_user_can() { return true; }
function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '">' . esc_html( $text ) . '</button>'; }
$fields = array(
	array( 'key'=>'holder_name', 'label'=>'Full name' ), array( 'key'=>'first_name', 'label'=>'First name' ), array( 'key'=>'middle_name', 'label'=>'Middle name' ), array( 'key'=>'last_name', 'label'=>'Last name' ), array( 'key'=>'email', 'label'=>'Email' ), array( 'key'=>'external_id', 'label'=>'External ID' ),
	array( 'key'=>'cert_number', 'label'=>'Certificate number' ), array( 'key'=>'status', 'label'=>'Status' ), array( 'key'=>'issue_date', 'label'=>'Issue date' ), array( 'key'=>'expiry_date', 'label'=>'Expiry date' ), array( 'key'=>'grade', 'label'=>'Grade' ), array( 'key'=>'standard', 'label'=>'Standard' ), array( 'key'=>'scope', 'label'=>'Scope' ),
	array( 'key'=>'status_reason', 'label'=>'Status reason' ), array( 'key'=>'internal_notes', 'label'=>'Internal notes' ), array( 'key'=>'__certloom_profile_visibility', 'label'=>'Public visibility' ), array( 'key'=>'department', 'label'=>'Department' ),
);
$header = array( 'display_name','first_name','last_name','email','grade','certificate_number','department','ignore_me' );
$guessed_mapping = array( 0=>'holder_name',1=>'first_name',2=>'last_name',3=>'email',4=>'grade',5=>'cert_number',6=>'department' );
$preview = array(
	array('Amina Khan','Amina','Khan','amina@example.test','A','CERT-1001','Science','x'),
	array('Omar Ali','Omar','Ali','omar@example.test','B+','CERT-1002','Arts','y'),
);
$token='fixture-token';
$css=file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' ) . "\n" . file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;padding:20px;background:#f0f0f1;color:#1d2327;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{margin:0}.brand-bar{display:none}.widefat{width:100%;border-collapse:collapse;background:#fff}.widefat th,.widefat td{padding:10px;border:1px solid #dcdcde;text-align:left;vertical-align:top}.ecapla-certverify-table-scroll{overflow:auto}.button{min-height:44px;padding:0 14px}.screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}select{min-height:44px;max-width:100%}@media(max-width:782px){body{padding:10px}.widefat{min-width:760px}}
<?php echo $css; ?>
</style></head><body><?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/import-mapping.php'; ?></body></html>
