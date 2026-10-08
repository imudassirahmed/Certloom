<?php
/** Organization configuration and contextual customer prompts keep field ownership intact. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
use Certloom\Application\Fields\FieldCollectionForm;
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Recipient\RecipientCollectionFields;
use Certloom\Application\Recipient\RecipientFieldEditValidator;
$passed = 0; $failed = 0;
function rcf_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$base = array( 'key' => 'father_name', 'label' => 'Father’s name', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private' );
$form = array( 'writer' => 'recipient', 'lifetime' => 'recipient', 'collect_when' => 'registration', 'approval_required' => '1', 'help' => 'Needed for enrollment.' );
$forms = new FieldCollectionForm(); $policy = $forms->normalize( $form, $base );
rcf_check( ! is_wp_error( $policy ) && 'recipient' === $policy['lifetime'] && 'registration' === $policy['collect_when'] && $policy['approval_required'] && ! $policy['show_on_certificate'], 'organization can request reusable private details at enrollment with approval' );
foreach ( array( array_merge( $form, array( 'writer' => array( 'recipient' ) ) ), array_merge( $form, array( 'approval_required' => 'yes' ) ), array_merge( $form, array( 'extra_authority' => '1' ) ), array_merge( $form, array( 'lifetime' => 'registration', 'collect_when' => 'profile' ) ), array_merge( $form, array( 'show_on_certificate' => '1' ) ) ) as $bad ) {
	rcf_check( is_wp_error( $forms->normalize( $bad, $base ) ), 'invalid choices or private artwork cannot grant collection or exposure authority' );
}
$score = $base; $score['key'] = 'score'; $score['type'] = 'number';
rcf_check( is_wp_error( $forms->normalize( $form, $score ) ), 'organization cannot let a customer supply a trusted score' );
$identity = $base; $identity['key'] = 'email';
rcf_check( is_wp_error( $forms->normalize( $form, $identity ) ), 'core identity remains with its canonical source' );
$staff = $forms->normalize( array_merge( $form, array( 'writer' => 'source', 'lifetime' => 'achievement' ) ), $score );
rcf_check( ! is_wp_error( $staff ) && 'never' === $staff['collect_when'] && ! $staff['approval_required'], 'source results are excluded from customer collection' );
rcf_check( null === $forms->normalize( array_merge( $form, array( 'writer' => 'legacy' ) ), $base ), 'legacy source is preserved unless customer collection is explicitly enabled' );
$father = $base; $father['collection_policy'] = $policy;
$personal = $father; $personal['key'] = 'department'; $personal['collection_policy']['collect_when'] = 'profile';
$enrollment = $father; $enrollment['key'] = 'enrollment_id'; $enrollment['collection_policy']['lifetime'] = 'registration';
$deferred = $father; $deferred['key'] = 'cnic'; $deferred['collection_policy']['collect_when'] = 'before_issue';
$source = $score; $source['collection_policy'] = $staff;
$context = array( 'catalog' => array( 'father_name' => $father, 'department' => $personal, 'enrollment_id' => $enrollment, 'cnic' => $deferred, 'score' => $source, 'legacy_detail' => $base ), 'contract' => array( 'fields' => array( array( 'key' => 'father_name' ), array( 'key' => 'enrollment_id' ), array( 'key' => 'cnic' ), array( 'key' => 'score' ) ) ) );
$selection = new RecipientCollectionFields();
rcf_check( array( 'department' ) === array_keys( $selection->select( $context, 'recipient' ) ), 'ordinary profiles request only fields configured for the account' );
$signup_context = $context; $signup_context['catalog']['department']['collection_policy']['collect_when'] = 'signup';
rcf_check( array( 'department' ) === array_keys( $selection->select( $signup_context, 'recipient' ) ), 'personal website signup answers remain correctable in the customer profile without granting enrollment or result access' );
$request_context = $context; $request_context['request_id'] = 1;
rcf_check( array( 'father_name', 'cnic' ) === array_keys( $selection->select( $request_context, 'recipient' ) ), 'an owned waiting request collects applicable reusable details at registration or before issuance without an unrelated profile field' );
$program = $selection->select( $context, 'registration' );
rcf_check( array( 'father_name', 'enrollment_id', 'cnic' ) === array_keys( $program ), 'Program forms request applicable personal and enrollment details and exclude source results' );
rcf_check( array( 'father_name', 'department', 'cnic' ) === array_keys( $selection->select( $context, 'recipient', true ) ), 'staff may decide a personal submission collected during enrollment or before issuance' );
rcf_check( array( 'enrollment_id' ) === array_keys( $selection->select( $context, 'registration', true ) ), 'staff registration decisions cannot write into the reusable personal store' );
$validator = new RecipientFieldEditValidator();
$patch = $validator->validate_form( array( 'father_name' => 'QA Parent', 'enrollment_id' => '00001', 'cnic' => '000-001' ), $program, 'registration' );
rcf_check( array( 'recipient' => array( 'father_name' => 'QA Parent', 'cnic' => '000-001' ), 'registration' => array( 'enrollment_id' => '00001' ) ) === $patch, 'one Program form partitions typed values by lifetime without losing identifier zeros' );
rcf_check( is_wp_error( $validator->validate_form( array( 'department' => 'out-of-context' ), $program, 'registration' ) ), 'a Program patch cannot smuggle an unrelated account field' );
rcf_check( is_wp_error( $validator->validate_form( array( 'enrollment_id' => '00001' ), $program, 'recipient' ) ), 'an unscoped profile patch cannot edit an enrollment' );
rcf_check( is_wp_error( $validator->validate_form( array( 'cnic' => array( 'value' => '000-001' ) ), $program, 'registration' ) ), 'partitioning does not bypass scalar validation' );
$broken = $context; $broken['catalog']['father_name']['collection_policy']['writer'] = 'invented';
rcf_check( is_wp_error( $selection->select( $broken, 'registration' ) ), 'a malformed current policy fails closed rather than enabling a customer input' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
