<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared add/edit layout retaining inline Program controls and batch popups. */
final class ProgramWorkspaceView {
	public static function assets() {
		if ( 'certloom-domain-programs' !== ( $_GET['page'] ?? '' ) ) { return; } // Read-only asset selection.
		foreach ( array( 'css', 'js' ) as $extension ) {
			$file = 'admin/assets/program-workspace.' . $extension;
			$version = (string) filemtime( ECAPLA_CERTVERIFY_PATH . $file );
			if ( 'css' === $extension ) { wp_enqueue_style( 'certloom-program-workspace', ECAPLA_CERTVERIFY_URL . $file, array( 'ecapla-certverify-admin' ), $version ); }
			else { wp_enqueue_script( 'certloom-program-workspace', ECAPLA_CERTVERIFY_URL . $file, array( 'ecapla-certverify-admin' ), $version, true ); }
		}
	}

	public function open( ?array $program ) {
		$id = absint( $program['id'] ?? 0 );
		echo '<div class="certloom-program-page ecapla-certverify-admin-card" data-certloom-program-page data-program-id="' . esc_attr( $id ) . '" data-batches-url="' . esc_url( rest_url( 'certloom/v1/admin/programs/' . $id . '/batches' ) ) . '"><main class="certloom-program-main">';
	}

	private function batches( ?array $program ) {
		$id = absint( $program['id'] ?? 0 );
		$can_batches = current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		echo '<aside class="certloom-program-batches" aria-labelledby="certloom-program-batches-title"><div class="certloom-program-section-head"><div><span class="certloom-eyebrow">' . esc_html__( 'In this Program', 'certloom' ) . '</span><h2 id="certloom-program-batches-title">' . esc_html__( 'Batches', 'certloom' ) . '</h2></div><button type="button" class="button button-primary" data-program-add-batch' . disabled( $can_batches, false, false ) . '>' . esc_html__( 'Add batch', 'certloom' ) . '</button></div>';
		echo '<p class="description">' . esc_html__( 'Group recipients by intake, cohort or session. Each batch inherits this Program’s fields and certificate.', 'certloom' ) . '</p>';
		if ( $can_batches ) {
			$default_id = absint( $program['profile']['default_batch_id'] ?? 0 );
			$default = $default_id ? ( new \Certloom\Infrastructure\Repository\BatchRepository() )->find( $default_id ) : null;
			$default_name = is_array( $default ) && ! empty( $default['active'] ) && (int) $default['program_id'] === $id ? sprintf( __( 'Default: %s', 'certloom' ), $default['name'] ) : ( $default_id ? __( 'Default batch unavailable', 'certloom' ) : __( 'No default batch', 'certloom' ) );
			echo '<div class="certloom-program-default-batch"><span data-program-default-summary>' . esc_html( $default_name ) . '</span><button type="button" class="button-link" data-program-default-clear' . ( $default_id ? '' : ' hidden' ) . '>' . esc_html__( 'Clear default', 'certloom' ) . '</button></div><p class="description">' . esc_html__( 'The default batch is preselected when issuing. You can choose another batch.', 'certloom' ) . '</p>';
			echo '<label class="certloom-batch-search"><span class="screen-reader-text">' . esc_html__( 'Search this Program’s batches', 'certloom' ) . '</span><input type="search" data-program-batch-search placeholder="' . esc_attr__( 'Search batches…', 'certloom' ) . '"></label><div class="certloom-batch-bulk-toolbar"><label><input type="checkbox" data-program-select-page> ' . esc_html__( 'Select page', 'certloom' ) . '</label><button type="button" class="button" data-program-bulk-open disabled>' . esc_html__( 'Bulk edit', 'certloom' ) . '</button></div><div data-program-batch-list class="certloom-program-batch-list" aria-busy="true"></div><div class="certloom-batch-pagination"><button type="button" class="button" data-program-list-previous disabled>' . esc_html__( 'Previous', 'certloom' ) . '</button><span data-program-list-page></span><button type="button" class="button" data-program-list-next disabled>' . esc_html__( 'Next', 'certloom' ) . '</button></div>';
		} else { echo '<p>' . esc_html__( 'Your role can edit this Program. Ask a batch manager to update its batches.', 'certloom' ) . '</p>'; }
		echo '<p class="certloom-program-feedback" data-program-feedback role="status" aria-live="polite"></p>';
		if ( isset( $_GET['certloom_retry'] ) ) {
			$reload = add_query_arg( $id ? array( 'page' => DomainAdmin::PROGRAMS_PAGE, 'program_id' => $id ) : array( 'page' => DomainAdmin::PROGRAMS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) );
			echo '<p class="description">' . esc_html__( 'Your unsaved draft is retained. If another window changed this Program, review the saved version before trying again.', 'certloom' ) . '</p><a class="button" href="' . esc_url( $reload ) . '">' . esc_html__( 'Discard draft and reload', 'certloom' ) . '</a>';
		}
		echo '<noscript><p>' . esc_html__( 'Enable JavaScript to use the Program settings and batch popups.', 'certloom' ) . '</p></noscript></aside>';
	}

