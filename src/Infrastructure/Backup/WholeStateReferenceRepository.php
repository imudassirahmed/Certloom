<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Application\Backup\WholeStateReferenceRecord;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Current, bounded identities. No emails, passwords, roles or ordinary WordPress data are exported. */
final class WholeStateReferenceRepository {
	public function account( $recipient_id, $user_id ) {
		if ( ! WholeStateReferenceRecord::id( $recipient_id ) || ! WholeStateReferenceRecord::id( $user_id ) ) { return $this->failed(); }
		$identity = $this->account_identity( $user_id );
		return is_wp_error( $identity ) ? $identity : array( 'kind' => 'account', 'recipient_id' => $recipient_id, 'source_user_id' => $user_id, 'identity_sha256' => $identity );
	}
	public function account_identity( $id, $lock = false ) {
		global $wpdb;
		if ( ! WholeStateReferenceRecord::id( $id ) || ( $lock && ! \Certloom\Infrastructure\Database\Transaction::owns_current() ) ) { return $this->failed(); }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,user_login,user_email,user_registered,user_status FROM %i WHERE ID=%d' . ( $lock ? ' FOR UPDATE' : '' ), $wpdb->users, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->failed(); }
		if ( null === $row ) { return null; }
		if ( ! is_array( $row ) || (string) $id !== (string) ( $row['ID'] ?? null ) ) { return $this->failed(); }
		// Include exact blog membership evidence, never import it or infer access from an email.
		$wpdb->last_error = '';
		$membership = $wpdb->get_results( $wpdb->prepare( 'SELECT CASE WHEN OCTET_LENGTH(meta_value)<=65536 THEN meta_value ELSE NULL END AS storage FROM %i WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), $wpdb->usermeta, $id, $wpdb->prefix . 'capabilities' ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $membership ) || count( $membership ) > 1 || ( $membership && ! is_string( $membership[0]['storage'] ?? null ) ) ) { return $this->failed(); }
		foreach ( $row as $value ) { if ( ! is_string( $value ) || strlen( $value ) > 512 ) { return $this->failed(); } }
		$row['blog_id'] = (int) get_current_blog_id(); $row['membership_sha256'] = $membership ? hash( 'sha256', $membership[0]['storage'] ) : null;
		try { return ( new CanonicalJson() )->hash( $row ); } catch ( \Throwable $e ) { return $this->failed(); }
	}
	/** Stable account creation identity; profile email and role edits do not transfer ownership. */
	public function account_creation_identity( $id, $lock = false ) {
		global $wpdb;
		if ( ! WholeStateReferenceRecord::id( $id ) || ( $lock && ! \Certloom\Infrastructure\Database\Transaction::owns_current() ) ) { return $this->failed(); }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,user_login,user_registered FROM %i WHERE ID=%d' . ( $lock ? ' FOR UPDATE' : '' ), $wpdb->users, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->failed(); } if ( null === $row ) { return null; }
		if ( ! is_array( $row ) || (string) $id !== ( $row['ID'] ?? null ) ) { return $this->failed(); }
		foreach ( $row as $v ) { if ( ! is_string( $v ) || strlen( $v ) > 512 ) { return $this->failed(); } }
		$row['blog_id'] = (int) get_current_blog_id(); $row['users_table'] = $wpdb->users;
		return ( new CanonicalJson() )->hash( $row );
	}
	public function object( $id ) {
		global $wpdb;
		if ( ! WholeStateReferenceRecord::id( $id ) ) { return $this->failed(); }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ID,post_type,post_name,post_date_gmt,post_parent,post_status FROM %i WHERE ID=%d', $wpdb->posts, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->failed(); }
		if ( null === $row ) { return array( 'kind' => 'object', 'source_id' => $id, 'post_type' => null, 'identity_sha256' => null ); }
		if ( ! is_array( $row ) || (string) $id !== (string) ( $row['ID'] ?? null ) ) { return $this->failed(); }
		foreach ( $row as $value ) { if ( ! is_string( $value ) || strlen( $value ) > 512 ) { return $this->failed(); } }
		try { $record = array( 'kind' => 'object', 'source_id' => $id, 'post_type' => $row['post_type'], 'identity_sha256' => ( new CanonicalJson() )->hash( $row ) ); } catch ( \Throwable $e ) { return $this->failed(); }
		return ( new WholeStateReferenceRecord() )->validate( $record );
	}
	private function failed() { return new \WP_Error( 'certloom_whole_reference_read_failed', __( 'Certloom could not read current installation reference evidence safely.', 'certloom' ) ); }
}
