<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RecipientNoticeJobHandler implements JobHandlerInterface {
	public function handle( array $job, $worker_token ) {
		$payload = $job['payload'] ?? null;
		if ( ! is_array( $payload ) || count( $payload ) !== 3 || array_diff( array_keys( $payload ), array( 'schema_version', 'request_id', 'notice_key' ) ) || 1 !== $payload['schema_version'] || ! is_int( $payload['request_id'] ) || $payload['request_id'] < 1 || ! is_string( $payload['notice_key'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $payload['notice_key'] ) ) { return new \WP_Error( 'certloom_recipient_notice_job_invalid', __( 'This recipient notice job needs review.', 'certloom' ) ); }
		$result = ( new RecipientNoticeService() )->deliver( $payload['request_id'], $payload['notice_key'], $job['job_key'] );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( in_array( $result['state'] ?? '', array( 'failed', 'uncertain' ), true ) ) { return new \WP_Error( 'certloom_recipient_notice_' . $result['state'], __( 'The recipient notice was not confirmed. Its evidence is retained; this attempt will not send again automatically.', 'certloom' ) ); }
		return JobSliceResult::succeeded( array( 'outcome' => $result['state'] ), 1 );
	}
}
