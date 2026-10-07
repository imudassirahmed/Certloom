<?php
/** alpha.4.79 Phase 4 Issue Credential UX fixture: reuse the canonical REST-app fixture. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/build_issue_credential_479_fixture.php';
