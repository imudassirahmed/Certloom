<?php
/**
 * Plugin Name: Certloom – Certificate Verification
 * Description: Manage certificates and publish exact-number verification with a native block, shortcode, CSV tools, and optional Elementor widgets.
 * Version: 1.6.47
 * Author: Ecapla
 * Author URI: https://ecapla.com
 * Text Domain: certloom
 * Domain Path: /languages
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ECAPLA_CERTVERIFY_VERSION', '1.6.47' );
define( 'ECAPLA_CERTVERIFY_SCHEMA_VERSION', '1.4.7' );
define( 'ECAPLA_CERTVERIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'ECAPLA_CERTVERIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'ECAPLA_CERTVERIFY_TABLE', 'certverify_certificates' );
define( 'ECAPLA_CERTVERIFY_CAP', 'manage_certverify' );
define( 'ECAPLA_CERTVERIFY_MINIMUM_ELEMENTOR_VERSION', '3.10.0' );

/*
 * Canonical Certloom constants. Legacy ECAPLA_CERTVERIFY_* constants remain
 * available while the proven 1.5 registry is migrated through explicit later
 * milestones inside the same plugin upgrade line.
 */
define( 'CERTLOOM_VERSION', ECAPLA_CERTVERIFY_VERSION );
define( 'CERTLOOM_PATH', ECAPLA_CERTVERIFY_PATH );
define( 'CERTLOOM_URL', ECAPLA_CERTVERIFY_URL );
if ( ! defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) ) {
	define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
}
if ( ! defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) ) {
	define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
}
if ( ! defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) ) {
	define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
}
if ( ! defined( 'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL' ) ) {
	define( 'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL', false );
}
if ( ! defined( 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL' ) ) {
	define( 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL', false );
}
if ( ! defined( 'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL' ) ) {
	define( 'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL', false );
}

require_once CERTLOOM_PATH . 'src/Autoloader.php';
\Certloom\Autoloader::register();


/*
 * Backward-compatible constants retained from earlier pre-release builds.
 * New code should use the ECAPLA_CERTVERIFY_* constants.
 */
if ( ! defined( 'CERTVERIFY_VERSION' ) ) {
	define( 'CERTVERIFY_VERSION', ECAPLA_CERTVERIFY_VERSION );
}
if ( ! defined( 'CERTVERIFY_PATH' ) ) {
	define( 'CERTVERIFY_PATH', ECAPLA_CERTVERIFY_PATH );
}
if ( ! defined( 'CERTVERIFY_URL' ) ) {
	define( 'CERTVERIFY_URL', ECAPLA_CERTVERIFY_URL );
}
if ( ! defined( 'CERTVERIFY_TABLE' ) ) {
	define( 'CERTVERIFY_TABLE', ECAPLA_CERTVERIFY_TABLE );
}
if ( ! defined( 'CERTVERIFY_CAP' ) ) {
	define( 'CERTVERIFY_CAP', ECAPLA_CERTVERIFY_CAP );
}

require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-settings.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-fields.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-validator.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-database.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-permissions.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-public-router.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-public-policy.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-secondary-code.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-public-presentation.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-qr-logo.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-qr.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-barcode.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-render.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-shortcode.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-block.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-rest.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-import.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-backup.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-numbering.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-database-proof.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-cron.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-system-status.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-dashboard.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-bulk-manager.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-onboarding.php';
require_once ECAPLA_CERTVERIFY_PATH . 'includes/class-ecapla-certverify-privacy.php';

register_activation_hook( __FILE__, 'ecapla_certverify_activate' );
register_deactivation_hook( __FILE__, 'ecapla_certverify_deactivate' );

add_action( 'plugins_loaded', 'ecapla_certverify_init', 20 );
add_action( 'elementor/loaded', 'ecapla_certverify_elementor_loaded' );
add_action( 'wp_initialize_site', 'ecapla_certverify_initialize_site', 200, 2 );
add_action( 'admin_init', 'ecapla_certverify_add_privacy_policy_content' );
add_action( 'network_admin_notices', 'ecapla_certverify_multisite_provisioning_notice' );
add_action( 'ecapla_certverify_continue_migrations', 'ecapla_certverify_continue_migrations' );
add_action( 'upgrader_process_complete', 'ecapla_certverify_after_plugin_upgrade', 10, 2 );

/**
 * Continues one bounded database-migration batch from WP-Cron.
 */
function ecapla_certverify_continue_migrations() {
	$result = Ecapla_CertVerify_Database::maybe_upgrade();
	if ( is_wp_error( $result ) ) {
		ecapla_certverify_add_runtime_error( $result->get_error_message() );
		if ( ! wp_next_scheduled( 'ecapla_certverify_continue_migrations' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ecapla_certverify_continue_migrations' );
		}
	}
}

/**
 * Clears cached health state after this plugin is updated so the next admin or
 * cron request verifies the new schema and resumes any bounded data migration.
 *
 * @param WP_Upgrader $upgrader Upgrader instance.
 * @param array       $options  Upgrade context.
 */
function ecapla_certverify_after_plugin_upgrade( $upgrader, $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	if ( empty( $options['action'] ) || 'update' !== $options['action'] || empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
		return;
	}
	$plugins = isset( $options['plugins'] ) ? (array) $options['plugins'] : array();
	if ( isset( $options['plugin'] ) ) {
		$plugins[] = $options['plugin'];
	}
	if ( ! in_array( plugin_basename( __FILE__ ), $plugins, true ) ) {
		return;
	}
	delete_option( Ecapla_CertVerify_Database::HEALTH_OPTION );
	delete_option( Ecapla_CertVerify_Permissions::HEALTH_OPTION );
	delete_option( \Certloom\Infrastructure\Database\SchemaManager::HEALTH_OPTION );
	if ( ! wp_next_scheduled( 'ecapla_certverify_continue_migrations' ) ) {
		wp_schedule_single_event( time() + 5, 'ecapla_certverify_continue_migrations' );
	}
}

/**
 * Activates the plugin for one site or every site during network activation.
 *
 * @param bool $network_wide Whether the plugin is being network activated.
 */
function ecapla_certverify_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$errors = ecapla_certverify_for_each_site( 'ecapla_certverify_activate_site' );
		if ( ! empty( $errors ) ) {
			if ( ! ecapla_certverify_store_provisioning_failures( $errors ) ) {
				$errors[0] = new WP_Error( 'ecapla_certverify_network_failure_store_failed', __( 'Certloom could not save the multisite provisioning failure report.', 'certloom' ) );
			}
			ecapla_certverify_fail_activation( $errors );
		}
		if ( ! delete_site_option( 'certverify_provisioning_failures' ) && false !== get_site_option( 'certverify_provisioning_failures', false ) ) {
			ecapla_certverify_fail_activation( array( 0 => new WP_Error( 'ecapla_certverify_network_failure_clear_failed', __( 'Certloom completed network setup but could not clear an old provisioning failure report.', 'certloom' ) ) ) );
		}
		return true;
	}

	$result = ecapla_certverify_activate_site();
	if ( is_wp_error( $result ) ) {
		ecapla_certverify_fail_activation( array( get_current_blog_id() => $result ) );
	}
	return true;
}

/**
 * Creates the table, roles, and scheduled-task state for the current site.
 *
 * @return true|WP_Error
 */
function ecapla_certverify_activate_site() {
	$recovery = Ecapla_CertVerify_Backup::recover_interrupted_restore();
	if ( is_wp_error( $recovery ) ) {
		return $recovery;
	}
	$database = Ecapla_CertVerify_Database::activate();
	if ( is_wp_error( $database ) ) {
		return $database;
	}

	$domain = \Certloom\Plugin::activate();
	if ( is_wp_error( $domain ) ) {
		return $domain;
	}

	Ecapla_CertVerify_Public_Router::activate();

	if ( ! Ecapla_CertVerify_Permissions::activate() ) {
		return new WP_Error( 'ecapla_certverify_permissions_setup_failed', __( 'Certloom could not save its required administrator access.', 'certloom' ) );
	}

	$cron = Ecapla_CertVerify_Cron::activate();
	if ( is_wp_error( $cron ) ) {
		return $cron;
	}

	return true;
}

/**
 * Stops activation with a concise, non-sensitive setup error.
 *
 * @param array<int,WP_Error> $errors Site IDs mapped to provisioning errors.
 */
function ecapla_certverify_fail_activation( $errors ) {
	$items = array();
	foreach ( $errors as $site_id => $error ) {
		if ( ! is_wp_error( $error ) ) {
			continue;
		}
		$items[] = sprintf(
			/* translators: 1: site ID, 2: setup error. */
			__( 'Site %1$d: %2$s', 'certloom' ),
			absint( $site_id ),
			$error->get_error_message()
		);
	}
	$message = __( 'Certloom activation stopped because required setup could not be completed.', 'certloom' );
	if ( ! empty( $items ) ) {
		$message .= '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
	}
	wp_die( wp_kses_post( $message ), esc_html__( 'Certloom activation failed', 'certloom' ), array( 'back_link' => true ) );
}

/**
 * Clears scheduled tasks for one site or every site during network deactivation.
 *
 * @param bool $network_wide Whether the plugin is being network deactivated.
 */
function ecapla_certverify_deactivate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		ecapla_certverify_for_each_site( 'ecapla_certverify_deactivate_site' );
		return;
	}
	ecapla_certverify_deactivate_site();
}

