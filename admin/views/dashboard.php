<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$metrics = isset( $dashboard['metrics'] ) && is_array( $dashboard['metrics'] ) ? $dashboard['metrics'] : Ecapla_CertVerify_Dashboard::normalize_metrics( array() );
$recent  = isset( $dashboard['recent'] ) && is_array( $dashboard['recent'] ) ? $dashboard['recent'] : array();
$health  = isset( $dashboard['health'] ) && is_array( $dashboard['health'] ) ? $dashboard['health'] : Ecapla_CertVerify_Dashboard::health_summary( array() );
$review  = max( 0, (int) ( $dashboard['legacy_review_count'] ?? 0 ) );
$statuses = Ecapla_CertVerify_Fields::get_statuses();
$health_overall = isset( $health['overall'] ) ? sanitize_key( (string) $health['overall'] ) : 'warning';
$health_label = 'good' === $health_overall ? __( 'Ready for current operations', 'certloom' ) : ( 'bad' === $health_overall ? __( 'Action required', 'certloom' ) : __( 'Review recommended', 'certloom' ) );
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-dashboard">
	<section class="certloom-dashboard-hero" aria-labelledby="certloom-dashboard-title">
		<div class="certloom-dashboard-hero-copy">
			<span class="certloom-eyebrow"><?php esc_html_e( 'Credential operations', 'certloom' ); ?></span>
			<h1 id="certloom-dashboard-title"><?php esc_html_e( 'Dashboard', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Manage and monitor your self-hosted certificate registry from one place.', 'certloom' ); ?></p>
		</div>
		<div class="certloom-dashboard-actions" aria-label="<?php esc_attr_e( 'Quick actions', 'certloom' ); ?>">
			<a class="button button-secondary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-setup' ) ); ?>">
				<svg class="certloom-button-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.64 5.64l2.12 2.12m8.48 8.48 2.12 2.12m0-12.72-2.12 2.12M7.76 16.24l-2.12 2.12" /></svg>
				<?php esc_html_e( 'Setup wizard', 'certloom' ); ?>
			</a>
			<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>">
				<svg class="certloom-button-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14" /></svg>
				<?php esc_html_e( 'Add certificate', 'certloom' ); ?>
			</a>
			<a class="button button-secondary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-import' ) ); ?>">
				<svg class="certloom-button-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 15V5m0 0L8 9m4-4 4 4M5 14v5h14v-5" /></svg>
				<?php esc_html_e( 'Import certificates', 'certloom' ); ?>
			</a>
		</div>
	</section>

	<?php if ( isset( $dashboard_error ) && is_wp_error( $dashboard_error ) ) : ?>
		<div class="notice notice-error inline certloom-owned-notice certloom-dashboard-notice"><p><?php echo esc_html( $dashboard_error->get_error_message() ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $onboarding_summary ) && is_array( $onboarding_summary ) ) :
		$setup_progress_completed = absint( $onboarding_summary['progress_completed'] ?? 0 );
		$setup_progress_total     = max( 1, absint( $onboarding_summary['progress_total'] ?? 6 ) );
		$wizard_walkthrough_done  = ! empty( $onboarding_summary['wizard_completed'] );
		$readiness_incomplete     = $setup_progress_completed < $setup_progress_total;
		if ( $wizard_walkthrough_done && $readiness_incomplete ) :
			$setup_checks = array(
				array( 'label' => __( 'Organization', 'certloom' ), 'ready' => ! empty( $onboarding_summary['organization_ready'] ) ),
				array( 'label' => __( 'Certificate fields', 'certloom' ), 'ready' => ! empty( $onboarding_summary['fields_ready'] ) ),
				array( 'label' => __( 'Verification page', 'certloom' ), 'ready' => ! empty( $onboarding_summary['page_ready'] ) ),
				array( 'label' => __( 'First certificate', 'certloom' ), 'ready' => ! empty( $onboarding_summary['registry_ready'] ) ),
				array( 'label' => __( 'Verification test', 'certloom' ), 'ready' => ! empty( $onboarding_summary['verification_tested'] ) ),
				array( 'label' => __( 'Appearance review', 'certloom' ), 'ready' => ! empty( $onboarding_summary['appearance_ready'] ) ),
			);
	?>
		<div class="certloom-callout certloom-callout-info certloom-setup-callout certloom-setup-readiness" role="status">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<div>
				<strong><?php esc_html_e( 'Setup review complete', 'certloom' ); ?></strong>
				<p><?php echo esc_html( sprintf( __( 'Your Wizard choices were reviewed and applied. %1$d of %2$d recommended readiness checks are complete; finish the remaining items when they are relevant to your workflow.', 'certloom' ), $setup_progress_completed, $setup_progress_total ) ); ?></p>
				<ul class="certloom-setup-checklist" aria-label="<?php esc_attr_e( 'Recommended setup readiness', 'certloom' ); ?>">
					<?php foreach ( $setup_checks as $setup_check ) : ?>
						<li class="<?php echo $setup_check['ready'] ? 'is-complete' : 'is-pending'; ?>"><span class="dashicons <?php echo $setup_check['ready'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span><?php echo esc_html( $setup_check['label'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-setup&step=review' ) ); ?>"><?php esc_html_e( 'Review setup', 'certloom' ); ?></a>
		</div>
	<?php elseif ( empty( $onboarding_summary['completed'] ) ) : ?>
		<div class="certloom-callout certloom-callout-info certloom-setup-callout" role="status">
			<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<div><strong><?php esc_html_e( 'Finish first-run setup', 'certloom' ); ?></strong><p><?php echo esc_html( sprintf( __( '%1$d of %2$d recommended setup steps are complete. Confirm your organization, public verification flow and display choices when convenient; Certloom never forces an activation redirect.', 'certloom' ), $setup_progress_completed, $setup_progress_total ) ); ?></p></div>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-setup' ) ); ?>"><?php esc_html_e( 'Open setup', 'certloom' ); ?></a>
		</div>
	<?php endif; endif; ?>

	<?php if ( $review > 0 ) : ?>
		<div class="certloom-callout certloom-callout-warning" role="status">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div>
				<strong><?php echo esc_html( sprintf( _n( '%d legacy Expired record needs review', '%d legacy Expired records need review', $review, 'certloom' ), $review ) ); ?></strong>
				<p><?php esc_html_e( 'These records remain Expired until an administrator deliberately confirms their workflow status.', 'certloom' ); ?></p>
			</div>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => Ecapla_CertVerify_Admin::CERTIFICATES_PAGE, 'legacy_expired' => '1' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Review records', 'certloom' ); ?></a>
		</div>
	<?php endif; ?>

	<section class="certloom-metric-grid" aria-label="<?php esc_attr_e( 'Certificate summary', 'certloom' ); ?>">
		<a class="certloom-metric-card" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>">
			<span class="certloom-metric-icon"><span class="dashicons dashicons-awards" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Total certificates', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['total'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'View registry', 'certloom' ); ?></span>
		</a>
		<a class="certloom-metric-card" href="<?php echo esc_url( add_query_arg( array( 'page' => Ecapla_CertVerify_Admin::CERTIFICATES_PAGE, 'status' => 'valid' ), admin_url( 'admin.php' ) ) ); ?>">
			<span class="certloom-metric-icon is-valid"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Valid', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['valid'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'Current credentials', 'certloom' ); ?></span>
		</a>
		<a class="certloom-metric-card" href="<?php echo esc_url( add_query_arg( array( 'page' => Ecapla_CertVerify_Admin::CERTIFICATES_PAGE, 'status' => 'suspended' ), admin_url( 'admin.php' ) ) ); ?>">
			<span class="certloom-metric-icon is-suspended"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Suspended', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['suspended'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'Temporarily inactive', 'certloom' ); ?></span>
		</a>
		<a class="certloom-metric-card" href="<?php echo esc_url( add_query_arg( array( 'page' => Ecapla_CertVerify_Admin::CERTIFICATES_PAGE, 'status' => 'withdrawn' ), admin_url( 'admin.php' ) ) ); ?>">
			<span class="certloom-metric-icon is-withdrawn"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Withdrawn', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['withdrawn'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'No longer valid', 'certloom' ); ?></span>
		</a>
		<a class="certloom-metric-card" href="<?php echo esc_url( add_query_arg( array( 'page' => Ecapla_CertVerify_Admin::CERTIFICATES_PAGE, 'status' => 'expired' ), admin_url( 'admin.php' ) ) ); ?>">
			<span class="certloom-metric-icon is-expired"><span class="dashicons dashicons-clock" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Expired', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['expired'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'Past validity date', 'certloom' ); ?></span>
		</a>
		<a class="certloom-metric-card" href="<?php echo esc_url( add_query_arg( array( 'page' => Ecapla_CertVerify_Admin::CERTIFICATES_PAGE, 'attention' => 'expiring_soon' ), admin_url( 'admin.php' ) ) ); ?>">
			<span class="certloom-metric-icon is-expiring"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Expiring in 30 days', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['expiring_soon'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'Review upcoming expiries', 'certloom' ); ?></span>
		</a>
		<div class="certloom-metric-card is-static">
			<span class="certloom-metric-icon is-issued"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span></span>
			<span class="certloom-metric-label"><?php esc_html_e( 'Issued this month', 'certloom' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $metrics['issued_this_month'] ) ); ?></strong>
			<span class="certloom-metric-meta"><?php esc_html_e( 'By issue date', 'certloom' ); ?></span>
		</div>
	</section>

	<?php if ( isset( $dashboard_analytics ) && is_array( $dashboard_analytics ) ) :
		$verification_30 = $dashboard_analytics['verification'];
		$issuance_30 = $dashboard_analytics['issuance'];
		$top_verified = ! empty( $verification_30['most_verified'][0] ) ? $verification_30['most_verified'][0] : null;
		$top_program = ! empty( $issuance_30['dimensions']['programs'][0] ) ? $issuance_30['dimensions']['programs'][0] : null;
		$top_template = ! empty( $issuance_30['dimensions']['templates'][0] ) ? $issuance_30['dimensions']['templates'][0] : null;
	?>
	<section class="certloom-panel certloom-dashboard-analytics-preview" aria-labelledby="certloom-dashboard-analytics-title">
		<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Last 30 days', 'certloom' ); ?></span><h2 id="certloom-dashboard-analytics-title"><?php esc_html_e( 'Credential analytics', 'certloom' ); ?></h2></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-analytics' ) ); ?>"><?php esc_html_e( 'Open Analytics', 'certloom' ); ?> <span aria-hidden="true">→</span></a></div>
		<div class="certloom-dashboard-analytics-grid">
			<article><span><?php esc_html_e( 'Issued', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $issuance_30['summary']['total'] ) ); ?></strong></article>
			<article><span><?php esc_html_e( 'Verification lookups', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $verification_30['summary']['total'] ) ); ?></strong></article>
			<article><span><?php esc_html_e( 'Failed lookups', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $verification_30['summary']['failed'] + (int) $verification_30['summary']['invalid'] ) ); ?></strong></article>
			<article><span><?php esc_html_e( 'Expiring ≤90 days', 'certloom' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $issuance_30['expiry_forecast']['total_90'] ) ); ?></strong></article>
		</div>
		<div class="certloom-dashboard-analytics-charts">
			<section aria-labelledby="certloom-dashboard-issuance-chart-title"><h3 id="certloom-dashboard-issuance-chart-title"><?php esc_html_e( 'Issuance trend', 'certloom' ); ?></h3><div class="certloom-dashboard-mini-chart" role="img" aria-label="<?php esc_attr_e( 'Daily issued credentials for the last 30 days', 'certloom' ); ?>"><?php $issuance_max=1; foreach($issuance_30['trend'] as $row){$issuance_max=max($issuance_max,(int)$row['total']);} foreach($issuance_30['trend'] as $row): $height=max(2,(int)round(100*(int)$row['total']/$issuance_max)); ?><span style="--certloom-dashboard-chart-height:<?php echo esc_attr($height); ?>%"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%1$s: %2$d issued', 'certloom' ), $row['label'], (int)$row['total'] ) ); ?></span></span><?php endforeach; ?></div></section>
			<section aria-labelledby="certloom-dashboard-verification-chart-title"><h3 id="certloom-dashboard-verification-chart-title"><?php esc_html_e( 'Verification trend', 'certloom' ); ?></h3><div class="certloom-dashboard-mini-chart" role="img" aria-label="<?php esc_attr_e( 'Daily verification lookups for the last 30 days', 'certloom' ); ?>"><?php $verification_max=1; foreach($verification_30['trend'] as $row){$row_total=(int)$row['success']+(int)$row['failed']+(int)$row['invalid']+(int)$row['expired']+(int)$row['revoked'];$verification_max=max($verification_max,$row_total);} foreach($verification_30['trend'] as $row): $row_total=(int)$row['success']+(int)$row['failed']+(int)$row['invalid']+(int)$row['expired']+(int)$row['revoked'];$height=max(2,(int)round(100*$row_total/$verification_max)); ?><span style="--certloom-dashboard-chart-height:<?php echo esc_attr($height); ?>%"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%1$s: %2$d lookups', 'certloom' ), $row['label'], $row_total ) ); ?></span></span><?php endforeach; ?></div></section>
		</div>
		<div class="certloom-dashboard-analytics-leaders">
			<p><strong><?php esc_html_e( 'Top credential', 'certloom' ); ?>:</strong> <?php echo $top_verified ? esc_html( (string) $top_verified['certificate_number'] ) . ' · ' . esc_html( number_format_i18n( (int) $top_verified['total'] ) ) : esc_html__( 'No resolved verification activity yet', 'certloom' ); ?></p>
			<p><strong><?php esc_html_e( 'Top Program', 'certloom' ); ?>:</strong> <?php echo $top_program ? esc_html( (string) $top_program['label'] ) . ' · ' . esc_html( number_format_i18n( (int) $top_program['total'] ) ) : esc_html__( 'No issuance activity yet', 'certloom' ); ?></p>
			<p><strong><?php esc_html_e( 'Top Template', 'certloom' ); ?>:</strong> <?php echo $top_template ? esc_html( (string) $top_template['label'] ) . ' · ' . esc_html( number_format_i18n( (int) $top_template['total'] ) ) : esc_html__( 'No issuance activity yet', 'certloom' ); ?></p>
		</div>
	</section>
	<?php endif; ?>

	<div class="certloom-dashboard-layout">
		<section class="certloom-panel certloom-recent-panel" aria-labelledby="certloom-recent-title">
			<div class="certloom-panel-header">
				<div>
					<span class="certloom-eyebrow"><?php esc_html_e( 'Registry', 'certloom' ); ?></span>
					<h2 id="certloom-recent-title"><?php esc_html_e( 'Recent certificates', 'certloom' ); ?></h2>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'View all', 'certloom' ); ?> <span aria-hidden="true">→</span></a>
			</div>
			<?php if ( empty( $recent ) ) : ?>
				<div class="certloom-premium-empty-state">
					<span class="dashicons dashicons-awards" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'No certificates yet', 'certloom' ); ?></h3>
					<p><?php esc_html_e( 'Create your first certificate or import existing records to populate the registry.', 'certloom' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>"><?php esc_html_e( 'Create first certificate', 'certloom' ); ?></a>
				</div>
			<?php else : ?>
				<div class="certloom-recent-table-wrap">
				<table class="widefat striped certloom-recent-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Certificate', 'certloom' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Holder', 'certloom' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'certloom' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Issued', 'certloom' ); ?></th>
						<th scope="col" class="certloom-table-action"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'certloom' ); ?></span></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $recent as $certificate ) :
						$status = Ecapla_CertVerify_Database::effective_status( $certificate );
						$label  = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : ucfirst( $status );
						$edit_url = add_query_arg( array( 'page' => 'certloom-add', 'id' => absint( $certificate['id'] ?? 0 ) ), admin_url( 'admin.php' ) );
					?>
						<tr>
							<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( (string) ( $certificate['cert_number'] ?? '' ) ); ?></a></strong></td>
							<td><?php echo esc_html( (string) ( $certificate['holder_name'] ?? '' ) ); ?></td>
							<td><span class="ecapla-certverify-badge ecapla-certverify-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></span></td>
							<td><?php echo ! empty( $certificate['issue_date'] ) ? esc_html( Ecapla_CertVerify_Validator::format_date( $certificate['issue_date'] ) ) : '<span aria-hidden="true">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal fallback only. ?></td>
							<td class="certloom-table-action"><a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Open', 'certloom' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</section>

		<aside class="certloom-dashboard-sidebar" aria-label="<?php esc_attr_e( 'Dashboard tools', 'certloom' ); ?>">
			<section class="certloom-panel" aria-labelledby="certloom-quick-actions-title">
				<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Shortcuts', 'certloom' ); ?></span><h2 id="certloom-quick-actions-title"><?php esc_html_e( 'Quick actions', 'certloom' ); ?></h2></div></div>
				<nav class="certloom-quick-links" aria-label="<?php esc_attr_e( 'Certloom quick actions', 'certloom' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Add certificate', 'certloom' ); ?></strong><small><?php esc_html_e( 'Create one registry record', 'certloom' ); ?></small></span><span aria-hidden="true">→</span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-import' ) ); ?>"><span class="dashicons dashicons-database-import" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Import & export', 'certloom' ); ?></strong><small><?php esc_html_e( 'CSV and complete backups', 'certloom' ); ?></small></span><span aria-hidden="true">→</span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-fields' ) ); ?>"><span class="dashicons dashicons-editor-table" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Manage fields', 'certloom' ); ?></strong><small><?php esc_html_e( 'Labels, visibility and custom data', 'certloom' ); ?></small></span><span aria-hidden="true">→</span></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-frontend' ) ); ?>"><span class="dashicons dashicons-layout" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Appearance & embed', 'certloom' ); ?></strong><small><?php esc_html_e( 'Verification presentation and embeds', 'certloom' ); ?></small></span><span aria-hidden="true">→</span></a>
				</nav>
			</section>

			<section class="certloom-panel certloom-health-panel is-<?php echo esc_attr( $health_overall ); ?>" aria-labelledby="certloom-health-title">
				<div class="certloom-panel-header">
					<div><span class="certloom-eyebrow"><?php esc_html_e( 'System health', 'certloom' ); ?></span><h2 id="certloom-health-title"><?php echo esc_html( $health_label ); ?></h2></div>
					<span class="certloom-health-dot" aria-hidden="true"></span>
				</div>
				<?php if ( empty( $health['issues'] ) ) : ?>
					<p><?php esc_html_e( 'The credential registry and system checks are healthy.', 'certloom' ); ?></p>
				<?php else : ?>
					<ul class="certloom-health-list">
					<?php foreach ( $health['issues'] as $issue ) : ?>
						<li><span class="dashicons <?php echo 'bad' === $issue['status'] ? 'dashicons-dismiss' : 'dashicons-warning'; ?>" aria-hidden="true"></span><span><strong><?php echo esc_html( (string) ( $issue['label'] ?? '' ) ); ?></strong><small><?php echo esc_html( (string) ( $issue['value'] ?? '' ) ); ?></small></span></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-settings#certloom-system-status' ) ); ?>"><?php esc_html_e( 'Open System Status', 'certloom' ); ?></a>
			</section>

			<section class="certloom-panel certloom-engine-panel" aria-labelledby="certloom-engine-title">
				<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Data safety', 'certloom' ); ?></span><h2 id="certloom-engine-title"><?php esc_html_e( 'Your certificate records stay protected', 'certloom' ); ?></h2></div></div>
				<p><?php esc_html_e( 'Certloom keeps your current certificate records in the proven live registry while newer data features remain protected until their safety checks are complete.', 'certloom' ); ?></p>
				<div class="certloom-engine-status"><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e( 'No automatic storage changes', 'certloom' ); ?></div>
			</section>
		</aside>
	</div>
</div>
