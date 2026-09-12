<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Setup\PurposePresetEngine;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Human-facing terminology for the active site usage profile.
 *
 * Storage and APIs deliberately keep the canonical Issuer / Program / Batch /
 * Signer vocabulary. This class changes presentation only, so integrations,
 * imports and immutable credential history never fork by industry profile.
 */
final class UsageProfilePresentation {
	/** @return string */
	public static function active_profile() {
		$engine = new PurposePresetEngine();
		$profile = class_exists( '\\Ecapla_CertVerify_Settings' )
			? (string) \Ecapla_CertVerify_Settings::get( 'usage_profile', 'general_other' )
			: 'general_other';
		$profile = $engine->normalize_profile( $profile );
		return isset( $engine->profiles()[ $profile ] ) ? $profile : 'general_other';
	}

	/** @return string */
	public static function profile_label() {
		$engine = new PurposePresetEngine();
		$profiles = $engine->profiles();
		$profile = self::active_profile();
		return (string) ( $profiles[ $profile ]['label'] ?? __( 'General / Other', 'certloom' ) );
	}

	/** @return array<string,array<string,string>> */
	public static function terms( $profile = '' ) {
		$profile = '' === $profile ? self::active_profile() : ( new PurposePresetEngine() )->normalize_profile( $profile );
		$default = array(
			'issuer'  => array( 'singular' => __( 'Issuer', 'certloom' ), 'plural' => __( 'Issuers', 'certloom' ), 'search' => __( 'Search Issuers…', 'certloom' ), 'choose' => __( 'Choose Issuer…', 'certloom' ) ),
			'program' => array( 'singular' => __( 'Program', 'certloom' ), 'plural' => __( 'Programs', 'certloom' ), 'search' => __( 'Search Programs…', 'certloom' ), 'choose' => __( 'Choose Program…', 'certloom' ) ),
			'batch'   => array( 'singular' => __( 'Batch', 'certloom' ), 'plural' => __( 'Batches', 'certloom' ), 'search' => __( 'Search Batches…', 'certloom' ), 'choose' => __( 'Choose Batch…', 'certloom' ) ),
			'signer'  => array( 'singular' => __( 'Signer', 'certloom' ), 'plural' => __( 'Signers', 'certloom' ), 'search' => __( 'Search Signers…', 'certloom' ), 'choose' => __( 'Choose Signer…', 'certloom' ) ),
		);

		switch ( $profile ) {
			case 'training_lms':
				return array(
					'issuer'  => array( 'singular' => __( 'Provider', 'certloom' ), 'plural' => __( 'Providers', 'certloom' ), 'search' => __( 'Search providers…', 'certloom' ), 'choose' => __( 'Choose provider…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Course / certification', 'certloom' ), 'plural' => __( 'Courses / certifications', 'certloom' ), 'search' => __( 'Search courses or certifications…', 'certloom' ), 'choose' => __( 'Choose course or certification…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Cohort / session', 'certloom' ), 'plural' => __( 'Cohorts / sessions', 'certloom' ), 'search' => __( 'Search cohorts or sessions…', 'certloom' ), 'choose' => __( 'Choose cohort or session…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Trainer / authorized academic authority', 'certloom' ), 'plural' => __( 'Trainers / authorized academic authorities', 'certloom' ), 'search' => __( 'Search trainers or authorized academic authorities…', 'certloom' ), 'choose' => __( 'Choose trainer or authorized academic authority…', 'certloom' ) ),
				);
			case 'iso_management':
				return array(
					'issuer'  => array( 'singular' => __( 'Certification body', 'certloom' ), 'plural' => __( 'Certification bodies', 'certloom' ), 'search' => __( 'Search certification bodies…', 'certloom' ), 'choose' => __( 'Choose certification body…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Scheme / standard / service line', 'certloom' ), 'plural' => __( 'Schemes / standards / service lines', 'certloom' ), 'search' => __( 'Search schemes, standards or service lines…', 'certloom' ), 'choose' => __( 'Choose scheme, standard or service line…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Audit / issuance cycle', 'certloom' ), 'plural' => __( 'Audit / issuance cycles', 'certloom' ), 'search' => __( 'Search audit or issuance cycles…', 'certloom' ), 'choose' => __( 'Choose audit or issuance cycle…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Authorized certification authority', 'certloom' ), 'plural' => __( 'Authorized certification authorities', 'certloom' ), 'search' => __( 'Search authorized certification authorities…', 'certloom' ), 'choose' => __( 'Choose authorized certification authority…', 'certloom' ) ),
				);
			case 'government_licensing':
				return array(
					'issuer'  => array( 'singular' => __( 'Ministry / regulator / agency', 'certloom' ), 'plural' => __( 'Ministries / regulators / agencies', 'certloom' ), 'search' => __( 'Search ministries, regulators or agencies…', 'certloom' ), 'choose' => __( 'Choose ministry, regulator or agency…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'License / permit / registration scheme', 'certloom' ), 'plural' => __( 'Licenses / permits / registration schemes', 'certloom' ), 'search' => __( 'Search licenses, permits or registration schemes…', 'certloom' ), 'choose' => __( 'Choose license, permit or registration scheme…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Application / issuance cycle', 'certloom' ), 'plural' => __( 'Application / issuance cycles', 'certloom' ), 'search' => __( 'Search application or issuance cycles…', 'certloom' ), 'choose' => __( 'Choose application or issuance cycle…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Authorized officer / registrar / inspector', 'certloom' ), 'plural' => __( 'Authorized officers / registrars / inspectors', 'certloom' ), 'search' => __( 'Search authorized officers, registrars or inspectors…', 'certloom' ), 'choose' => __( 'Choose authorized officer, registrar or inspector…', 'certloom' ) ),
				);
			case 'school_results':
				return array(
					'issuer'  => array( 'singular' => __( 'School / university / board', 'certloom' ), 'plural' => __( 'Schools / universities / boards', 'certloom' ), 'search' => __( 'Search schools, universities or boards…', 'certloom' ), 'choose' => __( 'Choose school, university or board…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Class / course / exam / qualification', 'certloom' ), 'plural' => __( 'Classes / courses / exams / qualifications', 'certloom' ), 'search' => __( 'Search classes, courses, exams or qualifications…', 'certloom' ), 'choose' => __( 'Choose class, course, exam or qualification…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Session / semester / cohort', 'certloom' ), 'plural' => __( 'Sessions / semesters / cohorts', 'certloom' ), 'search' => __( 'Search sessions, semesters or cohorts…', 'certloom' ), 'choose' => __( 'Choose session, semester or cohort…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Principal / registrar / controller / dean', 'certloom' ), 'plural' => __( 'Principals / registrars / controllers / deans', 'certloom' ), 'search' => __( 'Search principals, registrars, controllers or deans…', 'certloom' ), 'choose' => __( 'Choose principal, registrar, controller or dean…', 'certloom' ) ),
				);
			default:
				return $default;
		}
	}

	/** @return string */
	public static function term( $entity, $form = 'singular' ) {
		$terms = self::terms();
		$entity = sanitize_key( (string) $entity );
		$form = sanitize_key( (string) $form );
		return (string) ( $terms[ $entity ][ $form ] ?? ucfirst( $entity ) );
	}
}
