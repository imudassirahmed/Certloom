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
			case 'corporate_compliance':
				return array(
					'issuer'  => array( 'singular' => __( 'Organization / employer', 'certloom' ), 'plural' => __( 'Organizations / employers', 'certloom' ), 'search' => __( 'Search organizations or employers…', 'certloom' ), 'choose' => __( 'Choose organization or employer…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Compliance / competency pathway', 'certloom' ), 'plural' => __( 'Compliance / competency pathways', 'certloom' ), 'search' => __( 'Search compliance or competency pathways…', 'certloom' ), 'choose' => __( 'Choose compliance or competency pathway…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Campaign / training cycle', 'certloom' ), 'plural' => __( 'Campaigns / training cycles', 'certloom' ), 'search' => __( 'Search campaigns or training cycles…', 'certloom' ), 'choose' => __( 'Choose campaign or training cycle…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Authorized compliance officer', 'certloom' ), 'plural' => __( 'Authorized compliance officers', 'certloom' ), 'search' => __( 'Search authorized compliance officers…', 'certloom' ), 'choose' => __( 'Choose authorized compliance officer…', 'certloom' ) ),
				);
			case 'membership_association':
				return array(
					'issuer'  => array( 'singular' => __( 'Association / professional body', 'certloom' ), 'plural' => __( 'Associations / professional bodies', 'certloom' ), 'search' => __( 'Search associations or professional bodies…', 'certloom' ), 'choose' => __( 'Choose association or professional body…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Membership / fellowship / registration category', 'certloom' ), 'plural' => __( 'Membership / fellowship / registration categories', 'certloom' ), 'search' => __( 'Search membership, fellowship or registration categories…', 'certloom' ), 'choose' => __( 'Choose membership, fellowship or registration category…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Intake / renewal cycle', 'certloom' ), 'plural' => __( 'Intakes / renewal cycles', 'certloom' ), 'search' => __( 'Search intakes or renewal cycles…', 'certloom' ), 'choose' => __( 'Choose intake or renewal cycle…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Authorized membership officer / registrar', 'certloom' ), 'plural' => __( 'Authorized membership officers / registrars', 'certloom' ), 'search' => __( 'Search authorized membership officers or registrars…', 'certloom' ), 'choose' => __( 'Choose authorized membership officer or registrar…', 'certloom' ) ),
				);
			case 'events_awards':
				return array(
					'issuer'  => array( 'singular' => __( 'Organizer / awarding body', 'certloom' ), 'plural' => __( 'Organizers / awarding bodies', 'certloom' ), 'search' => __( 'Search organizers or awarding bodies…', 'certloom' ), 'choose' => __( 'Choose organizer or awarding body…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Event / award / recognition category', 'certloom' ), 'plural' => __( 'Events / awards / recognition categories', 'certloom' ), 'search' => __( 'Search events, awards or recognition categories…', 'certloom' ), 'choose' => __( 'Choose event, award or recognition category…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Edition / round', 'certloom' ), 'plural' => __( 'Editions / rounds', 'certloom' ), 'search' => __( 'Search editions or rounds…', 'certloom' ), 'choose' => __( 'Choose edition or round…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Authorized award authority', 'certloom' ), 'plural' => __( 'Authorized award authorities', 'certloom' ), 'search' => __( 'Search authorized award authorities…', 'certloom' ), 'choose' => __( 'Choose authorized award authority…', 'certloom' ) ),
				);
			case 'product_inspection':
				return array(
					'issuer'  => array( 'singular' => __( 'Inspection / certification body', 'certloom' ), 'plural' => __( 'Inspection / certification bodies', 'certloom' ), 'search' => __( 'Search inspection or certification bodies…', 'certloom' ), 'choose' => __( 'Choose inspection or certification body…', 'certloom' ) ),
					'program' => array( 'singular' => __( 'Inspection / conformity / product scheme', 'certloom' ), 'plural' => __( 'Inspection / conformity / product schemes', 'certloom' ), 'search' => __( 'Search inspection, conformity or product schemes…', 'certloom' ), 'choose' => __( 'Choose inspection, conformity or product scheme…', 'certloom' ) ),
					'batch'   => array( 'singular' => __( 'Lot / testing batch', 'certloom' ), 'plural' => __( 'Lots / testing batches', 'certloom' ), 'search' => __( 'Search lots or testing batches…', 'certloom' ), 'choose' => __( 'Choose lot or testing batch…', 'certloom' ) ),
					'signer'  => array( 'singular' => __( 'Authorized technical authority / inspector', 'certloom' ), 'plural' => __( 'Authorized technical authorities / inspectors', 'certloom' ), 'search' => __( 'Search authorized technical authorities or inspectors…', 'certloom' ), 'choose' => __( 'Choose authorized technical authority or inspector…', 'certloom' ) ),
				);
			default:
				return $default;
		}
	}


	/**
	 * Profile-driven Manual Issuance presentation contract.
	 *
	 * This remains a UX contract only: storage and immutable credential authority
	 * stay canonical. A selected Program may add explicitly configured Credential
	 * fields, while profile-inappropriate generic helpers stay out of the form.
	 *
	 * @return array<string,mixed>
	 */
	public static function issuance_contract( $profile = '' ) {
		$engine = new PurposePresetEngine();
		$profile = '' === $profile ? self::active_profile() : $engine->normalize_profile( $profile );
		if ( ! isset( $engine->profiles()[ $profile ] ) ) { $profile = 'general_other'; }
		$terms = self::terms( $profile );
		$program = (string) ( $terms['program']['singular'] ?? __( 'Program', 'certloom' ) );
		$issuer = (string) ( $terms['issuer']['singular'] ?? __( 'Issuer', 'certloom' ) );
		$batch = (string) ( $terms['batch']['singular'] ?? __( 'Batch', 'certloom' ) );
		$signer = (string) ( $terms['signer']['singular'] ?? __( 'Signer', 'certloom' ) );
		$contract = array(
			'profile' => $profile,
			'grade_default' => false,
			'repeating_rowsets' => array(),
			'basis_prompt' => __( 'What should this credential be based on?', 'certloom' ),
			'context_title' => __( 'Additional details', 'certloom' ),
			'context_help' => __( 'Optional relationship and profile fields', 'certloom' ),
			'credential_details_program' => sprintf( /* translators: %s: profile-aware Program term. */ __( '%s field rules appear here. Add one-off credential details below when this issuance or its design needs them.', 'certloom' ), $program ),
			'credential_details_independent' => __( 'Add the credential-specific facts you want to store with this independent credential.', 'certloom' ),
			'issuer' => $issuer,
			'program' => $program,
			'batch' => $batch,
			'signer' => $signer,
		);
		switch ( $profile ) {
			case 'training_lms':
				$contract['grade_default'] = true;
				$contract['basis_prompt'] = __( 'Which course, certification or learning pathway is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Training context', 'certloom' );
				$contract['context_help'] = __( 'Optional provider, cohort/session and training-authority context', 'certloom' );
				break;
			case 'iso_management':
				$contract['basis_prompt'] = __( 'Which scheme, standard or service line is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Certification context', 'certloom' );
				$contract['context_help'] = __( 'Certification body, audit/issuance cycle and authorized authority', 'certloom' );
				break;
			case 'government_licensing':
				$contract['basis_prompt'] = __( 'Which license, permit or registration scheme is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Licensing context', 'certloom' );
				$contract['context_help'] = __( 'Regulator, application/issuance cycle and authorized officer context', 'certloom' );
				break;
			case 'school_results':
				$contract['grade_default'] = true;
				$contract['repeating_rowsets'] = array( 'results', 'subjects' );
				$contract['basis_prompt'] = __( 'Which class, course, exam or qualification is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Academic context', 'certloom' );
				$contract['context_help'] = __( 'Institution, session/semester/cohort, academic authority and result rows', 'certloom' );
				break;
			case 'corporate_compliance':
				$contract['basis_prompt'] = __( 'Which compliance, competency or internal training program is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Compliance context', 'certloom' );
				$contract['context_help'] = __( 'Organization, campaign/session and authorized compliance context', 'certloom' );
				break;
			case 'membership_association':
				$contract['basis_prompt'] = __( 'Which membership, fellowship or registration category is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Membership context', 'certloom' );
				$contract['context_help'] = __( 'Association, intake/renewal cycle and authorized officer context', 'certloom' );
				break;
			case 'events_awards':
				$contract['basis_prompt'] = __( 'Which event, award program or recognition category is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Event / award context', 'certloom' );
				$contract['context_help'] = __( 'Organizer, edition/round and authorized award authority', 'certloom' );
				break;
			case 'product_inspection':
				$contract['basis_prompt'] = __( 'Which inspection, conformity or product-certification scheme is this credential based on?', 'certloom' );
				$contract['context_title'] = __( 'Inspection context', 'certloom' );
				$contract['context_help'] = __( 'Inspection body, lot/testing batch and authorized technical authority', 'certloom' );
				break;
		}
		return $contract;
	}

	/** @return string */
	public static function term( $entity, $form = 'singular' ) {
		$terms = self::terms();
		$entity = sanitize_key( (string) $entity );
		$form = sanitize_key( (string) $form );
		return (string) ( $terms[ $entity ][ $form ] ?? ucfirst( $entity ) );
	}
}
