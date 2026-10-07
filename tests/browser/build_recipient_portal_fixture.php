<?php
/** Browser-only POR-01 Recipient Portal layout fixture. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$state = getenv( 'CERTLOOM_RECIPIENT_PORTAL_STATE' );
$allowed = array( 'cards', 'grid', 'table', 'logged_out', 'unlinked', 'authority_ready', 'empty' );
if ( ! in_array( $state, $allowed, true ) ) { $state = 'cards'; }
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' ) . "\n" . file_get_contents( dirname( __DIR__, 2 ) . '/public/assets/certloom-public.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/public/assets/certloom-public.js' );
function rph( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function rp_card( $program, $number, $status, $expiring = false, $verify = false, $share_url = '' ) {
    $labels = array( 'valid' => 'Valid', 'revoked' => 'Revoked', 'expired' => 'Expired' );
    echo '<article class="certloom-my-credential-card" role="listitem">';
    echo '<div class="certloom-my-credential-card__topline"><span class="certloom-my-credential-card__status certloom-my-credential-card__status--'.rph($status).'">'.rph($labels[$status] ?? 'Unavailable').'</span>';
    if ( $expiring ) { echo '<span class="certloom-my-credential-card__expiring">Expiring soon</span>'; }
    echo '</div><h3>'.rph($program).'</h3><p class="certloom-my-credential-card__issuer">Northstar Learning Institute</p>';
    echo '<dl class="certloom-my-credential-card__facts"><div><dt>Certificate</dt><dd>'.rph($number).'</dd></div><div><dt>Issued</dt><dd>2026-08-20</dd></div><div><dt>Expires</dt><dd>'.($expiring?'2026-09-10':'2027-08-20').'</dd></div><div><dt>Grade</dt><dd>Distinction</dd></div></dl>';
    echo '<div class="certloom-my-credential-card__credential-fields" aria-label="Credential details"><h4>Credential details</h4><dl><div><dt>GPA</dt><dd>3.82</dd></div><div><dt>Campus</dt><dd>Karachi Executive Learning Campus — North Building</dd></div></dl></div>';
    echo '<div class="certloom-my-credential-card__actions">';
    if ( $verify ) { echo '<a class="certloom-my-credentials__button" href="#verify" rel="nofollow noopener">Verify</a>'; }
    if ( '' !== $share_url ) { echo '<details class="certloom-share certloom-my-credentials__share"><summary class="certloom-my-credentials__button">Share</summary><div class="certloom-share-menu"><button type="button" class="certloom-share-link certloom-share-copy certloom-copy-link" data-copy-url="'.rph($share_url).'">Copy URL</button><span class="certloom-action-feedback" aria-live="polite"></span></div></details>'; }
    echo '<span class="certloom-my-credentials__action-muted" title="PDF and image downloads remain gated until production artifact authority is accepted.">Downloads gated</span></div></article>';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;padding:32px;background:#f8fafc;color:#172033;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.fixture-shell{max-width:1080px;margin:0 auto}.fixture-motion{transition:opacity 2s ease}.certloom-my-credentials__button{transition:opacity 2s ease}
<?php echo $css; ?>
</style></head><body><main class="fixture-shell"><div class="fixture-motion">
<?php if ( 'logged_out' === $state ) : ?>
<div class="certloom-my-credentials certloom-my-credentials__notice" role="status"><p>Sign in to view your credentials.</p><a class="certloom-my-credentials__button" href="#login">Sign in</a></div>
<?php elseif ( 'unlinked' === $state ) : ?>
<div class="certloom-my-credentials certloom-my-credentials__notice" role="status"><p>Your WordPress account is not linked to a Certloom recipient yet. Contact the credential issuer if you expected credentials here.</p></div>
<?php else :
$layout = in_array( $state, array('grid','table'), true ) ? $state : 'cards';
$verify = 'authority_ready' === $state;
$items = 'empty' === $state ? 0 : 2;
?>
<section class="certloom-my-credentials certloom-my-credentials--<?php echo rph($layout); ?>" aria-labelledby="certloom-my-credentials-heading" data-copy-success-text="Credential link copied." data-copy-error-text="Copy the credential link from your browser.">
<header class="certloom-my-credentials__header"><div><p class="certloom-my-credentials__eyebrow">Recipient portal</p><h2 id="certloom-my-credentials-heading" class="certloom-my-credentials__heading">My Credentials</h2><p class="certloom-my-credentials__intro">Credentials linked to Alexandria Noor Rahman.</p></div><div class="certloom-my-credentials__count" aria-label="<?php echo $items; ?> credentials"><strong><?php echo $items; ?></strong><span>credentials</span></div></header>
<form class="certloom-my-credentials__filters" method="get"><div><label for="certloom-my-status">Status</label><select id="certloom-my-status" name="certloom_my_status"><option>All statuses</option><option>Valid</option><option>Expired</option><option>Revoked</option><option>Suspended</option></select></div><div><label for="certloom-my-sort">Sort</label><select id="certloom-my-sort" name="certloom_my_sort"><option>Newest issued</option><option>Oldest issued</option><option>Expiring soon</option><option>Program name</option></select></div><button type="submit" class="certloom-my-credentials__button">Apply</button></form>
<?php if ( 'empty' === $state ) : ?>
<div class="certloom-my-credentials__empty" role="status"><strong>No credentials match this view.</strong><p>Try another status filter, or contact the issuer if you expected a credential here.</p></div>
<?php elseif ( 'table' === $layout ) : ?>
<div class="certloom-my-credentials__table-wrap" tabindex="0" role="region" aria-label="My credentials table"><table class="certloom-my-credentials__table"><thead><tr><th scope="col">Credential</th><th scope="col">Status</th><th scope="col">Issued</th><th scope="col">Expires</th><th scope="col">Action</th></tr></thead><tbody><tr><th scope="row"><strong>Premium Compliance Program</strong><small>CERT-0102</small></th><td><span class="certloom-my-credential-card__status certloom-my-credential-card__status--valid">Valid</span><small>Expiring soon</small></td><td>2026-08-20</td><td>2026-09-10</td><td><?php echo $verify?'<a href="#verify" rel="nofollow noopener">Verify</a>':'<span aria-label="Verification unavailable">—</span>'; ?></td></tr><tr><th scope="row"><strong>Leadership Essentials</strong><small>CERT-0097</small></th><td><span class="certloom-my-credential-card__status certloom-my-credential-card__status--revoked">Revoked</span></td><td>2026-06-12</td><td>2027-06-12</td><td><span aria-label="Verification unavailable">—</span></td></tr></tbody></table></div>
<?php else : ?><div class="certloom-my-credentials__collection" role="list"><?php rp_card('Premium Compliance Program','CERT-0102','valid',true,$verify,$verify?'https://example.test/credential/one':''); rp_card('Leadership Essentials','CERT-0097','revoked',false,$verify,$verify?'https://example.test/credential/two':''); ?></div><?php endif; ?>
<nav class="certloom-my-credentials__pagination" aria-label="Credential pages"><span>Page 1 of 2</span><a rel="next" href="#page2">Next</a></nav>
<footer class="certloom-my-credentials__authority-note"><?php echo $verify ? 'Credential status is evaluated live from the current immutable Version and lifecycle state.' : 'Public verification actions remain unavailable until normalized verification authority is accepted.'; ?></footer>
</section>
<?php endif; ?>
</div></main><script><?php echo $js; ?></script></body></html>
