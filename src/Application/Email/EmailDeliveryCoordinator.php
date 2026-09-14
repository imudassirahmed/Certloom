<?php
namespace Certloom\Application\Email;

use Certloom\Application\Contract\EmailDeliveryRepositoryInterface;
use Certloom\Application\Job\JobQueue;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Email\EmailEnvelopeStore;
use Certloom\Infrastructure\Email\EmailDesignAssignmentRepository;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Email\EmailTemplateSettingsRepository;
use Certloom\Infrastructure\Repository\EmailDeliveryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Review/queue boundary for explicit and automated credential email delivery. */
final class EmailDeliveryCoordinator {
	/** @var JobQueue */
	private $queue;
	/** @var EmailDeliveryRepositoryInterface */
	private $repository;
	/** @var EmailEnvelopeStore */
	private $envelopes;
	/** @var EmailTemplateService */
	private $templates;
	/** @var EmailTemplateSettingsRepository */
	private $settings;
	/** @var EmailTemplateCatalogRepository */
	private $catalog;
	/** @var EmailDesignAssignmentRepository */
	private $assignments;

	public function __construct(
		JobQueue $queue,
		?EmailDeliveryRepositoryInterface $repository = null,
		?EmailEnvelopeStore $envelopes = null,
		?EmailTemplateService $templates = null,
		?EmailTemplateSettingsRepository $settings = null,
		?EmailTemplateCatalogRepository $catalog = null,
		?EmailDesignAssignmentRepository $assignments = null
	) {
		$this->queue      = $queue;
		$this->repository = $repository ?: new EmailDeliveryRepository();
		$this->envelopes  = $envelopes ?: new EmailEnvelopeStore();
		$this->templates  = $templates ?: new EmailTemplateService();
		$this->settings   = $settings ?: new EmailTemplateSettingsRepository();
		$this->catalog    = $catalog ?: new EmailTemplateCatalogRepository();
		$this->assignments = $assignments ?: new EmailDesignAssignmentRepository();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function preview( $version_id, $destination = '', $template_id = 'automatic', $purpose = EmailDesignPurposes::ISSUED ) {
		$version = $this->repository->find_version( absint( $version_id ) );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		if ( ! is_array( $version ) || 'issued' !== (string) ( $version['issuance_state'] ?? '' ) ) {
			return new \WP_Error( 'certloom_email_version_invalid', __( 'Choose an issued immutable credential Version for Email Delivery.', 'certloom' ) );
		}

		$snapshot = is_array( $version['snapshot'] ?? null ) ? $version['snapshot'] : array();
		if ( isset( $snapshot['credential'] ) && is_array( $snapshot['credential'] ) ) {
			$snapshot['credential']['version_id'] = absint( $version['id'] ?? 0 );
		}
		$destination = $this->destination( $destination, $snapshot );
		if ( is_wp_error( $destination ) ) {
			return $destination;
		}

		$selection = $this->resolve_template( $snapshot, $template_id, $purpose );
		if ( is_wp_error( $selection ) ) { return $selection; }
		$template = $selection['design'];

		$authoritative = ( new DomainCutoverState() )->is_domain_authoritative();
		$composed      = $this->templates->compose( $snapshot, $template, $authoritative );
		if ( is_wp_error( $composed ) ) {
			return $composed;
		}
		return array(
			'version_id'                 => absint( $version['id'] ?? 0 ),
			'series_id'                  => absint( $version['series_id'] ?? 0 ),
			'destination'                => $destination,
			'destination_mask'           => $this->mask( $destination ),
			'destination_sha256'         => hash( 'sha256', strtolower( $destination ) ),
			'template_id'                => (string) $template['id'],
			'template_name'              => (string) $template['name'],
			'selection_intent'            => (string) $selection['selection_intent'],
			'resolution_scope'            => (string) $selection['scope_type'],
			'resolution_scope_id'         => absint( $selection['scope_id'] ),
			'message_purpose'             => (string) $selection['purpose'],
			'composed'                   => $composed,
			'snapshot'                   => $snapshot,
			'verification_authoritative' => $authoritative,
			'pdf_attachment_authoritative' => false,
		);
	}

	/** @return string|\WP_Error */
	public function enqueue( $version_id, $destination, $owner_user_id, $delivery_kind = 'issued', $confirmed = false, $template_id = 'automatic', $purpose = EmailDesignPurposes::ISSUED ) {
		if ( ! $confirmed ) {
			return new \WP_Error( 'certloom_email_confirmation_required', __( 'Review and explicitly confirm the credential email before queueing delivery.', 'certloom' ) );
		}
		$delivery_kind = sanitize_key( (string) $delivery_kind );
		if ( ! in_array( $delivery_kind, array( 'issued', 'resend', 'test' ), true ) ) {
			return new \WP_Error( 'certloom_email_kind_invalid', __( 'The credential email delivery type is invalid.', 'certloom' ) );
		}

		$review = $this->preview( $version_id, $destination, $template_id, $purpose );
		if ( is_wp_error( $review ) ) {
			return $review;
		}
		$composed = $review['composed'];
		$key      = $this->repository->create_delivery(
			array(
				'series_id'          => $review['series_id'],
				'version_id'         => $review['version_id'],
				'delivery_kind'      => $delivery_kind,
				'destination_mask'   => $review['destination_mask'],
				'destination_sha256' => $review['destination_sha256'],
				'subject_sha256'     => $composed['subject_sha256'],
				'html_sha256'        => $composed['html_sha256'],
				'text_sha256'        => $composed['text_sha256'],
				'composition_sha256' => $composed['composition_sha256'],
				'template_version'   => EmailTemplateService::TEMPLATE_VERSION,
				'evidence'           => array(
					'template_id'                => (string) $review['template_id'],
					'template_name'              => (string) $review['template_name'],
					'credential_number'          => sanitize_text_field( (string) ( $review['snapshot']['credential']['display_number'] ?? '' ) ),
					'verification_authoritative' => (bool) $composed['verification_authoritative'],
					'attachment_status'          => (string) $composed['attachment_status'],
					'static_attachment_count'     => absint( $composed['static_attachment_count'] ?? 0 ),
					'static_attachment_sha256'    => (string) ( $composed['static_attachment_sha256'] ?? '' ),
					'requested_by'               => absint( $owner_user_id ),
					'selection_intent'            => (string) $review['selection_intent'],
					'resolution_scope'            => (string) $review['resolution_scope'],
					'resolution_scope_id'         => absint( $review['resolution_scope_id'] ),
					'message_purpose'             => (string) $review['message_purpose'],
				),
			)
		);
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$envelope = array(
			'schema'             => 'certloom-email-envelope',
			'schema_version'     => 1,
			'delivery_key'       => $key,
			'version_id'         => $review['version_id'],
			'to'                 => $review['destination'],
			'subject'            => $composed['subject'],
			'html_body'          => $composed['html_body'],
			'text_body'          => $composed['text_body'],
			'attachments'        => is_array( $composed['attachments'] ?? null ) ? array_values( $composed['attachments'] ) : array(),
			'composition_sha256' => $composed['composition_sha256'],
			'destination_sha256' => $review['destination_sha256'],
		);
		$path = $this->envelopes->write( $key, $envelope );
		if ( is_wp_error( $path ) ) {
			$this->repository->mark_failed( $key, 'envelope_write_failed', array(), false );
			return $path;
		}
		$job = $this->queue->enqueue(
			JobTypes::EMAIL_DELIVERY,
			absint( $owner_user_id ),
			array(
				'delivery_key'  => $key,
				'envelope_path' => $path,
			)
		);
		if ( is_wp_error( $job ) ) {
			$this->repository->mark_failed( $key, 'schedule_failed', array( 'schedule_error' => $job->get_error_code() ), false );
			$this->envelopes->delete( $path );
			return $job;
		}
		$attached = $this->repository->attach_job( $key, $job );
		return is_wp_error( $attached ) ? $attached : $job;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function delivery_for_job( $job_key ) {
		return $this->repository->find_delivery_by_job( $job_key );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function recent( $limit = 50 ) {
		return $this->repository->recent( $limit );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}|\WP_Error */
	public function history_page( $page = 1, $per_page = 50 ) {
		return $this->repository->list_page( $page, $per_page );
	}

	/** Legacy default-template API retained for compatibility. @return array<string,mixed> */
	public function template() {
		return $this->settings->get();
	}

	/** Legacy write API retained only to fail closed after Email Designs became authoritative. @return \WP_Error */
	public function save_template( array $template ) {
		return new \WP_Error( 'certloom_email_content_authority_moved', __( 'Reusable email content is managed in Design → Email designs. No legacy email changes were applied.', 'certloom' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function templates() {
		return $this->catalog->all();
	}

	/** Legacy catalog write API retained only to fail closed after Email Designs became authoritative. @return \WP_Error */
	public function save_catalog_template( array $template ) {
		return new \WP_Error( 'certloom_email_content_authority_moved', __( 'Reusable email content is managed in Design → Email designs. No legacy email changes were applied.', 'certloom' ) );
	}


	/** @return array<string,mixed>|\WP_Error */
	private function resolve_template( array $snapshot, $template_id, $purpose ) {
		if ( ! EmailDesignPurposes::is_valid( $purpose ) ) {
			return new \WP_Error( 'certloom_email_purpose_invalid', __( 'The requested email message purpose is not supported.', 'certloom' ) );
		}
		$purpose = sanitize_key( (string) $purpose );
		$requested = sanitize_key( (string) $template_id );
		if ( '' !== $requested && 'automatic' !== $requested ) {
			$template = $this->catalog->get( $requested );
			if ( ! is_array( $template ) || empty( $template['active'] ) || $purpose !== (string) ( $template['purpose'] ?? EmailDesignPurposes::ISSUED ) ) {
				return new \WP_Error( 'certloom_email_template_unavailable', __( 'Choose an active email design for this message purpose.', 'certloom' ) );
			}
			return array( 'design' => $template, 'selection_intent' => 'override', 'scope_type' => 'one_send_override', 'scope_id' => 0, 'purpose' => $purpose );
		}
		$issuer_id  = absint( $snapshot['issuer']['id'] ?? 0 );
		$program_id = absint( $snapshot['program']['id'] ?? 0 );
		$batch_id   = absint( $snapshot['batch']['id'] ?? 0 );
		$resolved = $this->assignments->resolve( $issuer_id, $program_id, $batch_id, $purpose );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		return array( 'design' => $resolved['design'], 'selection_intent' => 'automatic', 'scope_type' => (string) $resolved['scope_type'], 'scope_id' => absint( $resolved['scope_id'] ), 'purpose' => $purpose );
	}

	/** @return string|\WP_Error */
	private function destination( $requested, array $snapshot ) {
		$requested = trim( (string) $requested );
		if ( '' === $requested ) {
			$requested = (string) ( $snapshot['recipient']['email'] ?? '' );
		}
		$email = sanitize_email( $requested );
		return '' !== $email && ( ! function_exists( 'is_email' ) || is_email( $email ) )
			? $email
			: new \WP_Error( 'certloom_email_destination_required', __( 'This credential needs a valid delivery email address. Enter one explicitly or add it to the immutable issued Recipient snapshot.', 'certloom' ) );
	}

	/** @return string */
	private function mask( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return '***';
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$shown  = '' !== $local ? substr( $local, 0, 1 ) : '';
		return $shown . str_repeat( '*', max( 3, min( 12, max( 0, strlen( $local ) - 1 ) ) ) ) . '@' . $domain;
	}
}
