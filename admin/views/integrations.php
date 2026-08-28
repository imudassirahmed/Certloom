<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-integrations-workspace">
	<section class="certloom-page-hero" aria-labelledby="certloom-integrations-title">
		<div>
			<span class="certloom-eyebrow"><?php esc_html_e( 'Automation foundation', 'certloom' ); ?></span>
			<h1 id="certloom-integrations-title"><?php esc_html_e( 'Integrations', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Certloom now has a provider-neutral integration contract for normalized completion events, certificate-specific eligibility, exact learner linking, server-owned mappings, and idempotent issuance. Concrete LMS adapters are intentionally separate releases.', 'certloom' ); ?></p>
		</div>
	</section>

	<section class="certloom-panel certloom-integration-foundation" aria-labelledby="certloom-integration-foundation-title">
		<div class="certloom-panel-head">
			<div><span class="certloom-eyebrow"><?php echo esc_html( $framework['contract'] ); ?></span><h2 id="certloom-integration-foundation-title"><?php echo esc_html( $framework['status'] ); ?></h2></div>
			<span class="certloom-status-pill certloom-status-valid"><?php esc_html_e( 'Ready', 'certloom' ); ?></span>
		</div>
		<div class="certloom-roadmap-grid certloom-integration-contract-grid">
			<div><strong><?php esc_html_e( 'Normalized event context', 'certloom' ); ?></strong><p><?php esc_html_e( 'Bounded provider-neutral source object, attempts, score/percentage, certificate threshold, completion state, and deterministic source-event identity.', 'certloom' ); ?></p></div>
			<div><strong><?php esc_html_e( 'Eligibility', 'certloom' ); ?></strong><p><?php esc_html_e( 'Certloom selects the latest completed attempt that satisfies the certificate-specific threshold rather than trusting a provider pass flag.', 'certloom' ); ?></p></div>
			<div><strong><?php esc_html_e( 'Identity and mapping', 'certloom' ); ?></strong><p><?php esc_html_e( 'Only an explicit WordPress-user-to-Recipient link and a server-owned Program/Template mapping can advance toward issuance.', 'certloom' ); ?></p></div>
			<div><strong><?php esc_html_e( 'Issuance provenance', 'certloom' ); ?></strong><p><?php esc_html_e( 'Accepted events reuse Certloom’s immutable issuance path under fixed lms_integration provenance and replay-safe idempotency.', 'certloom' ); ?></p></div>
		</div>
	</section>

	<section class="certloom-panel" aria-labelledby="certloom-integration-adapters-title">
		<div class="certloom-panel-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'First-party adapters', 'certloom' ); ?></span><h2 id="certloom-integration-adapters-title"><?php esc_html_e( 'No LMS adapter is active yet', 'certloom' ); ?></h2></div></div>
		<p><?php esc_html_e( 'Tutor LMS and LearnDash will be implemented as adapters to this framework, not as new credential-writing systems. UX-01 + FEUX-01 are now implemented; Program Profiles and the remaining approved UX program continue before Tutor LMS so integrations land on the simpler Program/Profile/Template experience.', 'certloom' ); ?></p>
		<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Next execution sequence', 'certloom' ); ?></strong><p><?php echo esc_html( $framework['next'] ); ?></p></div></div>
	</section>
</div>
