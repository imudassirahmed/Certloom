<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$days = isset( $analytics_days ) ? Ecapla_CertVerify_Analytics::normalize_period( $analytics_days ) : 30;
$report = isset( $analytics_report ) ? $analytics_report : new WP_Error( 'certloom_analytics_missing', __( 'Verification analytics are unavailable.', 'certloom' ) );
$periods = array( 7 => __( '7 days', 'certloom' ), 30 => __( '30 days', 'certloom' ), 90 => __( '90 days', 'certloom' ), 365 => __( '12 months', 'certloom' ) );
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-analytics">
	<nav class="nav-tab-wrapper certloom-analytics-tabs" aria-label="<?php esc_attr_e( 'Analytics reports', 'certloom' ); ?>">
		<a class="nav-tab nav-tab-active" aria-current="page" href="<?php echo esc_url( add_query_arg( array( 'page' => 'certloom-analytics', 'section' => 'verification', 'days' => $days ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Verification', 'certloom' ); ?></a>
		<a class="nav-tab" href="<?php echo esc_url( add_query_arg( array( 'page' => 'certloom-analytics', 'section' => 'issuance', 'days' => $days ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Issuance', 'certloom' ); ?></a>
	</nav>
	<section class="certloom-dashboard-hero" aria-labelledby="certloom-analytics-title">
		<div class="certloom-dashboard-hero-copy">
			<span class="certloom-eyebrow"><?php esc_html_e( 'Verification insights', 'certloom' ); ?></span>
			<h1 id="certloom-analytics-title"><?php esc_html_e( 'Verification Analytics', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Understand verification outcomes and trends without storing raw IP addresses, User-Agents, secondary proofs, or failed certificate-number input.', 'certloom' ); ?></p>
		</div>
		<form method="get" class="certloom-analytics-period">
			<input type="hidden" name="page" value="certloom-analytics" />
			<input type="hidden" name="section" value="verification" />
			<label for="certloom-analytics-days"><?php esc_html_e( 'Reporting period', 'certloom' ); ?></label>
			<select id="certloom-analytics-days" name="days">
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $days, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Apply', 'certloom' ); ?></button>
		</form>
	</section>

	<?php if ( is_wp_error( $report ) ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div>
	<?php else : ?>
		<?php
		$summary = $report['summary'];
		$total = max( 0, (int) $summary['total'] );
		$granularity = isset( $report['trend_granularity'] ) ? (string) $report['trend_granularity'] : 'daily';
		$trend_label = 'monthly' === $granularity ? __( 'Monthly trend', 'certloom' ) : ( 'weekly' === $granularity ? __( 'Weekly trend', 'certloom' ) : __( 'Daily trend', 'certloom' ) );
		$bucket_label = 'monthly' === $granularity ? __( 'Month', 'certloom' ) : ( 'weekly' === $granularity ? __( 'Week starting', 'certloom' ) : __( 'Date', 'certloom' ) );
		$chart_label = 'monthly' === $granularity ? __( 'Monthly verification lookup totals', 'certloom' ) : ( 'weekly' === $granularity ? __( 'Weekly verification lookup totals', 'certloom' ) : __( 'Daily verification lookup totals', 'certloom' ) );
		$table_label = 'monthly' === $granularity ? __( 'Monthly verification trend table', 'certloom' ) : ( 'weekly' === $granularity ? __( 'Weekly verification trend table', 'certloom' ) : __( 'Daily verification trend table', 'certloom' ) );
		?>
		<section class="certloom-analytics-metrics" aria-label="<?php esc_attr_e( 'Verification summary', 'certloom' ); ?>">
			<?php
			$metrics = array(
				array( 'value' => $total, 'label' => __( 'Total lookups', 'certloom' ) ),
				array( 'value' => (int) $summary['success'], 'label' => __( 'Successful', 'certloom' ) ),
				array( 'value' => (int) $summary['failed'], 'label' => __( 'Failed', 'certloom' ) ),
				array( 'value' => (int) $summary['invalid'], 'label' => __( 'Invalid input', 'certloom' ) ),
				array( 'value' => (int) $summary['expired'], 'label' => __( 'Expired', 'certloom' ) ),
				array( 'value' => (int) $summary['revoked'], 'label' => __( 'Revoked', 'certloom' ) ),
			);
			foreach ( $metrics as $metric ) : ?>
				<article class="certloom-domain-metric"><strong><?php echo esc_html( number_format_i18n( $metric['value'] ) ); ?></strong><span><?php echo esc_html( $metric['label'] ); ?></span></article>
			<?php endforeach; ?>
		</section>

		<div class="certloom-analytics-layout">
			<section class="certloom-manual-card certloom-analytics-trend" aria-labelledby="certloom-analytics-trend-title">
				<div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php echo esc_html( $trend_label ); ?></span><h2 id="certloom-analytics-trend-title"><?php esc_html_e( 'Verification activity', 'certloom' ); ?></h2></div></div>
				<?php if ( 0 === $total ) : ?>
					<p class="certloom-domain-empty"><strong><?php esc_html_e( 'No verification activity in this period.', 'certloom' ); ?></strong><span><?php esc_html_e( 'Counts will appear here after public verification requests are recorded.', 'certloom' ); ?></span></p>
				<?php else : ?>
					<div class="certloom-analytics-chart" role="img" aria-label="<?php echo esc_attr( $chart_label ); ?>">
					<?php
					$max_day = 1;
					foreach ( $report['trend'] as $row ) { $max_day = max( $max_day, array_sum( array_intersect_key( $row, array_flip( array( 'success', 'failed', 'invalid', 'expired', 'revoked' ) ) ) ) ); }
					foreach ( $report['trend'] as $row ) :
						$day_total = (int) $row['success'] + (int) $row['failed'] + (int) $row['invalid'] + (int) $row['expired'] + (int) $row['revoked'];
						$height = max( 2, (int) round( 100 * $day_total / $max_day ) ); ?>
						<div class="certloom-analytics-bar" style="--certloom-analytics-height:<?php echo esc_attr( $height ); ?>%" title="<?php echo esc_attr( $row['label'] . ': ' . $day_total ); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%1$s: %2$d verification lookups', 'certloom' ), $row['label'], $day_total ) ); ?></span></div>
					<?php endforeach; ?>
					</div>
					<div class="certloom-analytics-table-wrap" tabindex="0" role="region" aria-label="<?php echo esc_attr( $table_label ); ?>">
						<table class="widefat striped"><thead><tr><th><?php echo esc_html( $bucket_label ); ?></th><th><?php esc_html_e( 'Successful', 'certloom' ); ?></th><th><?php esc_html_e( 'Failed', 'certloom' ); ?></th><th><?php esc_html_e( 'Invalid', 'certloom' ); ?></th><th><?php esc_html_e( 'Expired', 'certloom' ); ?></th><th><?php esc_html_e( 'Revoked', 'certloom' ); ?></th></tr></thead><tbody>
						<?php foreach ( $report['trend'] as $row ) : ?><tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo esc_html( number_format_i18n( $row['success'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['failed'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['invalid'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['expired'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $row['revoked'] ) ); ?></td></tr><?php endforeach; ?>
						</tbody></table>
					</div>
				<?php endif; ?>
			</section>

			<aside class="certloom-analytics-sidebar">
				<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Source', 'certloom' ); ?></span><h2><?php esc_html_e( 'How verification started', 'certloom' ); ?></h2></div></div>
					<dl class="certloom-analytics-source-list"><div><dt><?php esc_html_e( 'Manual / direct', 'certloom' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $report['sources']['manual'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'QR scan', 'certloom' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $report['sources']['qr'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'API', 'certloom' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $report['sources']['api'] ) ); ?></dd></div></dl>
				</section>
				<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Privacy', 'certloom' ); ?></span><h2><?php esc_html_e( 'Aggregates, not visitor logs', 'certloom' ); ?></h2></div></div><p><?php echo esc_html( sprintf( __( 'Certloom keeps daily aggregate counters for up to %d days. It does not store raw IP addresses, User-Agents, secondary proof codes, or raw failed certificate-number input in this analytics table.', 'certloom' ), (int) $report['retention_days'] ) ); ?></p></section>
			</aside>
		</div>

		<section class="certloom-manual-card certloom-analytics-most" aria-labelledby="certloom-most-verified-title"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Credential interest', 'certloom' ); ?></span><h2 id="certloom-most-verified-title"><?php esc_html_e( 'Most verified credentials', 'certloom' ); ?></h2></div></div>
		<?php if ( empty( $report['most_verified'] ) ) : ?><p><?php esc_html_e( 'No resolved credentials have verification activity in this period.', 'certloom' ); ?></p><?php else : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Certificate number', 'certloom' ); ?></th><th><?php esc_html_e( 'Lookups', 'certloom' ); ?></th><th><?php esc_html_e( 'Action', 'certloom' ); ?></th></tr></thead><tbody><?php foreach ( $report['most_verified'] as $row ) : ?><tr><td><code><?php echo esc_html( $row['certificate_number'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add&id=' . absint( $row['credential_id'] ) ) ); ?>"><?php esc_html_e( 'Open credential', 'certloom' ); ?></a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
		</section>
	<?php endif; ?>
</div>
