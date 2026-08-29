<?php
namespace Certloom\Application\Contract;

/**
 * First-party integration adapter boundary.
 *
 * Adapters translate one provider-specific event into Certloom's bounded
 * normalized event payload. They never issue credentials directly.
 */
interface IntegrationAdapterInterface {
	/** Stable machine key owned by Certloom, for example tutor_lms or learndash. @return string */
	public function key();

	/** Human label used only in administrator diagnostics. @return string */
	public function label();

	/**
	 * Converts a provider event into a normalized context input.
	 *
	 * The returned array is still validated by IntegrationEventContext; adapters
	 * cannot broaden the schema by returning arbitrary metadata.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function normalize_event( array $provider_event );
}
