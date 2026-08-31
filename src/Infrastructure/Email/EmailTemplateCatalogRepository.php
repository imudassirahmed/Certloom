<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Email\EmailTemplateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress-local catalog of reusable credential email templates.
 *
 * The legacy single-template option remains readable through
 * EmailTemplateSettingsRepository, which delegates its default template to
 * this catalog. That preserves existing installations without maintaining two
 * independent email-template authorities.
 */
final class EmailTemplateCatalogRepository {
	const OPTION         = 'certloom_email_template_catalog_v1';
	const SCHEMA_VERSION = 1;
	const DEFAULT_ID     = 'default';
	const MAX_TEMPLATES  = 30;

	/** @var EmailTemplateService */
	private $service;

	public function __construct( ?EmailTemplateService $service = null ) {
		$this->service = $service ?: new EmailTemplateService();
	}

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		$items  = array();
		if ( is_array( $stored ) && self::SCHEMA_VERSION === absint( $stored['schema_version'] ?? 0 ) && is_array( $stored['templates'] ?? null ) ) {
			foreach ( array_slice( $stored['templates'], 0, self::MAX_TEMPLATES ) as $template ) {
				if ( ! is_array( $template ) ) {
					continue;
				}
				$normalized = $this->normalize( $template, false );
				if ( ! is_wp_error( $normalized ) ) {
					$items[] = $normalized;
				}
			}
		}

		$has_default = false;
		foreach ( $items as $item ) {
			if ( self::DEFAULT_ID === (string) $item['id'] ) {
				$has_default = true;
				break;
			}
		}
		if ( ! $has_default ) {
			$legacy = get_option( EmailTemplateSettingsRepository::OPTION, array() );
			$legacy = is_array( $legacy ) ? $legacy : array();
			$default = $this->normalize(
				array_merge(
					$legacy,
					array(
						'id'     => self::DEFAULT_ID,
						'name'   => __( 'Default credential email', 'certloom' ),
						'active' => 1,
					)
				),
				false
			);
			if ( ! is_wp_error( $default ) ) {
				array_unshift( $items, $default );
			}
		}
		return array_values( $items );
	}

	/** @return array<string,mixed>|null */
	public function get( $template_id ) {
		$template_id = sanitize_key( (string) $template_id );
		foreach ( $this->all() as $template ) {
			if ( $template_id === (string) $template['id'] ) {
				return $template;
			}
		}
		return null;
	}

	/** @return string|\WP_Error */
	public function save( array $template ) {
		$normalized = $this->normalize( $template, true );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$items = $this->all();
		$found = false;
		foreach ( $items as $index => $existing ) {
			if ( (string) $existing['id'] === (string) $normalized['id'] ) {
				$items[ $index ] = $normalized;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( count( $items ) >= self::MAX_TEMPLATES ) {
				return new \WP_Error( 'certloom_email_template_catalog_limit', __( 'Certloom supports up to 30 reusable email templates per site.', 'certloom' ) );
			}
			$items[] = $normalized;
		}

		$result = $this->persist( $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (string) $normalized['id'];
	}

	/** @return true|\WP_Error */
	public function delete( $template_id ) {
		$template_id = sanitize_key( (string) $template_id );
		if ( self::DEFAULT_ID === $template_id ) {
			return new \WP_Error( 'certloom_email_default_template_required', __( 'The default credential email cannot be deleted. You can edit or pause it instead.', 'certloom' ) );
		}
		$items = array_values(
			array_filter(
				$this->all(),
				static function ( array $template ) use ( $template_id ) {
					return $template_id !== (string) $template['id'];
				}
			)
		);
		return $this->persist( $items );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize( array $template, $generate_id ) {
		$id = sanitize_key( (string) ( $template['id'] ?? '' ) );
		if ( '' === $id && $generate_id ) {
			$id = sanitize_key( 'email-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) ) );
		}
		if ( '' === $id ) {
			return new \WP_Error( 'certloom_email_template_id_invalid', __( 'The email template identity is invalid.', 'certloom' ) );
		}
		$name = trim( sanitize_text_field( (string) ( $template['name'] ?? '' ) ) );
		if ( '' === $name || strlen( $name ) > 120 ) {
			return new \WP_Error( 'certloom_email_template_name_invalid', __( 'Give the email template a name between 1 and 120 characters.', 'certloom' ) );
		}
		$content = $this->service->normalize_template( $template );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		return array(
			'id'             => $id,
			'name'           => $name,
			'active'         => array_key_exists( 'active', $template ) ? ( empty( $template['active'] ) ? 0 : 1 ) : 1,
			'schema'         => (string) $content['schema'],
			'schema_version' => absint( $content['schema_version'] ),
			'subject'        => (string) $content['subject'],
			'html_body'      => (string) $content['html_body'],
			'text_body'      => (string) $content['text_body'],
			'updated_at'     => $this->valid_timestamp( $template['updated_at'] ?? '' ) ? (string) $template['updated_at'] : current_time( 'mysql', true ),
		);
	}

	/** @return true|\WP_Error */
	private function persist( array $items ) {
		$payload = array(
			'schema_version' => self::SCHEMA_VERSION,
			'templates'      => array_values( $items ),
		);
		$saved = update_option( self::OPTION, $payload, false );
		return $saved || $payload === get_option( self::OPTION, array() )
			? true
			: new \WP_Error( 'certloom_email_template_catalog_save_failed', __( 'Certloom could not save the email template catalog.', 'certloom' ) );
	}

	/** @return bool */
	private function valid_timestamp( $value ) {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $value );
	}
}
