<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Native WordPress certificates list table.
 */
class Ecapla_CertVerify_List_Table extends WP_List_Table {

	/** @var WP_Error|null */
	private $database_error = null;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'certificate',
				'plural'   => 'certificates',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox" />',
			'cert_number' => __( 'Certificate number', 'certverify' ),
			'holder_name' => __( 'Holder', 'certverify' ),
			'status'      => __( 'Status', 'certverify' ),
			'issue_date'  => __( 'Issue date', 'certverify' ),
			'expiry_date' => __( 'Expiry date', 'certverify' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'cert_number' => array( 'cert_number', false ),
			'holder_name' => array( 'holder_name', false ),
			'status'      => array( 'status', false ),
			'issue_date'  => array( 'issue_date', false ),
			'expiry_date' => array( 'expiry_date', false ),
		);
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="certificate-%1$d">%2$s</label><input id="certificate-%1$d" type="checkbox" name="cert_ids[]" value="%1$d" />',
			(int) $item['id'],
			esc_html(
				sprintf(
					/* translators: %s: certificate number. */
					__( 'Select certificate %s', 'certverify' ),
					$item['cert_number']
				)
			)
		);
	}

	protected function column_cert_number( $item ) {
		$edit_url = add_query_arg( array( 'page' => 'certverify-add', 'id' => $item['id'] ), admin_url( 'admin.php' ) );
		$delete_form = sprintf(
			'<button type="submit" form="ecapla-certverify-delete-certificate-form" name="certificate_id" value="%1$d" class="button-link-delete ecapla-certverify-link-button" data-ecapla-confirm="%2$s">%3$s</button>',
			(int) $item['id'],
			esc_attr__( 'Delete this certificate? This action cannot be undone.', 'certverify' ),
			esc_html__( 'Delete', 'certverify' )
		);
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'certverify' ) ),
			'delete' => $delete_form,
		);
		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>%3$s',
			esc_url( $edit_url ),
			esc_html( $item['cert_number'] ),
			$this->row_actions( $actions )
		);
	}

	protected function column_status( $item ) {
		$statuses = Ecapla_CertVerify_Fields::get_statuses();
		$status   = Ecapla_CertVerify_Database::effective_status( $item );
		$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : ucfirst( $status );
		$output   = sprintf( '<span class="ecapla-certverify-badge ecapla-certverify-badge-%1$s">%2$s</span>', esc_attr( $status ), esc_html( $label ) );
		if ( Ecapla_CertVerify_Database::is_legacy_expired( $item ) ) {
			$output .= '<br><span class="description">' . esc_html__( 'Legacy review required', 'certverify' ) . '</span>';
		}
		return $output;
	}

	protected function column_default( $item, $column_name ) {
		if ( in_array( $column_name, array( 'issue_date', 'expiry_date' ), true ) && ! empty( $item[ $column_name ] ) ) {
			return esc_html( Ecapla_CertVerify_Validator::format_date( $item[ $column_name ] ) );
		}
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	public function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'certverify' ) );
	}

	protected function extra_tablenav( $which ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Search and status filtering use the dedicated GET form above the table.
		// Keeping this area empty lets the table form remain POST-only for deletion.
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$per_page     = 20;
		$current_page = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search, filters, sorting, and pagination are read-only list state.
		$legacy_expired = isset( $_REQUEST['legacy_expired'] ) && '1' === sanitize_text_field( wp_unslash( $_REQUEST['legacy_expired'] ) );
		$result          = Ecapla_CertVerify_Database::get_list(
			array(
				'search'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'search_mode'    => isset( $_REQUEST['search_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['search_mode'] ) ) : 'prefix',
				'status'         => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
				'legacy_expired' => $legacy_expired,
				'orderby'        => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at',
				'order'          => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC',
				'per_page'       => $per_page,
				'page'           => $current_page,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( is_wp_error( $result ) ) {
			$this->database_error = $result;
			$this->items          = array();
			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => $per_page,
					'total_pages' => 0,
				)
			);
			return;
		}

		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * @return WP_Error|null
	 */
	public function get_database_error() {
		return $this->database_error;
	}
}
