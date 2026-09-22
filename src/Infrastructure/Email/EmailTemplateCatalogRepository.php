<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Email\EmailTemplateService;
use Certloom\Infrastructure\Media\PortableDesignAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress-local catalog of reusable credential email designs.
 *
 * The historical class name remains for binary/source compatibility with the
 * delivery subsystem. Product UI calls these Email designs. Existing v1
 * templates are upgraded lazily in memory to purpose=credential_issued.
 */
final class EmailTemplateCatalogRepository {
	const OPTION         = 'certloom_email_template_catalog_v1';
	const SCHEMA_VERSION = 3;
	const DEFAULT_ID     = 'default';
	const MAX_TEMPLATES  = 100;

	/** @var EmailTemplateService */
	private $service;
	/** @var EmailDesignCompiler */
	private $compiler;

	public function __construct( ?EmailTemplateService $service = null, ?EmailDesignCompiler $compiler = null ) {
		$this->service  = $service ?: new EmailTemplateService();
		$this->compiler = $compiler ?: new EmailDesignCompiler();
	}

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		$items  = array();
		if ( is_array( $stored ) && is_array( $stored['templates'] ?? null ) ) {
			foreach ( array_slice( $stored['templates'], 0, self::MAX_TEMPLATES ) as $template ) {
				if ( ! is_array( $template ) ) { continue; }
				$normalized = $this->normalize( $template, false );
				if ( ! is_wp_error( $normalized ) ) { $items[] = $normalized; }
			}
		}

