<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Job\JobRuntime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human-facing manual email delivery for one already-issued credential Version.
 *
 * Reusable message content is owned by Design → Email designs. This screen is
 * intentionally only credential → selection intent → preview → send. Automations
 * decide when delivery runs; they are not a second message-design authority.
 */
final class EmailDeliveryAdmin {
	const PAGE   = 'certloom-email-delivery';
	const NOTICE = 'certloom_email_notice_';

	/** @var JobRuntime */
	private $runtime;

	public function __construct( JobRuntime $runtime ) {
		$this->runtime = $runtime;
	}

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 40 );
		add_action( 'admin_post_certloom_email_preview', array( $this, 'preview_action' ) );
		add_action( 'admin_post_certloom_email_send', array( $this, 'send_action' ) );
		add_action( 'admin_post_certloom_email_retry', array( $this, 'retry_action' ) );

		// Backward-compatible handler for older bookmarked/form submissions. The
		// normal product UI owns reusable message content exclusively in Email designs.
		add_action( 'admin_post_certloom_email_template_save', array( $this, 'save_template' ) );
	}

	/** @return void */
	public function menu() {
		// Email Delivery is contextual rather than a primary WordPress submenu row.
		// Parentless registration keeps its admin_page_* hook stable after Certloom
		// consolidates the visible menu. render()/actions still enforce the real
		// granular-or-established-management authorization boundary.
		\Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		add_submenu_page(
			null,
			__( 'Email Delivery', 'certloom' ),
			__( 'Email Delivery', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/** @return void */
	public function render() {
		$this->cap();
		$version       = isset( $_GET['version_id'] ) ? absint( $_GET['version_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route context.
		$preview_token = isset( $_GET['email_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['email_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only transient key.

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-flow-workspace certloom-email-delivery">';
		echo '<section class="certloom-workspace-hero certloom-email-hero"><div class="certloom-workspace-hero-copy">';
		echo '<span class="certloom-eyebrow">' . esc_html__( 'Manual delivery', 'certloom' ) . '</span>';
		echo '<h1>' . esc_html__( 'Send credential email', 'certloom' ) . '</h1>';
		echo '<p>' . esc_html__( 'Use the email hierarchy by default, or choose a one-send override. Preview exactly what the recipient will receive before anything is queued.', 'certloom' ) . '</p>';
		echo '</div><div class="certloom-workspace-hero-actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=certloom-email-designs' ) ) . '">' . esc_html__( 'Email designs', 'certloom' ) . '</a></div></section>';

		$this->notice();
		$this->composer( $version, $preview_token );
		$this->automation_bridge();
		$this->history();
		echo '</div>';
	}

	/** @return void */
	public function preview_action() {
		$this->cap();
		check_admin_referer( 'certloom_email_preview' );

		$version_id  = absint( $_POST['version_id'] ?? 0 );
		$destination = isset( $_POST['destination'] ) ? sanitize_email( wp_unslash( $_POST['destination'] ) ) : '';
		$kind        = isset( $_POST['delivery_kind'] ) ? sanitize_key( wp_unslash( $_POST['delivery_kind'] ) ) : 'issued';
		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : 'automatic';

		if ( ! in_array( $kind, array( 'issued', 'resend', 'test' ), true ) ) {
			$this->fail( __( 'That email delivery type is invalid.', 'certloom' ), $version_id );
		}
		$review = $this->runtime->email_delivery()->preview( $version_id, $destination, $template_id );
		if ( is_wp_error( $review ) ) {
			$this->fail( $review->get_error_message(), $version_id );
		}
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			$this->fail( __( 'Certloom could not create the email review token.', 'certloom' ), $version_id );
		}
		set_transient(
			'certloom_email_preview_' . $token . '_' . get_current_user_id(),
			array(
				'version_id'         => $version_id,
				'destination'        => (string) $review['destination'],
				'delivery_kind'      => $kind,
				'selection_intent'    => 'automatic' === $template_id ? 'automatic' : 'override',
				'selection_value'     => $template_id,
				'template_id'         => (string) $review['template_id'],
				'template_name'       => (string) $review['template_name'],
				'resolution_scope'    => (string) ( $review['resolution_scope'] ?? '' ),
				'resolution_scope_id' => absint( $review['resolution_scope_id'] ?? 0 ),
				'composition_sha256'  => (string) $review['composed']['composition_sha256'],
			),
			300
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'version_id'    => $version_id,
					'email_preview' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** @return void */
	public function send_action() {
		$this->cap();
		$token = isset( $_POST['email_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['email_preview'] ) ) : '';
		check_admin_referer( 'certloom_email_send_' . $token );
		$key   = 'certloom_email_preview_' . $token . '_' . get_current_user_id();
		$saved = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $saved ) ) {
			$this->fail( __( 'That email review expired. Preview the message again before sending.', 'certloom' ) );
		}

		$selection = sanitize_key( (string) ( $saved['selection_value'] ?? 'automatic' ) );
		if ( '' === $selection ) { $selection = 'automatic'; }
		$review = $this->runtime->email_delivery()->preview( absint( $saved['version_id'] ), (string) $saved['destination'], $selection, EmailDesignPurposes::ISSUED );
		if ( is_wp_error( $review ) || ! hash_equals( (string) ( $saved['composition_sha256'] ?? '' ), is_wp_error( $review ) ? '' : (string) $review['composed']['composition_sha256'] ) ) {
			$this->fail( __( 'The credential or selected message changed after review. Preview it again before sending.', 'certloom' ), absint( $saved['version_id'] ) );
		}

		$job = $this->runtime->email_delivery()->enqueue(
			absint( $saved['version_id'] ),
			(string) $saved['destination'],
			get_current_user_id(),
			(string) ( $saved['delivery_kind'] ?? 'issued' ),
			true,
			$selection,
			EmailDesignPurposes::ISSUED
		);
		if ( is_wp_error( $job ) ) {
			$this->fail( $job->get_error_message(), absint( $saved['version_id'] ) );
		}
		$this->success( __( 'Credential email is being sent through WordPress.', 'certloom' ) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'version_id' => absint( $saved['version_id'] ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	public function retry_action() {
		$this->cap();
		$job = isset( $_POST['job_key'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['job_key'] ) ) ) : '';
		check_admin_referer( 'certloom_email_retry_' . $job );
		$row = $this->runtime->repository()->find( $job );
		if ( is_wp_error( $row ) || ! is_array( $row ) || JobTypes::EMAIL_DELIVERY !== (string) ( $row['job_type'] ?? '' ) ) {
			$this->fail( __( 'That Email Delivery job is unavailable.', 'certloom' ) );
		}
		$owner = absint( $row['owner_user_id'] ?? 0 );
		if ( 0 !== $owner && $owner !== get_current_user_id() ) {
			$this->fail( __( 'That Email Delivery job belongs to another administrator.', 'certloom' ) );
		}
		$retry = $this->runtime->retry_failed( $job );
		if ( is_wp_error( $retry ) ) {
			$this->fail( $retry->get_error_message() );
		}
		$this->success( __( 'The failed Email Delivery job was explicitly requeued.', 'certloom' ) );
		$this->go();
	}

	/**
	 * Legacy compatibility only. Email designs are the only reusable content authority.
	 *
	 * @return void
	 */
	public function save_template() {
		$this->cap();
		check_admin_referer( 'certloom_email_template_save' );
		$this->success( __( 'Reusable email content is now managed in Design → Email designs. No legacy template changes were applied.', 'certloom' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=certloom-email-designs' ) );
		exit;
	}

	/** @return void */
	private function composer( $version_id, $token ) {
		$review    = null;
		$saved     = null;
		$templates = array_values(
			array_filter(
				$this->runtime->email_delivery()->templates(),
				static function ( $template ) {
					return is_array( $template ) && ! empty( $template['active'] ) && EmailDesignPurposes::ISSUED === (string) ( $template['purpose'] ?? EmailDesignPurposes::ISSUED );
				}
			)
		);
		$template_id = isset( $_GET['template_id'] ) ? sanitize_key( wp_unslash( $_GET['template_id'] ) ) : 'automatic'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only compose preselection from issuance receipt.
		if ( '' === $template_id ) { $template_id = 'automatic'; }

		if ( '' !== $token ) {
			$saved = get_transient( 'certloom_email_preview_' . $token . '_' . get_current_user_id() );
			if ( is_array( $saved ) ) {
				$template_id = sanitize_key( (string) ( $saved['selection_value'] ?? 'automatic' ) );
				$review      = $this->runtime->email_delivery()->preview( absint( $saved['version_id'] ), (string) $saved['destination'], $template_id, EmailDesignPurposes::ISSUED );
			}
		}

		echo '<section class="certloom-workspace-card certloom-email-card">';
		echo '<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Send one credential', 'certloom' ) . '</span><h2>' . esc_html__( 'Preview message', 'certloom' ) . '</h2><p>' . esc_html__( 'Nothing is sent until you review the final message and confirm delivery.', 'certloom' ) . '</p></div><span class="certloom-state-pill">' . esc_html__( 'Review first', 'certloom' ) . '</span></header>';

		if ( $version_id <= 0 ) {
			echo '<div class="certloom-premium-empty-state certloom-email-empty"><span class="dashicons dashicons-awards" aria-hidden="true"></span><h3>' . esc_html__( 'Choose a credential first', 'certloom' ) . '</h3><p>' . esc_html__( 'Open the credential you want to deliver, then choose Email credential. This keeps delivery connected to the exact issued credential instead of asking you for an internal ID.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=certloom-domain-credentials' ) ) . '">' . esc_html__( 'Choose credential', 'certloom' ) . '</a></div>';
			echo '</section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="certloom-clean-form certloom-email-compose-form">';
		echo '<input type="hidden" name="action" value="certloom_email_preview"><input type="hidden" name="version_id" value="' . esc_attr( $version_id ) . '">';
		wp_nonce_field( 'certloom_email_preview' );
		echo '<div class="certloom-form-grid certloom-email-compose-grid">';
		echo '<label class="certloom-field-control"><span>' . esc_html__( 'Email design', 'certloom' ) . '</span><select name="template_id" required>';
		echo '<option value="automatic" ' . selected( $template_id, 'automatic', false ) . '>' . esc_html__( 'Automatic — use email hierarchy', 'certloom' ) . '</option>';
		foreach ( $templates as $template ) {
			$id   = (string) ( $template['id'] ?? '' );
			$name = (string) ( $template['name'] ?? '' );
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $template_id, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'Automatic resolves Batch → Program → Issuer → Global. Choosing a named design is a one-send override and does not change inheritance.', 'certloom' ) . '</small></label>';
		echo '<label class="certloom-field-control"><span>' . esc_html__( 'Send to', 'certloom' ) . '</span><input type="email" name="destination" value="' . esc_attr( is_array( $saved ) ? (string) ( $saved['destination'] ?? '' ) : '' ) . '" placeholder="' . esc_attr__( 'Uses the recipient email when blank', 'certloom' ) . '"><small>' . esc_html__( 'Leave blank to use the email saved at issuance. If none was saved, enter a delivery address here.', 'certloom' ) . '</small></label>';
		echo '</div>';
		echo '<fieldset class="certloom-email-kind"><legend>' . esc_html__( 'Delivery type', 'certloom' ) . '</legend><div class="certloom-choice-pills">';
		$selected_kind = is_array( $saved ) ? (string) ( $saved['delivery_kind'] ?? 'issued' ) : 'issued';
		foreach ( array( 'issued' => __( 'Issued credential', 'certloom' ), 'resend' => __( 'Resend', 'certloom' ), 'test' => __( 'Test', 'certloom' ) ) as $kind => $label ) {
			echo '<label><input type="radio" name="delivery_kind" value="' . esc_attr( $kind ) . '" ' . checked( $selected_kind, $kind, false ) . '><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div></fieldset>';
		echo '<div class="certloom-form-actions certloom-email-compose-actions"><button class="button button-primary" type="submit">' . esc_html__( 'Preview email', 'certloom' ) . '</button><span>' . esc_html__( 'Previewing does not send anything.', 'certloom' ) . '</span></div></form>';

		if ( is_wp_error( $review ) ) {
			$this->error( $review->get_error_message() );
		} elseif ( is_array( $review ) && is_array( $saved ) ) {
			$this->preview_panel( $review, $saved, $token );
		}
		echo '</section>';
	}

	/** @param array<string,mixed> $review @param array<string,mixed> $saved @return void */
	private function preview_panel( array $review, array $saved, $token ) {
		$composed = (array) $review['composed'];
		echo '<div class="certloom-email-preview">';
		echo '<div class="certloom-email-preview-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Ready to review', 'certloom' ) . '</span><h3>' . esc_html( (string) ( $review['template_name'] ?? __( 'Credential email', 'certloom' ) ) ) . '</h3></div><span class="certloom-state-pill is-success">' . esc_html__( 'Preview', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-email-review-meta"><div><span>' . esc_html__( 'Recipient', 'certloom' ) . '</span><strong>' . esc_html( (string) $review['destination_mask'] ) . '</strong></div><div><span>' . esc_html__( 'Delivery', 'certloom' ) . '</span><strong>' . esc_html( $this->kind_label( (string) ( $saved['delivery_kind'] ?? 'issued' ) ) ) . '</strong></div><div><span>' . esc_html__( 'Message', 'certloom' ) . '</span><strong>' . esc_html( (string) ( $review['template_name'] ?? '' ) ) . '</strong></div><div><span>' . esc_html__( 'Source', 'certloom' ) . '</span><strong>' . esc_html( $this->resolution_label( (string) ( $review['resolution_scope'] ?? '' ) ) ) . '</strong></div></div>';

		if ( empty( $review['verification_authoritative'] ) ) {
			echo '<div class="certloom-email-context-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>' . esc_html__( 'This site cannot include an online verification link for this credential yet. The preview below shows exactly what will be sent.', 'certloom' ) . '</p></div>';
		}
		if ( 'renderer_authority_gated' === (string) ( $composed['attachment_status'] ?? '' ) ) {
			echo '<div class="certloom-email-context-note"><span class="dashicons dashicons-paperclip" aria-hidden="true"></span><p>' . esc_html__( 'No certificate file will be attached to this email yet. Delivery remains available without an attachment.', 'certloom' ) . '</p></div>';
		}

		echo '<div class="certloom-email-preview-subject"><strong>' . esc_html__( 'Subject', 'certloom' ) . '</strong><p>' . esc_html( (string) $composed['subject'] ) . '</p></div>';
		echo '<div class="certloom-email-preview-body">' . EmailDesignCompiler::sanitize_inline_html( (string) $composed['html_body'] ) . '</div>';
		echo '<details class="certloom-email-plain"><summary>' . esc_html__( 'Plain-text version', 'certloom' ) . '</summary><pre>' . esc_html( (string) $composed['text_body'] ) . '</pre></details>';
		echo '<details class="certloom-technical-details"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Certloom checks this integrity fingerprint again before sending. It is available for support and auditing.', 'certloom' ) . '</p><div class="certloom-template-preview-proof"><div><span>' . esc_html__( 'Composition SHA-256', 'certloom' ) . '</span><code data-certloom-composition-sha>' . esc_html( (string) $composed['composition_sha256'] ) . '</code></div></div></details>';
		echo '<div class="certloom-email-confirm"><div><strong>' . esc_html__( 'Looks right?', 'certloom' ) . '</strong><p>' . esc_html__( 'If the credential or selected message changes, Certloom will require another preview before sending.', 'certloom' ) . '</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_email_send"><input type="hidden" name="email_preview" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'certloom_email_send_' . $token );
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Send reviewed email', 'certloom' ) . '</button></form></div>';
		echo '</div>';
	}

	/** @return void */
	private function automation_bridge() {
		echo '<section class="certloom-workspace-card certloom-email-automation-bridge"><div class="certloom-email-automation-copy"><span class="certloom-eyebrow">' . esc_html__( 'Want this to happen automatically?', 'certloom' ) . '</span><h2>' . esc_html__( 'Automations decide when to send', 'certloom' ) . '</h2><p>' . esc_html__( 'Email designs decide what the recipient sees. Automations decide when an issued credential email runs, who it applies to, and which issuance sources it covers.', 'certloom' ) . '</p></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=certloom-automations' ) ) . '">' . esc_html__( 'Open Automations', 'certloom' ) . '</a></section>';
	}

	/** @return void */
	private function history() {
		$page   = isset( $_GET['email_paged'] ) ? max( 1, absint( $_GET['email_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$result = $this->runtime->email_delivery()->history_page( $page, 50 );
		echo '<section class="certloom-workspace-card certloom-email-history"><header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Recent activity', 'certloom' ) . '</span><h2>' . esc_html__( 'Email delivery history', 'certloom' ) . '</h2><p>' . esc_html__( 'See delivery status without storing full recipient email addresses in the history table.', 'certloom' ) . '</p></div></header>';
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
			echo '</section>';
			return;
		}
		$rows = (array) ( $result['items'] ?? array() );
		echo '<div class="certloom-domain-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Credential', 'certloom' ) . '</th><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Message', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th><th>' . esc_html__( 'Attempted', 'certloom' ) . '</th><th>' . esc_html__( 'Action', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$status   = (string) ( $row['status'] ?? '' );
			$evidence = is_array( $row['evidence'] ?? null ) ? $row['evidence'] : array();
			$number   = trim( (string) ( $evidence['credential_number'] ?? '' ) );
			$message  = trim( (string) ( $evidence['template_name'] ?? '' ) );
			echo '<tr><td>' . ( '' !== $number ? esc_html( $number ) : esc_html__( 'Issued credential', 'certloom' ) ) . '</td><td><code>' . esc_html( (string) ( $row['destination_mask'] ?? '' ) ) . '</code></td><td>' . esc_html( '' !== $message ? $message : $this->kind_label( (string) ( $row['delivery_kind'] ?? 'issued' ) ) ) . '</td><td><span class="certloom-email-status is-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( str_replace( '_', ' ', $status ) ) . '</span>';
			if ( 'uncertain' === $status ) {
				echo '<p class="description">' . esc_html__( 'Delivery outcome is unknown. Review before sending again.', 'certloom' ) . '</p>';
			}
			echo '</td><td>' . esc_html( (string) ( $row['attempted_at'] ?? '—' ) ) . '</td><td>';
			$owner = absint( $evidence['requested_by'] ?? 0 );
			if ( 'failed' === $status && ! empty( $row['job_key'] ) && ( 0 === $owner || $owner === get_current_user_id() ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_email_retry"><input type="hidden" name="job_key" value="' . esc_attr( (string) $row['job_key'] ) . '">';
				wp_nonce_field( 'certloom_email_retry_' . (string) $row['job_key'] );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Retry failed', 'certloom' ) . '</button></form>';
			} elseif ( 'failed' === $status && ! empty( $row['job_key'] ) ) {
				echo '<span class="description">' . esc_html__( 'Retry is available to the administrator who started this delivery.', 'certloom' ) . '</span>';
			} elseif ( 'uncertain' === $status ) {
				echo '<a class="button button-small" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'version_id' => absint( $row['version_id'] ?? 0 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Review resend', 'certloom' ) . '</a>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No credential emails have been sent yet.', 'certloom' ) . '</strong><span>' . esc_html__( 'Send from an issued credential, or create an Automation for automatic delivery.', 'certloom' ) . '</span></div></td></tr>';
		}
		echo '</tbody></table></div>';

		$total = absint( $result['total'] ?? 0 );
		$pages = max( 1, (int) ceil( $total / 50 ) );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => str_replace( '999999999', '%#%', add_query_arg( array( 'page' => self::PAGE, 'email_paged' => 999999999 ), admin_url( 'admin.php' ) ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
						'type'    => 'plain',
					)
				)
			) . '</div></div>';
		}
		echo '</section>';
	}

	/** @return string */
	private function resolution_label( $scope ) {
		$labels = array(
			'batch'             => __( 'Inherited from Batch', 'certloom' ),
			'program'           => __( 'Inherited from Program', 'certloom' ),
			'issuer'            => __( 'Inherited from Issuer', 'certloom' ),
			'global'            => __( 'Inherited from Global', 'certloom' ),
			'catalog_default'   => __( 'Default email design', 'certloom' ),
			'one_send_override' => __( 'One-send override', 'certloom' ),
		);
		$scope = sanitize_key( (string) $scope );
		return (string) ( $labels[ $scope ] ?? __( 'Automatic email hierarchy', 'certloom' ) );
	}

	/** @return string */
	private function kind_label( $kind ) {
		$labels = array(
			'issued' => __( 'Issued credential', 'certloom' ),
			'resend' => __( 'Resend', 'certloom' ),
			'test'   => __( 'Test', 'certloom' ),
		);
		return (string) ( $labels[ sanitize_key( (string) $kind ) ] ?? __( 'Credential email', 'certloom' ) );
	}

	/** @return void */
	private function cap() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) && ! current_user_can( ECAPLA_CERTVERIFY_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to deliver Certloom credential email.', 'certloom' ) );
		}
	}

	/** @return void */
	private function go() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/** @return void */
	private function fail( $message, $version_id = 0 ) {
		$this->store( 'error', $message );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'version_id' => $version_id ?: null ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	private function success( $message ) {
		$this->store( 'success', $message );
	}

	/** @return void */
	private function store( $type, $message ) {
		set_transient( self::NOTICE . get_current_user_id(), array( 'type' => $type, 'message' => (string) $message ), 120 );
	}

	/** @return void */
	private function notice() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice ' . ( 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	/** @return void */
	private function error( $message ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $message ) . '</p></div>';
	}
}
