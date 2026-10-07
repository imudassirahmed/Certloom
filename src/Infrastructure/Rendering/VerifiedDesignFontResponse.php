<?php
namespace Certloom\Infrastructure\Rendering;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private path is never REST response data or a response header. */
final class VerifiedDesignFontResponse extends \WP_REST_Response {
	private $verified_asset;
	public function __construct( array $asset ) {
		$this->verified_asset = $asset;
		parent::__construct( null, 200, array( 'Content-Type' => 'font/' . $asset['format'], 'Content-Length' => (string) $asset['size'], 'ETag' => '"' . $asset['sha256'] . '"', 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff' ) );
	}
	public function asset() { return $this->verified_asset; }
}