		$has_default = false;
		foreach ( $items as $item ) {
			if ( self::DEFAULT_ID === (string) $item['id'] ) { $has_default = true; break; }
		}
		if ( ! $has_default ) {
			$legacy = get_option( EmailTemplateSettingsRepository::OPTION, array() );
			$legacy = is_array( $legacy ) ? $legacy : array();
			$default = $this->normalize(
				array_merge(
					$legacy,
					array(
						'id'      => self::DEFAULT_ID,
						'name'    => __( 'Default credential email', 'certloom' ),
						'purpose' => EmailDesignPurposes::ISSUED,
						'active'  => 1,
					)
				),
				false
			);
			if ( ! is_wp_error( $default ) ) { array_unshift( $items, $default ); }
		}
		return array_values( $items );
	}

	/** @return array<string,mixed>|null */
	public function get( $template_id ) {
		$template_id = sanitize_key( (string) $template_id );
		foreach ( $this->all() as $template ) {
			if ( $template_id === (string) $template['id'] ) { return $template; }
		}
		return null;
	}

	/** @return array<int,array<string,mixed>> */
	public function for_purpose( $purpose, $active_only = false ) {
		if ( ! EmailDesignPurposes::is_valid( $purpose ) ) { return array(); }
		$purpose = sanitize_key( (string) $purpose );
		return array_values( array_filter( $this->all(), static function( array $template ) use ( $purpose, $active_only ) {
			if ( $purpose !== (string) ( $template['purpose'] ?? '' ) ) { return false; }
			return ! $active_only || ! empty( $template['active'] );
		} ) );
	}

	/** @return string|\WP_Error */
	public function save( array $template ) {
		$normalized = $this->normalize( $template, true );
		if ( is_wp_error( $normalized ) ) { return $normalized; }

		$items = $this->all();
		$found = false;
		foreach ( $items as $index => $existing ) {
			if ( (string) $existing['id'] === (string) $normalized['id'] ) {
				$normalized['source'] = (string) ( $existing['source'] ?? 'customer' );
				$normalized['factory_version'] = absint( $existing['factory_version'] ?? 0 );
				// An inherited design must not silently change purpose or become inactive.
				if ( ( new EmailDesignAssignmentRepository() )->design_is_assigned( (string) $existing['id'] ) ) {
					if ( empty( $normalized['active'] ) ) {
						return new \WP_Error( 'certloom_email_design_assigned_pause_blocked', __( 'This email design is used by one or more Design Rules. Move or inherit those rules before unpublishing it.', 'certloom' ) );
					}
					if ( (string) $existing['purpose'] !== (string) $normalized['purpose'] ) {
						return new \WP_Error( 'certloom_email_design_assigned_purpose_blocked', __( 'This email design is used by one or more Design Rules. Move or inherit those rules before changing its message purpose.', 'certloom' ) );
					}
				}
				$items[ $index ] = $normalized;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( count( $items ) >= self::MAX_TEMPLATES ) { return new \WP_Error( 'certloom_email_template_catalog_limit', __( 'Certloom supports up to 100 reusable email designs per site.', 'certloom' ) ); }
			$items[] = $normalized;
		}
		$result = $this->persist( $items );
		return is_wp_error( $result ) ? $result : (string) $normalized['id'];
	}


	/**
	 * Keep the product-owned starter library present and canonical. Publishing is
	 * user state, so repairs/upgrades preserve each built-in design's current
	 * Published/Unpublished choice while restoring the product-owned content.
	 *
	 * @return true|\WP_Error
	 */
	public function ensure_factory_presets() {
		$items = $this->all();
		$by_id = array();
		foreach ( $items as $index => $item ) {
			$by_id[ (string) ( $item['id'] ?? '' ) ] = $index;
		}

		$changed = false;
		foreach ( ( new EmailDesignPresetCatalog() )->all() as $preset ) {
			$id = (string) ( $preset['id'] ?? '' );
			if ( '' === $id ) { continue; }

			if ( ! isset( $by_id[ $id ] ) ) {
				if ( count( $items ) >= self::MAX_TEMPLATES ) {
					return new \WP_Error( 'certloom_email_template_catalog_limit', __( 'Certloom could not add its built-in email designs because the site has reached the 100-design catalog limit.', 'certloom' ) );
				}
				$normalized = $this->normalize( $preset, false );
				if ( is_wp_error( $normalized ) ) { return $normalized; }
				$items[] = $normalized;
				$by_id[ $id ] = count( $items ) - 1;
				$changed = true;
				continue;
			}

			$index = $by_id[ $id ];
			$existing = $items[ $index ];
			if ( 'factory' !== (string) ( $existing['source'] ?? '' ) ) {
				return new \WP_Error( 'certloom_email_factory_identity_conflict', __( 'A custom email design is using an identity reserved for Certloom’s built-in library. Rename or re-import that custom design before repairing the library.', 'certloom' ) );
			}

			// Built-in content is product-owned; status is site-owned. Re-normalizing
			// every time also repairs a partially written older factory record without
			// changing whether the administrator has published it.
			$preset['active'] = ! empty( $existing['active'] ) ? 1 : 0;
			$preset['updated_at'] = (string) ( $existing['updated_at'] ?? '' );
			$normalized = $this->normalize( $preset, false );
			if ( is_wp_error( $normalized ) ) { return $normalized; }
			if ( $normalized !== $existing ) {
				$items[ $index ] = $normalized;
				$changed = true;
			}
		}

		return $changed ? $this->persist( $items ) : true;
	}

	/** @return string|\WP_Error */
	public function duplicate( $template_id ) {
		$design = $this->get( $template_id );
		if ( ! is_array( $design ) ) { return new \WP_Error( 'certloom_email_design_missing', __( 'The email design no longer exists.', 'certloom' ) ); }
		unset( $design['id'], $design['updated_at'], $design['html_body'], $design['schema'], $design['schema_version'], $design['factory_version'] );
		$design['name'] = sprintf( /* translators: %s: design name. */ __( '%s copy', 'certloom' ), (string) $design['name'] );
		$design['source'] = 'customer';
		$design['active'] = 0;
		return $this->save( $design );
	}

	/** @return true|\WP_Error */
	public function set_published( $template_id, $published ) {
		$design = $this->get( $template_id );
		if ( ! is_array( $design ) ) { return new \WP_Error( 'certloom_email_design_missing', __( 'The email design no longer exists.', 'certloom' ) ); }
		$design['active'] = $published ? 1 : 0;
		$result = $this->save( $design );
		return is_wp_error( $result ) ? $result : true;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function export_package( $template_id, $plugin_version = '' ) {
		$design = $this->get( $template_id );
		if ( ! is_array( $design ) ) { return new \WP_Error( 'certloom_email_design_export_missing', __( 'The email design cannot be exported because it no longer exists.', 'certloom' ) ); }
		return ( new EmailDesignPackage( $this->compiler ) )->build( $design, $plugin_version );
	}

	/** Imported designs are intentionally unpublished until reviewed. @return string|\WP_Error */
	public function import_package( $json ) {
		$design = ( new EmailDesignPackage( $this->compiler ) )->decode( $json );
		if ( is_wp_error( $design ) ) { return $design; }

		$media = new PortableDesignAssets();
		$installed = $media->install_email_assets(
			(array) ( $design['blocks'] ?? array() ),
			(array) ( $design['assets'] ?? array() ),
			(array) ( $design['theme'] ?? array() )
		);
		if ( is_wp_error( $installed ) ) { return $installed; }

		$design['blocks'] = $installed['blocks'];
		if ( isset( $installed['theme'] ) && is_array( $installed['theme'] ) ) { $design['theme'] = $installed['theme']; }
		unset( $design['assets'] );

		$saved = $this->save( $design );
		if ( is_wp_error( $saved ) ) {
			$media->cleanup( $installed['created_ids'] );
		}
		return $saved;
	}

	/** @return true|\WP_Error */
	public function delete( $template_id ) {
		$template_id = sanitize_key( (string) $template_id );
		if ( self::DEFAULT_ID === $template_id ) { return new \WP_Error( 'certloom_email_default_template_required', __( 'The default credential email cannot be deleted. You can edit it instead.', 'certloom' ) ); }
		if ( ( new EmailDesignAssignmentRepository() )->design_is_assigned( $template_id ) ) { return new \WP_Error( 'certloom_email_design_in_use', __( 'This email design is used by one or more Design Rules. Move or inherit those rules before deleting it.', 'certloom' ) ); }
		foreach ( ( new EmailAutomationRuleRepository() )->all() as $rule ) {
			if ( $template_id === (string) ( $rule['legacy_template_id'] ?? '' ) ) { return new \WP_Error( 'certloom_email_design_legacy_rule_in_use', __( 'A legacy Automation still references this email design. Re-save or remove that Automation before deleting the design.', 'certloom' ) ); }
		}
		$items = array_values( array_filter( $this->all(), static function( array $template ) use ( $template_id ) { return $template_id !== (string) $template['id']; } ) );
		return $this->persist( $items );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize( array $template, $generate_id ) {
		$id = sanitize_key( (string) ( $template['id'] ?? '' ) );
		if ( '' === $id && $generate_id ) { $id = sanitize_key( 'email-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) ) ); }
		if ( '' === $id ) { return new \WP_Error( 'certloom_email_template_id_invalid', __( 'The email design identity is invalid.', 'certloom' ) ); }
		$name = trim( sanitize_text_field( (string) ( $template['name'] ?? '' ) ) );
		if ( '' === $name || $this->text_length( $name ) > 120 ) { return new \WP_Error( 'certloom_email_template_name_invalid', __( 'Give the email design a name between 1 and 120 characters.', 'certloom' ) ); }

		$has_purpose = array_key_exists( 'purpose', $template ) && '' !== trim( (string) $template['purpose'] );
		$raw_purpose = sanitize_key( (string) ( $template['purpose'] ?? '' ) );
		if ( ( $generate_id || $has_purpose ) && ! EmailDesignPurposes::is_valid( $raw_purpose ) ) {
			return new \WP_Error( 'certloom_email_design_purpose_invalid', $generate_id ? __( 'Choose a valid message purpose for this email design.', 'certloom' ) : __( 'A stored email design has an invalid message purpose and has been excluded until it is repaired.', 'certloom' ) );
		}
		// Missing purpose is accepted only for pre-purpose legacy records. A modern
		// record with an explicit invalid purpose must never become issued silently.
		$purpose = $has_purpose ? $raw_purpose : EmailDesignPurposes::ISSUED;
		$preheader = trim( sanitize_text_field( (string) ( $template['preheader'] ?? '' ) ) );
		if ( $this->text_length( $preheader ) > 180 ) { return new \WP_Error( 'certloom_email_preheader_invalid', __( 'Keep the email preheader to 180 characters or fewer.', 'certloom' ) ); }

		$blocks = $template['blocks'] ?? null;
		if ( is_string( $blocks ) && '' !== trim( $blocks ) ) {
			$decoded = json_decode( $blocks, true );
			$blocks  = is_array( $decoded ) ? $decoded : null;
		}
		$theme = $template['theme'] ?? array();
		$attachments = $template['attachments'] ?? array();
		if ( is_string( $attachments ) && '' !== trim( $attachments ) ) {
			$decoded = json_decode( $attachments, true );
			$attachments = is_array( $decoded ) ? $decoded : array();
		}
		$attachments = array_values( array_slice( array_unique( array_filter( array_map( 'absint', is_array( $attachments ) ? $attachments : array() ) ) ), 0, EmailTemplateService::MAX_STATIC_ATTACHMENTS ) );
		if ( is_string( $theme ) && '' !== trim( $theme ) ) {
			$decoded = json_decode( $theme, true );
			$theme   = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_array( $blocks ) ) {
			$compiled = $this->compiler->compile( $blocks, $theme );
			$template['html_body'] = $compiled['html_body'];
			if ( '' === trim( (string) ( $template['text_body'] ?? '' ) ) ) { $template['text_body'] = $compiled['text_body']; }
			$blocks = $compiled['blocks'];
			$theme  = $compiled['theme'];
		} else {
			// Legacy/custom HTML designs remain editable and become a single safe text block
			// the first time they are opened in the visual designer.
			$theme  = $this->compiler->normalize_theme( $theme );
			$legacy_html = wp_kses_post( (string) ( $template['html_body'] ?? EmailTemplateService::defaults()['html_body'] ) );
			$blocks = array( array( 'type' => 'text', 'html' => $legacy_html ) );
		}

		$template['preheader'] = $preheader;
		$template['attachments'] = $attachments;
		$content = $this->service->normalize_template( $template );
		if ( is_wp_error( $content ) ) { return $content; }
		$source = sanitize_key( (string) ( $template['source'] ?? ( self::DEFAULT_ID === $id ? 'legacy' : 'customer' ) ) );
		if ( ! in_array( $source, array( 'factory', 'customer', 'import', 'legacy' ), true ) ) { $source = 'customer'; }

		return array(
			'id'             => $id,
			'name'           => $name,
			'purpose'        => $purpose,
			'preheader'      => (string) ( $content['preheader'] ?? $preheader ),
			'active'         => array_key_exists( 'active', $template ) ? ( empty( $template['active'] ) ? 0 : 1 ) : 1,
			'schema'         => (string) $content['schema'],
			'schema_version' => absint( $content['schema_version'] ),
			'subject'        => (string) $content['subject'],
			'html_body'      => (string) $content['html_body'],
			'text_body'      => (string) $content['text_body'],
			'blocks'         => $blocks,
			'theme'          => $theme,
			'attachments'    => $attachments,
			'updated_at'     => $this->valid_timestamp( $template['updated_at'] ?? '' ) ? (string) $template['updated_at'] : current_time( 'mysql', true ),
			'source'         => $source,
			'factory_version'=> 'factory' === $source ? max( 1, absint( $template['factory_version'] ?? 1 ) ) : 0,
		);
	}

	/** @return true|\WP_Error */
	private function persist( array $items ) {
		$payload = array( 'schema_version' => self::SCHEMA_VERSION, 'templates' => array_values( $items ) );
		$saved = update_option( self::OPTION, $payload, false );
		return $saved || $payload === get_option( self::OPTION, array() ) ? true : new \WP_Error( 'certloom_email_template_catalog_save_failed', __( 'Certloom could not save the email design catalog.', 'certloom' ) );
	}

	private function valid_timestamp( $value ) { return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $value ); }
	private function text_length( $value ) { return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value ); }
}
