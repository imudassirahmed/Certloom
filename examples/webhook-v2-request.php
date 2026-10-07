<?php
/** Portable PHP 7.4+ recipe. Builds an HTTP request; never sends or logs secrets. */
function certloom_webhook_v2_request( $source, $secret, $mode, array $payload, $request_ref = '', $timestamp = null ) {
	if ( ! is_string( $source ) || 1 !== preg_match( '/^v2-[a-f0-9]{12}$/D', $source ) || ! is_string( $secret ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $secret ) || ! in_array( $mode, array( 'test', 'intake', 'evidence' ), true ) || ( 'evidence' === $mode && ( ! is_string( $request_ref ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $request_ref ) ) ) || ( 'evidence' !== $mode && '' !== $request_ref ) ) { throw new InvalidArgumentException( 'Valid connection, mode and signing secret required.' ); }
	$timestamp = null === $timestamp ? (string) time() : $timestamp;
	if ( ! is_string( $timestamp ) || 1 !== preg_match( '/^[0-9]{10}$/D', $timestamp ) ) { throw new InvalidArgumentException( 'Use a current Unix timestamp.' ); }
	$body = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
	if ( strlen( $body ) > 32768 ) { throw new InvalidArgumentException( 'Payload exceeds 32 KiB.' ); }
	$route = '/certloom/v2/integrations/webhook/' . $source . ( 'test' === $mode ? '/test' : ( 'evidence' === $mode ? '/requests/' . $request_ref . '/evidence' : '' ) );
	$signature = hash_hmac( 'sha256', "certloom-webhook-v2\nPOST\n" . $route . "\n" . $timestamp . "\n" . $body, $secret );
	return array( 'route' => $route, 'body' => $body, 'headers' => array( 'Content-Type' => 'application/json', 'X-Certloom-Timestamp' => $timestamp, 'X-Certloom-Signature' => 'sha256=' . $signature ) );
}