/** Clears all legacy and new-domain scheduled work for the current site. */
function ecapla_certverify_deactivate_site() {
	Ecapla_CertVerify_Cron::deactivate();
	\Certloom\Plugin::deactivate();
	return true;
}

/**
 * Runs a callback for each site without loading every multisite ID at once.
 *
 * @param callable $callback Callback to run after switching to each site.
 */
function ecapla_certverify_for_each_site( $callback ) {
	$number = 100;
	$offset = 0;
	$errors = array();
	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $number,
				'offset' => $offset,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$result = call_user_func( $callback );
			restore_current_blog();
			if ( is_wp_error( $result ) ) {
				$errors[ absint( $site_id ) ] = $result;
			}
		}
		$offset += count( $site_ids );
	} while ( count( $site_ids ) === $number );
	return $errors;
}

/**
 * Provisions a newly created multisite site when this plugin is network active.
 *
 * @param WP_Site $new_site New site object.
 * @param array   $args     Site initialization arguments.
 */
function ecapla_certverify_initialize_site( $new_site, $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! is_multisite() || ! $new_site || ! isset( $new_site->blog_id ) ) {
		return;
	}
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	if ( ! isset( $network_plugins[ plugin_basename( __FILE__ ) ] ) ) {
		return;
	}
	$blog_id = absint( $new_site->blog_id );
	switch_to_blog( $blog_id );
	$result = ecapla_certverify_activate_site();
	restore_current_blog();
	ecapla_certverify_update_provisioning_failure( $blog_id, $result );
}

