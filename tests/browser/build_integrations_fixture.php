<?php
/** Browser-only INT-01 Integrations workspace fixture using the production view/CSS. */
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
$framework=array('status'=>'Integration framework ready','contract'=>'Supported LMS adapters','adapters'=>array(array('key'=>'tutor_lms','label'=>'Tutor LMS','available'=>false),array('key'=>'learndash','label'=>'LearnDash','available'=>false)),'next'=>'Tutor LMS and LearnDash use Certloom’s standard credential workflow. Course mapping remains unavailable while Program and Template management is protected, and automatic credential creation stays locked until Certloom’s production credential-writing checks are approved.');
$tutor=array('status'=>array('available'=>false,'version'=>'','active_mapping_count'=>0,'authority_ready'=>false),'configuration_ready'=>false,'error'=>null,'courses'=>array(),'programs'=>array(),'templates'=>array(),'mappings'=>array());
$learndash=array('status'=>array('available'=>false,'version'=>'','active_mapping_count'=>0,'authority_ready'=>false),'configuration_ready'=>false,'error'=>null,'courses'=>array(),'programs'=>array(),'templates'=>array(),'mappings'=>array());
$css=file_get_contents(dirname(__DIR__,2).'/admin/assets/admin.css');
ob_start(); include dirname(__DIR__,2).'/admin/views/integrations.php'; $view=ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.certloom-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.dashicons{display:inline-block}<?php echo $css; ?>
</style></head><body><?php echo $view; ?></body></html>
