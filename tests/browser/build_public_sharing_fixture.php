<?php
/** Browser-only SOC-01 sharing fixture using the real ShareLinkBuilder and production CSS/JS. */
define( 'ABSPATH', __DIR__ . '/webroot/' );
require_once dirname( __DIR__, 2 ) . '/src/Application/Sharing/ShareLinkBuilder.php';
use Certloom\Application\Sharing\ShareLinkBuilder;
$state = getenv( 'CERTLOOM_SOC_STATE' );
$allowed = array( 'public', 'summary', 'private', 'portal_ready', 'portal_closed' );
if ( ! in_array( $state, $allowed, true ) ) { $state = 'public'; }
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' ) . "\n" . file_get_contents( dirname( __DIR__, 2 ) . '/public/assets/certloom-public.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/public/assets/certloom-public.js' );
function sh( $v ){ return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function share_menu( $url ) {
    $b = new ShareLinkBuilder();
    $links = $b->links( $url, 'View this verified credential', 'Verified credential' );
    if ( ! $links ) { return ''; }
    $labels = array( 'linkedin'=>'LinkedIn','whatsapp'=>'WhatsApp','facebook'=>'Facebook','x'=>'X','email'=>'Email' );
    $html = '<details class="certloom-share"><summary class="certloom-result-action">Share</summary><div class="certloom-share-menu">';
    foreach ( $links as $link ) {
        $platform = $link['platform']; $external = 'email' === $platform ? '' : ' target="_blank" rel="nofollow noopener noreferrer"';
        $html .= '<a class="certloom-share-link certloom-share-link--'.sh($platform).'" data-platform="'.sh($platform).'" href="'.sh($link['url']).'"'.$external.'>'.sh($labels[$platform]).'</a>';
    }
    $html .= '<button type="button" class="certloom-share-link certloom-share-copy certloom-copy-link" data-copy-url="'.sh($url).'">Copy URL</button></div></details>';
    return $html;
}
$public_url = 'https://credentials.example.test/certloom/credential/CERT-168/';
$verify_url = 'https://credentials.example.test/certloom/verify/CERT-168/';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;padding:32px;background:#f8fafc;color:#172033;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.fixture-shell{max-width:920px;margin:0 auto}.certloom-result-action{display:inline-flex;padding:10px 14px;border:1px solid #64748b;border-radius:8px;background:#fff;color:#172033}.certloom-my-credentials__button{display:inline-flex;padding:10px 14px;border:1px solid #64748b;border-radius:8px}.certloom-share-menu{transition:opacity 2s ease}
<?php echo $css; ?>
</style></head><body><main class="fixture-shell">
<?php if ( 'private' === $state ) : ?>
<section class="cvp-widget"><p class="cvp-result-notfound">This credential could not be found or is not available.</p></section>
<?php elseif ( 'portal_closed' === $state ) : ?>
<section class="certloom-my-credentials" data-copy-success-text="Credential link copied." data-copy-error-text="Copy the credential link from your browser."><article class="certloom-my-credential-card"><h2>Private Recipient Credential</h2><div class="certloom-my-credential-card__actions"><span class="certloom-my-credentials__action-muted">Sharing unavailable</span></div></article></section>
<?php elseif ( 'portal_ready' === $state ) : ?>
<section class="certloom-my-credentials" data-copy-success-text="Credential link copied." data-copy-error-text="Copy the credential link from your browser."><article class="certloom-my-credential-card"><h2>Public Recipient Credential</h2><div class="certloom-my-credential-card__actions"><a class="certloom-my-credentials__button" href="<?php echo sh($verify_url); ?>">Verify</a><?php echo share_menu($verify_url); ?><span class="certloom-action-feedback" aria-live="polite"></span></div></article></section>
<?php else : $url = 'summary' === $state ? $verify_url : $public_url; ?>
<section class="cvp-widget" data-copy-success-text="Verification link copied." data-copy-error-text="Copy the verification link from your browser."><div class="cvp-result-card"><h1>Verified credential</h1><p>Northstar Learning Institute</p><div class="cvp-result-actions"><?php echo share_menu($url); ?><span class="certloom-action-feedback" aria-live="polite"></span></div></div></section>
<?php endif; ?>
</main><script><?php echo $js; ?></script></body></html>
