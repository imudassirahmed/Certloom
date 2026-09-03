<?php
/** UX-QA browser fixture for the real current-authority Add credential view. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ECAPLA_CERTVERIFY_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'CERTLOOM_QA_UNGATED', true );
require ECAPLA_CERTVERIFY_PATH . 'src/Infrastructure/Migration/DomainFeatureGate.php';

function __( $v, $d = null ) { return $v; }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $v, $d = null ) { return $v; }
function esc_html_e( $v, $d = null ) { echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $v, $d = null ) { echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_NOQUOTES, 'UTF-8' ); }
function esc_url( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function is_wp_error( $v ) { return false; }
function get_option( $key, $default = false ) { return $default; }
function mysql2date( $format, $date, $translate = true ) { return $date; }
function selected( $a, $b, $echo = true ) { $out = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) echo $out; return $out; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture-' . esc_attr( $action ) . '">'; }
function submit_button( $text = 'Save', $type = 'primary', $name = 'submit', $wrap = true ) { $button = '<button type="submit" class="button button-' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; echo $wrap ? '<p class="submit">' . $button . '</p>' : $button; }

class Ecapla_CertVerify_Fields {
    public static function get_field_map() {
        $fields = self::fields(); $map = array(); foreach ( $fields as $field ) { $map[ $field['key'] ] = $field; } return $map;
    }
    public static function fields() {
        return array(
            array( 'key'=>'cert_number','label'=>'Certificate number','type'=>'text','column'=>true,'public_exposure'=>'verification','sensitivity'=>'public_safe' ),
            array( 'key'=>'holder_name','label'=>'Holder name','type'=>'text','column'=>true,'public_exposure'=>'both','sensitivity'=>'personal' ),
            array( 'key'=>'issue_date','label'=>'Issue date','type'=>'date','column'=>true,'public_exposure'=>'both','sensitivity'=>'public_safe' ),
            array( 'key'=>'expiry_date','label'=>'Expiry date','type'=>'date','column'=>true,'public_exposure'=>'both','sensitivity'=>'public_safe' ),
            array( 'key'=>'status','label'=>'Status','type'=>'status','column'=>true,'public_exposure'=>'both','sensitivity'=>'public_safe' ),
            array( 'key'=>'status_reason','label'=>'Status reason','type'=>'text','column'=>true,'public_exposure'=>'verification','sensitivity'=>'personal' ),
            array( 'key'=>'grade','label'=>'Grade','type'=>'text','column'=>false,'public_exposure'=>'both','sensitivity'=>'personal' ),
            array( 'key'=>'internal_notes','label'=>'Internal notes','type'=>'textarea','column'=>true,'locked_visibility'=>true,'public_exposure'=>'private','sensitivity'=>'sensitive' ),
            array( 'key'=>'__certloom_profile_visibility','label'=>'Public visibility','type'=>'visibility','column'=>false,'public_exposure'=>'private','sensitivity'=>'public_safe' ),
        );
    }
    public static function get_statuses() { return array( 'valid'=>array('label'=>'Valid'), 'expired'=>array('label'=>'Expired'), 'suspended'=>array('label'=>'Suspended'), 'withdrawn'=>array('label'=>'Withdrawn') ); }
    public static function get_editable_statuses() { return array( 'valid'=>array('label'=>'Valid'), 'suspended'=>array('label'=>'Suspended'), 'withdrawn'=>array('label'=>'Withdrawn') ); }
    public static function get_visible_fields( $surface ) { return 'verification' === $surface ? array( 'cert_number','holder_name','issue_date','expiry_date','status' ) : array( 'cert_number','holder_name','issue_date','expiry_date','status','grade' ); }
    public static function get_sensitivity_options() { return array( 'public_safe'=>'Public safe', 'personal'=>'Personal', 'sensitive'=>'Sensitive' ); }
}
class Ecapla_CertVerify_Secondary_Code { public static function has_code( $certificate ) { return false; } }
class Ecapla_CertVerify_Numbering {
    public static function is_enabled() { return true; }
    public static function preview() { return array( 'certificate_number'=>'CERT-0001' ); }
}
class Ecapla_CertVerify_Database {
    public static function effective_status( $certificate ) { return 'valid'; }
    public static function is_legacy_expired( $certificate ) { return false; }
}
class Ecapla_CertVerify_Onboarding { public static function get_state() { return array( 'verification_page_id'=>0 ); } }
class Ecapla_CertVerify_Public_Router { public static function verification_url( $number ) { return ''; } public static function profile_url( $number ) { return ''; } }
class Ecapla_CertVerify_Public_Policy {
    public const MODE_PROFILE = 'profile';
    public static function guest_mode( $certificate ) { return 'verification'; }
    public static function visibility_options() { return array( 'public'=>'Public profile', 'verification'=>'Verification only', 'private'=>'Private' ); }
}

$certificate = null;
$fields = Ecapla_CertVerify_Fields::fields();
$css = file_get_contents( ECAPLA_CERTVERIFY_PATH . 'admin/assets/admin.css' );
ob_start(); include ECAPLA_CERTVERIFY_PATH . 'admin/views/edit-certificate.php'; $view = ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.dashicons{display:inline-block}.button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border:1px solid #2271b1;border-radius:4px;background:#f6f7f7;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}.regular-text,.large-text,select,input[type=date],input[type=text],textarea{max-width:100%;font:inherit}
<?php echo $css; ?>
</style></head><body><?php echo $view; ?></body></html>
