<?php
/**
 * Real WordPress/MySQL gate for ID-02 current-authority numbering.
 *
 * Run only on disposable/staging WordPress:
 *   wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-numbering-policy.php
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This integration test must run inside WordPress.\n" );
	exit( 1 );
}
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! defined( 'CERTLOOM_RUN_INTEGRATION_TESTS' ) ) {
	wp_die( 'Certloom integration tests require WP-CLI or CERTLOOM_RUN_INTEGRATION_TESTS.' );
}

require_once __DIR__ . '/gate-evidence.php';
certloom_wp_integration_evidence( 'numbering-policy', __FILE__ );

function certloom_numbering_it_fail( $message ) { throw new RuntimeException( $message ); }
function certloom_numbering_it_ok( $value, $message ) {
	if ( is_wp_error( $value ) ) { certloom_numbering_it_fail( $message . ': ' . $value->get_error_code() . ' — ' . $value->get_error_message() ); }
	return $value;
}
function certloom_numbering_it_assert( $condition, $message ) { if ( ! $condition ) { certloom_numbering_it_fail( $message ); } }

$settings_before = get_option( Ecapla_CertVerify_Settings::OPTION_KEY, null );
$state_before    = get_option( Ecapla_CertVerify_Numbering::STATE_OPTION, null );
$created_ids     = array();
$exit_code       = 0;

try {
	$token = strtoupper( wp_generate_password( 6, false, false ) );
	$start = random_int( 100000, 800000 );
	$settings = Ecapla_CertVerify_Settings::get_all();
	$settings['numbering_mode']    = 'sequential';
	$settings['numbering_prefix']  = 'ITN-' . $token . '-';
	$settings['numbering_suffix']  = '';
	$settings['numbering_start']   = $start;
	$settings['numbering_padding'] = 8;
	certloom_numbering_it_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Could not enable sequential numbering policy.' );
	delete_option( Ecapla_CertVerify_Numbering::STATE_OPTION );

	$collision = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::format_sequence( $start ), 'Could not format collision candidate' );
	$collision_id = certloom_numbering_it_ok(
		Ecapla_CertVerify_Database::insert( array( 'cert_number' => $collision, 'holder_name' => 'Numbering collision fixture' ) ),
		'Could not create deliberate manual-number collision'
	);
	$created_ids[] = $collision_id;

	$preview = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::preview(), 'Numbering preview failed' );
	certloom_numbering_it_assert( (int) $preview['sequence'] === $start + 1 && 1 === (int) $preview['skipped_collisions'], 'Preview did not skip the existing manual collision.' );
	certloom_numbering_it_assert( null === get_option( Ecapla_CertVerify_Numbering::STATE_OPTION, null ), 'Preview mutated durable sequence state.' );

	$first = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => 'Generated One' ) ), 'First generated credential failed' );
	$created_ids[] = (int) $first['id'];
	certloom_numbering_it_assert( (int) $first['sequence'] === $start + 1, 'First generated credential did not burn/skip the collided sequence.' );

	$second = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => 'Generated Two' ) ), 'Second generated credential failed' );
	$created_ids[] = (int) $second['id'];
	certloom_numbering_it_assert( (int) $second['sequence'] === $start + 2, 'Sequential allocator did not advance monotonically.' );
	certloom_numbering_it_assert( $first['certificate_number'] !== $second['certificate_number'], 'Generated certificate numbers collided.' );

	$raised_start = $start + 50;
	$settings['numbering_start'] = $raised_start;
	certloom_numbering_it_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Could not raise numbering floor.' );
	$raised = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => 'Generated Raised Floor' ) ), 'Raised-floor generation failed' );
	$created_ids[] = (int) $raised['id'];
	certloom_numbering_it_assert( (int) $raised['sequence'] === $raised_start, 'Raised sequence floor was not honored atomically.' );

	$settings['numbering_start'] = $start;
	certloom_numbering_it_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Could not lower configured floor for no-rewind proof.' );
	certloom_numbering_it_assert( (int) certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::current_next_sequence(), 'Could not read durable sequence state' ) === $raised_start + 1, 'Lowering configured start rewound durable sequence state.' );

	$manual_number = 'MAN-' . $token;
	$manual_id = certloom_numbering_it_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => $manual_number, 'holder_name' => 'Manual Override' ) ), 'Explicit manual-number insert failed' );
	$created_ids[] = $manual_id;
	certloom_numbering_it_assert( $manual_number === (string) certloom_numbering_it_ok( Ecapla_CertVerify_Database::get_by_id( $manual_id ), 'Manual record read failed' )['cert_number'], 'Explicit manual identifier was rewritten.' );
	certloom_numbering_it_assert( (int) certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::current_next_sequence(), 'Could not read cursor after manual insert' ) === $raised_start + 1, 'Manual identifier unexpectedly advanced automatic sequence state.' );

	$cursor_before_random = get_option( Ecapla_CertVerify_Numbering::STATE_OPTION, null );
	$settings['numbering_mode'] = 'random';
	$settings['numbering_prefix'] = 'ITR-' . $token . '-';
	certloom_numbering_it_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Could not enable Random numbering strategy.' );
	$random = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => 'Generated Random' ) ), 'Random human-number generation failed' );
	$created_ids[] = (int) $random['id'];
	certloom_numbering_it_assert( 'random' === (string) $random['mode'], 'Random insert did not report its strategy.' );
	certloom_numbering_it_assert( 1 === preg_match( '/^ITR-' . preg_quote( $token, '/' ) . '-(?:[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{4}-){4}[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{4}$/', (string) $random['certificate_number'] ), 'Random human number does not match the 100-bit Crockford contract.' );
	certloom_numbering_it_assert( $cursor_before_random === get_option( Ecapla_CertVerify_Numbering::STATE_OPTION, null ), 'Random strategy mutated sequential cursor state.' );

	$settings['numbering_mode'] = 'uuid';
	$settings['numbering_prefix'] = 'ITU-' . $token . '-';
	certloom_numbering_it_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Could not enable UUID numbering strategy.' );
	$uuid = certloom_numbering_it_ok( Ecapla_CertVerify_Numbering::insert_generated( array( 'holder_name' => 'Generated UUID' ) ), 'UUID human-number generation failed' );
	$created_ids[] = (int) $uuid['id'];
	certloom_numbering_it_assert( 'uuid' === (string) $uuid['mode'], 'UUID insert did not report its strategy.' );
	certloom_numbering_it_assert( 1 === preg_match( '/^ITU-' . preg_quote( $token, '/' ) . '-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $uuid['certificate_number'] ), 'UUID human number is not RFC-4122 v4.' );
	certloom_numbering_it_assert( $cursor_before_random === get_option( Ecapla_CertVerify_Numbering::STATE_OPTION, null ), 'UUID strategy mutated sequential cursor state.' );

	echo "PASS  real wp_options sequence allocation\n";
	echo "PASS  manual collision skip + unique legacy-table inserts\n";
	echo "PASS  preview is non-reserving\n";
	echo "PASS  raised sequence floor advances and lowering never rewinds\n";
	echo "PASS  explicit manual identifier remains independent and immutable\n";
	echo "PASS  Random 100-bit and UUID v4 human-number strategies preserve sequential state\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL  ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	if ( $created_ids ) {
		Ecapla_CertVerify_Database::delete_many( $created_ids );
	}
	if ( null === $settings_before ) { delete_option( Ecapla_CertVerify_Settings::OPTION_KEY ); }
	else { update_option( Ecapla_CertVerify_Settings::OPTION_KEY, $settings_before, false ); }
	if ( null === $state_before ) { delete_option( Ecapla_CertVerify_Numbering::STATE_OPTION ); }
	else { update_option( Ecapla_CertVerify_Numbering::STATE_OPTION, $state_before, false ); }
}
exit( $exit_code );
