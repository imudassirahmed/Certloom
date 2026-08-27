<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\VisibilityPolicy;
use Certloom\Application\Sharing\ShareLinkBuilder;
use Certloom\Domain\Credential\StatusService;
use Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Verification\PublicVerificationGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated POR-01 My Credentials read model.
 *
 * Ownership is resolved only through Recipient.wp_user_id. The service never
 * infers identity from recipient email, display name, external ID, URL data or
 * request-supplied Recipient IDs. Public-directory and claim semantics are
 * intentionally outside this boundary.
 */
final class RecipientPortalService {
	const PER_PAGE_DEFAULT = 12;
	const PER_PAGE_MAX     = 24;
	const EXPIRING_DAYS    = 30;

	/** @var RecipientRepository */
	private $recipients;
	/** @var CredentialRepository */
	private $credentials;
	/** @var StatusService */
	private $status;
	/** @var VisibilityPolicy */
	private $visibility;
	/** @var PublicVerificationGate */
	private $verification_gate;
	/** @var NormalizedPublicCredentialUrl */
	private $public_urls;
	/** @var ShareLinkBuilder */
	private $share_links;

	public function __construct(
		?RecipientRepository $recipients = null,
		?CredentialRepository $credentials = null,
		?StatusService $status = null,
		?VisibilityPolicy $visibility = null,
		?PublicVerificationGate $verification_gate = null,
		?NormalizedPublicCredentialUrl $public_urls = null,
		?ShareLinkBuilder $share_links = null
	) {
		$this->recipients         = $recipients ?: new RecipientRepository();
		$this->credentials        = $credentials ?: new CredentialRepository();
		$this->status             = $status ?: new StatusService();
		$this->visibility         = $visibility ?: new VisibilityPolicy();
		$this->verification_gate  = $verification_gate ?: new PublicVerificationGate();
		$this->public_urls        = $public_urls ?: new NormalizedPublicCredentialUrl();
		$this->share_links        = $share_links ?: new ShareLinkBuilder();
	}

