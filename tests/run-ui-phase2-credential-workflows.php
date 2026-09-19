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
	'issue_css' => file_get_contents( $root . '/admin/assets/issue-credential.css' ),
	'issue_js' => file_get_contents( $root . '/admin/assets/issue-credential.js' ),
	'rest' => file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' ),
	'service' => file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' ),
);
$pass=0;$fail=0;
function p2($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
$m=$files['manual'];$d=$files['domain'];$b=$files['bulk'];$n=$files['nav'];$j=$files['js'];$c=$files['css'];$ic=$files['issue_css'];$ij=$files['issue_js'];$r=$files['rest'];$svc=$files['service'];
p2(false!==strpos($m,'data-credential-basis="program"')&&false!==strpos($m,'data-credential-basis="independent"')&&false!==strpos($m,"__( 'Independent credential', 'certloom' )")&&false!==strpos($m,"__( 'Effective date', 'certloom' )"),'Issue surface makes Program versus Independent credential an explicit fast-path decision');
p2(false!==strpos($m,'data-field="credential_title"')&&false!==strpos($m,'The title stays editable even when a Program supplies the default.')&&false!==strpos($ij,"state.issuance.title_source = state.issuance.program_id && value === programTitle ? 'program' : 'custom'")&&false!==strpos($ij,'data-use-program-title'),'Program title is inherited by default while the always-visible title field can directly become a one-off custom title');
p2(false!==strpos($m,'data-presentation="details_only"')&&false!==strpos($m,'data-presentation="image"')&&false!==strpos($m,'data-presentation="design"')&&false!==strpos($m,"__( 'Credential details only', 'certloom' )")&&false!==strpos($m,"__( 'Uploaded certificate image', 'certloom' )")&&false!==strpos($m,"__( 'Certloom design', 'certloom' )"),'three approved presentation modes are explicit');
p2(false!==strpos($m,'Use one PNG or JPEG image for this credential.')&&false!==strpos($ij,"library:{type:'image'}")&&false!==strpos($r,"array( 'image/png', 'image/jpeg' )"),'uploaded certificate image is restricted to PNG/JPEG in both browser and REST validation');
p2(false!==strpos($ij,"image.hidden = 'image' !== state.issuance.presentation_mode")&&false!==strpos($ij,"design.hidden = 'design' !== state.issuance.presentation_mode")&&false!==strpos($ij,"if ('design' !== state.issuance.presentation_mode) {")&&false!==strpos($ij,'state.preview = null;')&&false!==strpos($ij,'state.previewController.abort();'),'browser interaction keeps image and design modes mutually exclusive and cancels stale preview work');
p2(false!==strpos($m,'data-step-key="context"')&&false!==strpos($m,"render_async_picker( 'program'")&&false!==strpos($m,"render_async_picker( 'batch'")&&false!==strpos($m,'data-step-key="authority"')&&false!==strpos($m,"render_async_picker( 'signer'"),'Program and Batch are primary context while authority and signer choices have their own focused step');
p2(false!==strpos($m,'Additional one-off credential data')&&false!==strpos($m,'data-step-key="authority"')&&false!==strpos($m,'data-display-number-field hidden')&&false===strpos($m,'Program can mean a course, certification scheme, license type, examination or membership class'),'Issue Credential progressively discloses one-off and specialist concepts instead of crowding the main path');
p2(false!==strpos($m,'UsageProfilePresentation::active_profile()')&&false!==strpos($m,'data-program-term=')&&false!==strpos($m,'data-batch-term=')&&false!==strpos($ij,'programTerm')&&false!==strpos($ij,'batchTerm'),'active usage profile adapts visible terminology without renaming canonical storage');
p2(false!==strpos($ij,"'image' === state.issuance.presentation_mode && !state.issuance.certificate_image_id")&&false!==strpos($r,'certloom_issue_certificate_image_invalid')&&false===strpos($r,'certloom_phase2_presentation_backend_pending'),'details-only review is supported while uploaded-image review still requires a valid image');
p2(false!==strpos($svc,'certloom_manual_issue_duplicate_series')&&false!==strpos($svc,'Renewal or re-certification should continue from the existing credential')&&false!==strpos($ij,'View existing credential')&&false!==strpos($ij,'error.data.series_id'),'duplicate guidance stays actionable without a blind Issue-anyway bypass');
p2(false===strpos($ij,'Issue anyway')&&false===strpos($r,"'force' => true"),'duplicate protection is not weakened to copy PressPrimer literally');
$row_start = strpos( $d, '$primary_label = \'\' !== $credential_title' );
$row_end = false !== $row_start ? strpos( $d, "echo '<td data-col=\"' . esc_attr__( 'Recipient'", $row_start ) : false;
$row_segment = false !== $row_start && false !== $row_end ? substr( $d, $row_start, $row_end - $row_start ) : '';
p2(''!==$row_segment&&false!==strpos($row_segment,"__( 'View credential', 'certloom' )")&&false===strpos($row_segment,'↗'),'ledger prefers credential title when available and removes decorative external-link arrows');
p2(false!==strpos($d,"__( 'Review resend', 'certloom' )")&&false!==strpos($d,"'page' => 'certloom-email-delivery'")&&false!==strpos($d,"'version_id'"),'ledger adapts PressPrimer row-level resend through Certloom Email Delivery authority');
p2(false!==strpos($b,"const INTAKE_PAGE = 'certloom-file-intake'")&&false!==strpos($b,"__( 'Issue new credentials', 'certloom' )")&&false!==strpos($b,"__( 'Import existing credential records', 'certloom' )"),'file workflow has one user-facing entry with the approved first decision');
p2(false!==strpos($n,"__( 'Import / issue from file', 'certloom' )")&&false===strpos($n,"__( 'Bulk issue', 'certloom' )"),'Credentials navigation exposes the unified file task instead of Bulk issue');
p2(false!==strpos($b,'one reusable profile-aware mapper')&&false!==strpos($b,'does not pretend that backend convergence is already complete'),'file intake is honest that reusable mapper convergence belongs to the later backend phase');
p2(false!==strpos($ic,'.certloom-issue-presentation-options')&&false!==strpos($c,'.certloom-file-intake-options')&&false!==strpos($ic,'var(--cl-blue-wash)')&&false!==strpos($c,'var(--cl-'),'new Phase 2 surfaces use the Phase 1 token authority');
p2(false===strpos($c,'.certloom-presentation-choices !important')&&false===strpos($c,'.certloom-file-intake-options !important'),'Phase 2 presentation/file-intake surfaces do not introduce !important overrides');
p2(false!==strpos($ij,'initPresentation();')&&false!==strpos($ij,'renderPresentation();')&&false!==strpos($ij,'renderPreview();')&&false!==strpos($ij,'Choose a PNG or JPEG certificate image.'),'dedicated Issue Credential client boots certificate appearance and readiness interactions');
p2(false!==strpos($m,'Certloom builds this review on the server.')&&false!==strpos($r,"self::BASE . '/review'")&&false!==strpos($r,"self::BASE . '/issue'")&&false!==strpos($ij,'review_fingerprint:state.reviewFingerprint'),'review remains mutation-free and final Issue is a separate fingerprint-revalidated commit');
p2(false===strpos($d,'certloom-registry-secondary-actions')&&false!==strpos($n,"__( 'Import / issue from file', 'certloom' )"),'Credentials overview avoids duplicating the file workflow already owned by contextual navigation');
echo "\n$pass UI Phase 2 source checks, $fail failures\n"; exit($fail?1:0);
