<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Unified product-level Certloom administration navigation. */
final class AdminProductNavigation {
	/** @var array<string,string> */
	private const PRIMARY = array(
		'certloom'                    => 'Home',
		'certloom-domain-credentials' => 'Credentials',
		'certloom-domain-programs'    => 'Programs',
		'certloom-templates'           => 'Design',
		'certloom-automations'         => 'Automations',
		'certloom-settings'            => 'Settings',
	);

	public function register() {
		add_action( 'admin_menu', array( $this, 'consolidate_primary_menu' ), 999 );
		add_action( 'admin_notices', array( $this, 'render_subnav' ), 2 );
		add_filter( 'parent_file', array( $this, 'filter_parent_file' ), 9999 );
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ), 9999 );
	}

	public function consolidate_primary_menu() {
		global $submenu, $_parent_pages;
		if ( empty( $submenu['certloom'] ) || ! is_array( $submenu['certloom'] ) ) { return; }
		$registered = array();
		foreach ( $submenu['certloom'] as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( isset( self::PRIMARY[ $slug ] ) && ! isset( $registered[ $slug ] ) ) { $item[0] = $this->primary_label( $slug ); $registered[ $slug ] = $item; }
		}
		$ordered = array();
		foreach ( array_keys( self::PRIMARY ) as $slug ) { if ( isset( $registered[ $slug ] ) ) { $ordered[] = $registered[ $slug ]; } }
		$submenu['certloom'] = $ordered;

		foreach ( array(
			'certloom-add', 'certloom-certificates', 'certloom-domain-credentials', 'certloom-issuance', 'certloom-file-intake', 'certloom-bulk-issuance', 'certloom-email-delivery', 'certloom-bulk-review',
			'certloom-domain-recipients', 'certloom-recipient-reviews', 'certloom-pending-requests', 'certloom-domain-issuers', 'certloom-domain-batches', 'certloom-domain-signers',
			'certloom-templates', 'certloom-email-designs', 'certloom-frontend',
			'certloom-automations', 'certloom-design-rules', 'certloom-integrations', 'certloom-analytics',
			'certloom-import', 'certloom-recovery', 'certloom-setup', 'certloom-verification-sample', 'certloom-fields', 'certloom-domain-data-tools',
		) as $contextual_page ) { $_parent_pages[ $contextual_page ] = 'certloom'; }
	}

	public function filter_parent_file( $parent_file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '' !== $page && 0 === strpos( $page, 'certloom' ) ? 'certloom' : $parent_file;
	}

	public function filter_submenu_file( $submenu_file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page || 0 !== strpos( $page, 'certloom' ) ) { return $submenu_file; }
		$primary = array( 'dashboard' => 'certloom', 'credentials' => 'certloom-domain-credentials', 'people' => 'certloom-domain-programs', 'design' => 'certloom-templates', 'insights' => 'certloom-automations', 'settings' => 'certloom-settings' );
		$section = $this->section_for_page( $page );
		return $primary[ $section ] ?? $submenu_file;
	}

	private function primary_label( $slug ) {
		$labels = array( 'certloom' => __( 'Home', 'certloom' ), 'certloom-domain-credentials' => __( 'Credentials', 'certloom' ), 'certloom-domain-programs' => __( 'Programs', 'certloom' ), 'certloom-templates' => __( 'Design', 'certloom' ), 'certloom-automations' => __( 'Automations', 'certloom' ), 'certloom-settings' => __( 'Settings', 'certloom' ) );
		return (string) ( $labels[ $slug ] ?? '' );
	}

	public function render_subnav() {
		$page = $this->current_page();
		if ( '' === $page || 0 !== strpos( $page, 'certloom' ) || ! is_user_logged_in() ) { return; }
		$design_view = isset( $_GET['design_view'] ) ? sanitize_key( wp_unslash( $_GET['design_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		if ( 'certloom-templates' === $page && 'editor' === $design_view ) { return; }

		/*
		 * Workspaces with a shared Certloom hero render their contextual navigation
		 * inside the page callback, immediately after the hero. Rendering those links
		 * from admin_notices first and moving them in footer JavaScript caused a
		 * visible top-of-page flash/layout shift on real WordPress screens. Keep the
		 * admin_notices path only as the compatibility fallback for older workspaces.
		 */
		if ( $this->page_renders_own_subnav( $page ) ) { return; }
		$this->render_for_page( $page, false );
	}

	/** Render contextual navigation at the page-owned location (normally after its hero). */
	public function render_current_subnav() {
		$page = $this->current_page();
		if ( '' === $page || 0 !== strpos( $page, 'certloom' ) || ! is_user_logged_in() ) { return; }
		$this->render_for_page( $page, true );
	}

	/** @return void */
	private function render_for_page( $page, $below_hero ) {
		$links = $this->links_for_section( $this->section_for_page( $page ) );
		if ( empty( $links ) ) { return; }
		$classes = 'certloom-product-subnav' . ( $below_hero ? ' is-below-hero' : '' );
		echo '<nav class="' . esc_attr( $classes ) . '" data-certloom-product-subnav aria-label="' . esc_attr__( 'Certloom section navigation', 'certloom' ) . '">';
		foreach ( $links as $link ) {
			if ( ! $this->can_access_link( $link ) ) { continue; }
			$current = in_array( $page, $link['pages'], true );
			echo '<a href="' . esc_url( add_query_arg( 'page', $link['page'], admin_url( 'admin.php' ) ) ) . '" class="' . ( $current ? 'is-current' : '' ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $link['label'] ) . '</a>';
		}
		echo '</nav>';
	}

	/** @return string */
	private function current_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * These callbacks already own a shared hero and can therefore emit navigation
	 * in its final DOM position without JavaScript relocation.
	 *
	 * @return bool
	 */
	private function page_renders_own_subnav( $page ) {
		return in_array( $page, array(
			'certloom-email-designs',
			'certloom-frontend',
			'certloom-settings',
			'certloom-fields',
			'certloom-analytics',
			'certloom-domain-credentials',
			'certloom-domain-recipients',
			'certloom-recipient-reviews',
			'certloom-pending-requests',
			'certloom-domain-programs',
			'certloom-domain-issuers',
			'certloom-domain-batches',
			'certloom-domain-signers',
			'certloom-domain-data-tools',
			'certloom-recovery',
			'certloom-issuance',
			'certloom-file-intake',
			'certloom-bulk-issuance',
		), true );
	}

	private function section_for_page( $page ) {
		if ( 'certloom-domain-recipients' === $page ) { return 'credentials'; }
		if ( in_array( $page, array( 'certloom-recipient-reviews', 'certloom-pending-requests' ), true ) ) { return 'dashboard'; }
		$groups = array(
			'credentials' => array( 'certloom-certificates', 'certloom-add', 'certloom-domain-credentials', 'certloom-issuance', 'certloom-file-intake', 'certloom-bulk-issuance', 'certloom-email-delivery', 'certloom-bulk-review' ),
			'people'      => array( 'certloom-domain-programs', 'certloom-domain-issuers', 'certloom-domain-batches', 'certloom-domain-signers' ),
			'design'      => array( 'certloom-templates', 'certloom-email-designs', 'certloom-frontend' ),
			'insights'    => array( 'certloom-analytics', 'certloom-integrations', 'certloom-automations', 'certloom-design-rules' ),
			'settings'    => array( 'certloom-settings', 'certloom-import', 'certloom-recovery', 'certloom-setup', 'certloom-verification-sample', 'certloom-fields', 'certloom-domain-data-tools' ),
		);
		foreach ( $groups as $section => $pages ) { if ( in_array( $page, $pages, true ) ) { return $section; } }
		return 'dashboard';
	}

	private function links_for_section( $section ) {
		$preview = \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready();
		switch ( $section ) {
			case 'credentials':
				if ( ! $preview ) { return array( $this->link( __( 'Credentials', 'certloom' ), 'certloom-certificates', array( 'certloom-certificates', 'certloom-add', 'certloom-bulk-review' ), ECAPLA_CERTVERIFY_CAP ) ); }
				return array(
					$this->link( __( 'Credentials', 'certloom' ), 'certloom-domain-credentials', array( 'certloom-domain-credentials', 'certloom-certificates', 'certloom-add', 'certloom-bulk-review' ), \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS ),
					$this->link( __( 'Recipients', 'certloom' ), 'certloom-domain-recipients', array( 'certloom-domain-recipients' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS ),
					$this->link( __( 'Import / issue from file', 'certloom' ), 'certloom-file-intake', array( 'certloom-file-intake', 'certloom-bulk-issuance' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ),
					$this->link( __( 'Email delivery', 'certloom' ), 'certloom-email-delivery', array( 'certloom-email-delivery' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ),
				);
			case 'people':
				$links = array( $this->link( __( 'Programs', 'certloom' ), 'certloom-domain-programs', array( 'certloom-domain-programs' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) );
				if ( $preview ) {
					if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) ) {
						$links[] = $this->link( __( 'Batches', 'certloom' ), 'certloom-domain-batches', array( 'certloom-domain-batches' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
					}
					$links[] = $this->link( __( 'Issuers', 'certloom' ), 'certloom-domain-issuers', array( 'certloom-domain-issuers' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
					$links[] = $this->link( __( 'Signers', 'certloom' ), 'certloom-domain-signers', array( 'certloom-domain-signers' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
				}
				return $links;
			case 'design':
				return array(
					$this->link( __( 'Certificate designs', 'certloom' ), 'certloom-templates', array( 'certloom-templates' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ),
					$this->link( __( 'Email designs', 'certloom' ), 'certloom-email-designs', array( 'certloom-email-designs' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ),
					$this->link( __( 'Verification page', 'certloom' ), 'certloom-frontend', array( 'certloom-frontend' ), ECAPLA_CERTVERIFY_CAP ),
				);
			case 'insights':
				return array( $this->link( __( 'Automations','certloom'),'certloom-automations',array('certloom-automations'),\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS), $this->link( __( 'Design Rules', 'certloom' ), 'certloom-design-rules', array( 'certloom-design-rules' ), array( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ) ), $this->link(__( 'Integrations','certloom'),'certloom-integrations',array('certloom-integrations'),ECAPLA_CERTVERIFY_CAP), $this->link(__( 'Analytics','certloom'),'certloom-analytics',array('certloom-analytics'),\Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS) );
			case 'settings':
				$links = array( $this->link(__( 'Settings','certloom'),'certloom-settings',array('certloom-settings'),'manage_options'), $this->link(__( 'Backup & Restore','certloom'),'certloom-import',array('certloom-import'),'manage_options'), $this->link(__( 'Setup','certloom'),'certloom-setup',array('certloom-setup'),'manage_options'), $this->link(__( 'Fields','certloom'),'certloom-fields',array('certloom-fields'),ECAPLA_CERTVERIFY_CAP) );
				if ( $preview ) { $links[] = $this->link( __( 'Credential data tools','certloom'), 'certloom-domain-data-tools', array('certloom-domain-data-tools'), \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ); }
				$links[] = $this->link( __( 'Recovery snapshots', 'certloom' ), 'certloom-recovery', array( 'certloom-recovery' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS );
				return $links;
		}
		return array();
	}

	/** @return bool */
	private function can_access_link( array $link ) {
		$capabilities = isset( $link['capabilities'] ) && is_array( $link['capabilities'] )
			? $link['capabilities']
			: array( (string) ( $link['capability'] ?? '' ) );
		foreach ( $capabilities as $capability ) {
			if ( '' === (string) $capability || ! current_user_can( (string) $capability ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,mixed> */
	private function link( $label, $page, array $pages, $capability ) {
		$link = array(
			'label' => $label,
			'page'  => $page,
			'pages' => $pages,
		);
		if ( is_array( $capability ) ) {
			$link['capabilities'] = array_values( $capability );
		} else {
			$link['capability'] = (string) $capability;
		}
		return $link;
	}
}
