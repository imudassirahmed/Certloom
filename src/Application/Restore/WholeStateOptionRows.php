<?php
namespace Certloom\Application\Restore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Closed current storage semantics; normalization cannot silently repair imported rows. */
final class WholeStateOptionRows {
	/** Unlike UI readers, recovery cannot drop a row, generate defaults or change its types. */
	public static function validate_list( $value, $maximum, callable $normalize, array $identity_columns, $date_column = 'updated_at' ) {
		if ( ! is_array( $value ) || count( $value ) > $maximum || array_values( $value ) !== $value ) { return self::invalid(); }
		$seen = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ( null !== $date_column && ! self::date( $row[$date_column] ?? null, 'Y-m-d H:i:s' ) ) ) { return self::invalid(); }
			$normalized = self::guarded( static function () use ( $normalize, $row ) { return $normalize( $row ); } );
			if ( ! is_array( $normalized ) || ! self::same( $row, $normalized ) ) { return self::invalid(); }
			$identity = array(); foreach ( $identity_columns as $column ) { if ( ! isset( $row[$column] ) || ! is_scalar( $row[$column] ) ) { return self::invalid(); } $identity[$column] = $row[$column]; }
			if ( $identity_columns ) { $key = serialize( $identity ); if ( isset( $seen[$key] ) ) { return self::invalid(); } $seen[$key] = true; }
		}
		return $value;
	}
	public static function validate( $value, $version, $container, $maximum, callable $normalize, array $identity_columns, $index_column = null ) {
		if ( ! is_array( $value ) || count( $value ) !== 2 || array_diff( array_keys( $value ), array( 'schema_version', $container ) ) || $version !== ( $value['schema_version'] ?? null ) || ! is_array( $value[$container] ?? null ) || count( $value[$container] ) > $maximum || ( null === $index_column && array_values( $value[$container] ) !== $value[$container] ) ) { return self::invalid(); }
		$seen = array();
		foreach ( $value[$container] as $index => $row ) {
			if ( ! is_array( $row ) || ! self::date( $row['updated_at'] ?? null, 'Y-m-d H:i:s' ) ) { return self::invalid(); }
			// Native integration stores key their rows by their source identity.
			// Verify that exact index instead of silently rekeying imported data.
			if ( null !== $index_column && ( ! isset( $row[$index_column] ) || ! is_scalar( $row[$index_column] ) || (string) $index !== (string) $row[$index_column] ) ) { return self::invalid(); }
			foreach ( $row as $cell ) { if ( null !== $cell && ( ! is_scalar( $cell ) || ( is_string( $cell ) && strlen( $cell ) > 16384 ) || ( is_float( $cell ) && ! is_finite( $cell ) ) ) ) { return self::invalid(); } }
			if ( isset( $row['expires_at'] ) && ! self::date( $row['expires_at'], 'Y-m-d' ) ) { return self::invalid(); }
			try { $normalized = $normalize( $row ); } catch ( \Throwable $error ) { return self::invalid(); }
			if ( is_wp_error( $normalized ) || ! is_array( $normalized ) || ! self::same( $row, $normalized ) ) { return self::invalid(); }
			$identity = array(); foreach ( $identity_columns as $column ) { if ( ! isset( $row[$column] ) || ! is_scalar( $row[$column] ) ) { return self::invalid(); } $identity[$column] = $row[$column]; }
			$key = serialize( $identity ); if ( isset( $seen[$key] ) ) { return self::invalid(); } $seen[$key] = true;
		}
		return $value;
	}
	public static function same( $a, $b ) {
		if ( ! is_array( $a ) || ! is_array( $b ) ) { return $a === $b; }
		if ( count( $a ) !== count( $b ) || array_diff_key( $a, $b ) || array_diff_key( $b, $a ) ) { return false; }
		foreach ( $a as $key => $value ) { if ( ! self::same( $value, $b[$key] ) ) { return false; } }
		return true;
	}
	public static function date( $value, $format ) {
		if ( ! in_array( $format, array( 'Y-m-d H:i:s', 'Y-m-d' ), true ) || ! is_string( $value ) || strlen( $value ) !== ( 'Y-m-d' === $format ? 10 : 19 ) ) { return false; }
		try { $parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value, new \DateTimeZone( 'UTC' ) ); return $parsed && $parsed->format( $format ) === $value; }
		catch ( \Throwable $error ) { return false; }
	}
	public static function iso_date( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) !== 25 ) { return false; }
		try { $date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $value ); return $date && $date->format( 'c' ) === $value; } catch ( \Throwable $error ) { return false; }
	}
	/** Normalizers used by ordinary UI readers may cast malformed nested input. */
	public static function guarded( callable $operation ) {
		set_error_handler( static function () { throw new \RuntimeException( 'Invalid stored recovery semantics.' ); } );
		try { return $operation(); } catch ( \Throwable $error ) { return self::invalid(); } finally { restore_error_handler(); }
	}
	private static function invalid() { return new \WP_Error( 'certloom_whole_option_semantics_invalid', __( 'A saved recovery connection contains invalid, duplicate or unsupported settings. Repair that connection before restoring it.', 'certloom' ) ); }
}
