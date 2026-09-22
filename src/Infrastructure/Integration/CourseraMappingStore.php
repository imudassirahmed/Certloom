<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationMappingResolverInterface;
use Certloom\Application\Integration\IntegrationEventContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Server-owned Coursera course → Certloom Program/Batch mappings. */
final class CourseraMappingStore implements IntegrationMappingResolverInterface {
	const OPTION         = 'certloom_coursera_mappings_v1';
	const SCHEMA_VERSION = 1;
	const MAX_MAPPINGS   = 500;

	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_mapping( IntegrationEventContext $context ) {
		if ( CourseraXapiAdapter::KEY !== $context->adapter_key() || CourseraXapiAdapter::SOURCE_OBJECT_TYPE !== $context->source_object_type() ) { return null; }
		$row = $this->find( $context->source_object_id() );
		if ( ! is_array( $row ) || empty( $row['active'] ) ) { return null; }
		return array(
			'adapter'               => CourseraXapiAdapter::KEY,
			'source_object_type'    => CourseraXapiAdapter::SOURCE_OBJECT_TYPE,
			'source_object_id'      => (string) $row['course_id'],
			'program_id'            => absint( $row['program_id'] ),
			'batch_id'              => absint( $row['batch_id'] ?? 0 ),
			'template_id'           => 0,
			'legacy_template_id'    => absint( $row['template_id'] ?? 0 ),
			'expiry_mode'           => (string) $row['expiry_mode'],
			'expires_at'            => $row['expires_at'],
			'certificate_threshold' => 100.0,
			'active'                => true,
		);
	}

	/** @return array<string,mixed>|null */
	public function find( $course_id ) { $all = $this->all(); $key = (string) $course_id; return isset( $all[ $key ] ) ? $all[ $key ] : null; }

	/** @return array<string,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || absint( $stored['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION || ! is_array( $stored['mappings'] ?? null ) ) { return array(); }
		$out = array();
		foreach ( array_slice( $stored['mappings'], 0, self::MAX_MAPPINGS ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$row = $this->normalize_row( $row, false ); if ( is_wp_error( $row ) ) { continue; }
			$out[ (string) $row['course_id'] ] = $row;
		}
		ksort( $out, SORT_STRING ); return $out;
	}

	/** @return true|\WP_Error */
	public function save( array $row ) {
		$row = $this->normalize_row( $row, true ); if ( is_wp_error( $row ) ) { return $row; }
		$all = $this->all(); $key = (string) $row['course_id'];
		if ( ! isset( $all[ $key ] ) && count( $all ) >= self::MAX_MAPPINGS ) { return new \WP_Error( 'certloom_coursera_mapping_limit', __( 'Coursera mappings reached the Certloom safety limit. Remove an unused mapping before adding another.', 'certloom' ) ); }
		$all[ $key ] = $row; ksort( $all, SORT_STRING ); return $this->persist( $all );
	}

	/** @return true|\WP_Error */
	public function delete( $course_id ) { $course_id = trim( (string) $course_id ); if ( ! self::stable_id( $course_id ) ) { return new \WP_Error( 'certloom_coursera_course_invalid', __( 'Choose a valid Coursera course mapping.', 'certloom' ) ); } $all=$this->all(); unset($all[$course_id]); return $this->persist($all); }

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_row( array $row, $touch ) {
		$course_id=trim((string)($row['course_id']??'')); $program_id=absint($row['program_id']??0); $batch_id=absint($row['batch_id']??0); $template_id=absint($row['template_id']??0);
		if(!self::stable_id($course_id)||$program_id<1){return new \WP_Error('certloom_coursera_mapping_target_invalid',__('Coursera mappings require a course ID and Certloom Program. A Batch is optional; design follows Global → Issuer → Program → Batch.','certloom'));}
		$expiry_mode=strtolower(trim((string)($row['expiry_mode']??'program'))); if(!in_array($expiry_mode,array('program','permanent','date'),true)){return new \WP_Error('certloom_coursera_mapping_expiry_invalid',__('Choose a valid expiry rule for the Coursera mapping.','certloom'));}
		$expires_at=null; if('date'===$expiry_mode){$expires_at=trim((string)($row['expires_at']??''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$expires_at)){return new \WP_Error('certloom_coursera_mapping_expiry_invalid',__('Choose a valid explicit expiry date for the Coursera mapping.','certloom'));}}
		$out=array('course_id'=>$course_id,'program_id'=>$program_id,'batch_id'=>$batch_id,'template_id'=>$template_id,'expiry_mode'=>$expiry_mode,'expires_at'=>$expires_at,'active'=>!array_key_exists('active',$row)||!empty($row['active']),'updated_at'=>trim((string)($row['updated_at']??'')));
		if($touch||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$out['updated_at'])){$out['updated_at']=current_time('mysql',true);} return $out;
	}
	/** @return true|\WP_Error */ private function persist(array $mappings){$payload=array('schema_version'=>self::SCHEMA_VERSION,'mappings'=>$mappings);$saved=update_option(self::OPTION,$payload,false);return $saved||$payload===get_option(self::OPTION,null)?true:new \WP_Error('certloom_coursera_mapping_save_failed',__('Certloom could not save the Coursera mapping safely.','certloom'));}
	private static function stable_id($value){$value=trim((string)$value);return ''!==$value&&strlen($value)<=191&&1===preg_match('/^[A-Za-z0-9._:@\/-]+$/',$value);}
}
