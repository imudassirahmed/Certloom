<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Accessible, compact recipient → review → receipt shell. Data comes from REST. */
final class ProgramIssuancePopup {
	public function render() {
		$terms = UsageProfilePresentation::terms();
		$program = $terms['program']['singular'] ?? __( 'Program', 'certloom' );
		$batch = $terms['batch']['singular'] ?? __( 'Batch', 'certloom' );
		?>
		<dialog class="certloom-admin-shell certloom-program-issuance" data-program-issuance aria-labelledby="certloom-program-issue-title">
			<header class="cl-pi-head">
				<div><span class="certloom-eyebrow" data-pi-progress><?php esc_html_e( '1 · Recipient', 'certloom' ); ?></span><h2 id="certloom-program-issue-title" data-pi-title><?php esc_html_e( 'Issue credential', 'certloom' ); ?></h2></div>
				<button type="button" class="cl-pi-close" data-pi-close aria-label="<?php esc_attr_e( 'Close issuance', 'certloom' ); ?>"><span aria-hidden="true">×</span></button>
			</header>
			<form data-pi-form novalidate>
				<div class="cl-pi-content">
					<section data-pi-select>
						<div class="cl-pi-context" data-pi-context hidden><div><span data-pi-context-label><?php echo esc_html( $program ); ?></span><strong data-pi-context-title></strong><small data-pi-context-batch></small></div><div class="cl-pi-context-actions"><button type="button" class="button-link" data-pi-program-change hidden><?php esc_html_e( 'Change', 'certloom' ); ?></button><a data-pi-manage href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-domain-programs' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit settings', 'certloom' ); ?></a></div></div>
						<div data-pi-picker="program" class="cl-pi-picker" hidden><label for="cl-pi-program"><?php echo esc_html( $program ); ?></label><div class="cl-pi-search"><input id="cl-pi-program" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cl-pi-program-results" placeholder="<?php esc_attr_e( 'Search Programs…', 'certloom' ); ?>"><button type="button" data-pi-clear hidden aria-label="<?php esc_attr_e( 'Change Program', 'certloom' ); ?>">×</button></div><div id="cl-pi-program-results" role="listbox" class="cl-pi-results" hidden></div><small data-pi-search-status role="status"></small></div>
						<div data-pi-batch-line class="cl-pi-batch-line" hidden><button type="button" class="button-link" data-pi-batch-open><?php echo esc_html( sprintf( __( 'Choose %s', 'certloom' ), $batch ) ); ?></button><span data-pi-batch-label></span><button type="button" class="button-link" data-pi-batch-clear hidden><?php esc_html_e( 'Clear', 'certloom' ); ?></button></div>
						<div data-pi-picker="batch" class="cl-pi-picker" hidden><label for="cl-pi-batch"><?php echo esc_html( $batch ); ?></label><div class="cl-pi-search"><input id="cl-pi-batch" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cl-pi-batch-results" placeholder="<?php esc_attr_e( 'Search Batches…', 'certloom' ); ?>"></div><div id="cl-pi-batch-results" role="listbox" class="cl-pi-results" hidden></div><small data-pi-search-status role="status"></small></div>
						<div class="cl-pi-recipient-head"><h3><?php esc_html_e( 'Who receives it?', 'certloom' ); ?></h3><div class="cl-pi-modes" role="group" aria-label="<?php esc_attr_e( 'Recipient type', 'certloom' ); ?>"><button type="button" data-pi-mode="existing" aria-pressed="true"><?php esc_html_e( 'Existing', 'certloom' ); ?></button><button type="button" data-pi-mode="new" aria-pressed="false"><?php esc_html_e( 'New', 'certloom' ); ?></button></div></div>
						<div data-pi-picker="recipient" class="cl-pi-picker"><label class="screen-reader-text" for="cl-pi-recipient"><?php esc_html_e( 'Recipient', 'certloom' ); ?></label><div class="cl-pi-search"><input id="cl-pi-recipient" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cl-pi-recipient-results" placeholder="<?php esc_attr_e( 'Search name, email or external ID…', 'certloom' ); ?>"><button type="button" data-pi-clear hidden aria-label="<?php esc_attr_e( 'Change recipient', 'certloom' ); ?>">×</button></div><div id="cl-pi-recipient-results" role="listbox" class="cl-pi-results" hidden></div><small data-pi-search-status role="status"></small></div>
						<div data-pi-new class="cl-pi-new" hidden><label><?php esc_html_e( 'Full name', 'certloom' ); ?><input name="display_name" maxlength="255" autocomplete="name"></label><label><?php esc_html_e( 'Email (optional)', 'certloom' ); ?><input name="email" type="email" maxlength="320" autocomplete="email"></label></div>
						<div data-pi-enrollment hidden><label for="cl-pi-enrollment"><?php esc_html_e( 'Enrollment', 'certloom' ); ?></label><select id="cl-pi-enrollment"><option value=""><?php esc_html_e( 'Choose enrollment…', 'certloom' ); ?></option></select></div>
						<div class="cl-pi-exceptions"><label data-pi-number hidden><?php esc_html_e( 'Certificate number', 'certloom' ); ?><input name="display_number" maxlength="100"></label><label data-pi-expiry hidden><?php esc_html_e( 'Expiry date', 'certloom' ); ?><input name="expires_at" type="date"><small data-pi-expiry-hint><?php esc_html_e( 'This Program leaves expiry to each issuance. Blank means no expiry.', 'certloom' ); ?></small></label></div>
						<p class="cl-pi-hint" data-pi-hint><?php esc_html_e( 'Fields, certificate design, signing and email rules come from your Program or Batch settings.', 'certloom' ); ?></p>
					</section>
					<section data-pi-review hidden><div class="cl-pi-review-person"><span class="cl-pi-avatar" aria-hidden="true" data-pi-initials></span><div><span><?php esc_html_e( 'Ready to issue to', 'certloom' ); ?></span><h3 data-pi-recipient-name></h3><small data-pi-recipient-email></small></div></div><dl class="cl-pi-facts" data-pi-facts></dl><p class="cl-pi-hint"><?php esc_html_e( 'Required customer details are checked before issuance. Configured delivery rules apply after issuance.', 'certloom' ); ?></p></section>
					<section data-pi-receipt hidden><span class="cl-pi-success" aria-hidden="true">✓</span><h3><?php esc_html_e( 'Credential issued', 'certloom' ); ?></h3><p data-pi-receipt-number></p><p class="cl-pi-hint"><?php esc_html_e( 'View the credential or review its email. Configured delivery rules still apply.', 'certloom' ); ?></p><div class="cl-pi-receipt-links"><a class="button" data-pi-view target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View credential', 'certloom' ); ?></a><a class="button" data-pi-email><?php esc_html_e( 'Review email', 'certloom' ); ?></a></div></section>
					<div class="cl-pi-message" data-pi-message role="status" aria-live="polite" hidden></div>
					<div class="cl-pi-help" data-pi-help hidden><a data-pi-customer target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open recipient details', 'certloom' ); ?></a><a data-pi-program target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Program settings', 'certloom' ); ?></a></div>
				</div>
				<footer class="cl-pi-footer"><button type="button" class="button" data-pi-back hidden><?php esc_html_e( 'Back', 'certloom' ); ?></button><small data-pi-status role="status"></small><button type="submit" class="button button-primary" data-pi-next><?php esc_html_e( 'Review credential', 'certloom' ); ?></button><button type="button" class="button button-primary" data-pi-another hidden><?php esc_html_e( 'Issue another', 'certloom' ); ?></button></footer>
			</form>
		</dialog>
		<?php
	}
}
