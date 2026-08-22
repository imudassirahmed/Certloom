<?php
/** Static release guard for explicit failed-job retry orchestration. */
$runtime = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Job/JobRuntime.php' );
$admin   = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Admin/DomainDataToolsAdmin.php' );
if ( false === $runtime || false === $admin ) { throw new RuntimeException( 'Could not read job retry implementation.' ); }
if ( false === strpos( $runtime, 'public function retry_failed' ) ) { throw new RuntimeException( 'JobRuntime lost its failed-job retry boundary.' ); }
if ( false === strpos( $runtime, '$this->service->requeue_failed' ) ) { throw new RuntimeException( 'Retry no longer uses the job state machine/service.' ); }
if ( false === strpos( $runtime, '$this->dispatcher->schedule' ) ) { throw new RuntimeException( 'Retry no longer schedules a prompt continuation.' ); }
if ( false === strpos( $runtime, 'certloom_job_retry_schedule_deferred' ) ) { throw new RuntimeException( 'Retry no longer tolerates one-shot scheduling failure for watchdog recovery.' ); }
if ( false === strpos( $admin, 'certloom_domain_job_retry' ) || false === strpos( $admin, 'JobStates::FAILED' ) ) { throw new RuntimeException( 'Data Tools lost its failed-only retry endpoint.' ); }
if ( false === strpos( $admin, 'job_error_summary' ) ) { throw new RuntimeException( 'Data Tools lost its bounded protected job error summary.' ); }
if ( false === strpos( $runtime, 'public function pause_queued' ) || false === strpos( $runtime, 'public function resume_paused' ) || false === strpos( $runtime, 'public function cancel_non_running' ) ) { throw new RuntimeException( 'JobRuntime lost premium pause/resume/cancel boundaries.' ); }
if ( false === strpos( $runtime, 'Running slices are deliberately allowed' ) ) { throw new RuntimeException( 'Cancellation safety rationale for atomic running slices was removed.' ); }
echo "PASS  failed-job retry remains explicit, state-machine-driven, watchdog-safe and protected-admin only\n";
echo "\n1 test, 0 failures\n";