/**
 * Stores network-site provisioning failures until the affected site succeeds.
 *
 * @param array<int,WP_Error> $errors Site IDs mapped to errors.
 */
function ecapla_certverify_store_provisioning_failures( $errors ) {
	$stored = (array) get_site_option( 'certverify_provisioning_failures', array() );
	foreach ( $errors as $site_id => $error ) {
		if ( is_wp_error( $error ) ) {
			$stored[ absint( $site_id ) ] = array( 'message' => sanitize_text_field( $error->get_error_message() ), 'time' => time() );
		}
	}
	$saved = update_site_option( 'certverify_provisioning_failures', $stored );
	return $saved || $stored === (array) get_site_option( 'certverify_provisioning_failures', array() );
}

/**
 * Adds or clears one site's provisioning failure record.
 *
 * @param int           $blog_id Site ID.
 * @param true|WP_Error $result  Provisioning result.
 * @return bool
 */
function ecapla_certverify_update_provisioning_failure( $blog_id, $result ) {
	$stored = (array) get_site_option( 'certverify_provisioning_failures', array() );
	if ( is_wp_error( $result ) ) {
		$stored[ absint( $blog_id ) ] = array( 'message' => sanitize_text_field( $result->get_error_message() ), 'time' => time() );
	} else {
		unset( $stored[ absint( $blog_id ) ] );
	}
	if ( empty( $stored ) ) {
		$deleted = delete_site_option( 'certverify_provisioning_failures' );
		return $deleted || false === get_site_option( 'certverify_provisioning_failures', false );
	}
	$saved = update_site_option( 'certverify_provisioning_failures', $stored );
	return $saved || $stored === (array) get_site_option( 'certverify_provisioning_failures', array() );
}

