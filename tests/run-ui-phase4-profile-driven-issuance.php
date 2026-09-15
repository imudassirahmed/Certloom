<?php
/** Alpha.4.72 profile-driven Manual Issuance contract. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); } }
require_once dirname( __DIR__ ) . '/src/Application/Setup/PurposePresetEngine.php';
require_once dirname( __DIR__ ) . '/src/Infrastructure/Admin/UsageProfilePresentation.php';

use Certloom\Infrastructure\Admin\UsageProfilePresentation;

$root = dirname( __DIR__ );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$checks = 0; $fail = 0;
function p472( $ok, $label ) { global $checks, $fail; $checks++; if ( $ok ) { echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

$school = UsageProfilePresentation::issuance_contract( 'school_results' );
$training = UsageProfilePresentation::issuance_contract( 'training_lms' );
$iso = UsageProfilePresentation::issuance_contract( 'iso_management' );
$government = UsageProfilePresentation::issuance_contract( 'government_licensing' );
$corporate = UsageProfilePresentation::issuance_contract( 'corporate_compliance' );
$membership = UsageProfilePresentation::issuance_contract( 'membership_association' );
$events = UsageProfilePresentation::issuance_contract( 'events_awards' );
$product = UsageProfilePresentation::issuance_contract( 'product_inspection' );
$general = UsageProfilePresentation::issuance_contract( 'general_other' );

p472( true === $school['grade_default'] && array( 'results', 'subjects' ) === $school['repeating_rowsets'], 'School / Results enables grade plus academic Subjects/Results rows' );
p472( true === $training['grade_default'] && array() === $training['repeating_rowsets'], 'Training / LMS keeps simple grade/result without transcript-style rowsets' );
p472( false === $iso['grade_default'] && array() === $iso['repeating_rowsets'], 'ISO / Management Systems hides generic grade and academic rowsets' );
p472( false === $government['grade_default'] && array() === $government['repeating_rowsets'], 'Government / Licensing hides academic result helpers' );
p472( false === $general['grade_default'] && array() === $general['repeating_rowsets'], 'General / Other starts neutral instead of exposing academic result UI' );
p472( false === $corporate['grade_default'] && array() === $corporate['repeating_rowsets'], 'Corporate / Compliance starts without academic result helpers' );
p472( false === $membership['grade_default'] && array() === $membership['repeating_rowsets'], 'Membership / Association starts without academic result helpers' );
p472( false === $events['grade_default'] && array() === $events['repeating_rowsets'], 'Events / Awards uses explicit configured facts instead of academic result helpers' );
p472( false === $product['grade_default'] && array() === $product['repeating_rowsets'], 'Product / Inspection uses explicit configured facts instead of academic result helpers' );
$corporate_terms = UsageProfilePresentation::terms( 'corporate_compliance' );
$membership_terms = UsageProfilePresentation::terms( 'membership_association' );
$events_terms = UsageProfilePresentation::terms( 'events_awards' );
$product_terms = UsageProfilePresentation::terms( 'product_inspection' );
p472( 'Compliance / competency pathway' === $corporate_terms['program']['singular'], 'Corporate / Compliance gets profile-specific relationship terminology' );
p472( 'Membership / fellowship / registration category' === $membership_terms['program']['singular'], 'Membership / Association gets profile-specific relationship terminology' );
p472( 'Event / award / recognition category' === $events_terms['program']['singular'], 'Events / Awards gets profile-specific relationship terminology' );
p472( 'Inspection / conformity / product scheme' === $product_terms['program']['singular'], 'Product / Inspection gets profile-specific relationship terminology' );
p472( 'Certification context' === $iso['context_title'] && false !== strpos( $iso['basis_prompt'], 'scheme' ), 'ISO profile supplies certification-specific compose wording' );
p472( 'Academic context' === $school['context_title'] && false !== strpos( $school['basis_prompt'], 'class' ), 'School profile supplies academic compose wording' );
p472( false !== strpos( $admin, "'usageIssuance' =>") && false !== strpos( $admin, 'issuance_contract( $usage_profile )' ), 'admin localization exposes one canonical profile-driven issuance contract' );
p472( false !== strpos( $manual, 'UsageProfilePresentation::issuance_contract( $usage_profile )' ) && false !== strpos( $manual, "data-certloom-usage-profile" ), 'Manual Issuance binds the active profile at render time' );
p472( false !== strpos( $manual, "if ( \$repeating_rowsets ) { \$this->render_repeating_data_editor") && false !== strpos( $manual, 'repeating_data_from_post( $post[\'repeating_data\'] ?? array(), $allowed_repeating )' ), 'profile-inappropriate repeating rows are neither rendered nor accepted from stale POST data' );
p472( false !== strpos( $js, 'var profileGradeDefault = !! issuanceProfile.grade_default;') && false !== strpos( $js, 'var gradeAvailable = !! gradeField || ( profileGradeDefault && ! payload.configured );'), 'browser grade visibility follows profile defaults while explicit Program grade fields can still opt in' );
p472( false !== strpos( $manual, 'These values are frozen on this credential only.' ) && false !== strpos( $manual, 'Add structured facts under Credential details when needed.' ), 'generic recipient and details-only copy remains neutral and concise across profiles' );
p472( false !== strpos( $manual, "\$profile_terms['program']['search']") && false !== strpos( $manual, "\$profile_terms['batch']['search']") && false !== strpos( $manual, "\$profile_terms['signer']['search']"), 'primary relationship labels and searches use profile-aware terminology server-side' );
p472( false !== strpos( $manual, '$this->review_fact( $program_term' ) && false !== strpos( $manual, '$this->review_fact( $signer_term' ), 'Review step preserves the active profile terminology instead of reverting to Program/Signer labels' );

echo "\n" . ( $checks - $fail ) . " alpha.4.72 profile-driven issuance checks, $fail failures\n";
exit( $fail ? 1 : 0 );
