<?php
namespace Certloom\Infrastructure\Data;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stops legacy recovery immediately when durable ownership cannot be read. */
final class LegacyStateReadException extends \RuntimeException {
	private $failure;
	public function __construct( \WP_Error $failure ) { parent::__construct( $failure->get_error_message() ); $this->failure = $failure; }
	public function failure() { return $this->failure; }
}
