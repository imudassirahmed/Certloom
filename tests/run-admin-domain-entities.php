<?php
/** Phase 1C structural regression for acceptance-gated Recipient / Program administration. */
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$recipients = file_get_contents( $root . '/src/Infrastructure/Repository/RecipientRepository.php' );
$programs = file_get_contents( $root . '/src/Infrastructure/Repository/ProgramRepository.php' );
$css = file_get_contents( $root . '/admin/assets/admin.css' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$bootstrap = file_get_contents( $root . '/certloom.php' );
function ade_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
foreach ( array( $admin, $recipients, $programs, $css, $js, $bootstrap ) as $source ) { ade_assert( is_string( $source ) && '' !== $source, 'Required Phase 1C source unavailable.' ); }

$checks = 0;
function ade_check( $condition, $message ) { global $checks; ade_assert( $condition, $message ); $checks++; }

ade_check( false !== strpos( $admin, "__( 'Recipients', 'certloom' )" ), 'Recipients screen must use the premium production label.' );
ade_check( false !== strpos( $admin, "__( 'Programs', 'certloom' )" ), 'Programs screen must use the premium production label.' );
ade_check( false !== strpos( $admin, 'Acceptance preview' ) && false !== strpos( $admin, 'Safety gate active.' ), 'Acceptance-gated state must remain explicit in the preview UI.' );
ade_check( false !== strpos( $admin, "'first_name'" ) && false !== strpos( $admin, "'middle_name'" ) && false !== strpos( $admin, "'last_name'" ), 'REC-01 identity fields must be editable.' );
ade_check( false !== strpos( $admin, "'wp_user_id'" ) && false !== strpos( $admin, 'get_userdata' ), 'REC-01 WordPress-user association must be editable and validated.' );
ade_check( false !== strpos( $admin, 'recipient_custom_fields_present' ) && false !== strpos( $admin, 'parse_key_value_request' ), 'REC-01 custom fields must have a structured editor.' );
ade_check( false !== strpos( $admin, 'advanced structured custom data' ), 'Unsupported nested recipient data must be preserved rather than flattened.' );
ade_check( false !== strpos( $admin, 'recipient_history' ) && false !== strpos( $recipients, 'credential_history' ), 'REC-01 credential history must be surfaced through a read-only repository query.' );
ade_check( false !== strpos( $admin, "__( 'Description', 'certloom' )" ), 'PRG-01 description must be editable.' );
ade_check( false !== strpos( $admin, 'issuer_field' ) && false !== strpos( $admin, "name=\"issuer_id\"" ), 'PRG-01 normalized issuer relationship must be exposed.' );
ade_check( false !== strpos( $admin, 'program_metadata_present' ), 'Program metadata must have a structured editor.' );
ade_check( false !== strpos( $admin, '$data = $this->program_request();' ) && false !== strpos( $admin, 'if ( is_wp_error( $data ) )' ), 'Program create must surface request-validation errors before calling the array-typed repository.' );
ade_check( false !== strpos( $admin, 'advanced structured metadata' ), 'Unsupported nested program metadata must be preserved rather than flattened.' );
ade_check( false !== strpos( $admin, 'program_history' ) && false !== strpos( $programs, 'credential_history' ), 'Program credential activity must be visible.' );
ade_check( false !== strpos( $admin, 'program_status' ) && false !== strpos( $programs, "array( 'all', 'active', 'inactive' )" ), 'Programs need bounded active/inactive operational views.' );
ade_check( false !== strpos( $recipients, 'credential_count' ) && false !== strpos( $programs, 'credential_count' ), 'Entity lists must expose credential counts without loading full histories.' );
ade_check( false !== strpos( $recipients, 'summary()' ) && false !== strpos( $programs, 'summary()' ), 'Recipient/Program dashboards need bounded aggregate summaries.' );
ade_check( false !== strpos( $recipients, 'text_length' ) && false !== strpos( $programs, 'text_length' ), 'Repository writes must validate persisted VARCHAR limits before mutation.' );
ade_check( false !== strpos( $recipients, 'certloom_recipient_wp_user_duplicate' ), 'Duplicate WordPress-user links need an actionable error.' );
ade_check( false !== strpos( $programs, 'certloom_program_code_duplicate' ), 'Duplicate program codes need an actionable error.' );
ade_check( false !== strpos( $admin, 'custom metadata keys must be unique' ) || false !== strpos( $admin, 'Custom metadata keys must be unique.' ), 'Key/value metadata editor must reject duplicate machine keys.' );
ade_check( false !== strpos( $css, 'certloom-domain-metrics' ) && false !== strpos( $css, '@media (max-width: 600px)' ), 'Phase 1C admin must have responsive premium styling.' );
ade_check( false !== strpos( $js, 'initDomainKeyValueLists' ) && false !== strpos( $js, 'certloom-domain-remove-kv' ), 'Custom metadata rows must be keyboard/click manageable without a framework dependency.' );
ade_check( false === strpos( $admin, 'wp_remote_' ) && false === strpos( $recipients, 'wp_remote_' ) && false === strpos( $programs, 'wp_remote_' ), 'Recipient/Program administration must remain local-only.' );
ade_check( false !== strpos( $admin, 'check_admin_referer( \'certloom_domain_update_recipient_\' . $id )' ), 'Recipient updates must remain nonce protected.' );
ade_check( false !== strpos( $admin, 'check_admin_referer( \'certloom_domain_update_program_\' . $id )' ), 'Program updates must remain nonce protected.' );
ade_check( false !== strpos( $admin, 'CAP_MANAGE_RECIPIENTS' ) && false !== strpos( $admin, 'CAP_MANAGE_PROGRAMS' ), 'Recipient/Program screens must retain granular capabilities.' );
ade_check( false !== strpos( $admin, '$this->recipient_request( $existing )' ) && false !== strpos( $admin, '$this->program_request( $existing )' ), 'Updates must read existing structured state before deciding whether a reduced editor may replace it.' );
ade_check( false !== strpos( $admin, 'is_scalar_map' ), 'Reduced editors must detect structured values they cannot represent.' );
ade_check( false !== strpos( $admin, 'sanitize_key( $key ) !== $key' ) && false !== strpos( $admin, '! is_string( $item )' ), 'Reduced metadata editors must require exact string-key/string-value round trips.' );
ade_check( false !== strpos( $admin, 'cache_users( $ids )' ), 'Recipient list must prime linked WordPress users to avoid per-row user queries.' );
ade_check( false !== strpos( $bootstrap, "CERTLOOM_DOMAIN_ADMIN_PREVIEW', false" ), 'Phase 1C must not enable the production admin preview gate.' );
ade_check( false !== strpos( $bootstrap, "CERTLOOM_DOMAIN_WRITE_PATHS_READY', false" ) && false !== strpos( $bootstrap, "CERTLOOM_DOMAIN_DATA_TOOLS_READY', false" ), 'Phase 1C must not enable future-domain write/data-tool gates.' );

echo "PASS  Phase 1C Recipient/Program administration is complete behind the acceptance gate\n";
echo "PASS  structured metadata preservation, local-only behavior, capabilities and nonces remain enforced\n\n{$checks} checks, 0 failures\n";
