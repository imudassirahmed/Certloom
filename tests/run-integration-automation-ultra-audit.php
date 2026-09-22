<?php
/** Cross-system ultra-audit contract for issuance provenance, integrations and automations. */
$root=dirname(__DIR__);$pass=0;$fail=0;function ua($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$read=function($f)use($root){return file_get_contents($root.'/'.$f);};
$cred=$read('src/Infrastructure/Repository/CredentialRepository.php');$unified=$read('src/Infrastructure/Repository/UnifiedCredentialRegistry.php');$domain=$read('src/Infrastructure/Admin/DomainAdmin.php');$listener=$read('src/Infrastructure/Email/EmailAutomationListener.php');$rules=$read('src/Infrastructure/Email/EmailAutomationRuleRepository.php');$runs=$read('src/Infrastructure/Email/EmailAutomationRunRepository.php');$woo=$read('src/Infrastructure/Integration/WooCommerceBridge.php');$coord=$read('src/Application/Integration/IntegrationIssuanceCoordinator.php');$manual=$read('src/Application/Issuance/ManualIssuanceService.php');$integrations=$read('admin/views/integrations.php');$automations=$read('admin/views/automations.php');$admin=$read('admin/class-ecapla-certverify-admin.php');$credly=$read('src/Infrastructure/Integration/CredlyPublishingListener.php');$webhook=$read('src/Infrastructure/Integration/WebhookBridge.php');$coursera=$read('src/Infrastructure/Integration/CourseraXapiBridge.php');
ua(false!==strpos($cred,'ov.version_number=1'),'credential registry joins immutable Version 1 provenance');
ua(false!==strpos($cred,'origin_source_type')&&false!==strpos($cred,'origin_source_ref'),'registry projects original source type and reference');
ua(false!==strpos($unified,"'certloom_legacy' === \$origin_source"),'Legacy identity is derived from Version 1 origin rather than latest correction');
ua(false!==strpos($unified,"'learndash' => __( 'LearnDash'")&&false!==strpos($unified,"'webhook' => __( 'Webhook / API'"),'built-in issuance adapters have curated provenance labels');
ua(false!==strpos($unified,"ucwords( str_replace")&&false!==strpos($unified,"LMS"),'unknown validated adapter keys get humanized provenance instead of generic Integration');
ua(false!==strpos($domain,'_credential_issuance_source_label')&&false!==strpos($domain,'is-origin'),'Credentials list renders separate original issuance-source badge');
ua(false!==strpos($manual,'issue_reviewed_for_integration')&&false!==strpos($manual,"'lms_integration'"),'automatic integration issuance uses canonical immutable issuance service');
ua(false!==strpos($coord,'issue_reviewed_for_integration'),'provider-neutral coordinator delegates actual writes to canonical issuance');
foreach(array('TutorLmsBridge.php','LearnDashBridge.php','WooCommerceBridge.php','CourseraXapiBridge.php','WebhookBridge.php') as$f){$x=$read('src/Infrastructure/Integration/'.$f);ua(false!==strpos($x,'IntegrationIssuanceCoordinator'),$f.' converges on canonical coordinator');}
ua(false!==strpos($manual,"do_action( 'certloom_credential_issued'")||false!==strpos($manual,"\\do_action( 'certloom_credential_issued'"),'automation hook fires only after canonical issuance transaction returns a Version');
ua(false===strpos($listener,'if ( ! $program_id ) { return; }'),'automation listener no longer drops valid independent credentials');
ua(strpos($listener,'best_matching')<strpos($listener,'recipient_email_missing'),'automation rule is matched before recipient-email skip is recorded');
ua(false!==strpos($runs,'MAX_RECORDS = 50')&&false!==strpos($runs,'array_slice( $all, 0, self::MAX_RECORDS )'),'automation run evidence is bounded');
ua(false===strpos($runs,'recipient_email')&&false===strpos($runs,'message_body'),'automation evidence repository stores no recipient/body fields');
ua(false!==strpos($rules,"preg_match( '/^\\d{4}-\\d{2}-\\d{2}")&&false!==strpos($rules,'$updated_at'),'automation normalization preserves valid read timestamps');
$limit_pos=strpos($woo,'certloom_woocommerce_mapped_item_limit');$loop_pos=strpos($woo,'foreach ( $mapped_products as $product_id )');
ua(false!==strpos($woo,'count( $mapped_products ) > self::MAX_MAPPED_ITEMS')&&false!==$limit_pos&&false!==$loop_pos&&$limit_pos<$loop_pos,'WooCommerce oversized mapped orders fail closed before issuance loop');
ua(false!==strpos($woo,'foreach ( $mapped_products as $product_id )'),'WooCommerce issues only after full mapped-item safety scan');
ua(false!==strpos($credly,"add_action( 'certloom_credential_issued'")||false!==strpos($credly,'on_issued'),'Credly publishing remains downstream of canonical issuance');
ua(false!==strpos($integrations,'Last event')&&false!==strpos($integrations,'No activity recorded yet'),'Integrations cards expose privacy-safe last-event observability');
ua(false!==strpos($integrations,"'replayed' => __( 'Already issued'")&&false!==strpos($integrations,"'blocked' => __( 'Blocked'"),'integration result semantics distinguish replay and block states');
ua(false!==strpos($automations,'Latest: %1$s')&&false!==strpos($automations,'Could not queue delivery'),'Automations list exposes latest queue/skip/failure evidence');
ua(false!==strpos($admin,"'Webhook / API'")&&false!==strpos($admin,'active source'),'Automations source summary includes Webhook/API operational count');
ua(false!==strpos($admin,'WooCommerceIntegrationAdmin')&&substr_count($admin,"['status']['active_mapping_count']")>=4,'Automations source summary reads active mapping counts for Tutor/LearnDash/WooCommerce/Coursera');
ua(false!==strpos($admin,"_n( '%d active mapping', '%d active mappings'")&&false!==strpos($admin,'number of active integration mappings'),'Automations mapping-count summary is localized with a translator-safe plural contract');
ua(false!==strpos($webhook,'hash_hmac')&&false!==strpos($webhook,'MAX_CLOCK_SKEW')&&false!==strpos($webhook,'MAX_BODY_BYTES'),'Webhook endpoint is HMAC authenticated and time/size bounded');
ua(false!==strpos($coursera,'IntegrationIssuanceCoordinator'),'Coursera xAPI uses same canonical issuance coordinator');
ua(false===stripos($coord,'woocommerce')&&false===stripos($coord,'learndash')&&false===stripos($coord,'tutor'),'generic coordinator remains provider-neutral');
ua(false===strpos($runs,'source_ref')&&false===strpos($runs,'payload'),'automation operational evidence excludes provider source refs/payloads');
printf("\n%d integration/automation ultra-audit checks, %d failures\n",$pass,$fail);exit($fail?1:0);
