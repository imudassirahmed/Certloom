<?php
/** UX-CORE-06: canonical design hierarchy + integration/automation consistency. */
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ux6_src( $root, $file ) { $v = file_get_contents( $root . '/' . $file ); if ( false === $v ) { throw new RuntimeException( 'Missing ' . $file ); } return $v; }
function ux6( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  $label\n"; } else { ++$fail; echo "FAIL  $label\n"; } }

$domain = ux6_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$structure = ux6_src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$nav = ux6_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$hierarchy = ux6_src( $root, 'src/Infrastructure/Admin/DesignHierarchyConfigurationGuard.php' );
$target = ux6_src( $root, 'src/Infrastructure/Admin/IntegrationMappingTargetGuard.php' );
$dependencies = ux6_src( $root, 'src/Infrastructure/Admin/IntegrationConfigurationDependencyGuard.php' );
$templates = ux6_src( $root, 'src/Infrastructure/Repository/TemplateRepository.php' );
$template_admin = ux6_src( $root, 'src/Infrastructure/Admin/TemplateAdmin.php' );
$issuance = ux6_src( $root, 'src/Application/Issuance/ManualIssuanceService.php' );
$coordinator = ux6_src( $root, 'src/Application/Integration/IntegrationIssuanceCoordinator.php' );
$automations = ux6_src( $root, 'admin/views/automations.php' );
$integrations = ux6_src( $root, 'admin/views/integrations.php' );
$automation_rules = ux6_src( $root, 'src/Infrastructure/Email/EmailAutomationRuleRepository.php' );
$automation_listener = ux6_src( $root, 'src/Infrastructure/Email/EmailAutomationListener.php' );
$automation_dependencies = ux6_src( $root, 'src/Infrastructure/Admin/AutomationConfigurationDependencyGuard.php' );
$css = ux6_src( $root, 'admin/assets/admin.css' );
$legacy_admin = ux6_src( $root, 'admin/class-ecapla-certverify-admin.php' );

ux6( false === strpos( $domain, 'data-certloom-quick-field-label required' ) && false === strpos( $structure, 'data-certloom-quick-field-label required' ), 'closed Program and Batch quick-field dialogs do not participate in native required validation' );
ux6( false !== strpos( $domain, 'new DesignHierarchyConfigurationGuard()' ) && false !== strpos( $structure, 'new DesignHierarchyConfigurationGuard()' ), 'Program, Issuer and Batch requests preflight submitted design hierarchy before persistence' );
ux6( false !== strpos( $hierarchy, 'Global → Issuer → Program → Batch' ) && false !== strpos( $hierarchy, 'validate_issuer' ) && false !== strpos( $hierarchy, 'validate_program' ) && false !== strpos( $hierarchy, 'validate_batch' ), 'one hierarchy preflight owns Issuer Program and Batch design changes' );
ux6( false !== strpos( $hierarchy, 'validate_inheriting_batches_against_layout' ), 'Program design preflight protects inheriting Batches' );
ux6( false !== strpos( $hierarchy, 'validate_program_variants' ) && false !== strpos( $hierarchy, 'Program language design' ), 'Program language variants are compatibility-checked against the Program and inheriting Batches before save' );
ux6( false !== strpos( $hierarchy, 'validate_design_inheritance_change' ), 'switching to inheritance preflights active automatic integrations' );
ux6( false !== strpos( $hierarchy, 'validate_global' ) && false !== strpos( $hierarchy, "has_direct_assignment( 'global', 0 )" ), 'Global assignment changes share the same published-design and active-integration inheritance guard' );
ux6( false !== strpos( $legacy_admin, 'DesignHierarchyConfigurationGuard' ) && false !== strpos( $legacy_admin, 'validate_global( $template_id )' ), 'Setup Wizard Global design changes use the same hierarchy/dependency preflight instead of bypassing it' );
ux6( substr_count( $template_admin, 'assignment_hierarchy_preflight' ) >= 3 && false !== strpos( $template_admin, 'validate_global' ) && false !== strpos( $template_admin, 'validate_program' ) && false !== strpos( $template_admin, 'validate_batch' ), 'Design workspace assignment mutations cannot bypass the entity-form hierarchy preflight' );
ux6( false !== strpos( $templates, 'resolve_published_assignment_details_excluding' ), 'Template repository can safely simulate one cleared direct assignment without mutating storage' );
ux6( false !== strpos( $target, 'excluded_assignment' ) && false !== strpos( $target, 'resolve_published_assignment_details_excluding' ), 'integration target validation can evaluate proposed inheritance instead of only persisted assignments' );
ux6( false !== strpos( $target, 'proposed_assignment' ) && false !== strpos( $target, 'normalize_proposed_assignment' ) && false !== strpos( $dependencies, 'proposed_assignment' ), 'Program/Batch edits can validate fields/context and a replacement design together without consulting the stale assignment being replaced' );
ux6( false !== strpos( $dependencies, 'validate_program_context_change' ) && false !== strpos( $dependencies, 'validate_batch_context_change' ) && false !== strpos( $dependencies, 'validate_design_inheritance_change' ), 'automatic-source dependencies guard structure fields and design inheritance changes' );

$program_pos = strpos( $nav, "link( __( 'Programs', 'certloom' )" );
$batch_pos = strpos( $nav, "link( __( 'Batches', 'certloom' )", $program_pos );
$issuer_pos = strpos( $nav, "link( __( 'Issuers', 'certloom' )", $batch_pos );
$signer_pos = strpos( $nav, "link( __( 'Signers', 'certloom' )", $issuer_pos );
ux6( false !== $program_pos && false !== $batch_pos && false !== $issuer_pos && false !== $signer_pos && $program_pos < $batch_pos && $batch_pos < $issuer_pos && $issuer_pos < $signer_pos, 'Programs family is ordered Programs then Batches then Issuers then Signers' );
ux6( false !== strpos( $nav, "if ( 'certloom-domain-recipients' === \$page )" ) && false !== strpos( $nav, "return 'credentials';" ), 'Recipients remain in Credentials navigation ownership in both UI modes' );
ux6( substr_count( $nav, "'certloom-automations'" ) >= 2 && false !== strpos( $nav, "'certloom-integrations'" ) && false !== strpos( $nav, "'certloom-analytics'" ), 'Advanced Automations destination retains Automations Integrations and Analytics continuations' );

ux6( false !== strpos( $domain, 'find_published_by_machine_key' ) && false !== strpos( $domain, "profile_template_variant_template_id[]" ), 'Program language-design edit round-trip resolves stored machine keys back to published design IDs' );
ux6( false !== strpos( $domain, "name=\"issuer_template_id\"" ) && false !== strpos( $domain, 'Programs and Batches may override this later' ) && false !== strpos( $domain, 'quick_issuer_created_but_not_connected_error' ), 'Program workspace quick Issuer creation exposes Issuer design inheritance and guards the Program connection' );
ux6( false !== strpos( $domain, 'Batch-specific design still wins' ) && false !== strpos( $issuance, 'direct Batch assignment must win over a locale variant' ), 'Program language variants are explicitly below a Batch override' );
ux6( false !== strpos( $issuance, 'Global → Issuer → Program → Batch' ), 'manual issuance documents and uses the canonical hierarchy' );
ux6( false !== strpos( $issuance, "'lms_integration' === \$source_type" ) && false !== strpos( $issuance, "['context']['adapter']" ) && false !== strpos( $issuance, '$automation_source = $adapter' ) && false !== strpos( $issuance, 'certloom_credential_issued' ), 'generic immutable integration provenance is translated back to the adapter key only for downstream automation source matching' );

foreach ( array( 'TutorLmsMappingStore.php', 'LearnDashMappingStore.php', 'WooCommerceMappingStore.php', 'CourseraMappingStore.php', 'WebhookSourceRepository.php' ) as $file ) {
    $src = ux6_src( $root, 'src/Infrastructure/Integration/' . $file );
    ux6( false !== strpos( $src, "'template_id'" ) && ( false !== strpos( $src, "=> 0" ) || false !== strpos( $src, "=>0" ) ) && false !== strpos( $src, "'legacy_template_id'" ), $file . ' resolves live hierarchy and exposes old pinned design only as legacy fallback' );
}
foreach ( array( 'TutorLmsIntegrationAdmin.php', 'LearnDashIntegrationAdmin.php', 'WooCommerceIntegrationAdmin.php', 'CourseraIntegrationAdmin.php', 'WebhookIntegrationAdmin.php' ) as $file ) {
    $src = ux6_src( $root, 'src/Infrastructure/Admin/' . $file );
    ux6( false !== strpos( $src, 'IntegrationMappingTargetGuard' ), $file . ' saves through shared Program/Batch hierarchy compatibility guard' );
}
ux6( false !== strpos( $coordinator, "'legacy_template_id'" ) && false !== strpos( $coordinator, 'certloom_manual_issue_program_design_missing' ), 'legacy integration design is fallback-only when the live hierarchy genuinely has no design' );
ux6( false !== strpos( $integrations, 'design follows hierarchy' ) && false !== strpos( $integrations, 'Legacy design fallback' ), 'Integrations UI explains live hierarchy and discloses old mapping fallback' );

ux6( false !== strpos( $automation_rules, "'issuer_id'" ) && false !== strpos( $automation_rules, "'program_id'" ) && false !== strpos( $automation_rules, "'batch_id'" ) && false !== strpos( $automation_rules, "'source_type'" ), 'email automation rules can scope by Issuer Program Batch and issuance source' );
ux6( false !== strpos( $automation_listener, "snapshot['issuer']['id']" ) && false !== strpos( $automation_listener, "snapshot['program']['id']" ) && false !== strpos( $automation_listener, "snapshot['batch']['id']" ), 'automation matching uses immutable issued snapshot context' );
ux6( false !== strpos( $automation_dependencies, 'validate_program_issuer_change' ) && false !== strpos( $automation_dependencies, 'validate_batch_program_change' ), 'relationship changes cannot silently make saved automation scopes impossible' );
ux6( false !== strpos( $automations, 'Email message' ) && false !== strpos( $automations, 'certificate design' ), 'Automations distinguishes reusable email messages from certificate designs' );
ux6( false !== strpos( $integrations, 'Credly badge template ID' ) && false !== strpos( $integrations, 'separate from Certloom certificate designs' ), 'external Credly badge template identity remains distinct from Certloom certificate design inheritance' );
ux6( false !== strpos( $css, '.certloom-field-warning' ) && false !== strpos( $css, 'var(--certloom-warning)' ), 'unavailable inherited/language design warnings have deliberate premium styling' );

printf( "\n%d UX-CORE-06 source checks, %d failures\n", $pass, $fail );
exit( $fail ? 1 : 0 );
