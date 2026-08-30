<?php
/** SOC-01 public sharing privacy, URL and architecture acceptance. */
define( 'ABSPATH', __DIR__ . '/webroot/' );
function __( $v ){ return $v; }
function sanitize_key( $v ){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function sanitize_text_field( $v ){ return trim(strip_tags((string)$v)); }
require_once __DIR__ . '/bootstrap-autoload.php';
require_once dirname(__DIR__) . '/src/Application/Sharing/ShareLinkBuilder.php';

use Certloom\Application\Sharing\ShareLinkBuilder;
use Certloom\Application\VisibilityPolicy;

$pass=0;$fail=0;
function soc( $ok, $label ){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
function source_file( $path ){ return file_get_contents(dirname(__DIR__).'/'.$path); }

$b = new ShareLinkBuilder();
soc( $b->is_shareable_url('https://example.test/certloom/credential/ABC/'), 'HTTPS canonical credential URL is shareable' );
soc( $b->is_shareable_url('http://localhost/certloom/verify/ABC/'), 'HTTP local/staging URL remains testable' );
soc( ! $b->is_shareable_url('javascript:alert(1)'), 'javascript URL fails closed' );
soc( ! $b->is_shareable_url('mailto:test@example.test'), 'input canonical URL cannot be mailto' );
soc( ! $b->is_shareable_url("https://example.test/\nattack"), 'control characters fail closed' );
soc( ! $b->is_shareable_url('/relative/path'), 'relative URL fails closed' );
$links=$b->links('https://example.test/certloom/credential/ABC/','View this verified credential','Verified credential');
soc( count($links)===5, 'exact five external share destinations are generated' );
soc( array_column($links,'platform')===array('linkedin','whatsapp','facebook','x','email'), 'platform order is deterministic' );
$by=[];foreach($links as $link){$by[$link['platform']]=$link['url'];}
soc( 0===strpos($by['linkedin'],'https://www.linkedin.com/sharing/share-offsite/?url='), 'LinkedIn share-offsite URL is local link generation only' );
soc( 0===strpos($by['whatsapp'],'https://wa.me/?text='), 'WhatsApp share URL is generated' );
soc( 0===strpos($by['facebook'],'https://www.facebook.com/sharer/sharer.php?u='), 'Facebook sharer URL is generated' );
soc( 0===strpos($by['x'],'https://x.com/intent/tweet?'), 'X intent URL is generated' );
soc( 0===strpos($by['email'],'mailto:?subject='), 'email share uses local mailto intent' );
soc( false!==strpos($by['linkedin'],rawurlencode('https://example.test/certloom/credential/ABC/')), 'canonical URL is percent-encoded in provider query' );
soc( false===strpos(implode('\n',$by),'recipient@example.test'), 'builder does not invent or leak recipient email' );
soc( $b->links('javascript:alert(1)')===array(), 'invalid canonical URL produces no share links' );

$v=new VisibilityPolicy();
soc( $v->allows_public_sharing('public'), 'public credential may be shared' );
soc( $v->allows_public_sharing('verification_summary'), 'verification-summary credential may share verification URL' );
soc( $v->allows_public_sharing('unlisted'), 'unlisted credential may share possessed canonical URL' );
soc( ! $v->allows_public_sharing('recipient_only'), 'recipient-only credential is never publicly shared' );
soc( ! $v->allows_public_sharing('organization_member'), 'organization-member credential is never publicly shared' );
soc( ! $v->allows_public_sharing('private'), 'private credential is never publicly shared' );

$policy=source_file('includes/class-ecapla-certverify-public-policy.php');
$render=source_file('includes/class-ecapla-certverify-render.php');
$js=source_file('public/assets/certloom-public.js');
$css=source_file('public/assets/certloom-public.css');
$portal=source_file('src/Application/Recipient/RecipientPortalService.php');
$portal_ui=source_file('includes/class-ecapla-certverify-recipient-portal.php');
$main=source_file('certloom.php');
soc( false!==strpos($policy,"'share' === \$action") || false!==strpos($policy,"'share' === $action"), 'legacy public policy explicitly authorizes share as a server-owned action' );
soc( false!==strpos($policy,'ShareLinkBuilder'), 'legacy sharing delegates to the deterministic ShareLinkBuilder' );
soc( false!==strpos($policy, "\$share_url = '' !== (string) \$profile_url ? (string) \$profile_url : (string) \$verification_url;"), 'public profile URL is preferred and verification URL is the safe fallback' );
soc( false!==strpos($render,"array( 'copy', 'profile', 'print', 'share' )"), 'server renderer allow-lists share action explicitly' );
soc( false!==strpos($render,'nofollow noopener noreferrer'), 'server social links use defensive external-link rel attributes' );
soc( false!==strpos($render,"array( 'http', 'https', 'mailto' )"), 'server share URL escaping uses a narrow protocol allow-list' );
soc( false!==strpos($js,'safeShareUrl') && false!==strpos($js,'linkedin:') && false!==strpos($js,'whatsapp:'), 'REST/browser rendering revalidates provider hosts before inserting links' );
soc( false!==strpos($js,".certloom-my-credentials'"), 'copy feedback works inside Recipient Portal as well as verifier/profile widgets' );
soc( false!==strpos($css,'.certloom-share-menu') && false!==strpos($css,'@media (forced-colors:active)'), 'sharing disclosure has responsive and forced-colors styling' );
soc( false!==strpos($portal,'allows_public_sharing') && false!==strpos($portal,"'share_url']"), 'Recipient Portal sharing is derived from guest-verifiable normalized URLs only' );
soc( false!==strpos($portal_ui,'share_controls') && false!==strpos($portal_ui,'Copy URL'), 'Recipient Portal renders SOC-01 platform and copy controls' );
soc( false===strpos($portal_ui,'recipient_only') && false===strpos($portal_ui,'organization_member'), 'browser renderer does not make independent visibility decisions' );
soc( false!==strpos($main,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false") && false!==strpos($main,"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false"), 'normalized authority defaults remain closed' );
soc( ! file_exists(dirname(__DIR__).'/resources/renderer/accepted.json'), 'SOC-01 does not open renderer authority or OG-image generation' );
soc( false===strpos($policy,'OpenGraph') && false===strpos($policy,'og:image'), 'SOC-01 does not silently absorb separate OG-01 work' );

echo "\n{$pass} SOC-01 checks, {$fail} failures\n";
exit($fail?1:0);
