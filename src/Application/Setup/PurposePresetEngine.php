<?php
namespace Certloom\Application\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup Wizard v2 usage-profile presets.
 *
 * Profiles are a UX/recommendation layer only. They never fork storage,
 * create hierarchy records, or become credential authority by themselves.
 */
final class PurposePresetEngine {
	/** @return array<string,array<string,string>> */
	public function profiles() {
		return array(
			'training_lms' => array(
				'label'       => __( 'Training / LMS', 'certloom' ),
				'description' => __( 'Courses, training, learning pathways and completion credentials.', 'certloom' ),
				'icon'        => 'dashicons-welcome-learn-more',
			),
			'iso_management' => array(
				'label'       => __( 'ISO / Management Systems', 'certloom' ),
				'description' => __( 'Management-system certification, schemes, standards and audit cycles.', 'certloom' ),
				'icon'        => 'dashicons-shield',
			),
			'government_licensing' => array(
				'label'       => __( 'Government / Licensing', 'certloom' ),
				'description' => __( 'Licenses, permits, registrations and regulatory credentials.', 'certloom' ),
				'icon'        => 'dashicons-building',
			),
			'school_results' => array(
				'label'       => __( 'School / Results', 'certloom' ),
				'description' => __( 'Academic results, qualifications, classes, sessions and awards.', 'certloom' ),
				'icon'        => 'dashicons-welcome-learn-more',
			),
			'corporate_compliance' => array(
				'label'       => __( 'Corporate / Compliance', 'certloom' ),
				'description' => __( 'Internal training, competency, HR and compliance credentials.', 'certloom' ),
				'icon'        => 'dashicons-portfolio',
			),
			'membership_association' => array(
				'label'       => __( 'Membership / Association', 'certloom' ),
				'description' => __( 'Membership classes, registrations, fellowships and professional credentials.', 'certloom' ),
				'icon'        => 'dashicons-groups',
			),
			'events_awards' => array(
				'label'       => __( 'Events / Awards', 'certloom' ),
				'description' => __( 'Participation, recognition, competitions and award programs.', 'certloom' ),
				'icon'        => 'dashicons-awards',
			),
			'product_inspection' => array(
				'label'       => __( 'Product / Inspection', 'certloom' ),
				'description' => __( 'Inspection, conformity, testing, lots and product certification.', 'certloom' ),
				'icon'        => 'dashicons-clipboard',
			),
			'general_other' => array(
				'label'       => __( 'General / Other', 'certloom' ),
				'description' => __( 'Start with a neutral credential setup and customize it as you go.', 'certloom' ),
				'icon'        => 'dashicons-admin-generic',
			),
		);
	}

	/** Compatibility alias used by older setup fixtures/integrations. @return array<string,string> */
	public function purposes() {
		$out = array();
		foreach ( $this->profiles() as $key => $profile ) {
			$out[ $key ] = $profile['label'];
		}
		return $out;
	}

