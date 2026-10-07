<?php
/** Unit-only persistence double. Real fences/SQL failures are tested by guarded MySQL probes. */
namespace Certloom\Infrastructure\Data {
	if ( PHP_SAPI !== 'cli' ) { exit; }
	if ( ! class_exists( DurableOptionMutation::class, false ) ) {
	final class DurableOptionMutation {
		public function set( $key, $value ) {
			$saved = \update_option( $key, $value, false );
			return $saved || $value === \get_option( $key, null ) ? true : new \WP_Error( 'certloom_durable_option_write_failed', 'Unit storage failure.' );
		}
		public function remove( $key ) { $saved = \delete_option( $key ); return $saved || false === \get_option( $key, false ) ? true : new \WP_Error( 'certloom_durable_option_write_failed', 'Unit deletion failure.' ); }
		public function change( $key, callable $decide ) {
			$sentinel = new \stdClass(); $before = \get_option( $key, $sentinel );
			$raw = $before === $sentinel ? null : ( \is_array( $before ) || \is_object( $before ) ? \serialize( $before ) : (string) $before );
			$next = $decide( $raw ); if ( \is_wp_error( $next ) ) { return $next; } if ( null === $next ) { return $this->remove( $key ); }
			$value = @\unserialize( $next, array( 'allowed_classes' => false ) ); return $this->set( $key, false !== $value || 'b:0;' === $next ? $value : $next );
		}
	}
	} else { throw new \RuntimeException( 'Install the unit persistence double before loading production commands.' ); }
}
namespace Certloom\Infrastructure\Database {
	if ( ! class_exists( Transaction::class, false ) ) { final class Transaction { public function run( callable $callback ) { return $callback(); } } }
}
