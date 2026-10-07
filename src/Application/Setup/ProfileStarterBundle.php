<?php
namespace Certloom\Application\Setup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Versioned recommendations and synthetic examples, never a field/value authority. */
final class ProfileStarterBundle {
	const VERSION = 1;

	/** Factory English is stable across site locales; translate labels when presenting guidance. */
	public function all() {
		return array(
			'training_lms' => $this->bundle( 'Learning', 'Training completion', 'training_courses', '#2563EB', 'modern', 'blue', 'Northern Learning Institute', 'Alexandria Noor Rahman', 'Digital Marketing', array(
				$this->field( 'completion_date', 'Completion date', 'date', 'achievement', 'staff', '2026-09-07' ),
				$this->field( 'training_hours', 'Training hours', 'number', 'program', 'staff', '40' ),
				$this->field( 'score', 'Assessment score', 'number', 'achievement', 'staff', '92' ),
			) ),
			'iso_management' => $this->bundle( 'Assurance', 'Management system certification', 'certification_standards', '#A77B3B', 'formal', 'navy_gold', 'Example Certification Body', 'Apex Precision Systems', 'Quality Management', array(
				$this->field( 'standard', 'Standard', 'text', 'program', 'staff', 'ISO 9001:2015' ),
				$this->field( 'scope', 'Certification scope', 'textarea', 'achievement', 'staff', 'Design and manufacture of precision components' ),
				$this->field( 'site', 'Certified site', 'text', 'achievement', 'staff', 'Example industrial site' ),
			) ),
			'government_licensing' => $this->bundle( 'Civic', 'License or permit', 'professional_license_membership', '#0F766E', 'formal', 'blue_teal', 'Example Licensing Authority', 'Alexandria Noor Rahman', 'Professional Registration', array(
				$this->field( 'license_type', 'License type', 'text', 'program', 'staff', 'Professional practice' ),
				$this->field( 'registration_number', 'Authority registration', 'text', 'achievement', 'staff', 'EXAMPLE-REG-0042' ),
				$this->field( 'jurisdiction', 'Jurisdiction', 'text', 'program', 'staff', 'Example jurisdiction' ),
			) ),
			'school_results' => $this->bundle( 'Academic', 'Academic achievement', 'academic_credentials', '#9F3654', 'academic', 'burgundy_gold', 'Northbridge School and College', 'Sara Ahmed Khan', 'Higher Secondary Examination', array(
				$this->field( 'roll_number', 'Student roll number', 'text', 'registration', 'recipient', '000142' ),
				$this->field( 'session', 'Academic session', 'text', 'program', 'staff', '2025–2026' ),
				$this->field( 'result', 'Examination result', 'text', 'achievement', 'staff', 'Distinction' ),
			) ),
			'corporate_compliance' => $this->bundle( 'Workplace', 'Competency and compliance', 'training_courses', '#315D6C', 'corporate', 'blue_teal', 'Example Employer', 'Alexandria Noor Rahman', 'Workplace Safety', array(
				$this->field( 'department', 'Department', 'text', 'achievement', 'staff', 'Operations' ),
				$this->field( 'competency', 'Competency', 'text', 'program', 'staff', 'Safe workplace practices' ),
				$this->field( 'completion_date', 'Completion date', 'date', 'achievement', 'staff', '2026-09-07' ),
			) ),
			'membership_association' => $this->bundle( 'Membership', 'Association membership', 'professional_license_membership', '#7C5A31', 'elegant', 'navy_gold', 'Example Professional Association', 'Alexandria Noor Rahman', 'Professional Membership', array(
				$this->field( 'membership_number', 'Association member number', 'text', 'achievement', 'staff', 'EXAMPLE-MEM-0042' ),
				$this->field( 'membership_class', 'Membership class', 'text', 'program', 'staff', 'Associate' ),
				$this->field( 'admission_date', 'Admission date', 'date', 'achievement', 'staff', '2026-09-07' ),
			) ),
			'events_awards' => $this->bundle( 'Recognition', 'Participation and recognition', 'events_participation', '#8B5CF6', 'elegant', 'neutral_accent', 'Example Event Organizer', 'Alexandria Noor Rahman', 'Community Innovation Forum', array(
				$this->field( 'event', 'Event', 'text', 'program', 'staff', 'Community Innovation Forum' ),
				$this->field( 'category', 'Recognition category', 'text', 'achievement', 'staff', 'Participant' ),
				$this->field( 'event_date', 'Event date', 'date', 'program', 'staff', '2026-09-07' ),
			) ),
			'product_inspection' => $this->bundle( 'Inspection', 'Inspection record', 'certification_standards', '#475569', 'corporate', 'neutral_accent', 'Example Inspection Laboratory', 'Example Manufacturing Company', 'Product Conformity Inspection', array(
				$this->field( 'product', 'Product or item', 'text', 'achievement', 'staff', 'Precision component' ),
				$this->field( 'lot_number', 'Lot number', 'text', 'achievement', 'staff', 'EXAMPLE-LOT-0042' ),
				$this->field( 'inspection_date', 'Inspection date', 'date', 'achievement', 'staff', '2026-09-07' ),
			) ),
			'general_other' => $this->bundle( 'Essential', 'Certificate of achievement', 'custom_other', '#6366F1', 'minimal', 'neutral_accent', 'Example Issuing Organization', 'Alexandria Noor Rahman', 'Professional Achievement', array() ),
		);
	}

	public function get( $profile ) {
		$key = ( new PurposePresetEngine() )->normalize_profile( $profile );
		return $this->all()[ $key ] ?? null;
	}

	/** Only synthetic scalar values; no registration, evidence, defaults or credential records are read. */
	public function sample_values( $profile ) {
		$bundle = $this->get( $profile );
		if ( null === $bundle ) { return array(); }
		$values = array( 'recipient.name' => $bundle['example']['recipient'], 'issuer.name' => $bundle['example']['issuer'], 'program.name' => $bundle['example']['program'], 'program.title' => $bundle['example']['program'], 'credential.title' => $bundle['example']['program'], 'credential.issue_date' => '8 September 2026' );
		foreach ( $bundle['fields'] as $field ) { $values[ $field['token'] ] = $field['sample']; }
		return $values;
	}

	private function bundle( $name, $title, $purpose, $accent, $style, $color, $issuer, $recipient, $program, array $fields ) {
		return array( 'version' => self::VERSION, 'name' => $name, 'title' => $title, 'purpose' => $purpose, 'accent' => $accent, 'style' => $style, 'color' => $color, 'fields' => $fields, 'example' => array( 'issuer' => $issuer, 'recipient' => $recipient, 'program' => $program ) );
	}
	private function field( $key, $label, $type, $lifetime, $writer, $sample ) {
		return array( 'key' => $key, 'label' => $label, 'type' => $type, 'lifetime' => $lifetime, 'writer' => $writer, 'required' => false, 'show_on_certificate' => false, 'token' => 'credential.custom.' . $key, 'sample' => $sample );
	}
}
