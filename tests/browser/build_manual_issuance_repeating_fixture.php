<?php
/** Browser-only focused fixture for Manual Issuance immutable repeating row entry. */
$root = dirname( __DIR__, 2 );
$css = file_get_contents( $root . '/admin/assets/admin.css' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
function cl_h( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function row_markup( $rowset, $index, array $fields, array $values = array() ) {
	echo '<div class="certloom-manual-repeating-row" data-certloom-repeating-row><div class="certloom-manual-repeating-fields">';
	foreach ( $fields as $field ) {
		$value = isset( $values[ $field ] ) ? (string) $values[ $field ] : '';
		echo '<label><span>' . cl_h( ucfirst( str_replace( '_', ' ', $field ) ) ) . '</span><input type="text" maxlength="1000" name="repeating_data[' . cl_h( $rowset ) . '][' . cl_h( $index ) . '][' . cl_h( $field ) . ']" value="' . cl_h( $value ) . '"></label>';
	}
	echo '</div><button type="button" class="button-link-delete certloom-manual-repeating-remove" data-certloom-repeating-remove>Remove</button></div>';
}
function rowset_markup( $rowset, array $fields, $label ) {
	echo '<section class="certloom-manual-rowset" data-certloom-repeating-rowset="' . cl_h( $rowset ) . '" data-certloom-next-row="1"><div class="certloom-manual-rowset-head"><div><h4>' . cl_h( $label ) . '</h4><p>Immutable issued rows.</p></div><button type="button" class="button" data-certloom-repeating-add>Add row</button></div><div class="certloom-manual-rowset-rows" data-certloom-repeating-rows>';
	row_markup( $rowset, '0', $fields, 'results' === $rowset ? array( 'subject' => 'Mathematics', 'score' => '88', 'grade' => 'A' ) : array() );
	echo '</div><template data-certloom-repeating-template>';
	row_markup( $rowset, '__INDEX__', $fields );
	echo '</template><small class="certloom-manual-rowset-limit">Maximum 50 non-empty rows. Empty rows are ignored.</small></section>';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 12px;border:1px solid #2271b1;border-radius:3px;background:#f6f7f7;color:#2271b1;text-decoration:none;font-weight:600}.button:disabled{opacity:.6}.button-link-delete{border:0;background:transparent;color:#b32d2e}.certloom-manual-form label{display:flex;flex-direction:column;gap:6px;font-weight:600}
<?php echo $css; ?>
</style></head><body><main class="wrap certloom-admin-shell certloom-manual-issuance"><section class="certloom-manual-card"><form class="certloom-manual-form"><details class="certloom-manual-repeating" data-certloom-repeating-data open><summary><span>Subjects &amp; results</span><small>Optional repeating rows for Data Tables</small></summary><div class="certloom-manual-repeating-body"><p class="description">Frozen into the issued version.</p><?php rowset_markup( 'results', array( 'subject', 'code', 'score', 'max_score', 'percentage', 'grade', 'credits', 'status' ), 'Results' ); rowset_markup( 'subjects', array( 'subject', 'code', 'credits' ), 'Subjects' ); ?></div></details></form></section></main><script><?php echo $js; ?></script></body></html>
