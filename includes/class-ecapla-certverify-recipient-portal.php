<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Certloom\Application\Recipient\RecipientPortalService;

/**
 * Authenticated frontend adapter for POR-01 My Credentials.
 *
 * The adapter contains presentation only. Identity, credential ownership,
 * visibility and effective status are resolved by the normalized-domain
 * application/repository layer.
 */
final class Ecapla_CertVerify_Recipient_Portal {
	const STATUS_QUERY = 'certloom_my_status';
	const SORT_QUERY   = 'certloom_my_sort';
	const PAGE_QUERY   = 'certloom_my_page';

	/** @return string */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'layout'       => 'cards',
				'per_page'     => '12',
				'show_filters' => 'yes',
				'heading'      => __( 'My Credentials', 'certloom' ),
			),
			is_array( $atts ) ? $atts : array(),
			'certloom_my_credentials'
		);
		$layout = sanitize_key( (string) $atts['layout'] );
		if ( ! in_array( $layout, array( 'cards', 'grid', 'table' ), true ) ) { $layout = 'cards'; }
		$per_page = max( 1, min( RecipientPortalService::PER_PAGE_MAX, absint( $atts['per_page'] ) ) );
		$show_filters = 'no' !== strtolower( (string) $atts['show_filters'] );

		Ecapla_CertVerify_Shortcode::enqueue_assets();
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( self::current_page_url() );
			return self::notice(
				__( 'Sign in to view your credentials.', 'certloom' ),
				'<a class="certloom-my-credentials__button" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Sign in', 'certloom' ) . '</a>'
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Authenticated, read-only filtering/pagination.
		$status = isset( $_GET[ self::STATUS_QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_QUERY ] ) ) : 'all';
		$sort   = isset( $_GET[ self::SORT_QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::SORT_QUERY ] ) ) : 'newest';
		$page   = isset( $_GET[ self::PAGE_QUERY ] ) ? max( 1, absint( wp_unslash( $_GET[ self::PAGE_QUERY ] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$service = new RecipientPortalService();
		$result  = $service->load_for_user(
			get_current_user_id(),
			array( 'status' => $status, 'sort' => $sort, 'page' => $page, 'per_page' => $per_page )
		);
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();
			if ( 'certloom_recipient_portal_authority_closed' === $result->get_error_code() ) {
				$message = __( 'My Credentials is installed but remains unavailable until Certloom’s normalized credential authority passes its final acceptance gate.', 'certloom' );
			}
			return self::notice( $message );
		}
		if ( empty( $result['linked'] ) ) {
			return self::notice( __( 'Your WordPress account is not linked to a Certloom recipient yet. Contact the credential issuer if you expected credentials here.', 'certloom' ) );
		}

		$heading = trim( wp_strip_all_tags( (string) $atts['heading'] ) );
		if ( '' === $heading ) { $heading = __( 'My Credentials', 'certloom' ); }
		$items = (array) ( $result['items'] ?? array() );
		$total = absint( $result['total'] ?? 0 );
		$recipient_name = (string) ( $result['recipient']['display_name'] ?? '' );
		$filter_status = (string) ( $result['status_filter'] ?? 'all' );
		$filter_sort = (string) ( $result['sort'] ?? 'newest' );

		ob_start();
		?>
		<section class="certloom-my-credentials certloom-my-credentials--<?php echo esc_attr( $layout ); ?>" aria-labelledby="certloom-my-credentials-heading" data-copy-success-text="<?php echo esc_attr__( 'Credential link copied.', 'certloom' ); ?>" data-copy-error-text="<?php echo esc_attr__( 'Copy the credential link from your browser.', 'certloom' ); ?>">
			<header class="certloom-my-credentials__header">
				<div>
					<p class="certloom-my-credentials__eyebrow"><?php esc_html_e( 'Recipient portal', 'certloom' ); ?></p>
					<h2 id="certloom-my-credentials-heading" class="certloom-my-credentials__heading"><?php echo esc_html( $heading ); ?></h2>
					<?php if ( '' !== $recipient_name ) : ?>
						<p class="certloom-my-credentials__intro"><?php echo esc_html( sprintf( /* translators: %s: Recipient name. */ __( 'Credentials linked to %s.', 'certloom' ), $recipient_name ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="certloom-my-credentials__count" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: credential count. */ _n( '%d credential', '%d credentials', $total, 'certloom' ), $total ) ); ?>">
					<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
					<span><?php echo esc_html( _n( 'credential', 'credentials', $total, 'certloom' ) ); ?></span>
				</div>
			</header>

			<?php if ( $show_filters ) : ?>
				<?php echo self::filters( $filter_status, $filter_sort ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
			<?php endif; ?>

			<?php if ( empty( $items ) ) : ?>
				<div class="certloom-my-credentials__empty" role="status">
					<strong><?php esc_html_e( 'No credentials match this view.', 'certloom' ); ?></strong>
					<p><?php esc_html_e( 'Try another status filter, or contact the issuer if you expected a credential here.', 'certloom' ); ?></p>
				</div>
			<?php elseif ( 'table' === $layout ) : ?>
				<?php echo self::table( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
			<?php else : ?>
				<div class="certloom-my-credentials__collection" role="list">
					<?php foreach ( $items as $item ) : ?>
						<?php echo self::card( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php echo self::pagination( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
			<footer class="certloom-my-credentials__authority-note">
				<?php if ( empty( $result['public_verify_ready'] ) ) : ?>
					<?php esc_html_e( 'Public verification actions remain unavailable until normalized verification authority is accepted.', 'certloom' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Credential status is evaluated live from the current immutable Version and lifecycle state.', 'certloom' ); ?>
				<?php endif; ?>
			</footer>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** @return string */
	private static function filters( $status, $sort ) {
		$statuses = array(
			'all'            => __( 'All statuses', 'certloom' ),
			'valid'          => __( 'Valid', 'certloom' ),
			'expired'        => __( 'Expired', 'certloom' ),
			'revoked'        => __( 'Revoked', 'certloom' ),
			'suspended'      => __( 'Suspended', 'certloom' ),
			'not_yet_valid'  => __( 'Not yet valid', 'certloom' ),
			'pending_review' => __( 'Pending review', 'certloom' ),
			'noncompliant'   => __( 'Non-compliant', 'certloom' ),
		);
		$sorts = array(
			'newest'       => __( 'Newest issued', 'certloom' ),
			'oldest'       => __( 'Oldest issued', 'certloom' ),
			'expires_soon' => __( 'Expiring soon', 'certloom' ),
			'program'      => __( 'Program name', 'certloom' ),
		);
		ob_start();
		?>
		<form class="certloom-my-credentials__filters" method="get" action="<?php echo esc_url( self::current_page_url() ); ?>">
			<div>
				<label for="certloom-my-status"><?php esc_html_e( 'Status', 'certloom' ); ?></label>
				<select id="certloom-my-status" name="<?php echo esc_attr( self::STATUS_QUERY ); ?>">
					<?php foreach ( $statuses as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="certloom-my-sort"><?php esc_html_e( 'Sort', 'certloom' ); ?></label>
				<select id="certloom-my-sort" name="<?php echo esc_attr( self::SORT_QUERY ); ?>">
					<?php foreach ( $sorts as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="submit" class="certloom-my-credentials__button"><?php esc_html_e( 'Apply', 'certloom' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/** @return string */
	private static function card( array $item ) {
		$status = (array) ( $item['status'] ?? array() );
		$code = sanitize_key( (string) ( $status['code'] ?? 'unknown' ) );
		$title = trim( (string) ( $item['program_title'] ?? '' ) );
		if ( '' === $title ) { $title = __( 'Credential', 'certloom' ); }
		ob_start();
		?>
		<article class="certloom-my-credential-card" role="listitem">
			<div class="certloom-my-credential-card__topline">
				<span class="certloom-my-credential-card__status certloom-my-credential-card__status--<?php echo esc_attr( $code ); ?>"><?php echo esc_html( self::status_label( $code ) ); ?></span>
				<?php if ( ! empty( $item['expiring_soon'] ) ) : ?><span class="certloom-my-credential-card__expiring"><?php esc_html_e( 'Expiring soon', 'certloom' ); ?></span><?php endif; ?>
			</div>
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( ! empty( $item['issuer_name'] ) ) : ?><p class="certloom-my-credential-card__issuer"><?php echo esc_html( (string) $item['issuer_name'] ); ?></p><?php endif; ?>
			<dl class="certloom-my-credential-card__facts">
				<?php echo self::fact( __( 'Certificate', 'certloom' ), (string) ( $item['display_number'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::fact( __( 'Issued', 'certloom' ), self::date_label( $item['issued_at'] ?? null ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo self::fact( __( 'Expires', 'certloom' ), self::date_label( $item['expires_at'] ?? null, __( 'No expiry', 'certloom' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( ! empty( $item['grade'] ) ) : echo self::fact( __( 'Grade', 'certloom' ), (string) $item['grade'] ); endif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</dl>
			<?php if ( ! empty( $item['credential_fields'] ) && is_array( $item['credential_fields'] ) ) : ?>
				<div class="certloom-my-credential-card__credential-fields" aria-label="<?php esc_attr_e( 'Credential details', 'certloom' ); ?>">
					<h4><?php esc_html_e( 'Credential details', 'certloom' ); ?></h4>
					<dl>
					<?php foreach ( $item['credential_fields'] as $field ) : if ( ! is_array( $field ) || '' === trim( (string) ( $field['value'] ?? '' ) ) ) { continue; } ?>
						<?php echo self::fact( (string) ( $field['label'] ?? $field['key'] ?? '' ), (string) $field['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
					<?php endforeach; ?>
					</dl>
				</div>
			<?php endif; ?>
			<div class="certloom-my-credential-card__actions">
				<?php if ( ! empty( $item['verify_url'] ) ) : ?><a class="certloom-my-credentials__button" href="<?php echo esc_url( $item['verify_url'] ); ?>" rel="nofollow noopener"><?php esc_html_e( 'Verify', 'certloom' ); ?></a><?php endif; ?>
				<?php echo self::share_controls( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?>
				<span class="certloom-my-credentials__action-muted" title="<?php esc_attr_e( 'PDF and image downloads remain gated until production artifact authority is accepted.', 'certloom' ); ?>"><?php esc_html_e( 'Downloads gated', 'certloom' ); ?></span>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/** @return string */
	private static function table( array $items ) {
		ob_start();
		?>
		<div class="certloom-my-credentials__table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'My credentials table', 'certloom' ); ?>">
		<table class="certloom-my-credentials__table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Credential', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Issued', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Expires', 'certloom' ); ?></th><th scope="col"><?php esc_html_e( 'Action', 'certloom' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $items as $item ) : $status = (array) ( $item['status'] ?? array() ); $code = sanitize_key( (string) ( $status['code'] ?? 'unknown' ) ); ?>
				<tr>
					<th scope="row"><strong><?php echo esc_html( (string) ( ! empty( $item['program_title'] ) ? $item['program_title'] : __( 'Credential', 'certloom' ) ) ); ?></strong><small><?php echo esc_html( (string) ( $item['display_number'] ?? '' ) ); ?></small></th>
					<td><span class="certloom-my-credential-card__status certloom-my-credential-card__status--<?php echo esc_attr( $code ); ?>"><?php echo esc_html( self::status_label( $code ) ); ?></span><?php if ( ! empty( $item['expiring_soon'] ) ) : ?><small><?php esc_html_e( 'Expiring soon', 'certloom' ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( self::date_label( $item['issued_at'] ?? null ) ); ?></td>
					<td><?php echo esc_html( self::date_label( $item['expires_at'] ?? null, __( 'No expiry', 'certloom' ) ) ); ?></td>
					<td><div class="certloom-my-credentials__table-actions"><?php if ( ! empty( $item['verify_url'] ) ) : ?><a href="<?php echo esc_url( $item['verify_url'] ); ?>" rel="nofollow noopener"><?php esc_html_e( 'Verify', 'certloom' ); ?></a><?php else : ?><span aria-label="<?php esc_attr_e( 'Verification unavailable', 'certloom' ); ?>">—</span><?php endif; ?><?php echo self::share_controls( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in helper. ?></div></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		return (string) ob_get_clean();
	}


	/** @return string */
	private static function share_controls( array $item ) {
		if ( empty( $item['share_available'] ) || empty( $item['share_url'] ) || empty( $item['share_links'] ) ) { return ''; }
		$labels = array(
			'linkedin' => __( 'LinkedIn', 'certloom' ),
			'whatsapp' => __( 'WhatsApp', 'certloom' ),
			'facebook' => __( 'Facebook', 'certloom' ),
			'x'        => __( 'X', 'certloom' ),
			'email'    => __( 'Email', 'certloom' ),
		);
		$links = '';
		foreach ( (array) $item['share_links'] as $link ) {
			if ( ! is_array( $link ) ) { continue; }
			$platform = sanitize_key( (string) ( $link['platform'] ?? '' ) );
			if ( ! isset( $labels[ $platform ] ) ) { continue; }
			$url = esc_url( (string) ( $link['url'] ?? '' ), array( 'http', 'https', 'mailto' ) );
			if ( '' === $url ) { continue; }
			$external = 'email' === $platform ? '' : ' target="_blank" rel="nofollow noopener noreferrer"';
			$links .= '<a class="certloom-share-link certloom-share-link--' . esc_attr( $platform ) . '" href="' . $url . '"' . $external . '>' . esc_html( $labels[ $platform ] ) . '</a>';
		}
		if ( '' === $links ) { return ''; }
		return '<details class="certloom-share certloom-my-credentials__share"><summary class="certloom-my-credentials__button">' . esc_html__( 'Share', 'certloom' ) . '</summary><div class="certloom-share-menu">' . $links . '<button type="button" class="certloom-share-link certloom-share-copy certloom-copy-link" data-copy-url="' . esc_attr( (string) $item['share_url'] ) . '">' . esc_html__( 'Copy URL', 'certloom' ) . '</button><span class="certloom-action-feedback" aria-live="polite"></span></div></details>';
	}

	/** @return string */
	private static function fact( $label, $value ) {
		if ( '' === trim( (string) $value ) ) { return ''; }
		return '<div><dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>';
	}

	/** @return string */
	private static function pagination( array $result ) {
		$page  = max( 1, absint( $result['page'] ?? 1 ) );
		$pages = absint( $result['pages'] ?? 0 );
		if ( $pages <= 1 ) { return ''; }
		$base = self::current_page_url();
		$status = sanitize_key( (string) ( $result['status_filter'] ?? 'all' ) );
		$sort = sanitize_key( (string) ( $result['sort'] ?? 'newest' ) );
		$links = array();
		if ( $page > 1 ) {
			$links[] = '<a rel="prev" href="' . esc_url( add_query_arg( array( self::STATUS_QUERY => $status, self::SORT_QUERY => $sort, self::PAGE_QUERY => $page - 1 ), $base ) ) . '">' . esc_html__( 'Previous', 'certloom' ) . '</a>';
		}
		$links[] = '<span>' . esc_html( sprintf( /* translators: %1$d: current page; %2$d: total pages. */ __( 'Page %1$d of %2$d', 'certloom' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) {
			$links[] = '<a rel="next" href="' . esc_url( add_query_arg( array( self::STATUS_QUERY => $status, self::SORT_QUERY => $sort, self::PAGE_QUERY => $page + 1 ), $base ) ) . '">' . esc_html__( 'Next', 'certloom' ) . '</a>';
		}
		return '<nav class="certloom-my-credentials__pagination" aria-label="' . esc_attr__( 'Credential pages', 'certloom' ) . '">' . implode( '', $links ) . '</nav>';
	}

	/** @return string */
	private static function notice( $message, $action = '' ) {
		Ecapla_CertVerify_Shortcode::enqueue_assets();
		return '<div class="certloom-my-credentials certloom-my-credentials__notice" role="status"><p>' . esc_html( (string) $message ) . '</p>' . $action . '</div>';
	}

	/** @return string */
	private static function date_label( $value, $empty = '' ) {
		$value = trim( (string) $value );
		return '' === $value ? (string) $empty : substr( $value, 0, 10 );
	}

	/** @return string */
	private static function status_label( $code ) {
		$labels = array(
			'valid'                  => __( 'Valid', 'certloom' ),
			'expired'                => __( 'Expired', 'certloom' ),
			'revoked'                => __( 'Revoked', 'certloom' ),
			'suspended'              => __( 'Suspended', 'certloom' ),
			'not_yet_valid'          => __( 'Not yet valid', 'certloom' ),
			'pending_review'         => __( 'Pending review', 'certloom' ),
			'suspended_noncompliant' => __( 'Non-compliant', 'certloom' ),
			'voided'                 => __( 'Voided', 'certloom' ),
			'not_issued'             => __( 'Not issued', 'certloom' ),
		);
		return $labels[ $code ] ?? __( 'Unavailable', 'certloom' );
	}

	/** @return string */
	private static function current_page_url() {
		if ( function_exists( 'get_queried_object_id' ) && function_exists( 'get_permalink' ) ) {
			$id = absint( get_queried_object_id() );
			if ( $id ) {
				$url = get_permalink( $id );
				if ( is_string( $url ) && '' !== $url ) { return $url; }
			}
		}
		return home_url( '/' );
	}
}
