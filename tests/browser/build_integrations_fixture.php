<?php
/** Browser-only INT-01 Integrations workspace fixture using the production view/CSS. */
define( 'ABSPATH', __DIR__ . '/' );
function __( $v, $d = null ){ return $v; }
function esc_html__( $v, $d = null ){ return $v; }
function esc_html_e( $v, $d = null ){ echo htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
$framework=array('status'=>'Framework ready','contract'=>'INT-01 + LMSCTX-01','adapters'=>array(),'next'=>'UX simplification program, then Tutor LMS');
$css=file_get_contents(dirname(__DIR__,2).'/admin/assets/admin.css');
ob_start(); include dirname(__DIR__,2).'/admin/views/integrations.php'; $view=ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.certloom-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.dashicons{display:inline-block}<?php echo $css; ?>
</style></head><body><?php echo $view; ?></body></html>