/**
 * Shows network administrators which sites still need Certloom provisioning.
 */
function ecapla_certverify_multisite_provisioning_notice() {
	if ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) ) {
		return;
	}
	$failures = (array) get_site_option( 'certverify_provisioning_failures', array() );
	if ( empty( $failures ) ) {
		return;
	}
	$site_ids = array_slice( array_map( 'absint', array_keys( $failures ) ), 0, 20 );
	?>
	<div class="notice notice-error"><p>
		<?php
		printf(
			/* translators: %s: comma-separated multisite site IDs. */
			esc_html__( 'Certloom could not finish provisioning on site IDs: %s. Visit each affected site administration area to retry database and access setup.', 'certloom' ),
			esc_html( implode( ', ', $site_ids ) )
		);
		?>
	</p></div>
	<?php
}

/**
 * Boots Certloom after plugins have loaded.
 */
function ecapla_certverify_init() {
	$restore_recovery   = Ecapla_CertVerify_Backup::recover_interrupted_restore();
	$database_upgrade   = is_wp_error( $restore_recovery ) ? true : Ecapla_CertVerify_Database::maybe_upgrade();
	$permission_upgrade = Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
	$GLOBALS['ecapla_certverify_verification_unavailable'] = is_wp_error( $restore_recovery ) || is_wp_error( $database_upgrade );
	$cron_upgrade       = Ecapla_CertVerify_Cron::init();
	$field_upgrade      = Ecapla_CertVerify_Fields::maybe_repair_schema();
	\Certloom\Plugin::boot();
	$provisioning_error = null;
	if ( is_wp_error( $restore_recovery ) ) {
		$provisioning_error = $restore_recovery;
		ecapla_certverify_add_runtime_error( $restore_recovery->get_error_message() );
	} elseif ( is_wp_error( $database_upgrade ) ) {
		$provisioning_error = $database_upgrade;
		ecapla_certverify_add_runtime_error( $database_upgrade->get_error_message() );
	} elseif ( ! $permission_upgrade ) {
		$provisioning_error = new WP_Error( 'ecapla_certverify_permissions_setup_failed', __( 'Certloom could not persist its required administrator access. The migration will be retried automatically.', 'certloom' ) );
		ecapla_certverify_add_runtime_error( $provisioning_error->get_error_message() );
	} elseif ( is_wp_error( $cron_upgrade ) ) {
		$provisioning_error = $cron_upgrade;
		ecapla_certverify_add_runtime_error( $cron_upgrade->get_error_message() );
	} elseif ( is_wp_error( $field_upgrade ) ) {
		$provisioning_error = $field_upgrade;
		ecapla_certverify_add_runtime_error( $field_upgrade->get_error_message() );
	}
	if ( is_multisite() ) {
		$maintenance_context = is_admin()
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		$existing_failures = (array) get_site_option( 'certverify_provisioning_failures', array() );
		$current_blog_id   = get_current_blog_id();
		if ( $maintenance_context && ( $provisioning_error || isset( $existing_failures[ $current_blog_id ] ) )
			&& ! ecapla_certverify_update_provisioning_failure( $current_blog_id, $provisioning_error ? $provisioning_error : true ) ) {
			ecapla_certverify_add_runtime_error( __( 'Certloom could not persist the multisite provisioning status.', 'certloom' ) );
		}
	}

	Ecapla_CertVerify_Public_Router::init();
	Ecapla_CertVerify_Shortcode::init();
	Ecapla_CertVerify_Block::init();
	Ecapla_CertVerify_Privacy::init();
	add_action( 'rest_api_init', array( 'Ecapla_CertVerify_REST', 'register_routes' ) );

	if ( is_admin() ) {
		require_once ECAPLA_CERTVERIFY_PATH . 'admin/class-ecapla-certverify-list-table.php';
		require_once ECAPLA_CERTVERIFY_PATH . 'admin/class-ecapla-certverify-admin.php';
		new Ecapla_CertVerify_Admin();
	}

	if ( did_action( 'elementor/loaded' ) ) {
		ecapla_certverify_elementor_loaded();
	}
}

/**
 * Whether public verification can safely read the active certificate registry.
 *
 * Interrupted restore recovery and database setup failures fail closed for the
 * current request instead of serving results from an uncertain registry state.
 *
 * @return bool
 */
