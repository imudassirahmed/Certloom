<?php
/** Browser-only INT-03 LearnDash fixture using the production Integrations view/CSS. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Admin/ScopedFieldAdminAjax.php';
function __( $v, $d = null ){ return $v; }
function esc_html__( $v, $d = null ){ return $v; }
function esc_html_e( $v, $d = null ){ echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function wp_create_nonce( $action ){ return 'fixture-' . preg_replace('/[^A-Za-z0-9_-]/','',(string)$action); }
function esc_attr( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $v, $d = null ){ echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function absint( $v ){ return abs( (int) $v ); }
function admin_url( $p = '' ){ return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); }
function wp_nonce_field( $action ){ echo '<input type="hidden" name="_wpnonce" value="fixture-' . esc_attr( $action ) . '">'; }
function is_wp_error( $v ){ return false; }

$framework = array(
    'status' => 'Detected: LearnDash',
    'contract' => 'Supported LMS adapters',
    'adapters' => array(
        array( 'key' => 'tutor_lms', 'label' => 'Tutor LMS', 'available' => false ),
        array( 'key' => 'learndash', 'label' => 'LearnDash', 'available' => true ),
    ),
    'next' => 'Tutor LMS and LearnDash use Certloom’s standard credential workflow. Course mapping remains unavailable while Program and Template management is protected, and automatic credential creation stays locked until Certloom’s production credential-writing checks are approved.',
);
$tutor = array('status'=>array('available'=>false,'version'=>'','active_mapping_count'=>0,'authority_ready'=>false),'configuration_ready'=>false,'error'=>null,'courses'=>array(),'programs'=>array(),'templates'=>array(),'mappings'=>array());
$learndash = array(
    'status' => array(
        'available' => true,
        'version' => '4.25.1',
        'active_mapping_count' => 1,
        'authority_ready' => false,
    ),
    'configuration_ready' => true,
    'error' => null,
    'courses' => array(
        array( 'id' => 401, 'title' => 'LearnDash Professional Course' ),
        array( 'id' => 402, 'title' => 'LearnDash Advanced Course' ),
    ),
    'programs' => array(
        array( 'id' => 201, 'title' => 'Professional Certification Program' ),
        array( 'id' => 202, 'title' => 'Advanced Program' ),
    ),
    'templates' => array(
        array( 'id' => 301, 'name' => 'Executive Landscape' ),
        array( 'id' => 302, 'name' => 'Classic Portrait' ),
    ),
    'mappings' => array(
        array( 'course_id' => 401, 'program_id' => 201, 'template_id' => 301, 'active' => true ),
    ),
);
$css = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
ob_start(); include dirname( __DIR__, 2 ) . '/admin/views/integrations.php'; $view = ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.certloom-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.dashicons{display:inline-block}.form-table{width:100%;border-collapse:collapse}.form-table th{width:180px;text-align:left;vertical-align:top;padding:12px 12px 12px 0}.form-table td{padding:8px 0}.form-table select,.form-table input[type=date]{min-height:40px;max-width:100%}.button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border:1px solid #2271b1;border-radius:4px;background:#f6f7f7;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}.widefat{width:100%;border-collapse:collapse}.widefat th,.widefat td{padding:10px;border-bottom:1px solid #dcdcde;text-align:left}.notice{padding:8px 12px;background:#fff;border-left:4px solid #72aee6}@media(max-width:600px){.wrap{margin:12px}.form-table,.form-table tbody,.form-table tr,.form-table th,.form-table td{display:block;width:100%}.form-table th{padding:10px 0 2px}.form-table td{padding:2px 0 10px}.form-table select,.form-table input[type=date]{width:100%;min-height:44px}.widefat{display:block;overflow-x:auto}.button{min-height:44px}.certloom-panel{padding:14px}}
<?php echo $css; ?>
</style></head><body><?php echo $view; ?></body></html>
