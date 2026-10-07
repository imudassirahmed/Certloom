<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $wizard_profile_bundle['fields'] ) ) { return; }
?>
<details class="certloom-setup-profile-example">
	<summary><?php esc_html_e( 'See how details work for this profile', 'certloom' ); ?></summary>
	<p><?php esc_html_e( 'These examples suggest who supplies each detail and when it belongs to a Program or an individual award. Choose the actual source and privacy settings below. Existing field settings are kept unless you change them.', 'certloom' ); ?></p>
	<ul>
		<?php foreach ( $wizard_profile_bundle['fields'] as $example ) : ?>
		<li><strong><?php echo esc_html( $example['label'] ); ?></strong><span class="certloom-setup-profile-sample"><?php echo esc_html( $example['sample'] ); ?></span><span><?php echo esc_html( 'recipient' === $example['writer'] ? __( 'Customer / learner supplies this', 'certloom' ) : __( 'Organization supplies this', 'certloom' ) ); ?></span><small><?php echo esc_html( 'program' === $example['lifetime'] ? __( 'Shared for this Program', 'certloom' ) : ( 'registration' === $example['lifetime'] ? __( 'For this customer’s enrollment', 'certloom' ) : __( 'For this individual award or result', 'certloom' ) ) ); ?></small></li>
		<?php endforeach; ?>
	</ul>
	<p class="description"><?php esc_html_e( 'Examples only. No customer answers, scores, field permissions or issuance eligibility are created here.', 'certloom' ); ?></p>
</details>
