<?php
namespace Certloom\Application\Issuance;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobQueue;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Issuance\BulkIssuanceCsvReader;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;

/** Application boundary for reviewed Bulk Issuance preflight/commit jobs. */
final class BulkIssuanceCoordinator {
	private $queue; private $jobs; private $files; private $reader;
	public function __construct(JobQueue $queue,JobRepositoryInterface $jobs,?PrivateJobFileStore $files=null,?BulkIssuanceCsvReader $reader=null){$this->queue=$queue;$this->jobs=$jobs;$this->files=$files?:new PrivateJobFileStore();$this->reader=$reader?:new BulkIssuanceCsvReader($this->files);}
	/** @return string|\WP_Error */
	public function enqueue_preflight($source_path,$original_name,$owner_user_id,array $shared=array()){
		$source_path=(string)$source_path;$original_name=sanitize_file_name((string)$original_name);if('csv'!==strtolower(pathinfo($original_name,PATHINFO_EXTENSION))){return new \WP_Error('certloom_bulk_upload_type_invalid',__('Certloom Bulk Issuance accepts CSV files.','certloom'));}$size=@filesize($source_path);if(false===$size||$size<1||$size>BulkIssuanceCsvReader::MAX_FILE_BYTES){return new \WP_Error('certloom_bulk_upload_size_invalid',__('Choose a non-empty Bulk Issuance CSV no larger than 10 MB.','certloom'));}
		$shared=$this->shared($shared);if(is_wp_error($shared)){return $shared;}try{$file_key=bin2hex(random_bytes(16));}catch(\Throwable $e){return new \WP_Error('certloom_bulk_file_key_failed',__('Certloom could not create a secure Bulk Issuance file identifier.','certloom'));}$private=$this->files->store_copy($file_key,$source_path,'bulk-source');if(is_wp_error($private)){return $private;}$probe=$this->reader->start($private,$shared,BulkIssuanceCsvReader::MAX_ROWS);if(is_wp_error($probe)){return $this->cleanup($private,$probe);}$schema=$this->validate_scoped_schema($probe['header']??array(),$shared);if(is_wp_error($schema)){return $this->cleanup($private,$schema);}$sha=@hash_file('sha256',$private);if(!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/',$sha)){return $this->cleanup($private,new \WP_Error('certloom_bulk_source_hash_failed',__('Certloom could not fingerprint the protected Bulk Issuance CSV.','certloom')));}
		$key=$this->queue->enqueue(JobTypes::BULK_ISSUANCE_PREFLIGHT,absint($owner_user_id),array('path'=>$private,'source_sha256'=>$sha,'original_name'=>$original_name,'shared'=>$shared,'max_rows'=>BulkIssuanceCsvReader::MAX_ROWS));if(is_wp_error($key)){return $this->cleanup($private,$key);}return $key;
	}
	/** @return string|\WP_Error */
	public function enqueue_commit( $preflight_job_key, $owner_user_id, $expected_review_fingerprint, $confirmed = false, $qualified_waiting = false ) {
		if ( true !== $confirmed ) { return new \WP_Error( 'certloom_bulk_commit_confirmation_required', __( 'Confirm the reviewed Bulk Issuance batch before issuing credentials.', 'certloom' ) ); }
		$pre = $this->owned_job( $preflight_job_key, $owner_user_id ); if ( is_wp_error( $pre ) ) { return $pre; }
		if ( JobTypes::BULK_ISSUANCE_PREFLIGHT !== $pre['job_type'] ) { return new \WP_Error( 'certloom_bulk_preflight_invalid', __( 'The selected job is not a Bulk Issuance review.', 'certloom' ) ); }
		if ( ! in_array( $pre['status'], array( JobStates::SUCCEEDED, JobStates::PARTIALLY_SUCCEEDED ), true ) ) { return new \WP_Error( 'certloom_bulk_preflight_incomplete', __( 'Bulk Issuance review must finish before credentials can be issued.', 'certloom' ) ); }
		$result = is_array( $pre['result'] ?? null ) ? $pre['result'] : array();
		if ( empty( $result['ready_for_issue'] ) ) { return new \WP_Error( 'certloom_bulk_preflight_not_ready', __( 'Fix every blocking CSV review error before Bulk Issuance can start.', 'certloom' ) ); }
		$expected = strtolower( trim( (string) $expected_review_fingerprint ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( (string) ( $result['review_fingerprint'] ?? '' ), $expected ) ) { return new \WP_Error( 'certloom_bulk_review_stale', __( 'The Bulk Issuance confirmation no longer matches the reviewed batch.', 'certloom' ) ); }
		$waiting = absint( $result['summary']['waiting'] ?? 0 );
		if ( $waiting && ( true !== $qualified_waiting || 'customer' !== ( $pre['payload']['shared']['waiting_policy'] ?? 'stop' ) ) ) { return new \WP_Error( 'certloom_bulk_pending_qualification_required', __( 'Confirm that every waiting recipient has already earned this certificate. Customer details establish completeness and do not establish qualification.', 'certloom' ) ); }
		$total = absint( $result['summary']['ready'] ?? 0 ) + $waiting;
		return $this->queue->enqueue( JobTypes::BULK_ISSUANCE_COMMIT, absint( $owner_user_id ), array( 'preflight_job_key' => $pre['job_key'], 'review_fingerprint' => $expected, 'confirmed_issue' => true, 'qualified_waiting' => true === $qualified_waiting ), $total, null, $pre['job_key'] );
	}
	/** @return array<string,mixed>|\WP_Error */
	public function owned_job($job_key,$owner_user_id){$job_key=strtolower(trim((string)$job_key));if(!preg_match('/^[a-f0-9]{32}$/',$job_key)){return new \WP_Error('certloom_bulk_job_key_invalid',__('The Bulk Issuance job identifier is invalid.','certloom'));}$job=$this->jobs->find($job_key);if(is_wp_error($job)){return $job;}if(!is_array($job)||!in_array((string)($job['job_type']??''),array(JobTypes::BULK_ISSUANCE_PREFLIGHT,JobTypes::BULK_ISSUANCE_COMMIT),true)){return new \WP_Error('certloom_bulk_job_missing',__('That Bulk Issuance job is no longer available.','certloom'));}if(absint($job['owner_user_id']??0)!==absint($owner_user_id)){return new \WP_Error('certloom_bulk_owner_mismatch',__('That Bulk Issuance job belongs to another administrator.','certloom'));}return $job;}
	/**
	 * FLD-02 file-level preflight. Row-level ManualIssuanceService remains the
	 * canonical validator, but obvious contract/header mismatches are rejected
	 * before a durable job is queued or administrators wait for row processing.
	 *
	 * @param array<int,string>   $header
	 * @param array<string,mixed> $shared
	 * @return true|\WP_Error
	 */
	private function validate_scoped_schema( array $header, array $shared ) {
		$schema = $this->csv_schema( absint( $shared['program_id'] ?? 0 ), absint( $shared['batch_id'] ?? 0 ) );
		return is_wp_error( $schema ) ? $schema : ( new BulkIssuanceCsvSchema() )->validate( $header, $shared, $schema );
	}

	/** Read-only Program-specific CSV source contract, shared by upload and download. */
	public function csv_schema( $program_id, $batch_id = 0 ) {
		$contract = $this->field_contract( array( 'program_id' => absint( $program_id ), 'batch_id' => absint( $batch_id ) ) );
		if ( is_wp_error( $contract ) ) { return $contract; }
		$catalog = class_exists( '\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		return ( new BulkIssuanceCsvSchema() )->describe( $contract, $catalog );
	}

	private function field_contract( array $shared ) {
		$program = ( new ProgramRepository() )->find( absint( $shared['program_id'] ?? 0 ) );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) || empty( $program['active'] ) ) { return new \WP_Error( 'certloom_bulk_program_unavailable', __( 'The selected Program is no longer active or available for Bulk Issuance.', 'certloom' ) ); }
		$batch = null;
		$batch_id = absint( $shared['batch_id'] ?? 0 );
		if ( $batch_id ) {
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) || absint( $batch['program_id'] ?? 0 ) !== absint( $program['id'] ?? 0 ) ) {
				return new \WP_Error( 'certloom_bulk_batch_program_mismatch', __( 'Choose an active Batch that belongs to the selected Program.', 'certloom' ) );
			}
		}
		$catalog = class_exists( '\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		$issuer = null;
		$issuer_id = absint( $program['issuer_id'] ?? 0 ) ?: absint( $shared['issuer_id'] ?? 0 );
		if ( $issuer_id ) {
			$issuer = ( new IssuerRepository() )->find( $issuer_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
			if ( ! is_array( $issuer ) ) { return new \WP_Error( 'certloom_bulk_issuer_unavailable', __( 'The selected Issuer is no longer available for Bulk Issuance.', 'certloom' ) ); }
		}
		$global_contract = ( new ScopedFieldGlobalRepository() )->get( $catalog );
		if ( is_wp_error( $global_contract ) ) { return $global_contract; }
		$contract = ( new ScopedFieldContract() )->resolve_hierarchy(
			$global_contract,
			is_array( $issuer ) && is_array( $issuer['field_contract'] ?? null ) ? $issuer['field_contract'] : null,
			(array) ( $program['field_contract'] ?? ScopedFieldContract::empty_program() ),
			is_array( $batch ) ? (array) ( $batch['field_contract'] ?? ScopedFieldContract::empty_batch() ) : null,
			$catalog
		);
		return $contract;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function shared( array $input ) {
		foreach ( $input as $value ) { if ( ! is_scalar( $value ) && null !== $value ) { return new \WP_Error( 'certloom_bulk_context_invalid', __( 'Review the bulk issuance choices before uploading the CSV.', 'certloom' ) ); } }
		$program = absint( $input['program_id'] ?? 0 );
		if ( ! $program ) { return new \WP_Error( 'certloom_bulk_context_required', __( 'Choose an active Program for Bulk Issuance. Certloom can resolve the Program’s published certificate design automatically.', 'certloom' ) ); }
		$mode = sanitize_key( (string) ( $input['expiry_mode'] ?? 'program' ) );
		if ( ! in_array( $mode, array( 'program', 'permanent', 'date' ), true ) ) { return new \WP_Error( 'certloom_bulk_expiry_mode_invalid', __( 'Choose a valid Bulk Issuance expiry policy.', 'certloom' ) ); }
		$waiting = $input['waiting_policy'] ?? 'stop';
		if ( ! in_array( $waiting, array( 'stop', 'customer' ), true ) ) { return new \WP_Error( 'certloom_bulk_waiting_policy_invalid', __( 'Choose whether missing customer details should stop the batch or create reviewed waiting requests.', 'certloom' ) ); }
		return array( 'program_id' => $program, 'batch_id' => absint( $input['batch_id'] ?? 0 ), 'template_id' => absint( $input['template_id'] ?? 0 ), 'valid_from' => trim( sanitize_text_field( (string) ( $input['valid_from'] ?? '' ) ) ), 'expiry_mode' => $mode, 'expires_at' => trim( sanitize_text_field( (string) ( $input['expires_at'] ?? '' ) ) ), 'grade' => trim( sanitize_text_field( (string) ( $input['grade'] ?? '' ) ) ), 'waiting_policy' => $waiting );
	}
	/** @return \WP_Error */ private function cleanup($path,\WP_Error $primary){$d=$this->files->delete((string)$path);return(!is_wp_error($d)&&false!==$d)?$primary:new \WP_Error('certloom_bulk_private_cleanup_failed',sprintf(/* translators: %1$s: primary error; %2$s: d. */ __('Certloom stopped Bulk Issuance safely (%1$s), but could not remove its protected CSV copy (%2$s).','certloom'),$primary->get_error_message(),is_wp_error($d)?$d->get_error_message():__('file deletion failed','certloom')));}
}