	/**
	 * Runtime availability stays fail-closed until accepted normalized authority.
	 * The existing domain admin-preview gate is accepted only as a development /
	 * staging preview mechanism and never creates public verification authority.
	 */
	public function runtime_available() {
		return $this->verification_gate->is_ready()
			|| ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && true === CERTLOOM_DOMAIN_ADMIN_PREVIEW );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function load_for_user( $wp_user_id, array $query = array(), $today = null ) {
		$wp_user_id = absint( $wp_user_id );
		if ( ! $wp_user_id ) {
			return new \WP_Error( 'certloom_recipient_portal_login_required', __( 'Sign in to view your Certloom credentials.', 'certloom' ) );
		}
		if ( ! $this->runtime_available() ) {
			return new \WP_Error( 'certloom_recipient_portal_authority_closed', __( 'My Credentials is not available until Certloom’s normalized credential authority has completed its acceptance gate.', 'certloom' ) );
		}

		$recipient = $this->recipients->find_by_wp_user_id( $wp_user_id );
		if ( is_wp_error( $recipient ) ) { return $recipient; }
		if ( ! is_array( $recipient ) ) {
			return array(
				'linked'            => false,
				'recipient'         => null,
				'items'             => array(),
				'total'             => 0,
				'page'              => 1,
				'pages'             => 0,
				'per_page'          => self::PER_PAGE_DEFAULT,
				'status_filter'     => 'all',
				'sort'              => 'newest',
				'public_verify_ready' => $this->verification_gate->is_ready(),
			);
		}

		$page     = max( 1, absint( $query['page'] ?? 1 ) );
		$per_page = max( 1, min( self::PER_PAGE_MAX, absint( $query['per_page'] ?? self::PER_PAGE_DEFAULT ) ) );
		$status   = sanitize_key( (string) ( $query['status'] ?? 'all' ) );
		$sort     = sanitize_key( (string) ( $query['sort'] ?? 'newest' ) );
		$allowed_statuses = array( 'all', 'valid', 'suspended', 'revoked', 'expired', 'not_yet_valid', 'pending_review', 'noncompliant' );
		$allowed_sorts    = array( 'newest', 'oldest', 'expires_soon', 'program' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) { $status = 'all'; }
		if ( ! in_array( $sort, $allowed_sorts, true ) ) { $sort = 'newest'; }

		$result = $this->credentials->list_recipient_portal_page( $recipient['id'], $page, $per_page, $status, $sort );
		if ( is_wp_error( $result ) ) { return $result; }
		$total = absint( $result['total'] ?? 0 );
		$pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		if ( $pages > 0 && $page > $pages ) {
			$page = $pages;
			$result = $this->credentials->list_recipient_portal_page( $recipient['id'], $page, $per_page, $status, $sort );
			if ( is_wp_error( $result ) ) { return $result; }
		}

		$today = null === $today ? gmdate( 'Y-m-d' ) : (string) $today;
		$public_verify_ready = $this->verification_gate->is_ready();
		$items = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! $this->visibility->allows_recipient_portal( $row['visibility'] ?? '' ) ) {
				/* Defense in depth: repository and policy must agree before exposure. */
				continue;
			}
			try {
				$status_result = $this->status->evaluate(
					array(
						'issuance_state'       => (string) ( $row['issuance_state'] ?? '' ),
						'administrative_state' => (string) ( $row['administrative_state'] ?? '' ),
						'legacy_expired'       => ! empty( $row['legacy_expired'] ),
						'valid_from'           => $row['valid_from'] ?? null,
						'expires_at'           => $row['expires_at'] ?? null,
						'compliance_state'     => (string) ( $row['compliance_state'] ?? '' ),
					),
					$today
				);
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'certloom_recipient_portal_state_invalid', __( 'One of your credentials has invalid lifecycle state and cannot be displayed safely.', 'certloom' ) );
			}

			$verify_url = '';
			if ( $public_verify_ready && $this->visibility->allows_guest_verification( $row['visibility'] ?? '' ) ) {
				$url = $this->public_urls->verification_url( $row['public_id'] ?? '' );
				if ( is_wp_error( $url ) ) { return $url; }
				$verify_url = (string) $url;
			}

			$row['status'] = $status_result->to_array();
			$row['expiring_soon'] = self::expiring_soon( $row['expires_at'] ?? null, $today, $status_result->is_valid() );
			$row['verify_url'] = $verify_url;
			$row['pdf_available'] = false;
			$row['image_available'] = false;
			$row['share_available'] = false;
			$row['share_url'] = '';
			$row['share_links'] = array();
			if ( '' !== $verify_url && $this->visibility->allows_public_sharing( $row['visibility'] ?? '' ) ) {
				$row['share_links'] = $this->share_links->links(
					$verify_url,
					__( 'View this verified credential', 'certloom' ),
					__( 'Verified credential', 'certloom' )
				);
				$row['share_available'] = ! empty( $row['share_links'] );
				$row['share_url'] = $row['share_available'] ? $verify_url : '';
			}
			$items[] = $row;
		}

		return array(
			'linked'              => true,
			'recipient'           => array(
				'id'           => absint( $recipient['id'] ?? 0 ),
				'display_name' => (string) ( $recipient['display_name'] ?? '' ),
			),
			'items'               => $items,
			'total'               => $total,
			'page'                => $page,
			'pages'               => $pages,
			'per_page'            => $per_page,
			'status_filter'       => $status,
			'sort'                => $sort,
			'public_verify_ready' => $public_verify_ready,
		);
	}

	/** @return bool */
	private static function expiring_soon( $expires_at, $today, $valid ) {
		if ( ! $valid ) { return false; }
		$expires_at = trim( (string) $expires_at );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $today ) ) {
			return false;
		}
		try {
			$start = new \DateTimeImmutable( (string) $today . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
			$end   = new \DateTimeImmutable( $expires_at . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $error ) {
			return false;
		}
		$days = (int) $start->diff( $end )->format( '%r%a' );
		return $days >= 0 && $days <= self::EXPIRING_DAYS;
	}
}
