<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Infrastructure\Email\EmailDesignAssignmentRepository;
use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Rendering\TemplatePreviewService;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Central visual administration for Certificate + Email design hierarchy. */
final class DesignRulesAdmin {
	const PAGE = 'certloom-design-rules';
	const NONCE = 'certloom_design_rules_preview';
	const TARGET_PAGE_SIZE = 50;

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 54 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_certloom_design_rules_apply', array( $this, 'apply' ) );
		add_action( 'wp_ajax_certloom_design_rules_preview', array( $this, 'preview' ) );
		add_action( 'wp_ajax_certloom_design_rules_apply_ajax', array( $this, 'apply_ajax' ) );
		add_action( 'wp_ajax_certloom_design_rules_targets', array( $this, 'targets_ajax' ) );
	}

	public function menu() {
		\Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		add_submenu_page( null, __( 'Design Rules', 'certloom' ), __( 'Design Rules', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS, self::PAGE, array( $this, 'render' ) );
	}

	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		$context_pages = array( DomainAdmin::PROGRAMS_PAGE, DomainAdmin::ISSUERS_PAGE, DomainStructureAdmin::BATCHES_PAGE );
		if ( self::PAGE !== $page && ! in_array( $page, $context_pages, true ) ) { return; }
		wp_enqueue_style( 'certloom-design-rules', CERTLOOM_URL . 'admin/assets/design-rules.css', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION );
		if ( self::PAGE === $page ) { wp_enqueue_script( 'certloom-design-rules', CERTLOOM_URL . 'admin/assets/design-rules.js', array(), CERTLOOM_VERSION, true ); }
		else { wp_enqueue_script( 'certloom-design-rule-context', CERTLOOM_URL . 'admin/assets/design-rule-context.js', array(), CERTLOOM_VERSION, true ); }
		$catalog = new EmailTemplateCatalogRepository();
		$catalog->ensure_factory_presets();
		$email_designs = array();
		foreach ( $catalog->all() as $design ) {
			if ( ! empty( $design['active'] ) ) {
				$email_designs[] = array(
					'id'      => (string) $design['id'],
					'name'    => (string) $design['name'],
					'purpose' => (string) $design['purpose'],
				);
			}
		}
		$global_rules = array();
		$rules = new EmailDesignAssignmentRepository();
		foreach ( EmailDesignPurposes::all() as $purpose ) {
			$row = $rules->get( 'global', 0, $purpose );
			if ( is_array( $row ) ) {
				$global_rules[ $purpose ] = (string) $row['design_id'];
			}
		}
		$script_handle = self::PAGE === $page ? 'certloom-design-rules' : 'certloom-design-rule-context';
		wp_localize_script( $script_handle, 'certloomDesignRules', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'previewUnavailable' => __( 'Preview unavailable', 'certloom' ),
			'selectedSingular' => __( '%d selected', 'certloom' ),
			'selectedPlural' => __( '%d selected', 'certloom' ),
			'varies' => __( 'Inherited results vary across this selection.', 'certloom' ),
			'emailDesigns' => $email_designs,
			'globalEmailRules' => $global_rules,
			'noChange' => __( 'No change', 'certloom' ),
			'inherit' => __( 'Inherit from parent', 'certloom' ),
			'noGlobal' => __( 'No explicit global design', 'certloom' ),
			'contextSaving' => __( 'Saving…', 'certloom' ),
			'contextSaved' => __( 'Designs updated.', 'certloom' ),
			'contextNoChanges' => __( 'No design changes to save.', 'certloom' ),
			'contextFailed' => __( 'The design changes could not be saved.', 'certloom' ),
			'loadingTargets' => __( 'Loading…', 'certloom' ),
			'targetsUnavailable' => __( 'Design Rule targets could not be loaded.', 'certloom' ),
			'loadMore' => __( 'Load more', 'certloom' ),
			'noTargets' => __( 'No matching records.', 'certloom' ),
		) );
	}

	public function render() {
		$this->cap();
		$usage_filter = $this->usage_filter_from_request();
		$email_catalog = new EmailTemplateCatalogRepository();
		$email_catalog->ensure_factory_presets();
		$template_repo = new TemplateRepository();
		$certificates = $template_repo->list_published_candidates( 250 );
		$emails = $email_catalog->all();
		$emails = array_values( array_filter( $emails, static function( $design ) { return is_array( $design ) && ! empty( $design['active'] ); } ) );
		$issuers = is_array( $usage_filter ) ? $this->usage_target_page( 'issuer', $usage_filter, '', 1 ) : ( new IssuerRepository() )->list_page( '', 1, self::TARGET_PAGE_SIZE );
		$programs = is_array( $usage_filter ) ? $this->usage_target_page( 'program', $usage_filter, '', 1 ) : ( new ProgramRepository() )->list_page( '', 1, self::TARGET_PAGE_SIZE, 'all' );
		$batches = is_array( $usage_filter ) ? $this->usage_target_page( 'batch', $usage_filter, '', 1 ) : ( new BatchRepository() )->list_page( '', 1, self::TARGET_PAGE_SIZE, 'all' );
		$certificate_rules = $template_repo->list_assignments( 5000 );
		$email_rules = ( new EmailDesignAssignmentRepository() )->all();
		$certificate_map = $this->certificate_rule_map( is_wp_error( $certificate_rules ) ? array() : $certificate_rules );
		$email_map = $this->email_rule_map( $email_rules, EmailDesignPurposes::ISSUED );
		$manager = new DesignRuleManager();
		$global = $manager->state( 'global', 0, EmailDesignPurposes::ISSUED );
		$pre_scope = isset( $_GET['rule_scope'] ) ? sanitize_key( wp_unslash( $_GET['rule_scope'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only contextual focus.
		$pre_scope_id = isset( $_GET['rule_scope_id'] ) ? absint( $_GET['rule_scope_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only contextual focus.
		$preselected = in_array( $pre_scope, array( 'issuer', 'program', 'batch' ), true ) && $pre_scope_id ? $pre_scope . ':' . $pre_scope_id : '';

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-design-rules"><section class="certloom-workspace-hero"><div class="certloom-workspace-hero-copy"><span class="certloom-eyebrow">' . esc_html__( 'Global → Issuer → Program → Batch', 'certloom' ) . '</span><h1>' . esc_html__( 'Design Rules', 'certloom' ) . '</h1><p>' . esc_html__( 'Choose where certificate and email designs apply. Design creation stays in the Design workspace; these rules only control inheritance and overrides.', 'certloom' ) . '</p></div><div class="certloom-workspace-hero-actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=certloom-templates' ) ) . '">' . esc_html__( 'Certificate designs', 'certloom' ) . '</a><a class="button" href="' . esc_url( admin_url( 'admin.php?page=certloom-email-designs' ) ) . '">' . esc_html__( 'Email designs', 'certloom' ) . '</a></div></section>';
		$this->notice();
		if ( is_wp_error( $certificates ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $certificates->get_error_message() ) . '</p></div>'; $certificates = array(); }
		if ( is_wp_error( $issuers ) || is_wp_error( $programs ) || is_wp_error( $batches ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Certloom could not load all hierarchy targets. No design rules have been changed.', 'certloom' ) . '</p></div>'; }
		$this->global_section( is_wp_error( $global ) ? array() : $global, $certificates, $emails );
		$this->target_workspace(
			is_wp_error( $issuers ) ? array( 'items'=>array(), 'total'=>0 ) : $issuers,
			is_wp_error( $programs ) ? array( 'items'=>array(), 'total'=>0 ) : $programs,
			is_wp_error( $batches ) ? array( 'items'=>array(), 'total'=>0 ) : $batches,
			$certificate_map, $email_map, $certificates, $emails, $preselected, $usage_filter
		);
		echo '</div>';
	}

	private function global_section( array $state, $certificates, array $emails ) {
		$direct_cert = is_array( $state['certificate_direct'] ?? null ) ? absint( $state['certificate_direct']['template_id'] ?? 0 ) : 0;
		$direct_email = is_array( $state['email_direct'] ?? null ) ? (string) ( $state['email_direct']['design_id'] ?? '' ) : '';
		echo '<section class="certloom-design-rules-global" aria-labelledby="certloom-global-designs"><div class="certloom-design-rules-section-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Starting point', 'certloom' ) . '</span><h2 id="certloom-global-designs">' . esc_html__( 'Global defaults', 'certloom' ) . '</h2><p>' . esc_html__( 'These are the site-wide defaults. Issuers, Programs and Batches can override them without changing the designs themselves.', 'certloom' ) . '</p></div></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="certloom-design-rules-global-form" data-certloom-design-rule-form><input type="hidden" name="action" value="certloom_design_rules_apply"><input type="hidden" name="targets[]" value="global:0">'; wp_nonce_field( 'certloom_design_rules_apply' );
		echo '<article class="certloom-design-rule-global-card"><div class="certloom-design-rule-global-copy"><span>' . esc_html__( 'Certificate', 'certloom' ) . '</span><h3>' . esc_html__( 'Global certificate design', 'certloom' ) . '</h3></div><label><span class="screen-reader-text">' . esc_html__( 'Global certificate design', 'certloom' ) . '</span><select name="certificate_choice" data-certloom-rule-choice="certificate"><option value="inherit">' . esc_html__( 'No explicit global design', 'certloom' ) . '</option>';
		foreach ( (array) $certificates as $item ) { echo '<option value="design:' . esc_attr( (string) absint( $item['id'] ?? 0 ) ) . '" ' . selected( $direct_cert, absint( $item['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</option>'; } echo '</select></label><div class="certloom-design-rule-preview" data-certloom-rule-preview="certificate">' . $this->certificate_state_preview( $state['certificate_effective'] ?? null, 'global' ) . '</div></article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Preview renderer emits escaped, deterministic markup.
		echo '<article class="certloom-design-rule-global-card"><div class="certloom-design-rule-global-copy"><span>' . esc_html__( 'Email', 'certloom' ) . '</span><h3>' . esc_html__( 'Global email design', 'certloom' ) . '</h3></div><label class="certloom-rule-purpose"><span>' . esc_html__( 'Message', 'certloom' ) . '</span><select name="purpose" data-certloom-rule-purpose>'; foreach ( EmailDesignPurposes::labels() as $key=>$label ) { echo '<option value="' . esc_attr($key) . '" ' . selected( EmailDesignPurposes::ISSUED, $key, false ) . '>' . esc_html($label) . '</option>'; } echo '</select><small>' . esc_html__( 'Only Credential issued is an automatic trigger in this release. Other purposes are ready for reviewed/manual and future lifecycle use.', 'certloom' ) . '</small></label><label><span class="screen-reader-text">' . esc_html__( 'Global email design', 'certloom' ) . '</span><select name="email_choice" data-certloom-rule-choice="email">' . $this->email_options( $emails, EmailDesignPurposes::ISSUED, $direct_email, false ) . '</select></label><div class="certloom-design-rule-preview" data-certloom-rule-preview="email">' . $this->email_state_preview( $state['email_effective'] ?? null, 'global' ) . '</div></article>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="certloom-design-rule-global-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Save global defaults', 'certloom' ) . '</button></div></form></section>';
	}

	private function target_workspace( array $issuers, array $programs, array $batches, array $certificate_map, array $email_map, $certificates, array $emails, $preselected = '', $usage_filter = null ) {
		echo '<section class="certloom-design-rules-workspace" aria-labelledby="certloom-design-rules-targets"><div class="certloom-design-rules-section-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Overrides', 'certloom' ) . '</span><h2 id="certloom-design-rules-targets">' . esc_html__( 'Issuer, Program and Batch rules', 'certloom' ) . '</h2><p>' . esc_html__( 'Select one or many records on the left. Use No change for the design type you do not want a bulk action to touch.', 'certloom' ) . '</p></div></div>';
		if ( is_array( $usage_filter ) ) {
			$clear_url = add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
			$global_note = ! empty( $usage_filter['global'] ) ? ' ' . __( 'The Global default also uses this design.', 'certloom' ) : '';
			echo '<div class="certloom-design-rule-usage-filter" role="status"><div><span class="certloom-eyebrow">' . esc_html__( 'Filtered usage', 'certloom' ) . '</span><strong>' . esc_html( (string) $usage_filter['label'] ) . '</strong><p>' . esc_html__( 'Showing records with a direct rule for this design.', 'certloom' ) . esc_html( $global_note ) . '</p></div><a class="button" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear filter', 'certloom' ) . '</a></div>';
		}
		$form_attrs = ' data-certloom-design-rule-form class="certloom-design-rules-form"';
		if ( is_array( $usage_filter ) ) {
			$form_attrs .= ' data-certloom-usage-type="' . esc_attr( (string) $usage_filter['type'] ) . '" data-certloom-usage-id="' . esc_attr( (string) $usage_filter['id'] ) . '"';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $form_attrs . '><input type="hidden" name="action" value="certloom_design_rules_apply">'; wp_nonce_field( 'certloom_design_rules_apply' );
		echo '<div class="certloom-design-rule-targets"><label class="certloom-design-rule-search"><span class="dashicons dashicons-search" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Search design rule targets', 'certloom' ) . '</span><input type="search" data-certloom-rule-search placeholder="' . esc_attr__( 'Search Issuers, Programs or Batches', 'certloom' ) . '"></label>';
		$this->target_group( 'issuer', __( 'Issuers', 'certloom' ), $issuers, $certificate_map, $email_map, $preselected );
		$this->target_group( 'program', __( 'Programs', 'certloom' ), $programs, $certificate_map, $email_map, $preselected );
		$this->target_group( 'batch', __( 'Batches', 'certloom' ), $batches, $certificate_map, $email_map, $preselected );
		echo '</div>';
		echo '<aside class="certloom-design-rule-editor" data-certloom-rule-editor><div class="certloom-design-rule-editor-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Selected records', 'certloom' ) . '</span><h3 data-certloom-rule-selected>' . esc_html__( '0 selected', 'certloom' ) . '</h3></div><button type="button" class="button button-small" data-certloom-rule-clear>' . esc_html__( 'Clear', 'certloom' ) . '</button></div>';
		echo '<section class="certloom-design-rule-control"><div class="certloom-design-rule-control-head"><span>' . esc_html__( 'Certificate design', 'certloom' ) . '</span><small>' . esc_html__( 'Independent from email', 'certloom' ) . '</small></div><label><span class="screen-reader-text">' . esc_html__( 'Certificate design change', 'certloom' ) . '</span><select name="certificate_choice" data-certloom-rule-choice="certificate"><option value="no_change">' . esc_html__( 'No change', 'certloom' ) . '</option><option value="inherit">' . esc_html__( 'Inherit from parent', 'certloom' ) . '</option>'; foreach ( (array) $certificates as $item ) { echo '<option value="design:' . esc_attr( (string) absint( $item['id'] ?? 0 ) ) . '">' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</option>'; } echo '</select></label><div class="certloom-design-rule-preview is-empty" data-certloom-rule-preview="certificate"><p>' . esc_html__( 'Choose a certificate change to preview it.', 'certloom' ) . '</p></div></section>';
		echo '<section class="certloom-design-rule-control"><div class="certloom-design-rule-control-head"><span>' . esc_html__( 'Email design', 'certloom' ) . '</span><small>' . esc_html__( 'Per message purpose', 'certloom' ) . '</small></div><label class="certloom-rule-purpose"><span>' . esc_html__( 'Message', 'certloom' ) . '</span><select name="purpose" data-certloom-rule-purpose>'; foreach ( EmailDesignPurposes::labels() as $key=>$label ) { echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>'; } echo '</select></label><label><span class="screen-reader-text">' . esc_html__( 'Email design change', 'certloom' ) . '</span><select name="email_choice" data-certloom-rule-choice="email">' . $this->email_options( $emails, EmailDesignPurposes::ISSUED, '', true ) . '</select></label><div class="certloom-design-rule-preview is-empty" data-certloom-rule-preview="email"><p>' . esc_html__( 'Choose an email change to preview it.', 'certloom' ) . '</p></div></section>';
		echo '<div class="certloom-design-rule-apply"><p data-certloom-rule-help>' . esc_html__( 'Select at least one record to apply a design rule.', 'certloom' ) . '</p><button type="submit" class="button button-primary" disabled data-certloom-rule-apply>' . esc_html__( 'Apply changes', 'certloom' ) . '</button></div></aside></form></section>';
	}

	private function target_group( $scope, $label, array $result, array $certificate_map, array $email_map, $preselected = '' ) {
		$items = (array) ( $result['items'] ?? array() );
		$total = absint( $result['total'] ?? count( $items ) );
		echo '<details class="certloom-design-rule-target-group" data-certloom-rule-group="' . esc_attr( $scope ) . '" open>';
		echo '<summary><span>' . esc_html( $label ) . '</span><span data-certloom-rule-total>' . esc_html( (string) $total ) . '</span></summary>';
		echo '<div class="certloom-design-rule-target-list" data-certloom-rule-list="' . esc_attr( $scope ) . '">';
		foreach ( $items as $item ) {
			$this->target_row( $scope, $item, $certificate_map, $email_map, $preselected );
		}
		echo '</div>';
		echo '<div class="certloom-design-rule-target-pagination" data-certloom-rule-pagination="' . esc_attr( $scope ) . '">';
		if ( $total > count( $items ) ) {
			echo '<button type="button" class="button" data-certloom-rule-load-more data-scope="' . esc_attr( $scope ) . '" data-page="1">' . esc_html__( 'Load more', 'certloom' ) . '</button>';
		}
		echo '<span class="certloom-design-rule-target-status" data-certloom-rule-target-status aria-live="polite"></span>';
		echo '</div></details>';
	}

	/** @return void */
	private function target_row( $scope, array $item, array $certificate_map, array $email_map, $preselected = '' ) {
		$payload = $this->target_payload( $scope, $item, $certificate_map, $email_map );
		if ( empty( $payload ) ) { return; }
		$is_preselected = (string) $preselected === (string) $payload['key'];
		echo '<label class="certloom-design-rule-target' . ( $is_preselected ? ' is-preselected' : '' ) . '" data-certloom-rule-target data-search="' . esc_attr( strtolower( $payload['name'] . ' ' . $payload['meta'] ) ) . '">';
		echo '<input type="checkbox" name="targets[]" value="' . esc_attr( $payload['key'] ) . '" ' . checked( $is_preselected, true, false ) . '>';
		echo '<span class="certloom-design-rule-target-main"><strong>' . esc_html( $payload['name'] ) . '</strong><small>' . esc_html( $payload['meta'] ) . '</small>';
		echo '<span class="certloom-design-rule-target-state"><span>' . esc_html__( 'Certificate:', 'certloom' ) . ' ' . esc_html( $payload['certificate'] ) . '</span><span>' . esc_html__( 'Issued email:', 'certloom' ) . ' ' . esc_html( $payload['email'] ) . '</span></span></span>';
		echo '<button type="button" class="button button-small" data-certloom-rule-edit>' . esc_html__( 'Edit', 'certloom' ) . '</button></label>';
	}

	/** @return array<string,string> */
	private function target_payload( $scope, array $item, array $certificate_map, array $email_map ) {
		$id = absint( $item['id'] ?? 0 );
		if ( ! $id ) { return array(); }
		$key = sanitize_key( (string) $scope ) . ':' . $id;
		if ( isset( $item['_design_rules_meta'] ) ) {
			$name = 'program' === $scope ? (string) ( $item['title'] ?? '' ) : (string) ( $item['name'] ?? '' );
			$meta = (string) $item['_design_rules_meta'];
		} elseif ( 'issuer' === $scope ) {
			$name = (string) ( $item['name'] ?? '' );
			$meta = sprintf( /* translators: %d: number of Programs belonging to the Issuer. */ _n( '%d Program', '%d Programs', absint( $item['program_count'] ?? 0 ), 'certloom' ), absint( $item['program_count'] ?? 0 ) );
		} elseif ( 'program' === $scope ) {
			$name = (string) ( $item['title'] ?? '' );
			$meta = (string) ( $item['issuer_name'] ?? '' );
		} else {
			$name = (string) ( $item['name'] ?? '' );
			$meta = (string) ( $item['program_title'] ?? '' );
		}
		return array(
			'key' => $key,
			'name' => $name,
			'meta' => $meta,
			'certificate' => isset( $certificate_map[ $key ] ) ? (string) $certificate_map[ $key ] : __( 'Inherited', 'certloom' ),
			'email' => isset( $email_map[ $key ] ) ? (string) $email_map[ $key ] : __( 'Inherited', 'certloom' ),
		);
	}


	/** Server-backed target search/pagination for large hierarchy inventories. */
	public function targets_ajax() {
		$this->cap();
		check_ajax_referer( self::NONCE, 'nonce' );
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page = isset( $_POST['target_page'] ) ? max( 1, absint( $_POST['target_page'] ) ) : 1;
		$design_type = isset( $_POST['design_type'] ) ? sanitize_key( wp_unslash( $_POST['design_type'] ) ) : '';
		$design_id = isset( $_POST['design_id'] ) ? sanitize_text_field( wp_unslash( $_POST['design_id'] ) ) : '';
		if ( ! in_array( $scope, array( 'issuer', 'program', 'batch' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid Design Rules target group.', 'certloom' ) ), 400 );
		}
		$usage_filter = ( '' !== $design_type && '' !== $design_id ) ? $this->usage_filter( $design_type, $design_id ) : null;
		if ( is_wp_error( $usage_filter ) ) {
			wp_send_json_error( array( 'message' => $usage_filter->get_error_message() ), 400 );
		}
		$result = is_array( $usage_filter ) ? $this->usage_target_page( $scope, $usage_filter, $search, $page ) : $this->target_page( $scope, $search, $page );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		$template_rules = ( new TemplateRepository() )->list_assignments( 5000 );
		if ( is_wp_error( $template_rules ) ) {
			wp_send_json_error( array( 'message' => $template_rules->get_error_message() ), 500 );
		}
		$certificate_map = $this->certificate_rule_map( $template_rules );
		$email_map = $this->email_rule_map( ( new EmailDesignAssignmentRepository() )->all(), EmailDesignPurposes::ISSUED );
		$items = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
			$payload = $this->target_payload( $scope, (array) $row, $certificate_map, $email_map );
			if ( ! empty( $payload ) ) { $items[] = $payload; }
		}
		$total = absint( $result['total'] ?? count( $items ) );
		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
				'page' => $page,
				'hasMore' => ( $page * self::TARGET_PAGE_SIZE ) < $total,
			)
		);
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function target_page( $scope, $search, $page ) {
		if ( 'issuer' === $scope ) { return ( new IssuerRepository() )->list_page( $search, $page, self::TARGET_PAGE_SIZE ); }
		if ( 'program' === $scope ) { return ( new ProgramRepository() )->list_page( $search, $page, self::TARGET_PAGE_SIZE, 'all' ); }
		if ( 'batch' === $scope ) { return ( new BatchRepository() )->list_page( $search, $page, self::TARGET_PAGE_SIZE, 'all' ); }
		return new \WP_Error( 'certloom_design_rule_scope_invalid', __( 'Choose Issuer, Program or Batch.', 'certloom' ) );
	}

	/** Read-only direct-usage filter from the Design Library deep-link. @return array<string,mixed>|null */
	private function usage_filter_from_request() {
		$type = isset( $_GET['design_type'] ) ? sanitize_key( wp_unslash( $_GET['design_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering.
		$id = isset( $_GET['design_id'] ) ? sanitize_text_field( wp_unslash( $_GET['design_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering.
		if ( '' === $type && '' === $id ) { return null; }
		$filter = $this->usage_filter( $type, $id );
		return is_wp_error( $filter ) ? null : $filter;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function usage_filter( $type, $id ) {
		$type = sanitize_key( (string) $type );
		$scope_ids = array( 'issuer' => array(), 'program' => array(), 'batch' => array() );
		$global = false;
		$label = '';

		if ( 'certificate' === $type ) {
			$template_id = absint( $id );
			if ( ! $template_id ) { return new \WP_Error( 'certloom_design_rule_usage_invalid', __( 'Choose a valid certificate design usage filter.', 'certloom' ) ); }
			$repo = new TemplateRepository();
			$template = $repo->find( $template_id );
			if ( is_wp_error( $template ) || ! is_array( $template ) ) { return new \WP_Error( 'certloom_design_rule_usage_missing', __( 'The certificate design for this usage filter is no longer available.', 'certloom' ) ); }
			$label = (string) ( $template['name'] ?? __( 'Certificate design', 'certloom' ) );
			$rows = $repo->list_assignments( 5000 );
			if ( is_wp_error( $rows ) ) { return $rows; }
			foreach ( $rows as $row ) {
				if ( $template_id !== absint( $row['template_id'] ?? 0 ) ) { continue; }
				$scope = sanitize_key( (string) ( $row['scope_type'] ?? '' ) );
				if ( 'global' === $scope ) { $global = true; continue; }
				if ( isset( $scope_ids[ $scope ] ) && absint( $row['scope_id'] ?? 0 ) ) { $scope_ids[ $scope ][] = absint( $row['scope_id'] ); }
			}
			$id = (string) $template_id;
		} elseif ( 'email' === $type ) {
			$design_id = sanitize_key( (string) $id );
			$catalog = new EmailTemplateCatalogRepository();
			$design = $catalog->get( $design_id );
			if ( '' === $design_id || ! is_array( $design ) ) { return new \WP_Error( 'certloom_design_rule_usage_missing', __( 'The email design for this usage filter is no longer available.', 'certloom' ) ); }
			$label = (string) ( $design['name'] ?? __( 'Email design', 'certloom' ) );
			foreach ( ( new EmailDesignAssignmentRepository() )->all() as $row ) {
				if ( $design_id !== (string) ( $row['design_id'] ?? '' ) ) { continue; }
				$scope = sanitize_key( (string) ( $row['scope_type'] ?? '' ) );
				if ( 'global' === $scope ) { $global = true; continue; }
				if ( isset( $scope_ids[ $scope ] ) && absint( $row['scope_id'] ?? 0 ) ) { $scope_ids[ $scope ][] = absint( $row['scope_id'] ); }
			}
			$id = $design_id;
		} else {
			return new \WP_Error( 'certloom_design_rule_usage_type_invalid', __( 'Choose a certificate or email design usage filter.', 'certloom' ) );
		}

		foreach ( $scope_ids as $scope => $ids ) { $scope_ids[ $scope ] = array_values( array_unique( array_map( 'absint', $ids ) ) ); }
		return array( 'type' => $type, 'id' => (string) $id, 'label' => $label, 'global' => $global, 'scope_ids' => $scope_ids );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function usage_target_page( $scope, array $filter, $search, $page ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $filter['scope_ids'][ $scope ] ?? array() ) ) ) );
		if ( empty( $ids ) ) { return array( 'items' => array(), 'total' => 0 ); }
		$labels = $this->usage_target_labels( $scope, $ids );
		if ( is_wp_error( $labels ) ) { return $labels; }
		$search = strtolower( trim( (string) $search ) );
		if ( '' !== $search ) {
			$ids = array_values( array_filter( $ids, static function( $target_id ) use ( $labels, $search ) {
				return false !== strpos( strtolower( (string) ( $labels[ $target_id ] ?? '' ) ), $search );
			} ) );
		}
		$total = count( $ids );
		$offset = ( max( 1, absint( $page ) ) - 1 ) * self::TARGET_PAGE_SIZE;
		$page_ids = array_slice( $ids, $offset, self::TARGET_PAGE_SIZE );
		$items = $this->usage_target_items( $scope, $page_ids );
		return is_wp_error( $items ) ? $items : array( 'items' => $items, 'total' => $total );
	}

	/** @return array<int,string>|\WP_Error */
	private function usage_target_labels( $scope, array $ids ) {
		$out = array();
		foreach ( array_chunk( $ids, 2500 ) as $chunk ) {
			if ( 'issuer' === $scope ) { $part = ( new IssuerRepository() )->labels_by_ids( $chunk ); }
			elseif ( 'program' === $scope ) { $part = ( new ProgramRepository() )->labels_by_ids( $chunk ); }
			elseif ( 'batch' === $scope ) { $part = ( new BatchRepository() )->labels_by_ids( $chunk ); }
			else { return new \WP_Error( 'certloom_design_rule_scope_invalid', __( 'Choose Issuer, Program or Batch.', 'certloom' ) ); }
			if ( is_wp_error( $part ) ) { return $part; }
			$out += $part;
		}
		return $out;
	}

	/** Hydrate only the current filtered page; this keeps direct-usage filtering bounded. @return array<int,array<string,mixed>>|\WP_Error */
	private function usage_target_items( $scope, array $ids ) {
		$items = array();
		$parents = array();
		foreach ( $ids as $id ) {
			if ( 'issuer' === $scope ) { $row = ( new IssuerRepository() )->find( $id ); }
			elseif ( 'program' === $scope ) { $row = ( new ProgramRepository() )->find( $id ); }
			else { $row = ( new BatchRepository() )->find( $id ); }
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $row ) ) { continue; }
			$items[] = $row;
			if ( 'program' === $scope && ! empty( $row['issuer_id'] ) ) { $parents[] = absint( $row['issuer_id'] ); }
			if ( 'batch' === $scope && ! empty( $row['program_id'] ) ) { $parents[] = absint( $row['program_id'] ); }
		}
		$parent_labels = array();
		if ( 'program' === $scope && $parents ) { $parent_labels = ( new IssuerRepository() )->labels_by_ids( $parents ); }
		if ( 'batch' === $scope && $parents ) { $parent_labels = ( new ProgramRepository() )->labels_by_ids( $parents ); }
		if ( is_wp_error( $parent_labels ) ) { return $parent_labels; }
		foreach ( $items as &$row ) {
			if ( 'issuer' === $scope ) { $row['_design_rules_meta'] = __( 'Issuer', 'certloom' ); }
			elseif ( 'program' === $scope ) { $row['_design_rules_meta'] = (string) ( $parent_labels[ absint( $row['issuer_id'] ?? 0 ) ] ?? __( 'Program', 'certloom' ) ); }
			else { $row['_design_rules_meta'] = (string) ( $parent_labels[ absint( $row['program_id'] ?? 0 ) ] ?? __( 'Batch', 'certloom' ) ); }
		}
		unset( $row );
		return $items;
	}


	/** Compact, shared hierarchy editor embedded in Issuer/Program/Batch screens. */
	public function context_card( $scope_type, $scope_id ) {
		$manager = new DesignRuleManager();
		$state = $manager->state( $scope_type, $scope_id, EmailDesignPurposes::ISSUED );
		if ( is_wp_error( $state ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $state->get_error_message() ) . '</p></div>'; return; }
		$target = (array) ( $state['target'] ?? array() );
		$target_key = sanitize_key( (string) $scope_type ) . ':' . absint( $scope_id );
		$certificates = ( new TemplateRepository() )->list_published_candidates( 250 );
		$certificates = is_wp_error( $certificates ) ? array() : (array) $certificates;
		$catalog = new EmailTemplateCatalogRepository(); $catalog->ensure_factory_presets();
		$emails = array_values( array_filter( $catalog->all(), static function( $design ) { return is_array( $design ) && ! empty( $design['active'] ); } ) );
		$direct_cert = is_array( $state['certificate_direct'] ?? null ) ? absint( $state['certificate_direct']['template_id'] ?? 0 ) : 0;
		$email_repo = new EmailDesignAssignmentRepository(); $direct_email = array();
		foreach ( EmailDesignPurposes::all() as $purpose ) { $row = $email_repo->get( $scope_type, $scope_id, $purpose ); if ( is_array( $row ) ) { $direct_email[ $purpose ] = (string) $row['design_id']; } }
		$cert_view = $this->certificate_resolved_preview( $state['certificate_effective'] ?? null, $scope_type );
		$email_view = $this->email_resolved_preview( $state['email_effective'] ?? null, $scope_type );
		$cert_label = is_wp_error( $cert_view ) ? __( 'No effective certificate design', 'certloom' ) : (string) $cert_view['label'];
		$cert_source = is_wp_error( $cert_view ) ? '' : (string) $cert_view['source'];
		$email_label = is_wp_error( $email_view ) ? __( 'No effective issued email design', 'certloom' ) : (string) $email_view['label'];
		$email_source = is_wp_error( $email_view ) ? '' : (string) $email_view['source'];
		$dialog_id = 'certloom-design-context-' . sanitize_key( (string) $scope_type ) . '-' . absint( $scope_id );
		echo '<section class="certloom-context-design-card"><div class="certloom-context-design-card__head"><div><span class="certloom-eyebrow">' . esc_html__( 'Presentation & communication', 'certloom' ) . '</span><h3>' . esc_html__( 'Designs', 'certloom' ) . '</h3><p>' . esc_html__( 'Certificate and email rules inherit independently through Global → Issuer → Program → Batch.', 'certloom' ) . '</p></div><button type="button" class="button" data-certloom-context-design-open="' . esc_attr( $dialog_id ) . '">' . esc_html__( 'Change designs', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-context-design-summary"><div><span>' . esc_html__( 'Certificate', 'certloom' ) . '</span><strong data-context-summary-label="certificate">' . esc_html( $cert_label ) . '</strong><small data-context-summary-source="certificate">' . esc_html( $cert_source ) . '</small></div><div><span>' . esc_html__( 'Credential issued email', 'certloom' ) . '</span><strong data-context-summary-label="email">' . esc_html( $email_label ) . '</strong><small data-context-summary-source="email">' . esc_html( $email_source ) . '</small></div></div></section>';
		echo '<dialog id="' . esc_attr( $dialog_id ) . '" class="certloom-context-design-dialog" data-certloom-context-design-dialog data-target="' . esc_attr( $target_key ) . '" data-email-rules="' . esc_attr( wp_json_encode( $direct_email ) ) . '"><div class="certloom-context-design-dialog__head"><div><span class="certloom-eyebrow">' . esc_html( $this->scope_name( (string) $scope_type ) ) . '</span><h2>' . esc_html( (string) ( $target['label'] ?? __( 'Designs', 'certloom' ) ) ) . '</h2></div><button type="button" class="button" data-certloom-context-design-close>' . esc_html__( 'Close', 'certloom' ) . '</button></div><div class="certloom-context-design-dialog__body">';
		echo '<section><div class="certloom-design-rule-control-head"><span>' . esc_html__( 'Certificate design', 'certloom' ) . '</span><small>' . esc_html__( 'Current or inherited', 'certloom' ) . '</small></div><select data-context-choice="certificate"><option value="inherit" ' . selected( $direct_cert, 0, false ) . '>' . esc_html__( 'Inherit from parent', 'certloom' ) . '</option>'; foreach ( $certificates as $item ) { echo '<option value="design:' . esc_attr( (string) absint( $item['id'] ?? 0 ) ) . '" ' . selected( $direct_cert, absint( $item['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</option>'; } echo '</select><div class="certloom-design-rule-preview" data-context-preview="certificate">' . $this->certificate_state_preview( $state['certificate_effective'] ?? null, $scope_type ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<section><div class="certloom-design-rule-control-head"><span>' . esc_html__( 'Email design', 'certloom' ) . '</span><small>' . esc_html__( 'Choose a message purpose', 'certloom' ) . '</small></div><label class="certloom-rule-purpose"><span>' . esc_html__( 'Message', 'certloom' ) . '</span><select data-context-purpose>'; foreach ( EmailDesignPurposes::labels() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; } echo '</select></label><select data-context-choice="email">' . $this->email_options( $emails, EmailDesignPurposes::ISSUED, (string) ( $direct_email[ EmailDesignPurposes::ISSUED ] ?? '' ), false ) . '</select><div class="certloom-design-rule-preview" data-context-preview="email">' . $this->email_state_preview( $state['email_effective'] ?? null, $scope_type ) . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><div class="certloom-context-design-dialog__foot"><span aria-live="polite" data-context-feedback></span><a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'rule_scope' => sanitize_key( (string) $scope_type ), 'rule_scope_id' => absint( $scope_id ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open Design Rules', 'certloom' ) . '</a><button type="button" class="button button-primary" data-context-save>' . esc_html__( 'Save changes', 'certloom' ) . '</button></div></dialog>';
	}

	/** @return void */
	public function apply_ajax() {
		$this->cap();
		check_ajax_referer( self::NONCE, 'nonce' );

		$target_raw = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		if ( ! preg_match( '/^(issuer|program|batch):(\d+)$/', $target_raw, $match ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid design-rule target.', 'certloom' ) ), 400 );
		}

		list( $certificate_mode, $certificate_id ) = $this->parse_choice( $_POST['certificate_choice'] ?? 'inherit' );
		list( $email_mode, $email_id ) = $this->parse_choice( $_POST['email_choice'] ?? 'inherit', true );
		$purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : EmailDesignPurposes::ISSUED;
		$manager = new DesignRuleManager();
		$result = $manager->apply_many(
			array(
				array(
					'scope_type' => $match[1],
					'scope_id'   => absint( $match[2] ),
				),
			),
			$certificate_mode,
			$certificate_id,
			$email_mode,
			$email_id,
			$purpose
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$state = $manager->state( $match[1], absint( $match[2] ), $purpose );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ), 500 );
		}
		$certificate = $this->certificate_resolved_preview( $state['certificate_effective'] ?? null, $match[1] );
		$email = $this->email_resolved_preview( $state['email_effective'] ?? null, $match[1] );
		if ( is_wp_error( $certificate ) ) {
			wp_send_json_error( array( 'message' => $certificate->get_error_message() ), 500 );
		}
		if ( is_wp_error( $email ) ) {
			wp_send_json_error( array( 'message' => $email->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Designs updated.', 'certloom' ),
				'certificate' => $certificate,
				'email'       => $email,
			)
		);
	}

	/** @return void */
	public function apply() {
		$this->cap();
		check_admin_referer( 'certloom_design_rules_apply' );

		$targets = array();
		foreach ( (array) ( $_POST['targets'] ?? array() ) as $value ) {
			$value = sanitize_text_field( wp_unslash( $value ) );
			if ( ! preg_match( '/^(global|issuer|program|batch):(\d+)$/', $value, $match ) ) {
				continue;
			}
			$targets[] = array(
				'scope_type' => $match[1],
				'scope_id'   => absint( $match[2] ),
			);
		}

		list( $certificate_mode, $certificate_id ) = $this->parse_choice( $_POST['certificate_choice'] ?? 'no_change' );
		list( $email_mode, $email_id ) = $this->parse_choice( $_POST['email_choice'] ?? 'no_change', true );
		$purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : EmailDesignPurposes::ISSUED;
		$result = ( new DesignRuleManager() )->apply_many(
			$targets,
			$certificate_mode,
			$certificate_id,
			$email_mode,
			$email_id,
			$purpose
		);

		$query = array(
			'page'            => self::PAGE,
			'certloom_notice' => 'rules_saved',
		);
		if ( is_wp_error( $result ) ) {
			$query['certloom_error'] = '1';
			$query['certloom_message'] = $result->get_error_message();
		} else {
			$query['certloom_message'] = __( 'Design rules updated.', 'certloom' );
		}
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	public function preview() {
		$this->cap();
		check_ajax_referer( self::NONCE, 'nonce' );

		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$choice = isset( $_POST['choice'] ) ? sanitize_text_field( wp_unslash( $_POST['choice'] ) ) : 'no_change';
		$purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : EmailDesignPurposes::ISSUED;
		$raw = isset( $_POST['targets_json'] ) ? (string) wp_unslash( $_POST['targets_json'] ) : '[]';
		$decoded = json_decode( $raw, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( empty( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one record to preview a rule.', 'certloom' ) ), 400 );
		}
		if ( count( $decoded ) > DesignRuleManager::MAX_TARGETS ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum number of preview targets. */
						__( 'Preview no more than %d records at once.', 'certloom' ),
						DesignRuleManager::MAX_TARGETS
					),
				),
				400
			);
		}

		list( $mode, $id ) = $this->parse_choice( $choice, 'email' === $kind );
		$manager = new DesignRuleManager();
		$rendered = array();
		$identities = array();
		foreach ( $decoded as $raw_target ) {
			if ( ! is_array( $raw_target ) ) {
				continue;
			}
			$target = $manager->target( $raw_target['scope_type'] ?? '', $raw_target['scope_id'] ?? 0 );
			if ( is_wp_error( $target ) ) {
				wp_send_json_error( array( 'message' => $target->get_error_message() ), 400 );
			}
			if ( 'certificate' === $kind ) {
				$resolved = $manager->certificate_after( $target, $mode, $id );
				$view = $this->certificate_resolved_preview( $resolved, (string) $target['scope_type'] );
			} else {
				$resolved = $manager->email_after( $target, $mode, (string) $id, $purpose );
				$view = $this->email_resolved_preview( $resolved, (string) $target['scope_type'] );
			}
			if ( is_wp_error( $view ) ) {
				wp_send_json_error( array( 'message' => $view->get_error_message() ), 400 );
			}
			$rendered[] = $view;
			$identities[] = (string) ( $view['identity'] ?? '' );
		}

		$first = $rendered[0] ?? array(
			'html'     => '',
			'label'    => '',
			'source'   => '',
			'identity' => '',
		);
		$varies = count( array_unique( $identities ) ) > 1;
		wp_send_json_success(
			array(
				'html'   => $first['html'],
				'label'  => $first['label'],
				'source' => $first['source'],
				'varies' => $varies,
			)
		);
	}

	/** @return array{0:string,1:int|string} */
	private function parse_choice( $choice, $email = false ) {
		$choice = sanitize_text_field( wp_unslash( $choice ) );
		if ( 'no_change' === $choice ) {
			return array( DesignRuleManager::MODE_NO_CHANGE, $email ? '' : 0 );
		}
		if ( 'inherit' === $choice ) {
			return array( DesignRuleManager::MODE_INHERIT, $email ? '' : 0 );
		}
		if ( preg_match( '/^design:([a-z0-9_-]+)$/', $choice, $match ) ) {
			return array(
				DesignRuleManager::MODE_DESIGN,
				$email ? sanitize_key( $match[1] ) : absint( $match[1] ),
			);
		}
		return array( DesignRuleManager::MODE_NO_CHANGE, $email ? '' : 0 );
	}

	/** @return array<string,string>|\WP_Error */
	private function certificate_resolved_preview( $resolved, $target_scope = '' ) {
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( ! is_array( $resolved ) ) {
			return array(
				'html'     => '<div class="certloom-rule-preview-empty">' . esc_html__( 'No effective certificate design', 'certloom' ) . '</div>',
				'label'    => __( 'No certificate design', 'certloom' ),
				'source'   => '',
				'identity' => 'none',
			);
		}

		if ( isset( $resolved['template'] ) && is_array( $resolved['template'] ) ) {
			$template = $resolved['template'];
			$label = (string) ( $template['name'] ?? __( 'Certificate design', 'certloom' ) );
			$source = __( 'Selected design', 'certloom' );
			$identity = 'template:' . absint( $template['id'] ?? 0 );
		} else {
			$template = array(
				'published_revision_id' => absint( $resolved['published_revision_id'] ?? 0 ),
				'published_revision'    => array(
					'id'              => absint( $resolved['published_revision_id'] ?? 0 ),
					'revision_number' => absint( $resolved['published_revision_number'] ?? 0 ),
					'layout_sha256'   => (string) ( $resolved['published_layout_sha256'] ?? '' ),
					'layout'          => (array) ( $resolved['published_layout'] ?? array() ),
				),
			);
			$label = (string) ( $resolved['template_name'] ?? __( 'Certificate design', 'certloom' ) );
			$source = $this->scope_label( (string) ( $resolved['scope_type'] ?? '' ), (string) $target_scope );
			$identity = 'template:' . absint( $resolved['template_id'] ?? 0 );
		}

		$built = ( new TemplatePreviewService() )->build( $template, 'published', TemplatePreviewSamples::STANDARD );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		try {
			$presented = ( new RenderPlanHtmlPreview() )->present( $built['render_plan'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error(
				'certloom_design_rule_preview_failed',
				__( 'Certificate preview could not be rendered.', 'certloom' )
			);
		}
		return array(
			'html'     => '<div class="certloom-rule-certificate-stage">' . (string) $presented['html'] . '</div>',
			'label'    => $label,
			'source'   => $source,
			'identity' => $identity,
		);
	}

	/** @return array<string,string>|\WP_Error */
	private function email_resolved_preview( $resolved, $target_scope = '' ) {
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( ! is_array( $resolved ) || ! is_array( $resolved['design'] ?? null ) ) {
			return new \WP_Error(
				'certloom_design_rule_email_preview_missing',
				__( 'No effective email design is available for this message purpose.', 'certloom' )
			);
		}
		$design = $resolved['design'];
		$html = $this->sample_email_html( (string) ( $design['html_body'] ?? '' ) );
		return array(
			'html'     => '<div class="certloom-rule-email-stage">' . $html . '</div>',
			'label'    => (string) ( $design['name'] ?? '' ),
			'source'   => $this->scope_label( (string) ( $resolved['scope_type'] ?? '' ), (string) $target_scope ),
			'identity' => 'email:' . (string) ( $design['id'] ?? '' ),
		);
	}

	/** @return string */
	private function certificate_state_preview( $resolved, $target_scope = '' ) {
		$view = $this->certificate_resolved_preview( $resolved, $target_scope );
		if ( is_wp_error( $view ) ) {
			return '<div class="certloom-rule-preview-empty">' . esc_html( $view->get_error_message() ) . '</div>';
		}
		return $view['html'] . '<footer><strong>' . esc_html( $view['label'] ) . '</strong><span>' . esc_html( $view['source'] ) . '</span></footer>';
	}

	/** @return string */
	private function email_state_preview( $resolved, $target_scope = '' ) {
		$view = $this->email_resolved_preview( $resolved, $target_scope );
		if ( is_wp_error( $view ) ) {
			return '<div class="certloom-rule-preview-empty">' . esc_html( $view->get_error_message() ) . '</div>';
		}
		return $view['html'] . '<footer><strong>' . esc_html( $view['label'] ) . '</strong><span>' . esc_html( $view['source'] ) . '</span></footer>';
	}

	/** @return string */
	private function email_options( array $emails, $purpose, $selected, $include_no_change ) {
		$out = $include_no_change ? '<option value="no_change">' . esc_html__( 'No change', 'certloom' ) . '</option>' : '';
		$out .= '<option value="inherit">' . esc_html__( 'Inherit from parent', 'certloom' ) . '</option>';
		foreach ( $emails as $design ) {
			$id = (string) ( $design['id'] ?? '' );
			$design_purpose = (string) ( $design['purpose'] ?? '' );
			$out .= '<option value="design:' . esc_attr( $id ) . '" data-purpose="' . esc_attr( $design_purpose ) . '" ' . selected( $selected, $id, false ) . '>' . esc_html( (string) ( $design['name'] ?? '' ) ) . '</option>';
		}
		return $out;
	}

	/** @return array<string,string> */
	private function certificate_rule_map( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['scope_type'] . ':' . absint( $row['scope_id'] ?? 0 ) ] = (string) ( $row['template_name'] ?? '' );
		}
		return $out;
	}

	/** @return array<string,string> */
	private function email_rule_map( array $rows, $purpose ) {
		$catalog = new EmailTemplateCatalogRepository();
		$out = array();
		foreach ( $rows as $row ) {
			if ( $purpose !== (string) ( $row['purpose'] ?? '' ) ) {
				continue;
			}
			$design = $catalog->get( (string) ( $row['design_id'] ?? '' ) );
			$out[ (string) $row['scope_type'] . ':' . absint( $row['scope_id'] ?? 0 ) ] = is_array( $design )
				? (string) $design['name']
				: __( 'Unavailable design', 'certloom' );
		}
		return $out;
	}

	/** @return string */
	private function sample_email_html( $html ) {
		return strtr(
			EmailDesignCompiler::sanitize_inline_html( $html ),
			array(
				'{{recipient_name}}'    => esc_html__( 'Sara Khan', 'certloom' ),
				'{{program_title}}'     => esc_html__( 'Professional Leadership Program', 'certloom' ),
				'{{issuer_name}}'       => esc_html__( 'Example Institute', 'certloom' ),
				'{{credential_number}}' => 'CL-2026-00421',
				'{{issue_date}}'        => '2026-09-04',
				'{{expiry_date}}'       => '2027-09-04',
				'{{grade}}'             => esc_html__( 'Distinction', 'certloom' ),
				'{{verification_url}}'  => '#',
				'{{verification_link}}' => esc_html__( 'Verify this credential', 'certloom' ),
			)
		);
	}

	/** @return string */
	private function scope_label( $scope, $target_scope = '' ) {
		$scope = sanitize_key( (string) $scope );
		$target_scope = sanitize_key( (string) $target_scope );
		if ( 'selected' === $scope ) {
			return __( 'Selected design', 'certloom' );
		}
		if ( 'catalog_default' === $scope ) {
			return __( 'Catalog default', 'certloom' );
		}
		if ( 'global' === $scope ) {
			return 'global' === $target_scope ? __( 'Global default', 'certloom' ) : __( 'Inherited from Global', 'certloom' );
		}
		if ( 'issuer' === $scope ) {
			return 'issuer' === $target_scope ? __( 'Direct Issuer rule', 'certloom' ) : __( 'Inherited from Issuer', 'certloom' );
		}
		if ( 'program' === $scope ) {
			return 'program' === $target_scope ? __( 'Direct Program rule', 'certloom' ) : __( 'Inherited from Program', 'certloom' );
		}
		if ( 'batch' === $scope ) {
			return 'batch' === $target_scope ? __( 'Direct Batch rule', 'certloom' ) : __( 'Batch override', 'certloom' );
		}
		return '';
	}

	/** @return string */
	private function scope_name( $scope ) {
		$labels = array(
			'global'  => __( 'Global', 'certloom' ),
			'issuer'  => __( 'Issuer', 'certloom' ),
			'program' => __( 'Program', 'certloom' ),
			'batch'   => __( 'Batch', 'certloom' ),
		);
		return (string) ( $labels[ sanitize_key( (string) $scope ) ] ?? '' );
	}

	/** @return void */
	private function notice() {
		if ( empty( $_GET['certloom_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice rendering.
			return;
		}
		$message = isset( $_GET['certloom_message'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice rendering.
		$type = isset( $_GET['certloom_error'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice rendering.
		if ( '' !== $message ) {
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/** @return void */
	private function cap() {
		if (
			! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ) ||
			! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS )
		) {
			wp_die(
				esc_html__( 'You are not allowed to manage Certloom design rules.', 'certloom' ),
				'',
				array( 'response' => 403 )
			);
		}
	}
}
