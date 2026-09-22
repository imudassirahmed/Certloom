<?php
namespace Certloom;

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobDispatcher;
use Certloom\Infrastructure\Job\JobRuntime;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Next-generation Certloom module bootstrap inside the existing plugin.
 *
 * During the in-place upgrade period the proven legacy public registry
 * remains active while the new additive domain schema is built alongside it.
 * This avoids destructive cutovers and gives the later migration milestone a
 * stable source and destination to validate independently.
 */
final class Plugin {
	/** @var JobRuntime|null */
	private static $job_runtime;
	/**
	 * Creates/validates additive next-generation Certloom domain storage.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate() {
		/*
		 * The additive Series/Version domain is intentionally gated while the
		 * proven 1.5 registry remains authoritative. Do not make unused future
		 * tables a hard dependency of normal plugin activation: hosts that cannot
		 * provision the gated schema must still be able to use the live verifier.
		 * Once a domain feature, cutover, or interrupted domain operation actually
		 * requires the storage, setup becomes mandatory and remains fail-closed.
		 */
		if ( ! self::domain_storage_required() ) {
			return true;
		}

		$recovery = ( new DomainRestoreWorkspace() )->recover_interrupted_commit();
		if ( is_wp_error( $recovery ) ) {
			return $recovery;
		}
		$schema = SchemaManager::activate();
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}
		return JobDispatcher::activate();
	}

	/**
	 * Boots only infrastructure that is safe before the new domain becomes the
	 * public source of truth. Existing 1.5 verification continues unchanged.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! self::domain_storage_required() ) {
			return;
		}

		$recovery = ( new DomainRestoreWorkspace() )->recover_interrupted_commit();
		if ( is_wp_error( $recovery ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $recovery->get_error_message() );
		}

		$result = SchemaManager::maybe_upgrade();
		if ( is_wp_error( $result ) && function_exists( 'ecapla_certverify_add_runtime_error' ) ) {
			ecapla_certverify_add_runtime_error( $result->get_error_message() );
		}
		if ( ! is_wp_error( $result ) ) {
			( new \Certloom\Infrastructure\Recipient\RecipientUserSync() )->register();
			self::$job_runtime = new JobRuntime();
			self::$job_runtime->register();
			// Post-issuance automations must also be available when Tutor, LearnDash,
			// WooCommerce or another integration issues outside wp-admin. The
			// listener only reacts after the canonical issuance service commits a
			// new immutable Version; it never creates credential truth itself.
			( new \Certloom\Infrastructure\Email\EmailAutomationListener( self::$job_runtime->email_delivery() ) )->register();
			( new \Certloom\Infrastructure\Integration\CredlyPublishingListener( self::$job_runtime->queue() ) )->register();
		}
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() ) {
			( new \Certloom\Infrastructure\Admin\AdminSearchRest() )->register();
			( new \Certloom\Infrastructure\Admin\IssueCredentialRest() )->register();
		}
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::admin_preview_ready() && is_admin() ) {
			( new \Certloom\Infrastructure\Admin\DomainAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\DomainStructureAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\CustomFontAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\TemplateAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\EmailDesignAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\DesignRulesAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\ManualIssuanceAdmin() )->register();
			( new \Certloom\Infrastructure\Admin\ScopedFieldAdminAjax() )->register();
			( new \Certloom\Infrastructure\Admin\AutomationAdmin() )->register();
			if ( self::$job_runtime ) {
				( new \Certloom\Infrastructure\Admin\BulkIssuanceAdmin( self::$job_runtime ) )->register();
				( new \Certloom\Infrastructure\Admin\EmailDeliveryAdmin( self::$job_runtime ) )->register();
				( new \Certloom\Infrastructure\Admin\DomainDataToolsAdmin( self::$job_runtime ) )->register();
			}
		}
	}

	/**
	 * Whether the additive domain must be available for this site right now.
	 *
	 * A fresh/live 1.5 site with every next-domain feature gate disabled must
	 * not create ten unused tables merely to activate Certloom. Conversely, a
	 * site that has switched authority or has durable maintenance/restore state
	 * must not bypass domain recovery just because the compile-time gates are
	 * disabled.
	 *
	 * @return bool
	 */
	private static function domain_storage_required() {
		if ( \Certloom\Infrastructure\Migration\DomainFeatureGate::any_ready() ) {
			return true;
		}

		$cutover = new DomainCutoverState();
		if ( $cutover->is_domain_authoritative() || $cutover->is_corrupt() || $cutover->has_any_receipt_state() ) {
			return true;
		}

		/* Any started official migration has durable rows/state that require the additive domain on every request. */
		if ( LegacyMigrationState::NOT_STARTED !== ( new LegacyMigrationState() )->status() ) {
			return true;
		}

		/* Durable recovery evidence makes domain setup/recovery mandatory. */
		if ( false !== get_option( DomainMaintenanceLock::OPTION, false )
			|| false !== get_option( DomainRestoreWorkspace::JOURNAL_OPTION, false )
			|| false !== get_option( DomainRestoreWorkspace::BUILD_OPTION, false ) ) {
			return true;
		}

		return false;
	}

	/** Clears WordPress-local domain job events for the current site. */
	public static function deactivate() {
		JobDispatcher::deactivate();
	}

	/** @return JobRuntime|null */
	public static function job_runtime() {
		return self::$job_runtime;
	}
}