	public static function settings_close() {
		echo '</div><footer><span>' . esc_html__( 'Changes apply when you save the Program.', 'certloom' ) . '</span><button type="button" class="button button-primary" data-program-settings-done>' . esc_html__( 'Done', 'certloom' ) . '</button></footer></dialog>';
	}
	public function design( $assignment, array $templates ) {
		$id = is_array( $assignment ) && ! empty( $assignment['active'] ) ? absint( $assignment['template_id'] ?? 0 ) : 0;
		echo '<section class="certloom-domain-subsection certloom-program-design-section"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Certificate design', 'certloom' ) . '</h3><p>' . esc_html__( 'Choose one design for this Program. Batches inherit it unless you choose an override.', 'certloom' ) . '</p></div></div>';
		$this->design_select( 'program_template_id', $templates, $id );
		echo '<p class="description" data-program-design-source role="status"></p><div class="certloom-program-design-preview" data-program-design-preview-body aria-label="' . esc_attr__( 'Live certificate preview with sample details', 'certloom' ) . '" aria-busy="true"></div><p class="description">' . esc_html__( 'Preview updates automatically with sample details. Changes apply to future certificates when you save this Program.', 'certloom' ) . '</p></section>';
	}

	private function design_select( $name, array $templates, $id = 0 ) {
		if ( $id && ! in_array( $id, array_map( static function ( $t ) { return absint( $t['id'] ); }, $templates ), true ) ) {
			$selected = ( new TemplateRepository() )->find( $id );
			$templates[] = array( 'id' => $id, 'name' => is_array( $selected ) ? (string) $selected['name'] : __( 'Selected design needs review', 'certloom' ) );
		}
		echo '<label class="certloom-domain-field"><strong>' . esc_html__( 'Certificate design', 'certloom' ) . '</strong><select name="' . esc_attr( $name ) . '" data-certloom-context-lookup="template" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-empty-value="0"><option value="0">' . esc_html__( 'Inherit parent design', 'certloom' ) . '</option>';
		foreach ( $templates as $template ) { echo '<option value="' . esc_attr( absint( $template['id'] ) ) . '" ' . selected( $id, absint( $template['id'] ), false ) . '>' . esc_html( (string) $template['name'] ) . '</option>'; }
		echo '</select></label>';
	}
	public function close( ?array $program ) {
		echo '</main>';
		$this->batches( $program );
		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ) { $this->batch_dialogs(); }
		$config = array(
			'nonce' => wp_create_nonce( 'wp_rest' ), 'limit' => ProgramBatchChanges::LIMIT,
			'defaultBatch' => (string) absint( $program['profile']['default_batch_id'] ?? 0 ),
			'fieldLabels' => array_column( \Ecapla_CertVerify_Fields::get_scopable_fields(), 'label', 'key' ),
			'initialBatch' => absint( $_GET['batch_id'] ?? 0 ), 'initialNew' => isset( $_GET['batch_new'] ),
			'previewUrl' => rest_url( 'certloom/v1/admin/template-preview' ),
			'text' => array(
				'empty' => __( 'No batches yet. Add your first intake or cohort.', 'certloom' ), 'noResults' => __( 'No batches match this search.', 'certloom' ),
				'loading' => __( 'Loading…', 'certloom' ), 'failed' => __( 'Could not load batches. Try again.', 'certloom' ),
				'add' => __( 'Add batch', 'certloom' ), 'edit' => __( 'Edit batch', 'certloom' ), 'new' => __( 'New batch', 'certloom' ),
				'prepared' => __( 'Changes prepared. Save Program to apply them.', 'certloom' ), 'undo' => __( 'Undo changes', 'certloom' ),
				'remove' => __( 'Remove draft', 'certloom' ), 'pending' => __( 'Unsaved', 'certloom' ), 'inactive' => __( 'Inactive', 'certloom' ),
				'limit' => __( 'Save the Program before changing more than 25 batches.', 'certloom' ),
				'discard' => __( 'Discard the changes you have not applied in this popup?', 'certloom' ),
				'dates' => __( 'End date must be on or after the start date.', 'certloom' ),
				'fields' => __( 'Choose at least one setting to bulk edit.', 'certloom' ),
				'inherit' => __( 'Inherit parent design', 'certloom' ), 'unavailable' => __( 'Selected design — review before saving', 'certloom' ),
				'assigned' => __( 'Assigned to this Program', 'certloom' ),
				'batchSummary' => __( '%s recipients', 'certloom' ),
				'defaultBatch' => __( 'Default', 'certloom' ), 'noDefault' => __( 'No default batch', 'certloom' ),
				'defaultSummary' => __( 'Default: %s', 'certloom' ), 'defaultUnavailable' => __( 'Default batch unavailable', 'certloom' ),
				'fromProgram' => __( 'From Program', 'certloom' ), 'setHere' => __( 'Set on batch', 'certloom' ),
				'certificate' => __( 'Certificate', 'certloom' ), 'email' => __( 'Issued email design', 'certloom' ), 'signing' => __( 'Signing', 'certloom' ),
				'programFields' => __( 'Fields', 'certloom' ), 'fieldChanges' => __( '%s field changes', 'certloom' ),
				'validityDelivery' => __( 'Validity & delivery', 'certloom' ), 'datesLabel' => __( 'Dates', 'certloom' ), 'instructor' => __( 'Instructor', 'certloom' ),
				'fieldRules' => array( 'required' => __( 'Required', 'certloom' ), 'optional' => __( 'Optional', 'certloom' ), 'exclude' => __( 'Excluded', 'certloom' ) ),
				'selected' => __( '%s selected batches', 'certloom' ),
				'saveMembers' => __( 'Save the Program before managing recipients.', 'certloom' ),
			),
		);
		echo '<script type="application/json" data-program-config>' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script></div>'; // JSON is encoded for a raw-text script element.
	}
	private function batch_dialogs() {
		$choices = new ScopedContextChoices();
		$templates = $choices->templates(); $templates = is_wp_error( $templates ) ? array() : $templates;
		$signers = $choices->signers(); $signers = is_wp_error( $signers ) ? array() : $signers;
		echo '<dialog class="certloom-program-dialog certloom-batch-dialog" data-program-batch-dialog aria-labelledby="certloom-batch-dialog-title"><header><button type="button" class="button certloom-batch-nav" data-program-batch-previous aria-label="' . esc_attr__( 'Previous batch', 'certloom' ) . '">‹</button><div><h2 id="certloom-batch-dialog-title">' . esc_html__( 'Batch settings', 'certloom' ) . '</h2><span data-program-batch-position></span></div><button type="button" class="button certloom-batch-nav" data-program-batch-next aria-label="' . esc_attr__( 'Next batch', 'certloom' ) . '">›</button><button type="button" class="button" data-program-batch-close aria-label="' . esc_attr__( 'Close batch settings', 'certloom' ) . '">×</button></header><form data-program-batch-form><div class="certloom-program-dialog-body" data-program-batch-body><div class="certloom-domain-form-grid">';
		foreach ( array( 'name' => __( 'Batch name', 'certloom' ), 'code' => __( 'Short code (optional)', 'certloom' ), 'start_date' => __( 'Start date', 'certloom' ), 'end_date' => __( 'End date', 'certloom' ) ) as $name => $label ) {
			echo '<label class="certloom-domain-field"><strong>' . esc_html( $label ) . '</strong><input name="' . esc_attr( $name ) . '" type="' . ( false !== strpos( $name, 'date' ) ? 'date' : 'text' ) . '"' . ( 'name' === $name ? ' required maxlength="255"' : ( 'code' === $name ? ' maxlength="100"' : '' ) ) . '></label>';
		}
		echo '<label class="certloom-domain-field"><strong>' . esc_html__( 'Instructor / signer', 'certloom' ) . '</strong><select name="instructor_signer_id" data-certloom-context-lookup="signer" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-empty-value=""><option value="">' . esc_html__( 'No batch-specific signer', 'certloom' ) . '</option>';
		foreach ( $signers as $signer ) { echo '<option value="' . esc_attr( absint( $signer['id'] ) ) . '">' . esc_html( (string) $signer['display_name'] ) . '</option>'; }
		echo '</select></label><label class="certloom-domain-field"><span><input type="checkbox" name="active" value="1" checked> <strong>' . esc_html__( 'Active batch', 'certloom' ) . '</strong></span></label>';
		echo '<label class="certloom-domain-field certloom-domain-field-wide"><span><input type="checkbox" name="default_for_issuance" value="1"> <strong>' . esc_html__( 'Use as default batch for issuance', 'certloom' ) . '</strong></span><span class="description">' . esc_html__( 'One default per Program. Recipients can still be issued to another batch.', 'certloom' ) . '</span></label>';
		$this->design_select( 'batch_template_id', $templates );
		echo '</div><details class="certloom-batch-field-settings"><summary>' . esc_html__( 'Batch fields', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Inherit the Program’s fields, or add stricter requirements for this batch.', 'certloom' ) . '</p>';
		foreach ( \Ecapla_CertVerify_Fields::get_scopable_fields() as $field ) {
			$key = sanitize_key( (string) $field['key'] );
			echo '<label class="certloom-batch-field-row"><span>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</span><select data-program-batch-field="' . esc_attr( $key ) . '"><option value="">' . esc_html__( 'Inherit Program', 'certloom' ) . '</option><option value="optional">' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required">' . esc_html__( 'Required', 'certloom' ) . '</option><option value="exclude">' . esc_html__( 'Exclude', 'certloom' ) . '</option></select></label>';
		}
		echo '</details><p class="description">' . esc_html__( 'Program fields and shared values apply to this batch.', 'certloom' ) . '</p><button type="button" class="button" data-program-batch-members>' . esc_html__( 'Manage recipients', 'certloom' ) . '</button><p data-program-batch-error role="alert"></p></div><footer><span>' . esc_html__( 'Apply here, then save the Program.', 'certloom' ) . '</span><button type="submit" class="button button-primary">' . esc_html__( 'Apply batch', 'certloom' ) . '</button></footer></form><div data-program-batch-members-body class="certloom-program-dialog-body"></div></dialog>';
		echo '<dialog class="certloom-program-dialog" data-program-bulk-dialog aria-labelledby="certloom-bulk-dialog-title"><header><h2 id="certloom-bulk-dialog-title">' . esc_html__( 'Bulk edit batches', 'certloom' ) . '</h2><button type="button" class="button" data-program-bulk-close aria-label="' . esc_attr__( 'Close bulk editor', 'certloom' ) . '">×</button></header><form data-program-bulk-form><div class="certloom-program-dialog-body"><p>' . esc_html__( 'Only checked settings change. Other batch details, fields and recipients stay as they are.', 'certloom' ) . '</p><p data-program-bulk-count></p>';
		foreach ( array( 'start_date' => __( 'Start date', 'certloom' ), 'end_date' => __( 'End date', 'certloom' ), 'batch_template_id' => __( 'Certificate design', 'certloom' ), 'active' => __( 'Active status', 'certloom' ), 'instructor_signer_id' => __( 'Instructor / signer', 'certloom' ) ) as $name => $label ) {
			echo '<div class="certloom-bulk-setting"><label><input type="checkbox" data-program-bulk-enable="' . esc_attr( $name ) . '"> <strong>' . esc_html( $label ) . '</strong></label><div data-program-bulk-value="' . esc_attr( $name ) . '">';
			if ( 'batch_template_id' === $name ) { $this->design_select( $name, $templates ); }
			elseif ( 'active' === $name ) { echo '<select name="active"><option value="1">' . esc_html__( 'Active', 'certloom' ) . '</option><option value="0">' . esc_html__( 'Inactive', 'certloom' ) . '</option></select>'; }
			elseif ( 'instructor_signer_id' === $name ) { echo '<select name="instructor_signer_id"><option value="">' . esc_html__( 'No batch-specific signer', 'certloom' ) . '</option>'; foreach ( $signers as $signer ) { echo '<option value="' . esc_attr( absint( $signer['id'] ) ) . '">' . esc_html( (string) $signer['display_name'] ) . '</option>'; } echo '</select>'; }
			else { echo '<input type="date" name="' . esc_attr( $name ) . '">'; }
			echo '</div></div>';
		}
		echo '<p data-program-bulk-error role="alert"></p></div><footer><span>' . esc_html__( 'Changes apply when you save the Program.', 'certloom' ) . '</span><button type="submit" class="button button-primary">' . esc_html__( 'Apply to selected batches', 'certloom' ) . '</button></footer></form></dialog>';
	}
}
