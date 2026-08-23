<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current-authority certificate numbering policy and atomic sequence allocator.
 *
 * The live legacy registry keeps the authoritative certificate number. This
 * service only allocates a candidate for a NEW manual-admin credential when the
 * site explicitly enables a generated-number strategy. Existing identifiers are
 * never rewritten by a policy change, and generated human numbers never replace
 * the separate normalized-domain public credential ID.
 */
class Ecapla_CertVerify_Numbering {

	const STATE_OPTION    = 'certverify_number_sequence_next';
	const MAX_SEQUENCE    = 999999999999;
	const EXHAUSTED_STATE = 1000000000000;
	const MAX_COLLISIONS  = 1000;
	const MAX_AFFIX_CHARS = 32;
	const RANDOM_DATA_CHARS = 20;
	const RANDOM_ALPHABET   = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'numbering_mode'    => 'manual',
			'numbering_prefix'  => 'CERT-',
			'numbering_suffix'  => '',
			'numbering_start'   => 1,
			'numbering_padding' => 6,
		);
	}

	/** @return array<string,mixed> */
	public static function policy() {
		$all = Ecapla_CertVerify_Settings::get_all();
		$raw = array();
		foreach ( self::defaults() as $key => $default ) {
			$raw[ $key ] = array_key_exists( $key, $all ) ? $all[ $key ] : $default;
		}
		$clean = self::normalize_policy( $raw );
		return is_wp_error( $clean ) ? self::defaults() : $clean;
	}

	/** @return array<string,mixed>|WP_Error */
	public static function normalize_policy( $data ) {
		$data = is_array( $data ) ? $data : array();
		$base = self::defaults();
		$data = wp_parse_args( $data, $base );

		$mode = sanitize_key( (string) $data['numbering_mode'] );
		if ( ! in_array( $mode, array( 'manual', 'sequential', 'random', 'uuid' ), true ) ) {
			return new WP_Error( 'ecapla_certverify_numbering_mode_invalid', __( 'Choose Manual, Sequential, Random, or UUID certificate numbering.', 'certloom' ) );
		}

		$prefix = self::normalize_affix( $data['numbering_prefix'], __( 'Certificate-number prefix', 'certloom' ) );
		if ( is_wp_error( $prefix ) ) {
			return $prefix;
		}
		$suffix = self::normalize_affix( $data['numbering_suffix'], __( 'Certificate-number suffix', 'certloom' ) );
		if ( is_wp_error( $suffix ) ) {
			return $suffix;
		}

		$start = self::positive_integer( $data['numbering_start'], 1, self::MAX_SEQUENCE );
		if ( is_wp_error( $start ) ) {
			return new WP_Error( 'ecapla_certverify_numbering_start_invalid', __( 'Sequence start must be a whole number from 1 to 999999999999.', 'certloom' ) );
		}
		$padding = self::positive_integer( $data['numbering_padding'], 1, 12 );
		if ( is_wp_error( $padding ) ) {
			return new WP_Error( 'ecapla_certverify_numbering_padding_invalid', __( 'Sequence length must be a whole number from 1 to 12 digits.', 'certloom' ) );
		}

		$clean = array(
			'numbering_mode'    => $mode,
			'numbering_prefix'  => $prefix,
			'numbering_suffix'  => $suffix,
			'numbering_start'   => $start,
			'numbering_padding' => $padding,
		);

		if ( 'sequential' === $mode ) {
			$probe = self::format_normalized_sequence( $start, $clean );
		} elseif ( 'random' === $mode ) {
			$probe = self::format_generated_core( '0000-0000-0000-0000-0000', $clean );
		} elseif ( 'uuid' === $mode ) {
			$probe = self::format_generated_core( '00000000-0000-4000-8000-000000000000', $clean );
		} else {
			$probe = '';
		}
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		return $clean;
	}

	/** @return bool */
	public static function is_enabled() {
		return 'manual' !== (string) ( self::policy()['numbering_mode'] ?? 'manual' );
	}

	/** @return bool */
	public static function is_sequential() {
		return 'sequential' === (string) ( self::policy()['numbering_mode'] ?? 'manual' );
	}

	/** @return string|WP_Error */
	public static function format_sequence( $sequence, $policy = null ) {
		$sequence = self::positive_integer( $sequence, 1, self::MAX_SEQUENCE );
		if ( is_wp_error( $sequence ) ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_invalid', __( 'The certificate sequence value is outside Certloom’s supported range.', 'certloom' ) );
		}
		$policy = null === $policy ? self::policy() : self::normalize_policy( $policy );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		return self::format_normalized_sequence( $sequence, $policy );
	}

	/**
	 * Returns the persisted next sequence, never below the configured start.
	 * Missing state is expected on upgrades and means "not allocated yet".
	 *
	 * @return int|WP_Error
	 */
	public static function current_next_sequence() {
		$policy = self::policy();
		$start  = (int) $policy['numbering_start'];
		$stored = get_option( self::STATE_OPTION, null );
		if ( null === $stored || false === $stored || '' === $stored ) {
			return $start;
		}
		if ( is_array( $stored ) || is_object( $stored ) || ! preg_match( '/^[0-9]+$/D', (string) $stored ) ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_state_corrupt', __( 'Stored certificate-number sequence state is invalid. Certloom stopped automatic numbering to prevent duplicate or out-of-order identifiers.', 'certloom' ) );
		}
		$next = (int) $stored;
		if ( self::EXHAUSTED_STATE === $next ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_exhausted', __( 'The configured certificate-number sequence is exhausted.', 'certloom' ) );
		}
		if ( $next < 1 || $next > self::MAX_SEQUENCE ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_state_corrupt', __( 'Stored certificate-number sequence state is outside Certloom’s supported range. Automatic numbering is paused.', 'certloom' ) );
		}
		return max( $start, $next );
	}

	/**
	 * Non-mutating next-number preview. A preview can become stale immediately;
	 * the real allocator still performs atomic reservation and unique insertion.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function preview() {
		$policy = self::policy();
		$mode   = (string) $policy['numbering_mode'];
		if ( 'manual' === $mode ) {
			return new WP_Error( 'ecapla_certverify_numbering_disabled', __( 'Automatic certificate numbering is not enabled.', 'certloom' ) );
		}
		if ( 'sequential' === $mode ) {
			$next = self::current_next_sequence();
			if ( is_wp_error( $next ) ) {
				return $next;
			}
			for ( $offset = 0; $offset < self::MAX_COLLISIONS; $offset++ ) {
				$sequence = $next + $offset;
				if ( $sequence > self::MAX_SEQUENCE ) {
					break;
				}
				$candidate = self::format_sequence( $sequence, $policy );
				if ( is_wp_error( $candidate ) ) {
					return $candidate;
				}
				$exists = Ecapla_CertVerify_Database::certificate_number_exists( $candidate );
				if ( is_wp_error( $exists ) ) {
					return $exists;
				}
				if ( ! $exists ) {
					return array(
						'certificate_number' => $candidate,
						'mode'               => $mode,
						'sequence'           => $sequence,
						'skipped_collisions' => $offset,
					);
				}
			}
			return new WP_Error( 'ecapla_certverify_number_preview_exhausted', __( 'Certloom found too many existing numbers in the next sequence range. Increase the sequence start before enabling automatic numbering.', 'certloom' ) );
		}

		for ( $attempt = 0; $attempt < self::MAX_COLLISIONS; $attempt++ ) {
			$candidate = self::generate_candidate( $mode, $policy );
			if ( is_wp_error( $candidate ) ) {
				return $candidate;
			}
			$exists = Ecapla_CertVerify_Database::certificate_number_exists( $candidate );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			if ( ! $exists ) {
				return array(
					'certificate_number' => $candidate,
					'mode'               => $mode,
					'sample'             => true,
					'skipped_collisions' => $attempt,
				);
			}
		}
		return new WP_Error( 'ecapla_certverify_number_preview_exhausted', __( 'Certloom could not produce a collision-free certificate-number sample. Review the numbering policy before enabling automatic numbering.', 'certloom' ) );
	}

	/**
	 * Creates a new legacy-authority credential using an atomically reserved
	 * sequence value. Unique database constraints remain the final collision gate.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function insert_generated( $data ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'ecapla_certverify_numbering_disabled', __( 'Automatic certificate numbering is not enabled.', 'certloom' ) );
		}
		$clean = Ecapla_CertVerify_Validator::certificate_data_for_generated_number( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$policy = self::policy();
		$mode   = (string) $policy['numbering_mode'];
		for ( $attempt = 0; $attempt < self::MAX_COLLISIONS; $attempt++ ) {
			$sequence = null;
			if ( 'sequential' === $mode ) {
				$sequence = self::allocate_sequence();
				if ( is_wp_error( $sequence ) ) {
					return $sequence;
				}
				$candidate = self::format_sequence( $sequence, $policy );
			} else {
				$candidate = self::generate_candidate( $mode, $policy );
			}
			if ( is_wp_error( $candidate ) ) {
				return $candidate;
			}
			$exists = Ecapla_CertVerify_Database::certificate_number_exists( $candidate );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			if ( $exists ) {
				continue;
			}

			$payload                = $clean;
			$payload['cert_number'] = $candidate;
			$created                = Ecapla_CertVerify_Database::insert( $payload );
			if ( is_wp_error( $created ) ) {
				if ( 'ecapla_certverify_duplicate_number' === $created->get_error_code() ) {
					continue; // A concurrent manual/generated insert won the unique constraint.
				}
				return $created;
			}
			$result = array(
				'id'                 => absint( $created ),
				'certificate_number' => $candidate,
				'mode'               => $mode,
			);
			if ( null !== $sequence ) {
				$result['sequence'] = $sequence;
			}
			return $result;
		}
		return new WP_Error( 'ecapla_certverify_number_collision_exhausted', __( 'Certloom could not create a unique certificate number after 1000 attempts. Review the numbering policy and try again.', 'certloom' ) );
	}

	/**
	 * Atomically reserves exactly one sequence value using the site options table.
	 * LAST_INSERT_ID(expr) is connection-local in MySQL/MariaDB, so concurrent
	 * requests cannot receive the same sequence value.
	 *
	 * @return int|WP_Error
	 */
	public static function allocate_sequence() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$policy = self::policy();
		$start  = (int) $policy['numbering_start'];
		$current = self::current_next_sequence();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( $current > self::MAX_SEQUENCE ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_exhausted', __( 'The configured certificate-number sequence is exhausted.', 'certloom' ) );
		}

		$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		/* First allocation is race-safe because option_name is unique. */
		$added = add_option( self::STATE_OPTION, (string) ( $start + 1 ), '', false );
		if ( $added ) {
			return $start;
		}

		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic cross-request sequence allocation requires one SQL update on the WordPress-owned options row.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = CAST(LAST_INSERT_ID(GREATEST(CAST(option_value AS UNSIGNED), %d)) + 1 AS CHAR) WHERE option_name = %s AND option_value REGEXP %s',
				$wpdb->options,
				$start,
				self::STATE_OPTION,
				'^[0-9]+$'
			)
		);
		if ( 1 !== (int) $updated || '' !== (string) $wpdb->last_error ) {
			wp_cache_delete( self::STATE_OPTION, 'options' );
			return new WP_Error( 'ecapla_certverify_number_sequence_allocate_failed', __( 'Certloom could not atomically reserve the next certificate sequence value. Automatic numbering stopped without creating a credential.', 'certloom' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the connection-local value set by the atomic update above.
		$allocated = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		wp_cache_delete( self::STATE_OPTION, 'options' );
		if ( '' !== (string) $wpdb->last_error || ! is_numeric( $allocated ) ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_allocate_failed', __( 'Certloom reserved numbering state but could not read the allocated sequence value. Automatic numbering is paused for review.', 'certloom' ) );
		}
		$allocated = (int) $allocated;
		if ( $allocated < 1 || $allocated > self::MAX_SEQUENCE ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_exhausted', __( 'The configured certificate-number sequence is exhausted.', 'certloom' ) );
		}
		return $allocated;
	}

	/** @return int|null|WP_Error */
	public static function export_state() {
		$stored = get_option( self::STATE_OPTION, null );
		if ( null === $stored || false === $stored || '' === $stored ) {
			return null;
		}
		if ( is_array( $stored ) || is_object( $stored ) || ! preg_match( '/^[0-9]+$/D', (string) $stored ) ) {
			return new WP_Error( 'ecapla_certverify_number_sequence_state_corrupt', __( 'Stored certificate-number sequence state is invalid, so Certloom cannot create a complete backup safely.', 'certloom' ) );
		}
		$next = (int) $stored;
		return $next >= 1 && $next <= self::EXHAUSTED_STATE
			? $next
			: new WP_Error( 'ecapla_certverify_number_sequence_state_corrupt', __( 'Stored certificate-number sequence state is outside Certloom’s supported range.', 'certloom' ) );
	}

	/** @return int|null|WP_Error */
	public static function validate_backup_state( $state ) {
		if ( null === $state || '' === $state ) {
			return null;
		}
		if ( is_bool( $state ) || is_array( $state ) || is_object( $state ) || ! preg_match( '/^[0-9]+$/D', (string) $state ) ) {
			return new WP_Error( 'ecapla_certverify_backup_numbering_state', __( 'The backup contains invalid certificate-number sequence state.', 'certloom' ) );
		}
		$state = (int) $state;
		return $state >= 1 && $state <= self::EXHAUSTED_STATE
			? $state
			: new WP_Error( 'ecapla_certverify_backup_numbering_state', __( 'The backup certificate-number sequence state is outside Certloom’s supported range.', 'certloom' ) );
	}

	/**
	 * Formats a sequence using an already-normalized policy. Keeping this helper
	 * private prevents policy validation from recursively re-entering itself.
	 *
	 * @param int                 $sequence Validated sequence value.
	 * @param array<string,mixed> $policy Normalized numbering policy.
	 * @return string|WP_Error
	 */
	private static function format_normalized_sequence( $sequence, $policy ) {
		$digits = str_pad( (string) $sequence, (int) $policy['numbering_padding'], '0', STR_PAD_LEFT );
		$number = (string) $policy['numbering_prefix'] . $digits . (string) $policy['numbering_suffix'];
		if ( self::string_length( $number ) > 100 ) {
			return new WP_Error( 'ecapla_certverify_numbering_format_too_long', __( 'The numbering prefix, sequence length, and suffix would create certificate numbers longer than 100 characters.', 'certloom' ) );
		}
		return $number;
	}


	/** @return string|WP_Error */
	private static function generate_candidate( $mode, $policy ) {
		if ( 'random' === $mode ) {
			$core = self::generate_random_core();
		} elseif ( 'uuid' === $mode ) {
			$core = self::generate_uuid_v4();
		} else {
			return new WP_Error( 'ecapla_certverify_numbering_mode_invalid', __( 'The configured certificate-number strategy cannot generate a number.', 'certloom' ) );
		}
		if ( is_wp_error( $core ) ) {
			return $core;
		}
		return self::format_generated_core( $core, $policy );
	}

	/** 100-bit Crockford-style random core, grouped for transcription. */
	private static function generate_random_core() {
		$data = '';
		try {
			$max = strlen( self::RANDOM_ALPHABET ) - 1;
			for ( $i = 0; $i < self::RANDOM_DATA_CHARS; $i++ ) {
				$data .= self::RANDOM_ALPHABET[ random_int( 0, $max ) ];
			}
		} catch ( Exception $exception ) {
			return new WP_Error( 'ecapla_certverify_number_random_failed', __( 'Secure random certificate-number generation is unavailable on this server.', 'certloom' ) );
		}
		return implode( '-', str_split( $data, 4 ) );
	}

	/** RFC-4122 version-4 UUID generated locally with cryptographic randomness. */
	private static function generate_uuid_v4() {
		try {
			$bytes = random_bytes( 16 );
		} catch ( Exception $exception ) {
			return new WP_Error( 'ecapla_certverify_number_uuid_failed', __( 'Secure UUID certificate-number generation is unavailable on this server.', 'certloom' ) );
		}
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/** @return string|WP_Error */
	private static function format_generated_core( $core, $policy ) {
		$number = (string) $policy['numbering_prefix'] . (string) $core . (string) $policy['numbering_suffix'];
		if ( self::string_length( $number ) > 100 ) {
			return new WP_Error( 'ecapla_certverify_numbering_format_too_long', __( 'The numbering prefix, generated identifier, and suffix would create certificate numbers longer than 100 characters.', 'certloom' ) );
		}
		return $number;
	}

	/** @return string|WP_Error */
	private static function normalize_affix( $value, $label ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( self::string_length( $value ) > self::MAX_AFFIX_CHARS ) {
			return new WP_Error(
				'ecapla_certverify_numbering_affix_too_long',
				sprintf( /* translators: 1: prefix/suffix label, 2: maximum characters. */ __( '%1$s must be %2$d characters or fewer.', 'certloom' ), $label, self::MAX_AFFIX_CHARS )
			);
		}
		if ( '' !== $value && 1 !== preg_match( '/^[\p{L}\p{N}._-]+$/u', $value ) ) {
			return new WP_Error(
				'ecapla_certverify_numbering_affix_invalid',
				sprintf( /* translators: %s: prefix/suffix label. */ __( '%s may contain letters, numbers, periods, underscores, and hyphens only.', 'certloom' ), $label )
			);
		}
		return $value;
	}

	/** @return int|WP_Error */
	private static function positive_integer( $value, $minimum, $maximum ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || ! preg_match( '/^[0-9]+$/D', (string) $value ) ) {
			return new WP_Error( 'ecapla_certverify_number_integer_invalid', __( 'Certificate-number sequence settings must use whole numbers.', 'certloom' ) );
		}
		$value = (int) $value;
		return $value >= $minimum && $value <= $maximum ? $value : new WP_Error( 'ecapla_certverify_number_integer_invalid', __( 'Certificate-number sequence setting is outside the supported range.', 'certloom' ) );
	}

	private static function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
