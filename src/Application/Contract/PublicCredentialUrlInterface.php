<?php
namespace Certloom\Application\Contract;

/** Stable self-hosted verification URL for a normalized public credential ID. */
interface PublicCredentialUrlInterface {
	/** @return string|\WP_Error */
	public function verification_url( $public_id );
}
