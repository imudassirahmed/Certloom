<?php
namespace Certloom\Application\Contract;

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
	/** @return string Binary artifact bytes. */
	public function render( array $resolved_document, $format );
}
