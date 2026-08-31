<?php
/** Static/contract acceptance for Phase 2 accessibility and theme resilience. */
$root = dirname(__DIR__);
$files = array(
    'render' => file_get_contents($root.'/includes/class-ecapla-certverify-render.php'),
    'route' => file_get_contents($root.'/public/views/credential-route.php'),
    'shortcode' => file_get_contents($root.'/includes/class-ecapla-certverify-shortcode.php'),
    'block' => file_get_contents($root.'/includes/class-ecapla-certverify-block.php'),
    'block_js' => file_get_contents($root.'/blocks/credential/index.js'),
    'block_json' => file_get_contents($root.'/blocks/credential/block.json'),
    'elementor' => file_get_contents($root.'/elementor/class-ecapla-certverify-credential-widget.php'),
    'js' => file_get_contents($root.'/public/assets/certloom-public.js'),
    'css' => file_get_contents($root.'/public/assets/certloom-public.css'),
);
foreach ($files as $name=>$contents) { if (!is_string($contents) || $contents==='') { throw new RuntimeException('missing '.$name); } }
function a11yq($condition,$message){ if(!$condition){throw new RuntimeException($message);} }
$checks = array(
    'verification results are regions not interactive role-status containers' => fn()=>a11yq(false!==strpos($files['render'],'class="cv-result" role="region"') && false!==strpos($files['render'],'class="cvx-result" role="region"'),'result region semantics'),
    'dedicated live status exists outside result region' => fn()=>a11yq(substr_count($files['render'],'certloom-live-status')>=3 && false!==strpos($files['render'],'role="status" aria-live="polite"'),'live status'),
    'AJAX announces outcome separately' => fn()=>a11yq(false!==strpos($files['js'],'function announce( message )') && false!==strpos($files['js'],'announce( statusDescription ? statusLabel'),'ajax announcement'),
    'AJAX uses status-specific icon vocabulary' => fn()=>a11yq(false!==strpos($files['js'],'statusIcon( statusKey )'),'ajax status icon'),
    'AJAX verification timestamp keeps semantic time element' => fn()=>a11yq(false!==strpos($files['js'],'<time datetime='),'time element'),
    'certificate inputs disable spellcheck/autocapitalize and use automatic direction' => fn()=>a11yq(substr_count($files['render'],'autocapitalize="off" spellcheck="false" dir="auto"')>=2,'input mobile/bidi'),
    'public field values use automatic bidi direction' => fn()=>a11yq(false!==strpos($files['render'],'<dd dir="auto">') && false!==strpos($files['js'],'<dd dir="auto">'),'bidi values'),
    'embedded profile defaults to h2' => fn()=>a11yq(false!==strpos($files['shortcode'],"'heading_tag' => 'h2'") && false!==strpos($files['block'],"'headingTag' => 'h2'") && false!==strpos($files['elementor'],"'default' => 'h2'"),'embedded h2'),
    'embedded profile heading is bounded to h2-h6' => fn()=>a11yq(false!==strpos($files['shortcode'],"array( 'h2', 'h3', 'h4', 'h5', 'h6' )") && false!==strpos($files['block'],"array( 'h2', 'h3', 'h4', 'h5', 'h6' )") && false!==strpos($files['elementor'],"array( 'h2', 'h3', 'h4', 'h5', 'h6' )"),'heading bounds'),
    'clean credential route intentionally owns h1' => fn()=>a11yq(false!==strpos($files['route'],"array( 'heading_tag' => 'h1' )"),'route h1'),
    'block exposes heading level control' => fn()=>a11yq(false!==strpos($files['block_json'],'"headingTag"') && false!==strpos($files['block_js'],'Heading level'),'block heading control'),
    'elementor exposes heading level control' => fn()=>a11yq(false!==strpos($files['elementor'],"'heading_tag'") && false!==strpos($files['elementor'],"'Heading level'"),'elementor heading control'),
    'dedicated route has independent skip link' => fn()=>a11yq(false!==strpos($files['route'],'certloom-skip-link') && false!==strpos($files['route'],'certloom-route-content') && false!==strpos($files['route'],'tabindex="-1"'),'skip link'),
    'action targets meet 44px premium target' => fn()=>a11yq(false!==strpos($files['css'],'min-height:44px;min-width:44px'),'target size'),
    'legacy search button has visible keyboard focus' => fn()=>a11yq(false!==strpos($files['css'],'.cv-search-btn:focus-visible'),'legacy focus'),
    'route explicitly neutralizes common primary-column theme layout' => fn()=>a11yq(false!==strpos($files['css'],'.certloom-route-main#primary{float:none;clear:both;width:100%;max-width:none;'),'route resilience'),
    'reduced motion covers legacy and modern loading indicators' => fn()=>a11yq(false!==strpos($files['css'],'prefers-reduced-motion: reduce') && false!==strpos($files['css'],'.cv-search-btn.cv-loading .cv-arrow') && false!==strpos($files['css'],'animation:none!important'),'reduced motion'),
    'forced colors policy exists' => fn()=>a11yq(false!==strpos($files['css'],'forced-colors: active') && false!==strpos($files['css'],'CanvasText') && false!==strpos($files['css'],'Highlight'),'forced colors'),
    'higher contrast preference strengthens borders' => fn()=>a11yq(false!==strpos($files['css'],'prefers-contrast: more') && false!==strpos($files['css'],'border-width:2px'),'contrast'),
    'live status hiding does not depend on theme screen-reader utility' => fn()=>a11yq(false!==strpos($files['css'],'.certloom-live-status{') && false!==strpos($files['css'],'clip:rect(0,0,0,0)!important'),'sr utility'),
    'result action reset resists common theme button styles' => fn()=>a11yq(false!==strpos($files['css'],'.certloom-result-action{-webkit-appearance:none;appearance:none;') && false!==strpos($files['css'],'text-transform:none;letter-spacing:normal;box-shadow:none;'),'action reset'),
);
$failed=0;foreach($checks as $name=>$fn){try{$fn();echo "PASS  $name\n";}catch(Throwable $e){$failed++;echo "FAIL  $name\n      {$e->getMessage()}\n";}}
echo "\n".count($checks)." tests, $failed failures\n";exit($failed?1:0);
