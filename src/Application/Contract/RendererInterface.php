<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
interface RendererInterface {
	/** @return string Stable machine identifier. */
	public function id();
	/** @return string Renderer/library version. */
	public function version();
	/** @return string[] e.g. array('pdf') or array('png','jpeg'). */
	public function formats();
	/** @return array<string,mixed> Runtime requirements such as extensions/classes/functions/php_min. */
	public function requirements();
	/** @return array<string,mixed> Deterministic context: font manifest/configuration/library details. */
	public function context();
	/** @return string Binary artifact bytes from a certloom-render-plan v1 array. */
	public function render( array $render_plan, $format );
}
