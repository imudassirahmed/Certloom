<?php
/** Pure application-service tests using in-memory repository/transaction fakes. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\CredentialRepositoryInterface;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Application\CredentialLifecycleService;
use Certloom\Domain\Credential\StateMachine;
use Certloom\Domain\Credential\States;

final class FakeCredentialRepository implements CredentialRepositoryInterface {
	public $series = array();
	public $versions = array();
	public $fail_series_read = false;

	public function find_series_for_update( $series_id ) { return $this->fail_series_read ? new WP_Error( 'db_read_failed', 'database read failed' ) : ( $this->series[ (int) $series_id ] ?? null ); }
	public function find_version_for_update( $version_id ) { return $this->versions[ (int) $version_id ] ?? null; }
	public function set_administrative_state( $series_id, $expected_state, $new_state ) {
		$id = (int) $series_id;
		if ( ! isset( $this->series[ $id ] ) || $this->series[ $id ]['administrative_state'] !== $expected_state ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->series[ $id ]['administrative_state'] = $new_state;
		return true;
	}
	public function set_version_issuance_state( $version_id, $expected_state, $new_state ) {
		$id = (int) $version_id;
		if ( ! isset( $this->versions[ $id ] ) || $this->versions[ $id ]['issuance_state'] !== $expected_state ) {
			return new WP_Error( 'state_conflict', 'state conflict' );
		}
		$this->versions[ $id ]['issuance_state'] = $new_state;
		return true;
	}
	public function set_current_version( $series_id, $version_id ) {
		$sid = (int) $series_id;
		$vid = (int) $version_id;
		if ( ! isset( $this->series[ $sid ], $this->versions[ $vid ] ) || (int) $this->versions[ $vid ]['series_id'] !== $sid ) {
			return new WP_Error( 'wrong_series', 'wrong series' );
		}
		$this->series[ $sid ]['current_version_id'] = $vid;
		return true;
	}
}

final class FakeEventRepository implements EventRepositoryInterface {
	public $events = array();
	public $fail_type = '';
	public function append( array $event ) {
		if ( $this->fail_type && $this->fail_type === $event['event_type'] ) {
			return new WP_Error( 'event_failed', 'event failed' );
		}
		$this->events[] = $event;
		return count( $this->events );
	}
}

final class SnapshotTransaction implements TransactionInterface {
	private $credentials;
	private $events;
	public function __construct( FakeCredentialRepository $credentials, FakeEventRepository $events ) {
		$this->credentials = $credentials;
		$this->events = $events;
	}
	public function run( callable $callback ) {
		$series = $this->credentials->series;
		$versions = $this->credentials->versions;
		$events = $this->events->events;
		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				$this->credentials->series = $series;
				$this->credentials->versions = $versions;
				$this->events->events = $events;
			}
			return $result;
		} catch ( Throwable $e ) {
			$this->credentials->series = $series;
			$this->credentials->versions = $versions;
			$this->events->events = $events;
			throw $e;
		}
	}
}

function a_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}
function a_true( $actual, $message = '' ) { a_same( true, (bool) $actual, $message ); }

$repo = new FakeCredentialRepository();
$events = new FakeEventRepository();
$repo->series[1] = array(
	'id' => 1,
	'current_version_id' => null,
	'administrative_state' => States::ADMIN_ACTIVE,
);
$repo->versions[10] = array(
	'id' => 10,
	'series_id' => 1,
	'issuance_state' => States::ISSUANCE_DRAFT,
);
$tx = new SnapshotTransaction( $repo, $events );
$service = new CredentialLifecycleService( $repo, $events, new StateMachine(), $tx );

$tests = array();
$tests['issue writes state, current pointer and event'] = function () use ( $service, $repo, $events ) {
	$result = $service->issue( 1, 10, array( 'actor_user_id' => 5, 'reason_code' => 'manual_issue' ) );
	a_true( true === $result );
	a_same( States::ISSUANCE_ISSUED, $repo->versions[10]['issuance_state'] );
	a_same( 10, $repo->series[1]['current_version_id'] );
	a_same( 'credential.issued', $events->events[0]['event_type'] );
	a_same( 5, $events->events[0]['actor_user_id'] );
};
$tests['suspend and reinstate mutate Series administrative state only'] = function () use ( $service, $repo, $events ) {
	$result = $service->suspend( 1, array( 'reason_code' => 'review' ) );
	a_true( true === $result );
	a_same( States::ADMIN_SUSPENDED, $repo->series[1]['administrative_state'] );
	a_same( States::ISSUANCE_ISSUED, $repo->versions[10]['issuance_state'] );
	$result = $service->reinstate( 1 );
	a_true( true === $result );
	a_same( States::ADMIN_ACTIVE, $repo->series[1]['administrative_state'] );
};
$tests['revoke event failure rolls state back'] = function () use ( $service, $repo, $events ) {
	$events->fail_type = 'credential.revoked';
	$before = $repo->series[1]['administrative_state'];
	$result = $service->revoke( 1, array( 'reason_code' => 'fraud' ) );
	a_true( is_wp_error( $result ) );
	a_same( $before, $repo->series[1]['administrative_state'] );
	$events->fail_type = '';
};
$tests['revoke succeeds when event can persist'] = function () use ( $service, $repo, $events ) {
	$result = $service->revoke( 1, array( 'reason_code' => 'authority_withdrawn' ) );
	a_true( true === $result );
	a_same( States::ADMIN_REVOKED, $repo->series[1]['administrative_state'] );
	a_same( 'credential.revoked', end( $events->events )['event_type'] );
};
$tests['reinstatement after revoke is explicit'] = function () use ( $service, $repo ) {
	$result = $service->reinstate( 1, array( 'reason_code' => 'appeal_approved' ) );
	a_true( true === $result );
	a_same( States::ADMIN_ACTIVE, $repo->series[1]['administrative_state'] );
};
$tests['void changes version disposition without deleting history'] = function () use ( $service, $repo, $events ) {
	$result = $service->void( 1, 10, array( 'reason_code' => 'issued_in_error' ) );
	a_true( true === $result );
	a_same( States::ISSUANCE_VOIDED, $repo->versions[10]['issuance_state'] );
	a_same( 'credential.voided', end( $events->events )['event_type'] );
};

$tests['database read failures propagate rather than becoming not-found transitions'] = function () use ( $service, $repo, $events ) {
	$repo->fail_series_read = true;
	$before_events = count( $events->events );
	$result = $service->suspend( 1 );
	a_true( is_wp_error( $result ) );
	a_same( 'db_read_failed', $result->get_error_code() );
	a_same( $before_events, count( $events->events ) );
	$repo->fail_series_read = false;
};

$failed = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		echo "PASS  $name\n";
	} catch ( Throwable $e ) {
		$failed++;
		echo "FAIL  $name\n      " . $e->getMessage() . "\n";
	}
}

echo "\n" . count( $tests ) . " tests, $failed failures\n";
exit( $failed ? 1 : 0 );