	/** @return array<string,array<string,string>> */
	public function subcategories() {
		// Setup Wizard v2 intentionally removed the old required subcategory step.
		return array();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function recommend( $profile, $legacy_subcategory = null ) {
		$profile = $this->normalize_profile( $profile );
		$profiles = $this->profiles();
		if ( ! isset( $profiles[ $profile ] ) ) {
			return new \WP_Error( 'certloom_setup_profile_invalid', __( 'Choose what you are using Certloom for.', 'certloom' ) );
		}

		$base = array(
			'profile'             => $profile,
			'profile_label'       => $profiles[ $profile ]['label'],
			'profile_description' => $profiles[ $profile ]['description'],
			'icon'                => $profiles[ $profile ]['icon'],
			'recommended_fields'  => array(),
			'hierarchy'           => array(),
			'checklist'           => array(),
			'recommendation_only' => true,
			'notice'              => __( 'These are editable starting points. They do not create separate product modes or change credential authority.', 'certloom' ),
		);

		switch ( $profile ) {
			case 'training_lms':
				$base['recommended_fields'] = array(
					array( 'key' => 'completion_date', 'label' => __( 'Completion date', 'certloom' ) ),
					array( 'key' => 'grade', 'label' => __( 'Grade / result', 'certloom' ) ),
					array( 'key' => 'score', 'label' => __( 'Score', 'certloom' ) ),
					array( 'key' => 'training_hours', 'label' => __( 'Training hours', 'certloom' ) ),
					array( 'key' => 'expiry', 'label' => __( 'Expiry / renewal', 'certloom' ) ),
					array( 'key' => 'instructor', 'label' => __( 'Instructor', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'training provider, academy, university, employer or certification organization', 'certloom' ),
					'program' => __( 'course, certification program, qualification or learning pathway', 'certloom' ),
					'batch' => __( 'cohort, class, intake, training session, term or run of the Program', 'certloom' ),
					'signer' => __( 'trainer, instructor, examiner or authorized signatory', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Create a Program', 'certloom' ), __( 'Connect your LMS', 'certloom' ), __( 'Set up email delivery', 'certloom' ), __( 'Configure renewals', 'certloom' ) );
				break;
			case 'iso_management':
				$base['recommended_fields'] = array(
					array( 'key' => 'standard', 'label' => __( 'Standard', 'certloom' ) ),
					array( 'key' => 'scope', 'label' => __( 'Scope', 'certloom' ) ),
					array( 'key' => 'certificate_number', 'label' => __( 'Certificate number', 'certloom' ) ),
					array( 'key' => 'issue_date', 'label' => __( 'Issue date', 'certloom' ) ),
					array( 'key' => 'expiry', 'label' => __( 'Expiry', 'certloom' ) ),
					array( 'key' => 'site', 'label' => __( 'Site / location', 'certloom' ) ),
					array( 'key' => 'accreditation_reference', 'label' => __( 'Accreditation reference', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'certification body or conformity-assessment organization', 'certloom' ),
					'program' => __( 'certification scheme, management-system standard or service line', 'certloom' ),
					'batch' => __( 'audit cycle, issuance cycle, certification round or managed group', 'certloom' ),
					'signer' => __( 'authorized certification-body signatory, lead auditor or technical authority', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Add certification schemes as Programs', 'certloom' ), __( 'Add certification-body Signers', 'certloom' ), __( 'Configure Standard / Scope fields', 'certloom' ), __( 'Import existing certifications', 'certloom' ) );
				break;
			case 'government_licensing':
				$base['recommended_fields'] = array(
					array( 'key' => 'license_type', 'label' => __( 'License / permit type', 'certloom' ) ),
					array( 'key' => 'registration_number', 'label' => __( 'Registration / license number', 'certloom' ) ),
					array( 'key' => 'jurisdiction', 'label' => __( 'Jurisdiction', 'certloom' ) ),
					array( 'key' => 'legal_reference', 'label' => __( 'Legal basis / reference', 'certloom' ) ),
					array( 'key' => 'issue_date', 'label' => __( 'Issue date', 'certloom' ) ),
					array( 'key' => 'valid_until', 'label' => __( 'Valid until', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'ministry, department, regulator, authority, municipality or agency', 'certloom' ),
					'program' => __( 'license, permit, registration, grant or regulatory scheme', 'certloom' ),
					'batch' => __( 'application round, fiscal period, issuance cycle or processing group', 'certloom' ),
					'signer' => __( 'authorized officer, registrar, director, inspector or government signatory', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Add license or permit schemes as Programs', 'certloom' ), __( 'Import existing registrations', 'certloom' ), __( 'Configure authority Signers', 'certloom' ), __( 'Set up automation or API access', 'certloom' ) );
				break;
			case 'school_results':
				$base['recommended_fields'] = array(
					array( 'key' => 'roll_number', 'label' => __( 'Roll number', 'certloom' ) ),
					array( 'key' => 'class', 'label' => __( 'Class', 'certloom' ) ),
					array( 'key' => 'session', 'label' => __( 'Session', 'certloom' ) ),
					array( 'key' => 'grade', 'label' => __( 'Grade', 'certloom' ) ),
					array( 'key' => 'result', 'label' => __( 'Result', 'certloom' ) ),
					array( 'key' => 'marks_percentage', 'label' => __( 'Marks / percentage', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'school, university, board, examination body or awarding institution', 'certloom' ),
					'program' => __( 'class, course, qualification, examination, degree or result scheme', 'certloom' ),
					'batch' => __( 'academic session, semester, exam session, section or cohort', 'certloom' ),
					'signer' => __( 'principal, registrar, controller of examinations, dean or authorized academic signatory', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Import student results', 'certloom' ), __( 'Create class or exam Programs', 'certloom' ), __( 'Set academic-session Batches', 'certloom' ), __( 'Configure result fields', 'certloom' ) );
				break;
			case 'corporate_compliance':
				$base['recommended_fields'] = array(
					array( 'key' => 'completion_date', 'label' => __( 'Completion date', 'certloom' ) ),
					array( 'key' => 'department', 'label' => __( 'Department', 'certloom' ) ),
					array( 'key' => 'competency', 'label' => __( 'Competency / requirement', 'certloom' ) ),
					array( 'key' => 'expiry', 'label' => __( 'Expiry / renewal', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'employer, training department, compliance team or approved provider', 'certloom' ),
					'program' => __( 'compliance program, competency program, internal training or policy requirement', 'certloom' ),
					'batch' => __( 'department cohort, training session, campaign or renewal cycle', 'certloom' ),
					'signer' => __( 'manager, trainer, compliance officer, HR authority or department head', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Create compliance Programs', 'certloom' ), __( 'Set up employee imports', 'certloom' ), __( 'Configure renewals', 'certloom' ), __( 'Connect HR or LMS automation', 'certloom' ) );
				break;
			case 'membership_association':
				$base['recommended_fields'] = array(
					array( 'key' => 'membership_number', 'label' => __( 'Membership / registration number', 'certloom' ) ),
					array( 'key' => 'membership_class', 'label' => __( 'Membership class', 'certloom' ) ),
					array( 'key' => 'admission_date', 'label' => __( 'Admission date', 'certloom' ) ),
					array( 'key' => 'valid_until', 'label' => __( 'Valid until', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'professional body, association, chamber, institute or council', 'certloom' ),
					'program' => __( 'membership class, certification scheme, fellowship or registration category', 'certloom' ),
					'batch' => __( 'intake, membership year, admission round or renewal cycle', 'certloom' ),
					'signer' => __( 'authorized officer, registrar, chair or professional-body signatory', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Create membership Programs', 'certloom' ), __( 'Import current members', 'certloom' ), __( 'Configure renewal dates', 'certloom' ), __( 'Set up member email delivery', 'certloom' ) );
				break;
			case 'events_awards':
				$base['recommended_fields'] = array(
					array( 'key' => 'event', 'label' => __( 'Event / award', 'certloom' ) ),
					array( 'key' => 'category', 'label' => __( 'Category', 'certloom' ) ),
					array( 'key' => 'event_date', 'label' => __( 'Event date', 'certloom' ) ),
					array( 'key' => 'result', 'label' => __( 'Result / recognition', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'event organizer, institution, company, award body or association', 'certloom' ),
					'program' => __( 'event, award program, competition or recognition category', 'certloom' ),
					'batch' => __( 'event edition, year, season, ceremony, round or participant cohort', 'certloom' ),
					'signer' => __( 'organizer, jury chair, authority or authorized signatory', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Create event or award Programs', 'certloom' ), __( 'Import participants or winners', 'certloom' ), __( 'Configure sharing', 'certloom' ), __( 'Set up event-triggered issuance', 'certloom' ) );
				break;
			case 'product_inspection':
				$base['recommended_fields'] = array(
					array( 'key' => 'product', 'label' => __( 'Product / item', 'certloom' ) ),
					array( 'key' => 'standard', 'label' => __( 'Standard / specification', 'certloom' ) ),
					array( 'key' => 'lot_number', 'label' => __( 'Lot / batch number', 'certloom' ) ),
					array( 'key' => 'inspection_date', 'label' => __( 'Inspection / test date', 'certloom' ) ),
					array( 'key' => 'result', 'label' => __( 'Result', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'laboratory, inspection body, certifier, manufacturer or regulator', 'certloom' ),
					'program' => __( 'conformity scheme, inspection program, product category or standard', 'certloom' ),
					'batch' => __( 'production lot, inspection batch, testing batch or certification round', 'certloom' ),
					'signer' => __( 'authorized inspector, technical authority or certifier', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Create inspection or conformity Programs', 'certloom' ), __( 'Configure product fields', 'certloom' ), __( 'Import existing records', 'certloom' ), __( 'Set up API or automation', 'certloom' ) );
				break;
			case 'general_other':
			default:
				$base['recommended_fields'] = array(
					array( 'key' => 'credential_title', 'label' => __( 'Credential title', 'certloom' ) ),
					array( 'key' => 'issue_date', 'label' => __( 'Issue date', 'certloom' ) ),
					array( 'key' => 'expiry', 'label' => __( 'Expiry', 'certloom' ) ),
					array( 'key' => 'credential_number', 'label' => __( 'Credential number', 'certloom' ) ),
					array( 'key' => 'status', 'label' => __( 'Status', 'certloom' ) ),
				);
				$base['hierarchy'] = array(
					'issuer' => __( 'organization or authority issuing the credential', 'certloom' ),
					'program' => __( 'repeatable credential scheme or category when you need one', 'certloom' ),
					'batch' => __( 'optional group, cycle, session or processing set', 'certloom' ),
					'signer' => __( 'optional authorized signatory', 'certloom' ),
				);
				$base['checklist'] = array( __( 'Review your credential fields', 'certloom' ), __( 'Choose a reusable design if needed', 'certloom' ), __( 'Set up email delivery', 'certloom' ), __( 'Connect integrations when useful', 'certloom' ) );
				break;
		}
		$bundle = ( new ProfileStarterBundle() )->get( $profile );
		$base['starter_bundle'] = $bundle;
		if ( is_array( $bundle ) ) {
			$semantics = array_column( $bundle['fields'], null, 'key' );
			foreach ( $base['recommended_fields'] as &$field ) {
				if ( isset( $semantics[ $field['key'] ] ) ) { $field['type'] = $semantics[ $field['key'] ]['type']; }
			}
			unset( $field );
		}
		return $base;
	}

	/** Completed setup keeps profile advice useful without repeating a saved Program task. @return array<int,string> */
	public function completion_checklist( $profile, array $draft ) {
		$profile = $this->normalize_profile( $profile );
		$recommendation = $this->recommend( $profile );
		if ( is_wp_error( $recommendation ) ) { return array(); }
		$checklist = $recommendation['checklist'];
		if ( absint( $draft['setup_program_id'] ?? 0 ) ) {
			$program_tasks = array( 'training_lms' => 0, 'iso_management' => 0, 'government_licensing' => 0, 'school_results' => 1, 'corporate_compliance' => 0, 'membership_association' => 0, 'events_awards' => 0, 'product_inspection' => 0 );
			if ( isset( $program_tasks[ $profile ] ) ) {
				$checklist[ $program_tasks[ $profile ] ] = __( 'Review Program requirements and customer fields', 'certloom' );
			}
		}
		return $checklist;
	}

	/** @return array<string,string> */
	public function reference_examples( $profile ) {
		return 'iso_management' === $this->normalize_profile( $profile ) ? array(
			'iso_9001'  => 'ISO 9001',
			'iso_14001' => 'ISO 14001',
			'iso_27001' => 'ISO/IEC 27001',
			'iso_45001' => 'ISO 45001',
		) : array();
	}

	public function normalize_profile( $profile ) {
		$profile = sanitize_key( (string) $profile );
		$legacy = array(
			'training_courses' => 'training_lms',
			'certification_standards' => 'iso_management',
			'academic_credentials' => 'school_results',
			'professional_license_membership' => 'membership_association',
			'awards_recognition' => 'events_awards',
			'events_participation' => 'events_awards',
			'custom_other' => 'general_other',
		);
		return isset( $legacy[ $profile ] ) ? $legacy[ $profile ] : $profile;
	}
}