function ecapla_certverify_verification_is_available() {
	return empty( $GLOBALS['ecapla_certverify_verification_unavailable'] );
}

/**
 * Queues a generic runtime setup error for administrators.
 *
 * @param string $message Error message.
 */
function ecapla_certverify_add_runtime_error( $message ) {
	if ( ! isset( $GLOBALS['ecapla_certverify_runtime_errors'] ) || ! is_array( $GLOBALS['ecapla_certverify_runtime_errors'] ) ) {
		$GLOBALS['ecapla_certverify_runtime_errors'] = array();
	}
	$GLOBALS['ecapla_certverify_runtime_errors'][] = sanitize_text_field( $message );
	add_action( 'admin_notices', 'ecapla_certverify_runtime_error_notice' );
}

/**
 * Displays setup failures without exposing SQL or infrastructure details.
 */
function ecapla_certverify_runtime_error_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $GLOBALS['ecapla_certverify_runtime_errors'] ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$screen_id = $screen && isset( $screen->id ) ? (string) $screen->id : '';
	if ( false === strpos( $screen_id, 'certloom' ) && ! in_array( $screen_id, array( 'plugins', 'plugins-network' ), true ) ) {
		return;
	}
	$messages = array_unique( array_filter( array_map( 'sanitize_text_field', $GLOBALS['ecapla_certverify_runtime_errors'] ) ) );
	foreach ( $messages as $message ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
}

/**
 * Initializes the optional Elementor integration only when a supported
 * Elementor installation is available. Certloom remains fully usable via
 * shortcode and REST when Elementor is absent.
 */
function ecapla_certverify_elementor_loaded() {
	static $initialized = false;

	if ( $initialized ) {
		return;
	}
	$initialized = true;

	if ( ! defined( 'ELEMENTOR_VERSION' ) || version_compare( ELEMENTOR_VERSION, ECAPLA_CERTVERIFY_MINIMUM_ELEMENTOR_VERSION, '<' ) ) {
		return;
	}

	add_action( 'elementor/widgets/register', 'ecapla_certverify_register_elementor_widgets' );
}

/**
 * Registers Certloom widgets with Elementor.
 *
 * @param object $widgets_manager Elementor widgets manager.
 */
function ecapla_certverify_register_elementor_widgets( $widgets_manager ) {
	if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
		return;
	}

	require_once ECAPLA_CERTVERIFY_PATH . 'elementor/class-ecapla-certverify-elementor-widget.php';
	require_once ECAPLA_CERTVERIFY_PATH . 'elementor/class-ecapla-certverify-template-widgets.php';
	require_once ECAPLA_CERTVERIFY_PATH . 'elementor/class-ecapla-certverify-credential-widget.php';

	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Widget() );
	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Meridian_Widget() );
	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Halo_Widget() );
	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Axis_Widget() );
	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Nova_Widget() );
	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Forge_Widget() );
	$widgets_manager->register( new Ecapla_CertVerify_Elementor_Credential_Widget() );
}

/**
 * Adds suggested privacy-policy wording to WordPress's privacy guide.
 */
function ecapla_certverify_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Certloom publishes only the certificate fields that a site administrator marks as visible. Those fields may include names or other personal information, so administrators should publish only information they are authorised to disclose.', 'certloom' ) . '</p>';
	$content .= '<p>' . esc_html__( 'Depending on the Certloom features enabled by the site, local WordPress storage may also contain recipient identity/contact data and administrator-managed issuer or signer contact details. These records remain on the site unless the administrator exports, changes, or removes them according to the organization’s retention obligations.', 'certloom' ) . '</p>';
	$content .= '<p>' . esc_html__( 'Certificate verification is performed on this WordPress site. Certloom does not send certificate records to Ecapla or another external service. Verification requests may still appear in normal web-server, security, analytics, browser-history, and cache logs. Direct verification links can include the certificate number in the URL.', 'certloom' ) . '</p>';
	$content .= '<p>' . esc_html__( 'Site administrators determine how long certificate records are retained and can configure whether Certloom data is removed when the plugin is uninstalled.', 'certloom' ) . '</p>';

	wp_add_privacy_policy_content( 'Certloom', wp_kses_post( $content ) );
}
