<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tutor = isset( $tutor ) && is_array( $tutor ) ? $tutor : array();
$tutor_status = isset( $tutor['status'] ) && is_array( $tutor['status'] ) ? $tutor['status'] : array();
$course_labels = array();
foreach ( (array) ( $tutor['courses'] ?? array() ) as $course ) { if ( is_array( $course ) ) { $course_labels[ absint( $course['id'] ?? 0 ) ] = (string) ( $course['title'] ?? '' ); } }
$program_labels = array();
foreach ( (array) ( $tutor['programs'] ?? array() ) as $program ) { if ( is_array( $program ) ) { $program_labels[ absint( $program['id'] ?? 0 ) ] = (string) ( $program['title'] ?? '' ); } }
$template_labels = array();
foreach ( (array) ( $tutor['templates'] ?? array() ) as $template ) { if ( is_array( $template ) ) { $template_labels[ absint( $template['id'] ?? 0 ) ] = (string) ( $template['name'] ?? '' ); } }

$learndash = isset( $learndash ) && is_array( $learndash ) ? $learndash : array();
$learndash_status = isset( $learndash['status'] ) && is_array( $learndash['status'] ) ? $learndash['status'] : array();
$learndash_course_labels = array();
foreach ( (array) ( $learndash['courses'] ?? array() ) as $course ) { if ( is_array( $course ) ) { $learndash_course_labels[ absint( $course['id'] ?? 0 ) ] = (string) ( $course['title'] ?? '' ); } }
$learndash_program_labels = array();
foreach ( (array) ( $learndash['programs'] ?? array() ) as $program ) { if ( is_array( $program ) ) { $learndash_program_labels[ absint( $program['id'] ?? 0 ) ] = (string) ( $program['title'] ?? '' ); } }
$learndash_template_labels = array();
foreach ( (array) ( $learndash['templates'] ?? array() ) as $template ) { if ( is_array( $template ) ) { $learndash_template_labels[ absint( $template['id'] ?? 0 ) ] = (string) ( $template['name'] ?? '' ); } }
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-integrations-workspace">
	<section class="certloom-page-hero" aria-labelledby="certloom-integrations-title">
		<div>
			<span class="certloom-eyebrow"><?php esc_html_e( 'Learning integrations', 'certloom' ); ?></span>
			<h1 id="certloom-integrations-title"><?php esc_html_e( 'Integrations', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Connect supported learning systems to Certloom without creating a second credential database. Course events are normalized, linked to an explicit Certloom recipient, mapped to a Program and published Template, then sent through the same immutable issuance service as reviewed Certloom issuance.', 'certloom' ); ?></p>
		</div>
	</section>

	<?php if ( isset( $_GET['certloom_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirected admin result. ?>
		<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) ); ?></p></div>
	<?php elseif ( isset( $_GET['certloom_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirected admin result.
		$certloom_notice = sanitize_key( wp_unslash( $_GET['certloom_notice'] ) );
		$certloom_notice_message = in_array( $certloom_notice, array( 'mapping_deleted', 'learndash_mapping_deleted' ), true )
			? __( 'Course mapping removed.', 'certloom' )
			: __( 'Course mapping saved.', 'certloom' );
	?>
		<div class="notice notice-success"><p><?php echo esc_html( $certloom_notice_message ); ?></p></div>
	<?php endif; ?>

	<section class="certloom-panel certloom-integration-foundation" aria-labelledby="certloom-integration-foundation-title">
		<div class="certloom-panel-head">
			<div><span class="certloom-eyebrow"><?php echo esc_html( $framework['contract'] ); ?></span><h2 id="certloom-integration-foundation-title"><?php echo esc_html( $framework['status'] ); ?></h2></div>
			<span class="certloom-status-pill certloom-status-valid"><?php esc_html_e( 'Ready', 'certloom' ); ?></span>
		</div>
		<div class="certloom-roadmap-grid certloom-integration-contract-grid">
			<div><strong><?php esc_html_e( 'Completion event', 'certloom' ); ?></strong><p><?php esc_html_e( 'Tutor LMS and LearnDash course completion events are translated into bounded Certloom events. Learner names, email addresses and arbitrary LMS metadata are not copied into integration provenance.', 'certloom' ); ?></p></div>
			<div><strong><?php esc_html_e( 'Recipient identity', 'certloom' ); ?></strong><p><?php esc_html_e( 'The WordPress learner must already be explicitly linked to one Certloom recipient. Certloom never guesses identity from name or email.', 'certloom' ); ?></p></div>
			<div><strong><?php esc_html_e( 'Course mapping', 'certloom' ); ?></strong><p><?php esc_html_e( 'Each enabled LMS course maps to one Certloom Program and one published Template. Changing that server-owned mapping invalidates stale issuance review.', 'certloom' ); ?></p></div>
			<div><strong><?php esc_html_e( 'Duplicate protection', 'certloom' ); ?></strong><p><?php esc_html_e( 'The same LMS course completion for the same WordPress learner reuses one deterministic source identity within that adapter, so hook retries cannot create duplicate credentials.', 'certloom' ); ?></p></div>
		</div>
	</section>

	<section class="certloom-panel" aria-labelledby="certloom-tutor-title">
		<div class="certloom-panel-head">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'First-party LMS adapter', 'certloom' ); ?></span><h2 id="certloom-tutor-title"><?php esc_html_e( 'Tutor LMS', 'certloom' ); ?></h2></div>
			<?php if ( ! empty( $tutor_status['available'] ) ) : ?>
				<span class="certloom-status-pill certloom-status-valid"><?php esc_html_e( 'Detected', 'certloom' ); ?></span>
			<?php else : ?>
				<span class="certloom-status-pill"><?php esc_html_e( 'Not installed', 'certloom' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $tutor_status['available'] ) ) : ?>
			<p><?php printf( /* translators: 1: Tutor LMS version, 2: active mapping count. */ esc_html__( 'Tutor LMS %1$s is available. Certloom currently has %2$d enabled course mapping(s).', 'certloom' ), esc_html( (string) ( $tutor_status['version'] ?: __( 'detected', 'certloom' ) ) ), absint( $tutor_status['active_mapping_count'] ?? 0 ) ); ?></p>
			<?php if ( empty( $tutor_status['authority_ready'] ) ) : ?>
				<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Automatic issuance remains safely locked', 'certloom' ); ?></strong><p><?php esc_html_e( 'The Tutor LMS adapter and mapping workflow are implemented, but Certloom will not write normalized credentials until the existing credential write-authority acceptance gate is opened. Course completion hooks therefore fail closed instead of bypassing Certloom authority.', 'certloom' ); ?></p></div></div>
			<?php else : ?>
				<div class="certloom-callout certloom-callout-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Automatic issuance is ready', 'certloom' ); ?></strong><p><?php esc_html_e( 'Enabled course mappings can issue through Certloom’s existing immutable integration issuance path when Tutor LMS records course completion.', 'certloom' ); ?></p></div></div>
			<?php endif; ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Install and activate Tutor LMS to configure course mappings. Certloom does not require Tutor LMS for any standalone credential workflow.', 'certloom' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( ! empty( $tutor_status['available'] ) ) : ?>
	<section class="certloom-panel" aria-labelledby="certloom-tutor-mappings-title">
		<div class="certloom-panel-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Course mappings', 'certloom' ); ?></span><h2 id="certloom-tutor-mappings-title"><?php esc_html_e( 'Tutor course → Certloom credential', 'certloom' ); ?></h2></div></div>
		<?php if ( ! empty( $tutor['error'] ) && is_wp_error( $tutor['error'] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $tutor['error']->get_error_message() ); ?></p></div>
		<?php elseif ( empty( $tutor['configuration_ready'] ) ) : ?>
			<p><?php esc_html_e( 'Mapping configuration is available with the Certloom Program/Template preview domain. The adapter remains installed and fail-closed while that preview/authority boundary is unavailable.', 'certloom' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-integration-mapping-form">
				<input type="hidden" name="action" value="certloom_tutor_lms_mapping_save">
				<?php wp_nonce_field( 'certloom_tutor_lms_mapping_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="certloom-tutor-course"><?php esc_html_e( 'Tutor course', 'certloom' ); ?></label></th><td><select id="certloom-tutor-course" name="course_id" required><option value=""><?php esc_html_e( 'Choose a course', 'certloom' ); ?></option><?php foreach ( (array) $tutor['courses'] as $course ) : ?><option value="<?php echo esc_attr( absint( $course['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $course['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="certloom-tutor-program"><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></label></th><td><select id="certloom-tutor-program" name="program_id" required><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) $tutor['programs'] as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="certloom-tutor-template"><?php esc_html_e( 'Published Template', 'certloom' ); ?></label></th><td><select id="certloom-tutor-template" name="template_id" required><option value=""><?php esc_html_e( 'Choose a Template', 'certloom' ); ?></option><?php foreach ( (array) $tutor['templates'] as $template ) : ?><option value="<?php echo esc_attr( absint( $template['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $template['name'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="certloom-tutor-expiry"><?php esc_html_e( 'Credential expiry', 'certloom' ); ?></label></th><td><select id="certloom-tutor-expiry" name="expiry_mode"><option value="program"><?php esc_html_e( 'Use Program policy', 'certloom' ); ?></option><option value="permanent"><?php esc_html_e( 'No expiry', 'certloom' ); ?></option><option value="date"><?php esc_html_e( 'Specific date', 'certloom' ); ?></option></select> <input type="date" name="expires_at" aria-label="<?php esc_attr_e( 'Specific expiry date', 'certloom' ); ?>"></td></tr>
				</table>
				<label><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Enable automatic issuance for this course', 'certloom' ); ?></label>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save course mapping', 'certloom' ); ?></button></p>
			</form>
		<?php endif; ?>

		<?php if ( ! empty( $tutor['mappings'] ) ) : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Tutor course', 'certloom' ); ?></th><th><?php esc_html_e( 'Program', 'certloom' ); ?></th><th><?php esc_html_e( 'Template', 'certloom' ); ?></th><th><?php esc_html_e( 'Status', 'certloom' ); ?></th><th><?php esc_html_e( 'Actions', 'certloom' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $tutor['mappings'] as $mapping ) : $course_id = absint( $mapping['course_id'] ?? 0 ); ?>
				<tr><td><?php echo esc_html( $course_labels[ $course_id ] ?? ( __( 'Course #', 'certloom' ) . $course_id ) ); ?></td><td><?php echo esc_html( $program_labels[ absint( $mapping['program_id'] ?? 0 ) ] ?? ( '#' . absint( $mapping['program_id'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( $template_labels[ absint( $mapping['template_id'] ?? 0 ) ] ?? ( '#' . absint( $mapping['template_id'] ?? 0 ) ) ); ?></td><td><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'Enabled', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_tutor_lms_mapping_delete"><input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>"><?php wp_nonce_field( 'certloom_tutor_lms_mapping_delete_' . $course_id ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
	</section>
	<?php endif; ?>


	<section class="certloom-panel" aria-labelledby="certloom-learndash-title">
		<div class="certloom-panel-head">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'First-party LMS adapter', 'certloom' ); ?></span><h2 id="certloom-learndash-title"><?php esc_html_e( 'LearnDash', 'certloom' ); ?></h2></div>
			<?php if ( ! empty( $learndash_status['available'] ) ) : ?>
				<span class="certloom-status-pill certloom-status-valid"><?php esc_html_e( 'Detected', 'certloom' ); ?></span>
			<?php else : ?>
				<span class="certloom-status-pill"><?php esc_html_e( 'Not installed', 'certloom' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( ! empty( $learndash_status['available'] ) ) : ?>
			<p><?php printf( /* translators: 1: LearnDash version, 2: active mapping count. */ esc_html__( 'LearnDash %1$s is available. Certloom currently has %2$d enabled course mapping(s).', 'certloom' ), esc_html( (string) ( $learndash_status['version'] ?: __( 'detected', 'certloom' ) ) ), absint( $learndash_status['active_mapping_count'] ?? 0 ) ); ?></p>
			<?php if ( empty( $learndash_status['authority_ready'] ) ) : ?>
				<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Automatic issuance remains safely locked', 'certloom' ); ?></strong><p><?php esc_html_e( 'The LearnDash adapter and mapping workflow are implemented, but Certloom will not write normalized credentials until the existing credential write-authority acceptance gate is opened. Course completion hooks therefore fail closed instead of bypassing Certloom authority.', 'certloom' ); ?></p></div></div>
			<?php else : ?>
				<div class="certloom-callout certloom-callout-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Automatic issuance is ready', 'certloom' ); ?></strong><p><?php esc_html_e( 'Enabled course mappings can issue through Certloom’s existing immutable integration issuance path when LearnDash records course completion.', 'certloom' ); ?></p></div></div>
			<?php endif; ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Install and activate LearnDash to configure course mappings. Certloom does not require LearnDash for any standalone credential workflow.', 'certloom' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( ! empty( $learndash_status['available'] ) ) : ?>
	<section class="certloom-panel" aria-labelledby="certloom-learndash-mappings-title">
		<div class="certloom-panel-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Course mappings', 'certloom' ); ?></span><h2 id="certloom-learndash-mappings-title"><?php esc_html_e( 'LearnDash course → Certloom credential', 'certloom' ); ?></h2></div></div>
		<?php if ( ! empty( $learndash['error'] ) && is_wp_error( $learndash['error'] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $learndash['error']->get_error_message() ); ?></p></div>
		<?php elseif ( empty( $learndash['configuration_ready'] ) ) : ?>
			<p><?php esc_html_e( 'Mapping configuration is available with the Certloom Program/Template preview domain. The adapter remains installed and fail-closed while that preview/authority boundary is unavailable.', 'certloom' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-integration-mapping-form">
				<input type="hidden" name="action" value="certloom_learndash_mapping_save">
				<?php wp_nonce_field( 'certloom_learndash_mapping_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="certloom-learndash-course"><?php esc_html_e( 'LearnDash course', 'certloom' ); ?></label></th><td><select id="certloom-learndash-course" name="course_id" required><option value=""><?php esc_html_e( 'Choose a course', 'certloom' ); ?></option><?php foreach ( (array) $learndash['courses'] as $course ) : ?><option value="<?php echo esc_attr( absint( $course['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $course['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="certloom-learndash-program"><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></label></th><td><select id="certloom-learndash-program" name="program_id" required><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( (array) $learndash['programs'] as $program ) : ?><option value="<?php echo esc_attr( absint( $program['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $program['title'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="certloom-learndash-template"><?php esc_html_e( 'Published Template', 'certloom' ); ?></label></th><td><select id="certloom-learndash-template" name="template_id" required><option value=""><?php esc_html_e( 'Choose a Template', 'certloom' ); ?></option><?php foreach ( (array) $learndash['templates'] as $template ) : ?><option value="<?php echo esc_attr( absint( $template['id'] ?? 0 ) ); ?>"><?php echo esc_html( (string) ( $template['name'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="certloom-learndash-expiry"><?php esc_html_e( 'Credential expiry', 'certloom' ); ?></label></th><td><select id="certloom-learndash-expiry" name="expiry_mode"><option value="program"><?php esc_html_e( 'Use Program policy', 'certloom' ); ?></option><option value="permanent"><?php esc_html_e( 'No expiry', 'certloom' ); ?></option><option value="date"><?php esc_html_e( 'Specific date', 'certloom' ); ?></option></select> <input type="date" name="expires_at" aria-label="<?php esc_attr_e( 'Specific expiry date', 'certloom' ); ?>"></td></tr>
				</table>
				<label><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Enable automatic issuance for this course', 'certloom' ); ?></label>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save course mapping', 'certloom' ); ?></button></p>
			</form>
		<?php endif; ?>

		<?php if ( ! empty( $learndash['mappings'] ) ) : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'LearnDash course', 'certloom' ); ?></th><th><?php esc_html_e( 'Program', 'certloom' ); ?></th><th><?php esc_html_e( 'Template', 'certloom' ); ?></th><th><?php esc_html_e( 'Status', 'certloom' ); ?></th><th><?php esc_html_e( 'Actions', 'certloom' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $learndash['mappings'] as $mapping ) : $course_id = absint( $mapping['course_id'] ?? 0 ); ?>
				<tr><td><?php echo esc_html( $learndash_course_labels[ $course_id ] ?? ( __( 'Course #', 'certloom' ) . $course_id ) ); ?></td><td><?php echo esc_html( $learndash_program_labels[ absint( $mapping['program_id'] ?? 0 ) ] ?? ( '#' . absint( $mapping['program_id'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( $learndash_template_labels[ absint( $mapping['template_id'] ?? 0 ) ] ?? ( '#' . absint( $mapping['template_id'] ?? 0 ) ) ); ?></td><td><?php echo ! empty( $mapping['active'] ) ? esc_html__( 'Enabled', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_learndash_mapping_delete"><input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>"><?php wp_nonce_field( 'certloom_learndash_mapping_delete_' . $course_id ); ?><button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></form></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<section class="certloom-panel" aria-labelledby="certloom-integration-next-title">
		<div class="certloom-panel-head"><div><span class="certloom-eyebrow"><?php esc_html_e( 'LMS foundation', 'certloom' ); ?></span><h2 id="certloom-integration-next-title"><?php esc_html_e( 'Tutor LMS and LearnDash share one issuance architecture', 'certloom' ); ?></h2></div></div>
		<p><?php esc_html_e( 'Both first-party adapters reuse the same explicit recipient linking, server-owned mapping, eligibility, provenance and idempotent issuance contract rather than introducing separate credential-writing paths.', 'certloom' ); ?></p>
		<div class="certloom-callout certloom-callout-info"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Approved execution sequence', 'certloom' ); ?></strong><p><?php echo esc_html( $framework['next'] ); ?></p></div></div>
	</section>
</div>
