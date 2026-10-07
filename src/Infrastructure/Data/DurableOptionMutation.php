<?php
namespace Certloom\Infrastructure\Data;

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateTemplateRecommendationInventory;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checked mutation of one explicitly inventoried raw option, including deletion. */
final class DurableOptionMutation {
	/** Preserve WordPress scalar/array storage without invoking option filters. */
	public function set( $name, $value ) {
		return $this->change( $name, static function () use ( $value ) { return (string) maybe_serialize( $value ); } );
	}
	public function remove( $name ) { return $this->change( $name, static function () { return null; } ); }
	/** The callback receives exact current storage bytes/null and returns bytes/null or WP_Error. */
	public function change( $name, callable $decide ) {
		if ( ! is_string( $name ) || ( ! in_array( $name, WholeStateOptionInventory::keys(), true ) && false === WholeStateTemplateRecommendationInventory::template_id( $name ) ) ) { return $this->failed(); }
		$result = ( new Transaction() )->run( function () use ( $name, $decide ) {
			global $wpdb;
			$wpdb->last_error = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, $name ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ( null !== $row && ! is_array( $row ) ) ) { return $this->failed(); }
			$before = is_array( $row ) ? $row['option_value'] : null;
			if ( null !== $before && ( ! is_string( $before ) || strlen( $before ) > WholeStateOptionInventory::MAX_OPTION_BYTES ) ) { return $this->failed(); }
			$after = $decide( $before );
			if ( is_wp_error( $after ) ) { return $after; }
			if ( null !== $after && ( ! is_string( $after ) || strlen( $after ) > WholeStateOptionInventory::MAX_OPTION_BYTES ) ) { return $this->failed(); }
			if ( $after === $before ) { return true; }
			$generation = ( new DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $generation ) ) { return $generation; }
			$wpdb->last_error = '';
			if ( null === $after ) { $written = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); }
			elseif ( null === $before ) { $written = $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $after, 'autoload' => 'off' ) ); }
			else { $written = $wpdb->update( $wpdb->options, array( 'option_value' => $after ), array( 'option_name' => $name ) ); }
			return 1 === $written && '' === (string) $wpdb->last_error ? true : $this->failed();
		} );
		DatabaseOptions::invalidate( array( $name ) );
		return $result;
	}
	private function failed() { return new \WP_Error( 'certloom_durable_option_write_failed', __( 'Certloom could not safely save this setting. Refresh and try again.', 'certloom' ) ); }
}
