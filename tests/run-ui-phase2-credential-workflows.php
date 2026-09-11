<?php
/** Certloom 1.7 UI Phase 2 credential-workflow source contract. */
$root = dirname( __DIR__ );
$files = array(
	'manual' => file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' ),
	'domain' => file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' ),
	'bulk' => file_get_contents( $root . '/src/Infrastructure/Admin/BulkIssuanceAdmin.php' ),
	'nav' => file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' ),
	'js' => file_get_contents( $root . '/admin/assets/admin.js' ),
	'css' => file_get_contents( $root . '/admin/assets/certloom-foundation.css' ),
);
$pass=0;$fail=0;
function p2($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
$m=$files['manual'];$d=$files['domain'];$b=$files['bulk'];$n=$files['nav'];$j=$files['js'];$c=$files['css'];
p2(false!==strpos($m,"__( 'Credential title', 'certloom' )")&&false!==strpos($m,"__( 'Issue date', 'certloom' )")&&false!==strpos($m,'data-certloom-recipient-compose'),'Issue surface locks Recipient + credential title + issue date as the visible core');
p2(false!==strpos($m,"'details_only'")&&false!==strpos($m,"'image'")&&false!==strpos($m,"'design'")&&false!==strpos($m,"__( 'Details only', 'certloom' )")&&false!==strpos($m,"__( 'Upload certificate image', 'certloom' )")&&false!==strpos($m,"__( 'Use a Certloom design', 'certloom' )"),'three approved presentation modes are explicit');
p2(false!==strpos($m,'data-certloom-media-types="image/png,image/jpeg"')&&false!==strpos($j,'data-certloom-media-types'),'uploaded certificate image is restricted to PNG/JPEG in both markup and media interaction');
p2(false!==strpos($j,"template.disabled = 'design' !== mode")&&false!==strpos($j,"imageId.value = ''")&&false!==strpos($j,'data-certloom-presentation-panel'),'browser interaction prevents image and design from competing');
p2(false!==strpos($m,"__( 'Additional details', 'certloom' )")&&false!==strpos($m,"__( 'Issuer (optional)', 'certloom' )")&&false!==strpos($m,"__( 'Program (optional)', 'certloom' )")&&false!==strpos($m,"__( 'Batch (optional)', 'certloom' )")&&false!==strpos($m,"__( 'Signer (optional)', 'certloom' )"),'hierarchy is progressive Additional details rather than the required fast path');
p2(false!==strpos($m,'Program can mean a course, certification scheme, license type, examination or membership class')&&false!==strpos($m,'Batch can mean a cohort, audit cycle, issuance round or academic session'),'context helper makes cross-profile Program/Batch semantics explicit');
p2(false!==strpos($m,"__( 'Training / LMS', 'certloom' )")&&false!==strpos($m,"__( 'ISO / Management Systems', 'certloom' )")&&false!==strpos($m,"__( 'Government / Licensing', 'certloom' )")&&false!==strpos($m,"__( 'School / Results', 'certloom' )")&&false!==strpos($m,'Issuer = certification body; Program = scheme/standard/service line'),'compact profile disclosure explicitly maps canonical entities without renaming them');
p2(false!==strpos($m,'phase2_backend_compatibility_error')&&false!==strpos($m,'certloom_phase2_presentation_backend_pending')&&false===strpos($m,'certloom_phase2_standalone_backend_pending'),'unsupported presentation paths still fail visibly while standalone Program-optional design issuance is no longer falsely blocked');
p2(false!==strpos($m,'certloom_manual_issue_duplicate_series')&&false!==strpos($m,"__( 'View existing credential', 'certloom' )")&&false!==strpos($m,'Issue another from Renew previous credential'),'duplicate guidance adapts the PressPrimer idea without a blind Issue-anyway bypass');
p2(false===strpos($m,"__( 'Issue anyway', 'certloom' )")&&false===strpos($m,"'force' => true"),'duplicate protection is not weakened to copy PressPrimer literally');
$row_start = strpos( $d, '$primary_label = \'\' !== $credential_title' );
$row_end = false !== $row_start ? strpos( $d, "echo '<td data-col=\"' . esc_attr__( 'Recipient'", $row_start ) : false;
$row_segment = false !== $row_start && false !== $row_end ? substr( $d, $row_start, $row_end - $row_start ) : '';
p2(''!==$row_segment&&false!==strpos($row_segment,"__( 'View credential', 'certloom' )")&&false===strpos($row_segment,'↗'),'ledger prefers credential title when available and removes decorative external-link arrows');
p2(false!==strpos($d,"__( 'Review resend', 'certloom' )")&&false!==strpos($d,"'page' => 'certloom-email-delivery'")&&false!==strpos($d,"'version_id'"),'ledger adapts PressPrimer row-level resend through Certloom Email Delivery authority');
p2(false!==strpos($b,"const INTAKE_PAGE = 'certloom-file-intake'")&&false!==strpos($b,"__( 'Issue new credentials', 'certloom' )")&&false!==strpos($b,"__( 'Import existing credential records', 'certloom' )"),'file workflow has one user-facing entry with the approved first decision');
p2(false!==strpos($n,"__( 'Import / issue from file', 'certloom' )")&&false===strpos($n,"__( 'Bulk issue', 'certloom' )"),'Credentials navigation exposes the unified file task instead of Bulk issue');
p2(false!==strpos($b,'one reusable profile-aware mapper')&&false!==strpos($b,'does not pretend that backend convergence is already complete'),'file intake is honest that reusable mapper convergence belongs to the later backend phase');
p2(false!==strpos($c,'.certloom-presentation-choices')&&false!==strpos($c,'.certloom-file-intake-options')&&false!==strpos($c,'var(--cl-blue-wash)'),'new Phase 2 surfaces use the Phase 1 token authority');
p2(false===strpos($c,'.certloom-presentation-choices !important')&&false===strpos($c,'.certloom-file-intake-options !important'),'Phase 2 presentation/file-intake surfaces do not introduce !important overrides');
p2(false!==strpos($j,'initPhase2Issuance();')&&false!==strpos($j,'phase2DetailsPending')&&false!==strpos($j,'phase2ChooseDesign'),'Phase 2 interaction initializer is actually booted and explains compatibility state');
p2(false!==strpos($m,'Review is read-only and never reserves a credential number.'),'review remains mutation-free and explicit');
p2(false===strpos($d,'certloom-registry-secondary-actions')&&false!==strpos($n,"__( 'Import / issue from file', 'certloom' )"),'Credentials overview avoids duplicating the file workflow already owned by contextual navigation');
echo "\n$pass UI Phase 2 source checks, $fail failures\n"; exit($fail?1:0);
