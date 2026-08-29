<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$days = isset( $analytics_days ) ? \Certloom\Application\Analytics\IssuanceAnalyticsService::normalize_period( $analytics_days ) : 30;
$report = isset( $analytics_report ) ? $analytics_report : new WP_Error( 'certloom_issuance_analytics_missing', __( 'Issuance analytics are unavailable.', 'certloom' ) );
$periods = array( 7 => __( '7 days', 'certloom' ), 30 => __( '30 days', 'certloom' ), 90 => __( '90 days', 'certloom' ), 365 => __( '12 months', 'certloom' ) );
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-analytics certloom-issuance-analytics">
	<nav class="nav-tab-wrapper certloom-analytics-tabs" aria-label="<?php esc_attr_e( 'Analytics reports', 'certloom' ); ?>">
		<a class="nav-tab" href="<?php echo esc_url( add_query_arg( array( 'page' => 'certloom-analytics', 'section' => 'verification', 'days' => $days ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Verification', 'certloom' ); ?></a>
		<a class="nav-tab nav-tab-active" aria-current="page" href="<?php echo esc_url( add_query_arg( array( 'page' => 'certloom-analytics', 'section' => 'issuance', 'days' => $days ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Issuance', 'certloom' ); ?></a>
	</nav>
	<section class="certloom-dashboard-hero" aria-labelledby="certloom-issuance-analytics-title">
		<div class="certloom-dashboard-hero-copy">
			<span class="certloom-eyebrow"><?php esc_html_e( 'Issuance insights', 'certloom' ); ?></span>
			<h1 id="certloom-issuance-analytics-title"><?php esc_html_e( 'Issuance Analytics', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Measure issued credentials by Program, Template, organization and Batch, with a current 90-day expiry forecast.', 'certloom' ); ?></p>
		</div>
		<form method="get" class="certloom-analytics-period">
			<input type="hidden" name="page" value="certloom-analytics" />
			<input type="hidden" name="section" value="issuance" />
			<label for="certloom-issuance-analytics-days"><?php esc_html_e( 'Reporting period', 'certloom' ); ?></label>
			<select id="certloom-issuance-analytics-days" name="days"><?php foreach ( $periods as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $days, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
			<button class="button"><?php esc_html_e( 'Apply', 'certloom' ); ?></button>
		</form>
	</section>

	<?php if ( is_wp_error( $report ) ) : ?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div>
	<?php else : ?>
		<?php
		$summary = $report['summary']; $forecast = $report['expiry_forecast'];
		$granularity = (string) $report['trend_granularity'];
		$trend_label = 'monthly' === $granularity ? __( 'Monthly trend', 'certloom' ) : ( 'weekly' === $granularity ? __( 'Weekly trend', 'certloom' ) : __( 'Daily trend', 'certloom' ) );
		$bucket_label = 'monthly' === $granularity ? __( 'Month', 'certloom' ) : ( 'weekly' === $granularity ? __( 'Week starting', 'certloom' ) : __( 'Date', 'certloom' ) );
		?>
		<section class="certloom-analytics-metrics certloom-issuance-metrics" aria-label="<?php esc_attr_e( 'Issuance summary', 'certloom' ); ?>">
			<?php foreach ( array(
				array( $summary['total'], __( 'Issued', 'certloom' ) ),
				array( $summary['manual'], __( 'Manual', 'certloom' ) ),
				array( $summary['bulk'], __( 'Bulk', 'certloom' ) ),
				array( $forecast['buckets']['0_30'], __( 'Expiring ≤30 days', 'certloom' ) ),
				array( $forecast['total_90'], __( 'Expiring ≤90 days', 'certloom' ) ),
				array( $forecast['permanent'], __( 'Permanent current', 'certloom' ) ),
			) as $metric ) : ?><article class="certloom-domain-metric"><strong><?php echo esc_html( number_format_i18n( (int) $metric[0] ) ); ?></strong><span><?php echo esc_html( $metric[1] ); ?></span></article><?php endforeach; ?>
		</section>

		<div class="certloom-analytics-layout">
			<section class="certloom-manual-card certloom-analytics-trend" aria-labelledby="certloom-issuance-trend-title">
				<div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php echo esc_html( $trend_label ); ?></span><h2 id="certloom-issuance-trend-title"><?php esc_html_e( 'Issued credentials', 'certloom' ); ?></h2></div></div>
				<?php if ( 0 === (int) $summary['total'] ) : ?><p class="certloom-domain-empty"><strong><?php esc_html_e( 'No issuance activity in this period.', 'certloom' ); ?></strong><span><?php esc_html_e( 'Only credentials actually issued during this reporting period are counted. Data migrations and relationship corrections are excluded.', 'certloom' ); ?></span></p>
				<?php else : ?>
					<div class="certloom-analytics-chart" role="img" aria-label="<?php esc_attr_e( 'Credential issuance trend', 'certloom' ); ?>"><?php $max = 1; foreach ( $report['trend'] as $row ) { $max = max( $max, (int) $row['total'] ); } foreach ( $report['trend'] as $row ) : $height = max( 2, (int) round( 100 * (int) $row['total'] / $max ) ); ?><div class="certloom-analytics-bar" style="--certloom-analytics-height:<?php echo esc_attr( $height ); ?>%" title="<?php echo esc_attr( $row['label'] . ': ' . (int) $row['total'] ); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( __( '%1$s: %2$d issued credentials', 'certloom' ), $row['label'], (int) $row['total'] ) ); ?></span></div><?php endforeach; ?></div>
					<div class="certloom-analytics-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Issuance trend table', 'certloom' ); ?>"><table class="widefat striped"><thead><tr><th><?php echo esc_html( $bucket_label ); ?></th><th><?php esc_html_e( 'Issued', 'certloom' ); ?></th></tr></thead><tbody><?php foreach ( $report['trend'] as $row ) : ?><tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></td></tr><?php endforeach; ?></tbody></table></div>
				<?php endif; ?>
			</section>
			<aside class="certloom-analytics-sidebar">
				<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Issuance source', 'certloom' ); ?></span><h2><?php esc_html_e( 'How credentials were issued', 'certloom' ); ?></h2></div></div><dl class="certloom-analytics-source-list"><div><dt><?php esc_html_e( 'Manual', 'certloom' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $summary['manual'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Bulk', 'certloom' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $summary['bulk'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Other', 'certloom' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $summary['other'] ) ); ?></dd></div></dl></section>
				<section class="certloom-manual-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Historical accuracy', 'certloom' ); ?></span><h2><?php esc_html_e( 'Issued credential details stay fixed', 'certloom' ); ?></h2></div></div><p><?php esc_html_e( 'Program, Template and organization attribution comes from the details stored when the credential was issued. Batch is shown as Unattributed when older issuance did not store a Batch; Certloom never guesses it from later changes.', 'certloom' ); ?></p></section>
			</aside>
		</div>

		<section class="certloom-manual-card certloom-analytics-most certloom-issuance-dimensions" aria-labelledby="certloom-issuance-dimensions-title"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Issuance mix', 'certloom' ); ?></span><h2 id="certloom-issuance-dimensions-title"><?php esc_html_e( 'Top issuance dimensions', 'certloom' ); ?></h2></div></div><div class="certloom-issuance-dimension-grid">
		<?php foreach ( array( 'programs' => __( 'Programs', 'certloom' ), 'templates' => __( 'Templates', 'certloom' ), 'organizations' => __( 'Organizations', 'certloom' ), 'batches' => __( 'Batches', 'certloom' ) ) as $key => $title ) : ?><section><h3><?php echo esc_html( $title ); ?></h3><?php if ( empty( $report['dimensions'][ $key ] ) ) : ?><p><?php esc_html_e( 'No issuance data in this period.', 'certloom' ); ?></p><?php else : ?><ol class="certloom-analytics-ranking"><?php foreach ( $report['dimensions'][ $key ] as $row ) : ?><li><span><?php echo esc_html( $row['label'] ); ?></span><strong><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></strong></li><?php endforeach; ?></ol><?php endif; ?></section><?php endforeach; ?>
		</div></section>

		<section class="certloom-manual-card certloom-analytics-most" aria-labelledby="certloom-expiry-forecast-title"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Forward-looking', 'certloom' ); ?></span><h2 id="certloom-expiry-forecast-title"><?php esc_html_e( '90-day expiry forecast', 'certloom' ); ?></h2></div></div>
			<div class="certloom-expiry-buckets" aria-label="<?php esc_attr_e( 'Expiry forecast summary', 'certloom' ); ?>"><article><strong><?php echo esc_html( number_format_i18n( $forecast['buckets']['0_30'] ) ); ?></strong><span><?php esc_html_e( '0–30 days', 'certloom' ); ?></span></article><article><strong><?php echo esc_html( number_format_i18n( $forecast['buckets']['31_60'] ) ); ?></strong><span><?php esc_html_e( '31–60 days', 'certloom' ); ?></span></article><article><strong><?php echo esc_html( number_format_i18n( $forecast['buckets']['61_90'] ) ); ?></strong><span><?php esc_html_e( '61–90 days', 'certloom' ); ?></span></article></div>
			<p><?php esc_html_e( 'Forecasts use each Series’ current issued Version and exclude revoked credentials. This is operational expiry visibility only; renewal reminders and re-certification remain the separate REN-01 workflow.', 'certloom' ); ?></p>
			<?php if ( ! empty( $forecast['top_programs'] ) ) : ?><div class="certloom-analytics-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Programs with upcoming expiries', 'certloom' ); ?>"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Program', 'certloom' ); ?></th><th><?php esc_html_e( 'Expiring within 90 days', 'certloom' ); ?></th></tr></thead><tbody><?php foreach ( $forecast['top_programs'] as $row ) : ?><tr><th scope="row"><?php echo esc_html( $row['label'] ); ?></th><td><?php echo esc_html( number_format_i18n( $row['total'] ) ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
		</section>
	<?php endif; ?>
</div>
