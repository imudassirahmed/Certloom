<?php
/** POR-01 authenticated Recipient Portal ownership/privacy/authority gate. */
define( 'ABSPATH', __DIR__ . '/webroot/' );
function __( $v ){ return $v; }
function is_wp_error( $v ){ return $v instanceof WP_Error; }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $c; private $m;
		public function __construct( $c = '', $m = '' ){ $this->c=$c; $this->m=$m; }
		public function get_error_code(){ return $this->c; }
		public function get_error_message(){ return $this->m; }
	}
}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\VisibilityPolicy;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;

$pass=0; $fail=0;
function por( $ok, $label ) { global $pass,$fail; if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";} }
function src( $path ){ return file_get_contents( dirname(__DIR__) . '/' . $path ); }

$visibility = new VisibilityPolicy();
por( array( 'public','verification_summary','unlisted','recipient_only' ) === VisibilityPolicy::recipient_portal_values(), 'portal visibility allow-list is explicit and minimal' );
por( $visibility->allows_recipient_portal( 'recipient_only' ), 'owning Recipient may see recipient_only credentials' );
por( ! $visibility->allows_recipient_portal( 'organization_member' ), 'organization_member fails closed until membership authorization exists' );
por( ! $visibility->allows_recipient_portal( 'private' ), 'private remains administrator-only' );
por( $visibility->allows_guest_verification( 'public' ) && ! $visibility->allows_guest_verification( 'recipient_only' ), 'portal access is distinct from guest verification visibility' );
por( $visibility->allows_public_sharing( 'public' ) && $visibility->allows_public_sharing( 'unlisted' ) && ! $visibility->allows_public_sharing( 'recipient_only' ), 'public sharing is never broader than guest verification' );

$status = new StatusService();
$base = array( 'issuance_state'=>States::ISSUANCE_ISSUED, 'administrative_state'=>States::ADMIN_ACTIVE, 'compliance_state'=>States::COMPLIANCE_COMPLIANT, 'valid_from'=>'2026-01-01', 'expires_at'=>'2026-09-15' );
$r = $status->evaluate( $base, '2026-08-27' );
por( $r->is_valid() && 'valid' === $r->code(), 'portal status reuses canonical StatusService truth' );
$rev=$base; $rev['administrative_state']=States::ADMIN_REVOKED; por( 'revoked' === $status->evaluate($rev,'2026-08-27')->code(), 'revoked status remains canonical' );
$exp=$base; $exp['expires_at']='2026-08-26'; por( 'expired' === $status->evaluate($exp,'2026-08-27')->code(), 'expiry is date-derived at read time' );

$recipient_repo = src( 'src/Infrastructure/Repository/RecipientRepository.php' );
por( false !== strpos( $recipient_repo, 'find_by_wp_user_id' ) && false !== strpos( $recipient_repo, 'WHERE wp_user_id = %d LIMIT 1' ), 'Recipient ownership resolves by exact linked wp_user_id' );
$portal_service = src( 'src/Application/Recipient/RecipientPortalService.php' );
por( false !== strpos( $portal_service, 'find_by_wp_user_id( $wp_user_id )' ), 'Portal service uses WordPress-user ownership bridge' );
por( false === strpos( $portal_service, 'find_by_email' ) && false === strpos( $portal_service, 'external_id' ), 'Portal ownership never falls back to email/name/external ID inference' );
por( false !== strpos( $portal_service, 'certloom_recipient_portal_login_required' ), 'logged-out access fails closed' );
por( false !== strpos( $portal_service, 'certloom_recipient_portal_authority_closed' ), 'normalized authority closure is explicit' );
por( false !== strpos( $portal_service, 'DomainFeatureGate::admin_preview_ready()' ) && false !== strpos( $portal_service, 'verification_gate->is_ready()' ), 'development preview remains distinct from accepted public authority' );
por( false !== strpos( $portal_service, "'pdf_available'] = false" ) && false !== strpos( $portal_service, "'image_available'] = false" ), 'download/image surfaces remain fail-closed behind renderer authority' );
por( false !== strpos( $portal_service, "'grade' !== sanitize_key" ), 'Portal avoids repeating Grade inside generic FLD-02 credential details because Grade is already a first-class fact' );
por( false !== strpos( $portal_service, 'allows_public_sharing' ) && false !== strpos( $portal_service, "'share_available'] = ! empty" ), 'SOC-01 sharing is derived only from an already-authoritative guest-verifiable URL' );
por( false !== strpos( $portal_service, 'EXPIRING_DAYS    = 30' ) && false !== strpos( $portal_service, 'expiring_soon' ), '30-day expiring-soon cue is explicit' );
por( false !== strpos( $portal_service, "array( 'all', 'valid', 'suspended', 'revoked', 'expired', 'not_yet_valid', 'pending_review', 'noncompliant' )" ), 'status-filter allow-list is bounded' );
por( false !== strpos( $portal_service, "array( 'newest', 'oldest', 'expires_soon', 'program' )" ), 'sort allow-list is bounded' );
por( false === strpos( $portal_service, "'email' =>" ), 'Portal view model does not return Recipient email' );

$credential_repo = src( 'src/Infrastructure/Repository/CredentialRepository.php' );
por( false !== strpos( $credential_repo, 'list_recipient_portal_page' ), 'Credential repository exposes dedicated recipient-portal query' );
por( false !== strpos( $credential_repo, 's.recipient_id = %d' ) || false !== strpos( $credential_repo, 's.recipient_id=%d' ), 'portal query is bound to exact Recipient ownership' );
por( false !== strpos( $credential_repo, 'v.issuance_state = %s' ) || false !== strpos( $credential_repo, 'v.issuance_state=%s' ), 'portal query returns issued credential Versions only' );
por( false !== strpos( $credential_repo, 'VisibilityPolicy::recipient_portal_values()' ), 'repository applies central visibility policy before rows leave persistence' );
por( false !== strpos( $credential_repo, 'management_status_predicate' ), 'portal SQL status filters reuse Credential Management predicates' );
por( false !== strpos( $credential_repo, "'newest'" ) && false !== strpos( $credential_repo, "'expires_soon'" ) && false !== strpos( $credential_repo, "'program'" ), 'portal sort SQL is selected from hard-coded choices' );

$shortcode = src( 'includes/class-ecapla-certverify-shortcode.php' );
por( false !== strpos( $shortcode, "add_shortcode( 'certloom_my_credentials'" ), 'canonical My Credentials shortcode is registered' );
por( false !== strpos( $shortcode, "add_shortcode( 'certloom_my_certificates'" ), 'original roadmap shortcode retained as compatibility alias' );
por( false !== strpos( $shortcode, 'Ecapla_CertVerify_Recipient_Portal::render' ), 'both shortcodes delegate to one authoritative renderer' );
$block_json = json_decode( src( 'blocks/my-credentials/block.json' ), true );
por( is_array($block_json) && 'certloom/my-credentials' === ($block_json['name']??''), 'native My Credentials Gutenberg block exists' );
por( false !== strpos( src('includes/class-ecapla-certverify-block.php'), "blocks/my-credentials" ) && false !== strpos( src('includes/class-ecapla-certverify-block.php'), 'render_my_credentials' ), 'dynamic block is server-rendered through the same portal surface' );

$renderer = src( 'includes/class-ecapla-certverify-recipient-portal.php' );
por( false !== strpos( $renderer, "array( 'cards', 'grid', 'table' )" ), 'cards/grid/table presentation is bounded to POR-01 layouts' );
por( false !== strpos( $renderer, 'wp_login_url' ) && false !== strpos( $renderer, 'is_user_logged_in' ), 'logged-out renderer offers sign-in instead of leaking data' );
por( false !== strpos( $renderer, 'Downloads gated' ) && false !== strpos( $renderer, 'verify_url' ) && false !== strpos( $renderer, 'share_controls' ), 'actions remain authority-aware and add SOC-01 sharing without opening downloads' );
por( false !== strpos( $renderer, 'Expiring soon' ), 'expiring-soon cue is rendered' );
por( false !== strpos( $renderer, 'role="region"' ) && false !== strpos( $renderer, 'tabindex="0"' ), 'responsive table is keyboard-focusable as a named scroll region' );
por( false !== strpos( $renderer, 'private static $instance_counter = 0' ) && false !== strpos( $renderer, "'certloom-my-status-' . absint( \$instance_id )" ) && false !== strpos( $renderer, "'certloom-my-sort-' . absint( \$instance_id )" ), 'multiple My Credentials shortcodes keep heading and filter control IDs unique' );
$public_js = src( 'public/assets/certloom-public.js' );
por( false !== strpos( $public_js, "button.closest( '.certloom-share, .cv-result-actions" ) && false !== strpos( $public_js, "local.querySelector( '.certloom-action-feedback' )" ), 'copy/share feedback stays local to the credential action that produced it' );

$css = src( 'public/assets/certloom-public.css' );
por( false !== strpos( $css, '.certloom-my-credentials' ) && false !== strpos( $css, '@media (prefers-reduced-motion:reduce)' ), 'Portal CSS includes reduced-motion support' );
por( false !== strpos( $css, '@media (forced-colors:active)' ), 'Portal CSS includes forced-colors support' );
$main = src( 'certloom.php' );
por( false !== strpos( $main, "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false" ) && false !== strpos( $main, "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false" ) && false !== strpos( $main, "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false" ), 'all normalized-domain authority defaults remain closed' );
por( ! file_exists( dirname(__DIR__) . '/resources/renderer/accepted.json' ), 'renderer accepted manifest remains absent' );

por( ! preg_match( '/function\s+[a-z0-9_]*(?:directory|claim)[a-z0-9_]*\s*\(/i', $portal_service ) && false === strpos( $portal_service, "add_shortcode( 'certloom_directory'" ) && false === strpos( $portal_service, "add_shortcode( 'certloom_claim'" ), 'POR-01 service does not implement public directory or claim workflow' );

echo "\n{$pass} Recipient Portal checks, {$fail} failures\n";
exit( $fail ? 1 : 0 );
