<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized validation and sanitization for certificate records.
 */
class Ecapla_CertVerify_Validator {

	/**
	 * Validates and sanitizes a complete or partial certificate payload.
	 *
	 * @param array $data       Submitted data.
	 * @param bool  $is_partial Whether missing keys should be preserved.
	 * @return array|WP_Error
	 */
	public static function certificate_data( $data, $is_partial = false ) {
		$data  = is_array( $data ) ? $data : array();
		$clean = array();

		if ( array_key_exists( 'cert_number', $data ) ) {
			$cert_number = trim( sanitize_text_field( (string) $data['cert_number'] ) );
			if ( '' === $cert_number ) {
				return new WP_Error( 'ecapla_certverify_missing_number', __( 'Certificate number is required.', 'certloom' ) );
			}
			if ( self::string_length( $cert_number ) > 100 ) {
				return new WP_Error( 'ecapla_certverify_number_too_long', __( 'Certificate number must be 100 characters or fewer.', 'certloom' ) );
			}
			$clean['cert_number'] = $cert_number;
		} elseif ( ! $is_partial ) {
			return new WP_Error( 'ecapla_certverify_missing_number', __( 'Certificate number is required.', 'certloom' ) );
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( ! in_array( $status, array( 'valid', 'suspended', 'withdrawn', 'expired' ), true ) ) {
				return new WP_Error( 'ecapla_certverify_invalid_status', __( 'Choose Valid, Suspended, Withdrawn, or preserve a legacy Expired record for review.', 'certloom' ) );
			}
			$clean['status']        = $status;
			$clean['status_source'] = 'expired' === $status ? 'legacy' : 'manual';
		} elseif ( ! $is_partial ) {
			$clean['status']        = 'valid';
			$clean['status_source'] = 'manual';
		}

		if ( array_key_exists( 'holder_name', $data ) ) {
			$holder = sanitize_text_field( (string) $data['holder_name'] );
			if ( self::string_length( $holder ) > 255 ) {
				return new WP_Error( 'ecapla_certverify_holder_too_long', __( 'Certificate holder must be 255 characters or fewer.', 'certloom' ) );
			}
			$clean['holder_name'] = $holder;
		} elseif ( ! $is_partial ) {
			$clean['holder_name'] = '';
		}

		foreach ( array( 'issue_date', 'expiry_date' ) as $date_key ) {
			if ( array_key_exists( $date_key, $data ) ) {
				$date = self::date( $data[ $date_key ] );
				if ( is_wp_error( $date ) ) {
					return $date;
				}
				$clean[ $date_key ] = $date;
			} elseif ( ! $is_partial ) {
				$clean[ $date_key ] = null;
			}
		}

		if ( array_key_exists( 'issue_date', $clean ) && array_key_exists( 'expiry_date', $clean ) ) {
			$date_range = self::date_range( $clean['issue_date'], $clean['expiry_date'] );
			if ( is_wp_error( $date_range ) ) {
				return $date_range;
			}
		}

		if ( array_key_exists( 'custom_fields', $data ) ) {
			$custom = is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array();
			$map    = Ecapla_CertVerify_Fields::get_field_map();
			$clean['custom_fields'] = array();

			foreach ( $custom as $key => $value ) {
				$key = sanitize_key( $key );
				if ( ! isset( $map[ $key ] ) || ! empty( $map[ $key ]['column'] ) ) {
					continue;
				}
				$sanitized = self::custom_field_value( $value, $map[ $key ] );
				if ( is_wp_error( $sanitized ) ) {
					return $sanitized;
				}
				$clean['custom_fields'][ $key ] = $sanitized;
			}
		} elseif ( ! $is_partial ) {
			$clean['custom_fields'] = array();
		}

		return $clean;
	}

	/**
	 * Validates an ISO date or returns null for an empty value.
	 *
	 * @return string|null|WP_Error
	 */
	public static function date( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return null;
		}

		$date   = DateTime::createFromFormat( '!Y-m-d', $value );
		$errors = DateTime::getLastErrors();
		$valid  = $date && $date->format( 'Y-m-d' ) === $value;
		if ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) {
			$valid = false;
		}

		if ( ! $valid ) {
			return new WP_Error( 'ecapla_certverify_invalid_date', __( 'Dates must use the YYYY-MM-DD format and represent a real calendar date.', 'certloom' ) );
		}

		return $value;
	}


	/**
	 * Confirms that an expiry date does not precede its issue date.
	 *
	 * @return true|WP_Error
	 */
	public static function date_range( $issue_date, $expiry_date ) {
		if ( $issue_date && $expiry_date && $expiry_date < $issue_date ) {
			return new WP_Error( 'ecapla_certverify_invalid_date_range', __( 'Expiry date cannot be earlier than issue date.', 'certloom' ) );
		}
		return true;
	}

	/**
	 * Formats a validated Y-m-d date in the site's timezone without relying on
	 * strtotime(), which can reinterpret exact dates around timezone boundaries.
	 *
	 * @param string $value Stored Y-m-d date.
	 * @return string
	 */
	public static function format_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date     = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ), $date->getTimestamp(), $timezone );
	}

	/**
	 * Sanitizes a custom field using its configured type.
	 *
	 * @return string|WP_Error
	 */
	public static function custom_field_value( $value, $field ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		if ( 'visibility' === $type ) {
			$value = sanitize_key( (string) $value );
			$allowed = class_exists( 'Ecapla_CertVerify_Public_Policy' ) ? array_keys( Ecapla_CertVerify_Public_Policy::visibility_options() ) : array( 'public', 'verification_summary', 'private' );
			if ( ! in_array( $value, $allowed, true ) ) {
				return new WP_Error( 'ecapla_certverify_invalid_profile_visibility', __( 'Choose a valid public credential visibility mode.', 'certloom' ) );
			}
			return $value;
		}

		if ( 'date' === $type ) {
			$date = self::date( $value );
			return is_null( $date ) ? '' : $date;
		}

		if ( 'number' === $type ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			if ( ! is_numeric( $value ) ) {
				return new WP_Error(
					'ecapla_certverify_invalid_number',
					sprintf(
						/* translators: %s: field label. */
						__( '%s must contain a valid number.', 'certloom' ),
						isset( $field['label'] ) ? $field['label'] : __( 'This field', 'certloom' )
					)
				);
			}
			return sanitize_text_field( $value );
		}

		if ( 'textarea' === $type ) {
			$value = sanitize_textarea_field( (string) $value );
			return self::validate_custom_length( $value, 10000, $field );
		}

		$value = sanitize_text_field( (string) $value );
		return self::validate_custom_length( $value, 1000, $field );
	}

	private static function validate_custom_length( $value, $maximum, $field ) {
		if ( self::string_length( $value ) > $maximum ) {
			return new WP_Error(
				'ecapla_certverify_custom_value_too_long',
				sprintf(
					/* translators: 1: field label, 2: maximum character count. */
					__( '%1$s must be %2$d characters or fewer.', 'certloom' ),
					isset( $field['label'] ) ? $field['label'] : __( 'This field', 'certloom' ),
					$maximum
				)
			);
		}
		return $value;
	}

	private static function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
