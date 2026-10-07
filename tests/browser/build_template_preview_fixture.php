<?php
/** Build a self-contained 1.6.55 browser Preview fixture from production classes. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_html_class($v){return preg_replace('/[^A-Za-z0-9_-]/','',(string)$v);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function esc_attr__($v){return esc_attr($v);}
function esc_html__($v){return esc_html($v);}
require_once dirname(__DIR__) . '/bootstrap-autoload.php';

use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Rendering\TemplatePreviewService;

$catalog = new PresetCatalog();
$service = new TemplatePreviewService();
$presenter = new RenderPlanHtmlPreview();
$cases = array();
foreach ( array_keys( PresetCatalog::families() ) as $family ) {
    $cases[] = array( $family . '-a4-landscape', TemplatePreviewSamples::STANDARD );
}
$cases[] = array( 'classic-a4-landscape', TemplatePreviewSamples::LONG );
$cases[] = array( 'classic-a4-landscape', TemplatePreviewSamples::RTL );

$body = '';
$i = 0;
foreach ( $cases as $case ) {
    list( $preset_key, $sample ) = $case;
    $definition = $catalog->get( $preset_key );
    if ( ! is_array( $definition ) ) { throw new RuntimeException( 'Missing preset ' . $preset_key ); }
    $template = array(
        'id' => ++$i,
        'current_revision_id' => 1000 + $i,
        'published_revision_id' => 0,
        'current_revision' => array( 'id' => 1000 + $i, 'revision_number' => 1, 'layout_sha256' => $definition['layout_sha256'], 'layout' => $definition['layout'] ),
        'published_revision' => null,
    );
    $preview = $service->build( $template, 'current', $sample );
    if ( is_wp_error( $preview ) ) { throw new RuntimeException( $preview->get_error_message() ); }
    $rendered = $presenter->present( $preview['render_plan'] );
    $body .= '<section class="fixture" data-preset="' . esc_attr( $preset_key ) . '" data-sample="' . esc_attr( $sample ) . '"><h2>' . esc_html( $preset_key . ' / ' . $sample ) . '</h2>' . $rendered['html'] . '</section>';
}
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' ) . "\n" . file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
if ( ! is_string( $css ) ) { throw new RuntimeException( 'Cannot read admin CSS.' ); }
$extra = 'body{margin:0;padding:20px;background:#f6f7f7;font-family:system-ui,sans-serif}.fixture{margin:0 0 30px}.fixture h2{font-size:14px}.fixture .certloom-template-preview-scroll{max-width:calc(100vw - 42px)}#certloom-browser-result{white-space:pre-wrap}';
$script = <<<'JS'
(function(){
 const checks={};
 const fixtures=[...document.querySelectorAll('.fixture')];
 checks.fixtureCount=fixtures.length===8;
 checks.pagesPresent=fixtures.every(f=>!!f.querySelector('.certloom-template-preview-page'));
 checks.qrPresent=fixtures.every(f=>!!f.querySelector('.certloom-template-preview-op-qr'));
 checks.noDocumentHorizontalOverflow=document.documentElement.scrollWidth<=document.documentElement.clientWidth+1;
 checks.pagesHavePositiveGeometry=fixtures.every(f=>{const p=f.querySelector('.certloom-template-preview-page');const r=p.getBoundingClientRect();return r.width>500&&r.height>300;});
 checks.scrollContainersContainPages=fixtures.every(f=>{const s=f.querySelector('.certloom-template-preview-scroll');const p=f.querySelector('.certloom-template-preview-page');return s.scrollWidth>=p.offsetWidth&&s.scrollHeight>=p.offsetHeight;});
 checks.qrSquare=fixtures.every(f=>{const q=f.querySelector('.certloom-template-preview-op-qr');const r=q.getBoundingClientRect();return Math.abs(r.width-r.height)<1.5&&r.width>25;});
 checks.qrHasRuns=fixtures.every(f=>f.querySelectorAll('.certloom-template-preview-op-qr rect').length>20);
 checks.textContained=fixtures.every(f=>[...f.querySelectorAll('.certloom-template-preview-op-text')].every(el=>el.scrollWidth<=el.clientWidth+1&&el.scrollHeight<=el.clientHeight+1));
 const rtl=fixtures.find(f=>f.dataset.sample==='rtl');
 checks.rtlContent=!!rtl && [...rtl.querySelectorAll('.certloom-template-preview-op-text')].some(el=>getComputedStyle(el).direction==='rtl');
 document.getElementById('certloom-browser-result').textContent=JSON.stringify({checks});
})();
JS;
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . $extra . '</style></head><body>' . $body . '<pre id="certloom-browser-result"></pre><script>' . $script . '</script></body></html>';
