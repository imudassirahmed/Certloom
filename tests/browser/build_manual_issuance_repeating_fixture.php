<?php
/**
 * Browser fixture for alpha.4.79 repeating credential data.
 *
 * Reuse the real eight-step Issue Credential REST fixture and enable both
 * supported repeating rowsets so this contract cannot drift back to the
 * deleted one-page Manual Issuance implementation.
 */
ob_start();
require __DIR__ . '/build_issue_credential_479_fixture.php';
$html = ob_get_clean();
$html = str_replace(
	"repeating_rowsets:['results']",
	"repeating_rowsets:['results','subjects']",
	$html
);
echo $html;
