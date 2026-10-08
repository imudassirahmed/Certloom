<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\FieldCollectionPolicy;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Organization configuration, never a recipient data-entry form. */
final class FieldCollectionControls {
	public function render( array $field, $index, $form_prefix = null ) {
		$policies = new FieldCollectionPolicy();
		if ( ! $policies->supports_policy( $field ) ) { return; }
		$policy = $policies->for_definition( $field );
		$error = is_wp_error( $policy );
		$writer = $error ? 'invalid' : ( ! empty( $policy['_meta']['configured'] ) ? $policy['writer'] : 'legacy' );
		$lifetime = $error ? 'recipient' : $policy['lifetime'];
		$when = $error ? 'profile' : $policy['collect_when'];
		$customer_allowed = 'internal' !== ( $field['sensitivity'] ?? '' ) && ! in_array( $field['key'], array( 'grade', 'score', 'result', 'marks_percentage' ), true );
		$prefix = null === $form_prefix ? 'fields[' . (int) $index . '][collection]' : (string) $form_prefix;
		$summary = 'recipient' === $writer ? __( 'Customer supplies this detail', 'certloom' ) : __( 'Choose who supplies this field', 'certloom' );
		?>
		<details class="certloom-field-collection" data-certloom-collection>
			<summary><?php echo esc_html( $summary ); ?></summary>
			<?php if ( $error ) : ?><p class="description"><?php esc_html_e( 'These saved settings need attention. Choose valid settings to repair this field.', 'certloom' ); ?></p><?php endif; ?>
			<div class="certloom-field-collection__grid">
				<label><span><?php esc_html_e( 'Who supplies it?', 'certloom' ); ?></span><select name="<?php echo esc_attr( $prefix ); ?>[writer]" data-certloom-collection-writer>
					<?php if ( $error ) : ?><option value="invalid" selected><?php esc_html_e( 'Choose settings', 'certloom' ); ?></option><?php endif; ?>
					<option value="legacy" <?php selected( $writer, 'legacy' ); ?>><?php esc_html_e( 'Existing source — no customer form', 'certloom' ); ?></option>
					<option value="recipient" <?php selected( $writer, 'recipient' ); ?> <?php disabled( ! $customer_allowed ); ?>><?php esc_html_e( 'Customer / learner', 'certloom' ); ?></option>
					<option value="staff" <?php selected( $writer, 'staff' ); ?>><?php esc_html_e( 'Organization staff', 'certloom' ); ?></option>
					<option value="source" <?php selected( $writer, 'source' ); ?>><?php esc_html_e( 'Connected app or course result', 'certloom' ); ?></option>
				</select></label>
				<label><span><?php esc_html_e( 'What does it belong to?', 'certloom' ); ?></span><select name="<?php echo esc_attr( $prefix ); ?>[lifetime]" data-certloom-collection-lifetime>
					<?php foreach ( array( 'recipient' => __( 'Person — reuse across Programs', 'certloom' ), 'registration' => __( 'This Program enrollment', 'certloom' ), 'achievement' => __( 'Each certificate or result', 'certloom' ), 'program' => __( 'Program — same for everyone', 'certloom' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $lifetime, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></label>
				<label data-certloom-collection-customer><span><?php esc_html_e( 'When should we ask?', 'certloom' ); ?></span><select name="<?php echo esc_attr( $prefix ); ?>[collect_when]" data-certloom-collection-timing>
					<?php foreach ( array( 'profile' => __( 'In the customer’s profile', 'certloom' ), 'signup' => __( 'At website signup and in the profile', 'certloom' ), 'registration' => __( 'In this Program’s enrollment details', 'certloom' ), 'before_issue' => __( 'When needed for a certificate', 'certloom' ), 'never' => __( 'Do not ask the customer', 'certloom' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $when, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select></label>
				<label class="certloom-field-collection__check" data-certloom-collection-customer><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[approval_required]" value="1" <?php checked( ! $error && $policy['approval_required'] ); ?> /><span><?php esc_html_e( 'Staff must approve the customer’s answer', 'certloom' ); ?></span></label>
				<label class="certloom-field-collection__help"><span><?php esc_html_e( 'Explain why you need it', 'certloom' ); ?></span><input type="text" name="<?php echo esc_attr( $prefix ); ?>[help]" value="<?php echo esc_attr( $error ? '' : $policy['help'] ); ?>" maxlength="400" placeholder="<?php esc_attr_e( 'For example: Enter your CNIC to verify your enrollment.', 'certloom' ); ?>" /></label>
				<label class="certloom-field-collection__check"><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[show_on_certificate]" value="1" <?php checked( ! $error && $policy['show_on_certificate'] ); ?> <?php disabled( null !== $form_prefix && ! in_array( $field['public_exposure'] ?? 'private', array( 'profile', 'both' ), true ) ); ?> /><span><?php esc_html_e( 'Expect this field on the certificate design', 'certloom' ); ?><?php if ( null !== $form_prefix && ! in_array( $field['public_exposure'] ?? 'private', array( 'profile', 'both' ), true ) ) : ?><small><?php esc_html_e( 'This field stays private. Review its public exposure in Fields Manager after setup before adding it to artwork.', 'certloom' ); ?></small><?php endif; ?></span></label>
			</div>
			<p class="description"><?php esc_html_e( 'For everyone: choose Person, collect in the profile or at website signup, and mark Required in global requirements when needed. For an enrolled customer: choose This Program enrollment and add the requirement in that Program. Signup uses the standard WordPress and WooCommerce account forms. Private details stay private unless you explicitly change their public exposure.', 'certloom' ); ?></p>
		</details>
		<?php
	}
}
