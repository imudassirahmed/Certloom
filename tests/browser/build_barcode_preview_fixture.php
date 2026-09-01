<?php
/** Production-path browser fixture for certloom-layout v2 Code 128 Preview. */
define('ABSPATH',__DIR__.'/');
function __($v){return $v;} function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_attr__($v){return esc_attr($v);} function esc_html__($v){return esc_html($v);}
require_once dirname(__DIR__).'/bootstrap-autoload.php';
use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Infrastructure\Rendering\LocalCode128Encoder;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
final class BarcodePreviewNoAssets implements LocalAssetResolverInterface { public function resolve($reference){throw new RuntimeException('No assets expected.');} }
$layout=array(
 '_meta'=>array('schema'=>'certloom-layout','schema_version'=>2),
 'page'=>array('size'=>'A4','orientation'=>'landscape','width_mm'=>297.0,'height_mm'=>210.0),
 'elements'=>array(array('id'=>'88888888-8888-4888-8888-888888888888','type'=>'barcode','x_mm'=>60.0,'y_mm'=>80.0,'width_mm'=>170.0,'height_mm'=>30.0,'z'=>1,'rotation_deg'=>0.0,'opacity'=>1.0,'source'=>'credential.display_number','symbology'=>'code128','quiet_zone_modules'=>10)),
);
$resolved=(new LayoutResolver(null,null,null,null,new LocalCode128Encoder()))->resolve($layout,array('credential.display_number'=>'CERT-2026-001'),'https://example.test/verify/CERT-2026-001',new BarcodePreviewNoAssets());
$plan=(new RenderPlanBuilder())->build($resolved);
$presented=(new RenderPlanHtmlPreview())->present($plan);
?><!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;background:#ddd}.certloom-template-preview-scroll{padding:20px}.certloom-template-preview-page{position:relative;background:white;overflow:hidden}.certloom-template-preview-op{position:absolute;display:block}.certloom-template-preview-op-barcode{image-rendering:pixelated}</style></head><body><?php echo $presented['html']; ?></body></html>
