<?php
$root = dirname( __DIR__, 2 );
$tokens = file_get_contents( $root . '/assets/css/certloom-tokens.css' );
$admin = file_get_contents( $root . '/admin/assets/admin.css' );
$foundation = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$email = file_get_contents( $root . '/admin/assets/email-designs.css' );
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.fixture{display:grid;gap:22px;padding:24px}.wrap{margin:0}.button{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 12px;border:1px solid #2271b1;background:#f6f7f7;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}.dashicons{display:inline-block}
<?php echo $tokens, "\n", $admin, "\n", $foundation, "\n", $email; ?>
</style></head><body><main class="fixture">
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-dashboard" id="dashboard-surface">
<section class="certloom-dashboard-hero"><div class="certloom-dashboard-hero-copy"><span class="certloom-eyebrow">Credential operations</span><h1>Dashboard</h1><p>Monitor credential health and activity.</p></div><a class="certloom-dashboard-setup-link">Setup wizard</a><div class="certloom-dashboard-actions"><a class="button button-primary">Issue credential</a><a class="button button-secondary">Open programs</a></div></section>
<section class="certloom-metric-grid"><a class="certloom-metric-card"><span class="certloom-metric-icon"><span class="dashicons">●</span></span><span class="certloom-metric-label">Total credentials</span><strong>184</strong><span class="certloom-metric-meta">Open registry</span></a><a class="certloom-metric-card"><span class="certloom-metric-icon is-valid">●</span><span class="certloom-metric-label">Valid</span><strong>151</strong></a><a class="certloom-metric-card"><span class="certloom-metric-icon is-withdrawn">▲</span><span class="certloom-metric-label">Revoked</span><strong>3</strong></a><a class="certloom-metric-card"><span class="certloom-metric-icon is-expired">■</span><span class="certloom-metric-label">Expired</span><strong>20</strong></a></section>
<section class="certloom-panel"><div class="certloom-panel-header"><h2>Recent credentials</h2></div><p>Ledger content</p></section>
<nav class="certloom-dashboard-quick-grid"><a><span class="dashicons">□</span><strong>Programs</strong></a><a><span class="dashicons">□</span><strong>Design</strong></a><a><span class="dashicons">□</span><strong>Analytics</strong></a></nav>
<div class="certloom-dashboard-analytics-grid"><article><span>Issued</span><strong>126</strong></article><article><span>Lookups</span><strong>342</strong></article><article><span>Failed</span><strong>8</strong></article><article><span>Expiring</span><strong>12</strong></article></div>
<div class="certloom-dashboard-mini-chart is-issuance"><span style="height:60%"></span></div>
<div class="certloom-dashboard-mini-chart is-verification"><span style="height:50%"></span></div>
<div class="certloom-analytics-chart is-issuance"><span class="certloom-analytics-bar"></span></div>
<div class="certloom-analytics-chart is-verification"><span class="certloom-analytics-bar"></span></div>
<svg class="certloom-series-fixture" width="100" height="40"><path class="certloom-chart-series" d="M0 5L100 5"></path><path class="certloom-chart-series" d="M0 10L100 10"></path><path class="certloom-chart-series" d="M0 15L100 15"></path><path class="certloom-chart-series" d="M0 20L100 20"></path><path class="certloom-chart-series" d="M0 25L100 25"></path></svg>
<div class="certloom-setup-reminder"><span class="dashicons">●</span><strong>Setup: 3 of 6 checks complete</strong><a>Review setup</a></div>
<div class="ecapla-certverify-progress"><span style="width:60%"></span></div>
<nav class="certloom-domain-views"><a class="is-current">Programs</a></nav>
<span class="ecapla-certverify-feature-icon">◆</span>
<span class="certloom-credential-source is-credential">Credential</span>
<article class="certloom-settings-info-card"><span class="dashicons">i</span><div><h3>Information</h3><p>General setup guidance.</p></div></article>
</div>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell" id="header-family"><section class="certloom-page-hero"><div><span class="certloom-eyebrow">Registry</span><h1>Credentials</h1><p>Authoritative records.</p></div><div class="certloom-page-actions"><a class="button button-primary">Issue</a></div></section><section class="certloom-workspace-hero"><div><span class="certloom-eyebrow">Rules</span><h1>Automations</h1><p>Credential delivery rules.</p></div><div class="certloom-workspace-hero-actions"><a class="button">Manage</a></div></section><section class="certloom-credential-hero"><div><h1>Credential record</h1><p>Current credential.</p></div><div class="certloom-credential-hero-actions"><a class="button">View</a></div></section><section class="certloom-manual-card"><h2>Manual section</h2><p>Content padding remains.</p></section><div class="certloom-editor-actions"><button class="button">Cancel</button><button class="button button-primary">Save</button></div></div>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-email-designs" id="email-surface"><header class="certloom-email-hero"><div><p class="certloom-eyebrow">Recipient communication</p><h1>Email designs</h1><p class="certloom-email-lede">Create credential messages.</p></div><div class="certloom-email-hero__actions"><a class="button button-primary">New template</a></div></header><div class="certloom-email-studio-toolbar"><div class="certloom-email-studio-toolbar__identity"><strong>Email Designer</strong></div><button class="button">Preview</button></div></div>
</main></body></html>
