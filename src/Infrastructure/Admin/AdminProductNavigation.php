<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product-level Certloom administration navigation.
 *
 * Individual feature controllers keep ownership of their page registration,
 * capabilities and callbacks. This class only consolidates the visible
 * WordPress submenu after every controller has registered, and renders a
 * contextual second-level navigation for routes that intentionally stay
 * hidden from the primary menu.
 */
final class AdminProductNavigation {
	/** @var array<string,string> */
	private const PRIMARY = array(
		'certloom'                 => 'Dashboard',
		'certloom-certificates'    => 'Credentials',
		'certloom-domain-programs' => 'People & Programs',
		'certloom-templates'       => 'Design',
		'certloom-analytics'       => 'Insights & Integrations',
		'certloom-settings'        => 'Settings',
	);

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'consolidate_primary_menu' ), 999 );
		add_action( 'admin_notices', array( $this, 'render_subnav' ), 2 );
		add_filter( 'parent_file', array( $this, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ) );
	}

	/**
	 * Keeps no more than the six approved customer-facing primary sections.
	 * Registered feature pages remain routable; only their redundant menu rows
	 * are removed from the visible WordPress submenu.
	 *
	 * @return void
	 */
	public function consolidate_primary_menu() {
		global $submenu, $_parent_pages;
		if ( empty( $submenu['certloom'] ) || ! is_array( $submenu['certloom'] ) ) {
			return;
		}

		$registered = array();
		foreach ( $submenu['certloom'] as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( isset( self::PRIMARY[ $slug ] ) && ! isset( $registered[ $slug ] ) ) {
				$item[0] = $this->primary_label( $slug );
				$registered[ $slug ] = $item;
			}
		}

		$ordered = array();
		foreach ( array_keys( self::PRIMARY ) as $slug ) {
			if ( isset( $registered[ $slug ] ) ) {
				$ordered[] = $registered[ $slug ];
			}
		}
		$submenu['certloom'] = $ordered;

		// Hidden contextual pages must still identify Certloom as their WordPress
		// parent. WordPress uses this map as well as parent_file/submenu_file when
		// calculating the active top-level menu state. Keep this explicit so a
		// hidden-but-routable subtab never makes the Certloom sidebar look closed.
		foreach ( array(
			'certloom-add', 'certloom-domain-credentials', 'certloom-issuance',
			'certloom-bulk-issuance', 'certloom-email-delivery', 'certloom-bulk-review',
			'certloom-domain-recipients', 'certloom-domain-issuers', 'certloom-domain-batches',
			'certloom-domain-signers', 'certloom-frontend', 'certloom-integrations',
			'certloom-import', 'certloom-setup', 'certloom-fields', 'certloom-domain-data-tools',
		) as $contextual_page ) {
			$_parent_pages[ $contextual_page ] = 'certloom';
		}
	}


	/** Keeps WordPress' Certloom parent menu highlighted on hidden contextual routes. */
	public function filter_parent_file( $parent_file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
		if ( '' !== $page && 0 === strpos( $page, 'certloom' ) ) {
			return 'certloom';
		}
		return $parent_file;
	}

	/** Maps hidden contextual routes back to one of the six visible primary rows. */
	public function filter_submenu_file( $submenu_file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
		if ( '' === $page || 0 !== strpos( $page, 'certloom' ) ) {
			return $submenu_file;
		}
		$primary = array(
			'dashboard'   => 'certloom',
			'credentials' => 'certloom-certificates',
			'people'      => 'certloom-domain-programs',
			'design'      => 'certloom-templates',
			'insights'    => 'certloom-analytics',
			'settings'    => 'certloom-settings',
		);
		$section = $this->section_for_page( $page );
		return isset( $primary[ $section ] ) ? $primary[ $section ] : $submenu_file;
	}

	/** @return string */
	private function primary_label( $slug ) {
		switch ( $slug ) {
			case 'certloom':
				return __( 'Dashboard', 'certloom' );
			case 'certloom-certificates':
				return __( 'Credentials', 'certloom' );
			case 'certloom-domain-programs':
				return __( 'People & Programs', 'certloom' );
			case 'certloom-templates':
				return __( 'Design', 'certloom' );
			case 'certloom-analytics':
				return __( 'Insights & Integrations', 'certloom' );
			case 'certloom-settings':
				return __( 'Settings', 'certloom' );
		}
		return '';
	}

	/** @return void */
	public function render_subnav() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state.
		if ( '' === $page || 0 !== strpos( $page, 'certloom' ) || ! is_user_logged_in() ) {
			return;
		}

		$section = $this->section_for_page( $page );
		$links   = $this->links_for_section( $section );
		if ( empty( $links ) ) {
			return;
		}

		echo '<nav class="certloom-product-subnav" aria-label="' . esc_attr__( 'Certloom section navigation', 'certloom' ) . '">';
		foreach ( $links as $link ) {
			if ( ! current_user_can( $link['capability'] ) ) {
				continue;
			}
			$current = in_array( $page, $link['pages'], true );
			echo '<a href="' . esc_url( add_query_arg( 'page', $link['page'], admin_url( 'admin.php' ) ) ) . '" class="' . ( $current ? 'is-current' : '' ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $link['label'] ) . '</a>';
		}
		echo '</nav>';
	}

	/** @return string */
	private function section_for_page( $page ) {
		$groups = array(
			'credentials' => array( 'certloom-certificates', 'certloom-add', 'certloom-domain-credentials', 'certloom-issuance', 'certloom-bulk-issuance', 'certloom-email-delivery', 'certloom-bulk-review' ),
			'people'      => array( 'certloom-domain-programs', 'certloom-domain-recipients', 'certloom-domain-issuers', 'certloom-domain-batches', 'certloom-domain-signers' ),
			'design'      => array( 'certloom-templates', 'certloom-frontend' ),
			'insights'    => array( 'certloom-analytics', 'certloom-integrations' ),
			'settings'    => array( 'certloom-settings', 'certloom-import', 'certloom-setup', 'certloom-fields', 'certloom-domain-data-tools' ),
		);
		foreach ( $groups as $section => $pages ) {
			if ( in_array( $page, $pages, true ) ) {
				return $section;
			}
		}
		return 'dashboard';
	}

	/**
	 * @return array<int,array{label:string,page:string,pages:array<int,string>,capability:string}>
	 */
	private function links_for_section( $section ) {
		$preview  = \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready();
		$advanced = ! AdminUiMode::is_simple();

		switch ( $section ) {
			case 'credentials':
				$links = array(
					$this->link( __( 'Registry', 'certloom' ), 'certloom-certificates', array( 'certloom-certificates', 'certloom-add', 'certloom-bulk-review' ), ECAPLA_CERTVERIFY_CAP ),
				);
				if ( $preview ) {
					$links[] = $this->link( __( 'Credential management', 'certloom' ), 'certloom-domain-credentials', array( 'certloom-domain-credentials' ), \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS );
					$links[] = $this->link( __( 'Issue', 'certloom' ), 'certloom-issuance', array( 'certloom-issuance' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
					$links[] = $this->link( __( 'Bulk issue', 'certloom' ), 'certloom-bulk-issuance', array( 'certloom-bulk-issuance' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
					$links[] = $this->link( __( 'Email delivery', 'certloom' ), 'certloom-email-delivery', array( 'certloom-email-delivery' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
				}
				return $links;

			case 'people':
				$links = array(
					$this->link( __( 'Programs', 'certloom' ), 'certloom-domain-programs', array( 'certloom-domain-programs' ), ECAPLA_CERTVERIFY_CAP ),
				);
				if ( $preview ) {
					$links[] = $this->link( __( 'Recipients', 'certloom' ), 'certloom-domain-recipients', array( 'certloom-domain-recipients' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
					$links[] = $this->link( __( 'Issuers', 'certloom' ), 'certloom-domain-issuers', array( 'certloom-domain-issuers' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
					$links[] = $this->link( __( 'Batches', 'certloom' ), 'certloom-domain-batches', array( 'certloom-domain-batches' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
					$links[] = $this->link( __( 'Signers', 'certloom' ), 'certloom-domain-signers', array( 'certloom-domain-signers' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
				}
				return $links;

			case 'design':
				return array(
					$this->link( __( 'Templates', 'certloom' ), 'certloom-templates', array( 'certloom-templates' ), ECAPLA_CERTVERIFY_CAP ),
					$this->link( __( 'Verification appearance', 'certloom' ), 'certloom-frontend', array( 'certloom-frontend' ), ECAPLA_CERTVERIFY_CAP ),
				);

			case 'insights':
				return array(
					$this->link( __( 'Analytics', 'certloom' ), 'certloom-analytics', array( 'certloom-analytics' ), \Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS ),
					$this->link( __( 'Integrations', 'certloom' ), 'certloom-integrations', array( 'certloom-integrations' ), ECAPLA_CERTVERIFY_CAP ),
				);

			case 'settings':
				$links = array(
					$this->link( __( 'Settings', 'certloom' ), 'certloom-settings', array( 'certloom-settings' ), 'manage_options' ),
					$this->link( __( 'Backup & Restore', 'certloom' ), 'certloom-import', array( 'certloom-import' ), 'manage_options' ),
					$this->link( __( 'Setup', 'certloom' ), 'certloom-setup', array( 'certloom-setup' ), 'manage_options' ),
					$this->link( __( 'Fields', 'certloom' ), 'certloom-fields', array( 'certloom-fields' ), ECAPLA_CERTVERIFY_CAP ),
				);
				if ( $preview && $advanced ) {
					$links[] = $this->link( __( 'Developer tools', 'certloom' ), 'certloom-domain-data-tools', array( 'certloom-domain-data-tools' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS );
				}
				return $links;
		}
		return array();
	}

	/**
	 * @param string        $label Label.
	 * @param string        $page  Destination slug.
	 * @param array<int,string> $pages Slugs represented by this link.
	 * @param string        $capability Required capability.
	 * @return array{label:string,page:string,pages:array<int,string>,capability:string}
	 */
	private function link( $label, $page, array $pages, $capability ) {
		return array(
			'label'      => $label,
			'page'       => $page,
			'pages'      => $pages,
			'capability' => $capability,
		);
	}
}
