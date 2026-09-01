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

	/**
	 * Renders a guided empty state instead of WordPress' bare "No items found."
	 *
	 * An empty registry and an over-narrow filter are different problems with
	 * opposite next actions, so they are distinguished here.
	 *
	 * @return void
	 */
	public function no_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display state.
		$filtered = ! empty( $_GET['s'] )
			|| ! empty( $_GET['status'] )
			|| ! empty( $_GET['attention'] )
			|| ! empty( $_GET['issue_from'] )
			|| ! empty( $_GET['issue_to'] )
			|| ! empty( $_GET['expiry_from'] )
			|| ! empty( $_GET['expiry_to'] )
			|| ! empty( $_GET['legacy_expired'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $filtered ) {
			echo '<div class="certloom-premium-empty-state">';
			echo '<span class="dashicons dashicons-filter" aria-hidden="true"></span>';
			echo '<h3>' . esc_html__( 'No certificates match these filters', 'certloom' ) . '</h3>';
			echo '<p>' . esc_html__( 'Try a wider date range, a different status, or clear the filters to see the whole registry.', 'certloom' ) . '</p>';
			echo '<div class="certloom-empty-state-actions"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ) . '">' . esc_html__( 'Clear filters', 'certloom' ) . '</a></div>';
			echo '</div>';
			return;
		}

		$issuance_ready = class_exists( '\\Certloom\\Infrastructure\\Migration\\DomainFeatureGate' )
			&& \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready()
			&& current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );

		$create_url = $issuance_ready
			? admin_url( 'admin.php?page=certloom-issuance' )
			: admin_url( 'admin.php?page=certloom-add' );

		echo '<div class="certloom-premium-empty-state">';
		echo '<span class="dashicons dashicons-awards" aria-hidden="true"></span>';
		echo '<h3>' . esc_html__( 'No certificates yet', 'certloom' ) . '</h3>';
		echo '<p>' . esc_html__( 'Issue your first credential, or import records you already hold elsewhere. Both routes end up in this registry.', 'certloom' ) . '</p>';
		echo '<div class="certloom-empty-state-actions">';
		echo '<a class="button button-primary" href="' . esc_url( $create_url ) . '">' . esc_html( $issuance_ready ? __( 'Issue first credential', 'certloom' ) : __( 'Create first certificate', 'certloom' ) ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=certloom-import' ) ) . '">' . esc_html__( 'Import from CSV', 'certloom' ) . '</a>';
		echo '</div>';
		echo '</div>';
	}

	/** @var array<string,int> */
	private $source_counts = array();

	public function get_columns() {
		$field_map = Ecapla_CertVerify_Fields::get_field_map();
		$label = static function ( $key, $fallback ) use ( $field_map ) {
			return isset( $field_map[ $key ]['label'] ) && '' !== trim( (string) $field_map[ $key ]['label'] )
				? $field_map[ $key ]['label']
				: $fallback;
		};

		return array(
			'cb'          => '<input type="checkbox" />',
			'cert_number' => $label( 'cert_number', __( 'Certificate number', 'certloom' ) ),
			'origin'      => __( 'Source', 'certloom' ),
			'holder_name' => $label( 'holder_name', __( 'Holder', 'certloom' ) ),
			'status'      => $label( 'status', __( 'Status', 'certloom' ) ),
			'issue_date'  => $label( 'issue_date', __( 'Issue date', 'certloom' ) ),
			'expiry_date' => $label( 'expiry_date', __( 'Expiry date', 'certloom' ) ),
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

	/**
	 * Marks which storage generation a row came from.
	 *
	 * Both the legacy 1.5 registry and the normalized Series/Version domain
	 * appear in this one list, so the row has to say which it is. Domain rows
	 * additionally show their Version number.
	 *
	 * @param array<string,mixed> $item
	 * @return string
	 */
	protected function column_origin( $item ) {
		$is_legacy = ! isset( $item['origin'] ) || \Certloom\Application\Registry\UnifiedCredentialQuery::ORIGIN_LEGACY === (string) $item['origin'];
		if ( $is_legacy ) {
			return '<span class="certloom-origin-badge is-legacy">' . esc_html__( 'Legacy', 'certloom' ) . '</span>';
		}
		$version = absint( $item['version_number'] ?? 0 );
		$out = '<span class="certloom-origin-badge is-domain">' . esc_html__( 'Credential', 'certloom' ) . '</span>';
		if ( $version ) {
			/* translators: version number */
			$out .= ' <span class="certloom-origin-version">' . esc_html( sprintf( __( 'v%d', 'certloom' ), $version ) ) . '</span>';
		}
		return $out;
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="certificate-%1$d">%2$s</label><input id="certificate-%1$d" type="checkbox" name="cert_ids[]" value="%1$d" />',
			(int) $item['id'],
			esc_html(
				sprintf(
					/* translators: %s: certificate number. */
					__( 'Select certificate %s', 'certloom' ),
					$item['cert_number']
				)
			)
		);
	}

	protected function column_cert_number( $item ) {
		$edit_url = add_query_arg( array( 'page' => 'certloom-add', 'id' => $item['id'] ), admin_url( 'admin.php' ) );
		$delete_form = sprintf(
			'<button type="submit" form="ecapla-certverify-delete-certificate-form" name="certificate_id" value="%1$d" class="button-link-delete ecapla-certverify-link-button" data-ecapla-confirm="%2$s">%3$s</button>',
			(int) $item['id'],
			esc_attr__( 'Delete this certificate? This action cannot be undone.', 'certloom' ),
			esc_html__( 'Delete', 'certloom' )
		);
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'certloom' ) ),
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
		$source   = Ecapla_CertVerify_Database::effective_status_source( $item );
		$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ]['label'] : ucfirst( $status );
		$source_labels = array(
			'manual' => __( 'Workflow status', 'certloom' ),
			'date'   => __( 'Date-derived', 'certloom' ),
			'legacy' => __( 'Legacy review required', 'certloom' ),
		);
		$output = sprintf( '<span class="ecapla-certverify-badge ecapla-certverify-badge-%1$s">%2$s</span>', esc_attr( $status ), esc_html( $label ) );
		$output .= '<span class="certloom-status-source">' . esc_html( isset( $source_labels[ $source ] ) ? $source_labels[ $source ] : $source ) . '</span>';

		$reason = isset( $item['custom_fields']['status_reason'] ) ? trim( (string) $item['custom_fields']['status_reason'] ) : '';
		if ( '' !== $reason ) {
			$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 120 ) : substr( $reason, 0, 120 );
			$output .= '<span class="certloom-status-reason">' . esc_html( $reason ) . '</span>';
		}
		return $output;
	}

	protected function column_holder_name( $item ) {
		$value = isset( $item['holder_name'] ) ? trim( (string) $item['holder_name'] ) : '';
		if ( '' === $value ) {
			return '<span class="certloom-muted">' . esc_html__( 'Not set', 'certloom' ) . '</span>';
		}
		return '<span class="certloom-holder-name">' . esc_html( $value ) . '</span>';
	}

	protected function column_default( $item, $column_name ) {
		if ( in_array( $column_name, array( 'issue_date', 'expiry_date' ), true ) ) {
			if ( ! empty( $item[ $column_name ] ) ) {
				return '<time datetime="' . esc_attr( $item[ $column_name ] ) . '">' . esc_html( Ecapla_CertVerify_Validator::format_date( $item[ $column_name ] ) ) . '</time>';
			}
			return '<span class="certloom-muted">' . esc_html( 'expiry_date' === $column_name ? __( 'No expiry', 'certloom' ) : __( 'Not set', 'certloom' ) ) . '</span>';
		}
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	protected function get_views() {
		$metrics = Ecapla_CertVerify_Database::get_dashboard_metrics();
		if ( is_wp_error( $metrics ) ) {
			return array();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		$current_status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$attention      = isset( $_REQUEST['attention'] ) ? sanitize_key( wp_unslash( $_REQUEST['attention'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$base = admin_url( 'admin.php?page=certloom-certificates' );
		$views = array();
		$definitions = array(
			'all' => array( __( 'All', 'certloom' ), (int) $metrics['total'], '', '' ),
			'valid' => array( __( 'Valid', 'certloom' ), (int) $metrics['valid'], 'valid', '' ),
			'suspended' => array( __( 'Suspended', 'certloom' ), (int) $metrics['suspended'], 'suspended', '' ),
			'withdrawn' => array( __( 'Withdrawn', 'certloom' ), (int) $metrics['withdrawn'], 'withdrawn', '' ),
			'expired' => array( __( 'Expired', 'certloom' ), (int) $metrics['expired'], 'expired', '' ),
			'expiring' => array( __( 'Expiring soon', 'certloom' ), (int) $metrics['expiring_soon'], '', 'expiring_soon' ),
		);

		foreach ( $definitions as $key => $definition ) {
			list( $label, $count, $status, $attention_key ) = $definition;
			$url = $base;
			if ( '' !== $status ) {
				$url = add_query_arg( 'status', $status, $url );
			}
			if ( '' !== $attention_key ) {
				$url = add_query_arg( 'attention', $attention_key, $url );
			}
			$is_current = ( 'all' === $key && '' === $current_status && '' === $attention ) || ( $status && $status === $current_status && '' === $attention ) || ( $attention_key && $attention_key === $attention );
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	public function get_bulk_actions() {
		return array(
			'bulk_edit' => __( 'Change selected…', 'certloom' ),
			'delete'    => __( 'Delete', 'certloom' ),
		);
	}

	protected function extra_tablenav( $which ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Search and status filtering use the dedicated GET form above the table.
		// Keeping this area empty lets the table form remain POST-only for deletion.
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), $this->get_hidden_columns(), $this->get_sortable_columns() );
		$per_page     = 50;
		$current_page = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Search, filters, sorting, and pagination are read-only list state.
		$legacy_expired = isset( $_REQUEST['legacy_expired'] ) && '1' === sanitize_text_field( wp_unslash( $_REQUEST['legacy_expired'] ) );
		$query_args = array(
			'search'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'search_mode'    => isset( $_REQUEST['search_mode'] ) ? sanitize_key( wp_unslash( $_REQUEST['search_mode'] ) ) : 'prefix',
			'status'         => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'legacy_expired' => $legacy_expired,
			'attention'      => isset( $_REQUEST['attention'] ) ? sanitize_key( wp_unslash( $_REQUEST['attention'] ) ) : '',
			'issue_from'     => isset( $_REQUEST['issue_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['issue_from'] ) ) : '',
			'issue_to'       => isset( $_REQUEST['issue_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['issue_to'] ) ) : '',
			'expiry_from'    => isset( $_REQUEST['expiry_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['expiry_from'] ) ) : '',
			'expiry_to'      => isset( $_REQUEST['expiry_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['expiry_to'] ) ) : '',
			'orderby'        => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at',
			'order'          => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC',
			'per_page'       => $per_page,
			'page'           => $current_page,
			'origin'         => isset( $_REQUEST['origin'] ) ? sanitize_key( wp_unslash( $_REQUEST['origin'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/*
		 * One list across both storage generations.
		 *
		 * The unified query does its sorting and paging inside a UNION ALL so
		 * this stays flat on installs with tens of thousands of records. The
		 * attention and legacy_expired filters are legacy-only refinements, so
		 * those fall back to the legacy reader, which already implements them.
		 */
		$legacy_only = '' !== (string) $query_args['attention'] || $legacy_expired;
		if ( $legacy_only ) {
			$result = Ecapla_CertVerify_Database::get_list( $query_args );
		} else {
			$unified = new \Certloom\Application\Registry\UnifiedCredentialQuery();
			$result  = $unified->list_page(
				array(
					'search'      => $query_args['search'],
					'status'      => $query_args['status'],
					'origin'      => $query_args['origin'],
					'issue_from'  => $query_args['issue_from'],
					'issue_to'    => $query_args['issue_to'],
					'expiry_from' => $query_args['expiry_from'],
					'expiry_to'   => $query_args['expiry_to'],
					'orderby'     => $this->map_orderby( $query_args['orderby'] ),
					'order'       => $query_args['order'],
					'per_page'    => $per_page,
					'page'        => $current_page,
				)
			);
			if ( is_array( $result ) ) {
				$this->source_counts = isset( $result['sources'] ) ? (array) $result['sources'] : array();
				$result['items']     = array_map( array( $this, 'to_row' ), $result['items'] );
			}
		}

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

	private function get_hidden_columns() {
		$saved = get_user_meta( get_current_user_id(), 'certloom_registry_hidden_columns', true );
		$allowed = array( 'origin', 'holder_name', 'status', 'issue_date', 'expiry_date' );
		return is_array( $saved ) ? array_values( array_intersect( $allowed, array_map( 'sanitize_key', $saved ) ) ) : array();
	}

	/**
	 * Translates a legacy column name into the unified projection's name.
	 *
	 * @param string $orderby
	 * @return string
	 */
	private function map_orderby( $orderby ) {
		$map = array(
			'cert_number' => 'display_number',
			'holder_name' => 'holder_name',
			'issue_date'  => 'issue_date',
			'expiry_date' => 'expiry_date',
			'created_at'  => 'created_at',
			'status'      => 'created_at',
		);
		return isset( $map[ $orderby ] ) ? $map[ $orderby ] : 'created_at';
	}

	/**
	 * Shapes a unified row into the array shape the column renderers expect,
	 * so the existing registry columns keep working unchanged.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function to_row( array $row ) {
		return array(
			'id'             => absint( $row['source_id'] ?? 0 ),
			'origin'         => (string) ( $row['origin'] ?? '' ),
			'public_id'      => (string) ( $row['public_id'] ?? '' ),
			'version_number' => absint( $row['version_number'] ?? 0 ),
			'cert_number'    => (string) ( $row['display_number'] ?? '' ),
			'holder_name'    => (string) ( $row['holder_name'] ?? '' ),
			'status'         => (string) ( $row['status_key'] ?? 'valid' ),
			'status_source'  => (string) ( $row['status_source'] ?? '' ),
			'issue_date'     => (string) ( $row['issue_date'] ?? '' ),
			'expiry_date'    => (string) ( $row['expiry_date'] ?? '' ),
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
			'custom_fields'  => array(),
		);
	}

	/**
	 * @return array<string,int>
	 */
	public function get_source_counts() {
		return $this->source_counts;
	}

	/**
	 * @return WP_Error|null
	 */
	public function get_database_error() {
		return $this->database_error;
	}
}
