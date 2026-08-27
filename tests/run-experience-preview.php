<?php
$root = dirname(__DIR__);
$main = file_get_contents($root . '/certloom.php');
$plugin = file_get_contents($root . '/src/Plugin.php');
$public_gate = file_get_contents($root . '/src/Infrastructure/Verification/PublicVerificationGate.php');
$renderer_gate = file_get_contents($root . '/src/Infrastructure/Rendering/RendererAuthorityGate.php');
$checks = array(
    'experience preview defaults on' => false !== strpos($main, "define( 'CERTLOOM_EXPERIENCE_PREVIEW', true )"),
    'admin preview derives from experience preview' => false !== strpos($main, "define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', (bool) CERTLOOM_EXPERIENCE_PREVIEW )"),
    'normalized write authority remains off' => false !== strpos($main, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )"),
    'normalized data tools authority remains off' => false !== strpos($main, "define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )"),
    'preview data tools default off' => false !== strpos($main, "define( 'CERTLOOM_EXPERIENCE_PREVIEW_DATA_TOOLS', false )"),
    'data tools require separate explicit gate' => false !== strpos($plugin, '$data_tools_ready') && false !== strpos($plugin, '$preview_data_tools') && false !== strpos($plugin, 'if ( $data_tools_ready || $preview_data_tools )'),
    'public verification still requires cutover receipt' => false !== strpos($public_gate, 'receipt_matches_completed_migration') && false !== strpos($public_gate, 'is_domain_authoritative()'),
    'renderer still requires accepted manifest' => false !== strpos($renderer_gate, 'resources/renderer/accepted.json') && false !== strpos($renderer_gate, 'manifest_missing'),
    'accepted renderer manifest absent' => !file_exists($root . '/resources/renderer/accepted.json'),
    'persistent preview warning exists' => false !== strpos($main, 'Certloom Experience Preview is enabled.'),
    'staging-only guidance bundled' => is_file($root . '/EXPERIENCE-PREVIEW.md'),
);
$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($ok) { $passed++; }
}
echo $passed . '/' . count($checks) . " checks passed\n";
exit($passed === count($checks) ? 0 : 1);
