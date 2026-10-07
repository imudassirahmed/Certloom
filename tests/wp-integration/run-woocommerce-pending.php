<?php
/** Actual installed WooCommerce completion hook, customer collection and canonical pending issuance. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport', 'QA' ); }, PHP_INT_MAX );
add_filter( 'woocommerce_logger_log_message', '__return_null', PHP_INT_MAX ); // No fixture logs outside the owned workspace.
foreach ( WC()->mailer()->get_emails() as $email ) { add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false', PHP_INT_MAX ); }
if ( 'cpt' === getenv( 'CERTLOOM_TEST_WOO_STORAGE' ) ) { add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', static function () { return 'no'; }, PHP_INT_MAX ); }
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Application\Integration\WooCommercePendingContext;
use Certloom\Application\Integration\WooCommercePendingIntake;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Infrastructure\Integration\WooCommerceAdapter;
use Certloom\Infrastructure\Integration\WooCommerceBridge;
use Certloom\Infrastructure\Integration\WooCommerceMappingStore;
use Certloom\Infrastructure\Integration\WooCommercePendingMappingRepository;
use Certloom\Infrastructure\Integration\WooCommerceCompletionEvidence;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
$checks = array(); $failure = null; $users = array(); $posts = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$tables = array_merge( array( $wpdb->users,$wpdb->usermeta,$wpdb->posts,$wpdb->postmeta,$wpdb->comments,$wpdb->commentmeta,$wpdb->terms,$wpdb->term_taxonomy,$wpdb->term_relationships,$wpdb->termmeta ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients','programs','issuers','signers','batches','batch_recipients','signer_assignments','templates','template_revisions','template_assignments','credential_series','credential_versions','credential_numbers','credential_events','jobs','email_deliveries','destination_deliveries','import_rows','restore_map','program_registrations','field_submissions','issuance_requests','issuance_request_events' ) ) );
$woo_tables = $wpdb->get_col( 'SHOW TABLES' ); foreach ( $woo_tables as $woo_table ) { if ( 0 === strpos( $woo_table,$wpdb->prefix . 'wc_' ) || 0 === strpos( $woo_table,$wpdb->prefix . 'woocommerce_' ) ) { $tables[] = $woo_table; } }
$fingerprints = static function () use ( $tables ) { global $wpdb; $out = array(); foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Checked preservation read required.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; };
$options = static function () { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR LEFT(option_name,12)='woocommerce_' OR LEFT(option_name,3)='wc_' OR option_name='cron' ORDER BY option_name", ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Checked option read required.' ); } return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ), 'hashes' => array_combine( array_column( $rows, 'option_name' ), array_map( static function ( $row ) { return hash( 'sha256', wp_json_encode( $row ) ); }, $rows ) ) ); };
$before = $fingerprints(); $before_options = $options();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprints, $options, &$failure, &$users, &$posts ) {
 try {
  global $wpdb; $nonce = bin2hex( random_bytes( 6 ) ); $actor = (int) get_users( array( 'role' => 'administrator','number' => 1,'fields' => 'ids' ) )[0]; wp_set_current_user( $actor );
  $check( WooCommerceAdapter::is_available() && class_exists( '\WC_Order' ), 'the actual installed WooCommerce provider is available' );
  $settings = Ecapla_CertVerify_Settings::get_all(); $settings['numbering_mode'] = 'sequential'; $settings['certificate_name_source'] = 'profile'; $settings['certificate_name_field_key'] = ''; $check( Ecapla_CertVerify_Settings::save( $settings ), 'fixture uses canonical automatic numbering and ordinary profile names' );
  $users[] = $user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-woocommerce-pending-' . $nonce,'user_pass' => wp_generate_password( 32 ),'display_name' => 'QA WooCommerce learner','role' => 'subscriber','user_email' => '' ) ) );
  $users[] = $other = $unwrap( wp_insert_user( array( 'user_login' => 'qa-woocommerce-other-' . $nonce,'user_pass' => wp_generate_password( 32 ),'role' => 'subscriber','user_email' => '' ) ) );
  $recipient = $unwrap( ( new RecipientRepository() )->find_by_wp_user_id( $user ) ); $check( is_array( $recipient ), 'actual subscriber registration creates an exact account connection' );
  $fields = Ecapla_CertVerify_Fields::get_fields(); $keys = array( 'personal' => 'qa_woocommerce_id_' . $nonce,'enrollment' => 'qa_woocommerce_enrollment_' . $nonce,'result' => 'qa_woocommerce_result_' . $nonce );
  foreach ( $keys as $kind => $key ) { $fields[] = array( 'key' => $key,'label' => 'QA WooCommerce ' . $kind,'type' => 'result' === $kind ? 'number' : 'text','sensitivity' => 'personal','public_exposure' => 'private','collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA,'schema_version' => 1,'configured' => true ),'writer' => 'result' === $kind ? 'source' : 'recipient','lifetime' => array( 'personal' => 'recipient','enrollment' => 'registration','result' => 'achievement' )[ $kind ],'collect_when' => 'result' === $kind ? 'never' : 'before_issue','approval_required' => 'personal' === $kind,'show_on_certificate' => false,'help' => '' ) ); }
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'organization enables private personal/enrollment collection and keeps results source owned' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $contract = new ScopedFieldContract(); $metadata = $unwrap( $contract->with_program_metadata( array(), array_fill_keys( array( $keys['personal'],$keys['enrollment'] ), 'required' ), $catalog ) );
  $program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA WooCommerce Program ' . $nonce,'code' => 'QTP-' . $nonce,'metadata' => $metadata ) ) );
  $templates = new TemplateRepository(); $design = $unwrap( $templates->create( array( 'name' => 'QA WooCommerce design ' . $nonce,'machine_key' => 'qa-woocommerce-' . $nonce,'layout' => TemplateDefaults::blank_a4_landscape() ) ) ); $design_row = $unwrap( $templates->find( $design ) ); $unwrap( $templates->publish( $design,$design_row['current_revision_id'],$design_row['current_revision']['layout_sha256'] ) ); $unwrap( $templates->assign( $design,'program',$program ) );
  $product_object = new WC_Product_Simple(); $product_object->set_name( 'QA pending product ' . $nonce ); $product_object->set_status( 'publish' ); $product_object->set_regular_price( '20' ); $posts[] = $product = $product_object->save(); $check( $product > 0, 'actual WooCommerce product is saved' );
  // A library larger than the initial selector: native provider search must reach the target.
  $lookup_sku = 'QA-WOO-' . $nonce; $product_object->set_sku( $lookup_sku ); $product_object->save();
  for ( $lookup_index = 0; $lookup_index < 25; ++$lookup_index ) { $lookup_product = new WC_Product_Simple(); $lookup_product->set_name( 'A QA lookup ' . $nonce . ' ' . $product . ' ' . sprintf( '%02d', $lookup_index ) ); $lookup_product->set_status( 'publish' ); $posts[] = $lookup_product->save(); }
  $lookup = new \Certloom\Infrastructure\Admin\ScopedFieldAdminAjax(); $lookup_before = $fingerprints(); $lookup_options = $options();
  $initial_choices = $unwrap( $lookup->choices( 'woocommerce_product' ) );
  $check( 20 === count( $initial_choices ) && ! in_array( $product,array_column( $initial_choices,'id' ),true ), 'WooCommerce library target is beyond the first twenty choices' );
  foreach ( array( 'QA pending product ' . $nonce,$lookup_sku,(string) $product ) as $lookup_term ) { $found = $unwrap( $lookup->choices( 'woocommerce_product',$lookup_term ) ); $check( count( $found ) <= 20 && in_array( $product,array_column( $found,'id' ),true ) && false !== strpos( wp_json_encode( $found ),$lookup_sku ), 'actual WooCommerce search reaches exact product by name, SKU or ID' ); }
  $check( array() === $unwrap( $lookup->choices( 'woocommerce_product','no-product-' . $nonce ) ), 'empty product search returns no invented or unrelated product' );
  $product_object->set_status( 'trash' ); $product_object->save();
  $check( array() === $unwrap( $lookup->choices( 'woocommerce_product',$lookup_sku ) ), 'trashed provider product is never offered as a connection choice' );
  $product_object->set_status( 'publish' ); $product_object->save();
  $lookup_before = $fingerprints(); $lookup_options = $options();
  $lookup_rest = static function ( $term ) { $request = new WP_REST_Request( 'GET','/certloom/v1/admin/context' ); $request->set_query_params( array( 'kind' => 'woocommerce_product','query' => $term ) ); $server = rest_get_server(); return apply_filters( 'rest_post_dispatch',$server->dispatch( $request ),$server,$request ); };
  $lookup_response = $lookup_rest( $lookup_sku ); $lookup_headers = array_change_key_case( $lookup_response->get_headers(),CASE_LOWER );
  $check( 200 === $lookup_response->get_status() && $product === $lookup_response->get_data()['choices'][0]['id'] && 'private, no-store, max-age=0' === $lookup_headers['cache-control'] && 'noindex' === $lookup_headers['x-robots-tag'], 'actual authenticated REST product search reaches target with private uncached headers' );
  $provider_fault = static function () { throw new RuntimeException( 'Private provider diagnostic must not escape.' ); };
  add_filter( 'woocommerce_product_pre_search_products',$provider_fault,PHP_INT_MAX );
  try { $lookup_failed = $lookup_rest( $lookup_sku ); } finally { remove_filter( 'woocommerce_product_pre_search_products',$provider_fault,PHP_INT_MAX ); }
  $check( 503 === $lookup_failed->get_status() && false === strpos( wp_json_encode( $lookup_failed->get_data() ),'Private provider diagnostic' ), 'provider search error fails closed without leaking its diagnostic' );
  $provider_malformed = static function () { return array( array( 'invented' => 1 ) ); };
  add_filter( 'woocommerce_product_pre_search_products',$provider_malformed,PHP_INT_MAX );
  try { $lookup_malformed = $lookup_rest( $lookup_sku ); } finally { remove_filter( 'woocommerce_product_pre_search_products',$provider_malformed,PHP_INT_MAX ); }
  $check( 503 === $lookup_malformed->get_status(), 'malformed provider ID objects cannot become invented product choices' );
  wp_set_current_user( $user ); $lookup_denied = $lookup_rest( $lookup_sku );
  $check( 403 === $lookup_denied->get_status() && 'private, no-store, max-age=0' === array_change_key_case( $lookup_denied->get_headers(),CASE_LOWER )['cache-control'], 'customer cannot search unpublished integration product choices' );
  ( new WP_User( $user ) )->add_cap( Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS ); wp_set_current_user( 0 ); wp_set_current_user( $user );
  $check( 403 === $lookup_rest( $lookup_sku )->get_status(), 'staff with credential viewing alone cannot search integration products' );
  ( new WP_User( $user ) )->remove_cap( Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS ); wp_set_current_user( $actor );
  $check( $lookup_before === $fingerprints() && $lookup_options === $options(), 'search, REST denial and provider failure leave business, WooCommerce and configuration state unchanged' );
  $repo = new WooCommercePendingMappingRepository(); $intake = new WooCommercePendingIntake(); $requests = new IssuanceRequestRepository(); $pending = new PendingIssuanceService();
  $key = $unwrap( $repo->create( $product,$program,0 ) ); $mapping = $unwrap( $repo->find( $key ) );
  $check( is_wp_error( $repo->create( $product,$program,0 ) ), 'one product cannot have duplicate customer-detail mappings' );
  $fault_product_object = new WC_Product_Simple(); $fault_product_object->set_name( 'QA fault product ' . $nonce ); $fault_product_object->set_status( 'publish' ); $fault_product_object->set_regular_price( '20' ); $posts[] = $fault_product = $fault_product_object->save();
  foreach ( array( 'error','zero' ) as $mode ) {
   $fault = static function ( $sql ) use ( $mode,$nonce ) { global $wpdb; if ( 0 === strpos( $sql,'UPDATE ' ) && false !== strpos( $sql, \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ) ) { return 'error' === $mode ? 'UPDATE `__qa_missing_commerce_' . $nonce . '` SET option_value=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   foreach ( array( 'create' => static function () use ( $repo,$fault_product,$program ) { return $repo->create( $fault_product,$program,0 ); },'pause' => static function () use ( $repo,$key,$mapping ) { return $repo->change( $key,$repo->revision( $mapping ),'pause' ); },'delete' => static function () use ( $repo,$key,$mapping ) { return $repo->change( $key,$repo->revision( $mapping ),'delete' ); } ) as $command => $operation ) {
    $prior = $fingerprints(); $prior_options = $options(); $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query',$fault,PHP_INT_MAX ); try { $bad = $operation(); } finally { remove_filter( 'query',$fault,PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
    $check( is_wp_error( $bad ) && $prior === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], $command . ' rolls back ' . $mode . ' outbox failure and preserves configuration, generation, jobs and cron' );
   }
  }
  $order = $unwrap( wc_create_order( array( 'customer_id' => $user ) ) ); $posts[] = $order_id = $order->get_id();
  $item = $order->add_product( $product_object,2 ); $order->calculate_totals(); $order->set_status( 'processing' ); $order->save();
  $check( is_wp_error( $intake->receive( $order_id,$product,$user ) ) && null === $unwrap( $requests->find_by_intake_key( WooCommercePendingContext::intake_key( $order_id,$product,$user ) ) ), 'payment or a Processing order creates no qualification or pending intent' );
  $order->set_status( 'completed' ); $order->set_date_completed( time() - 120 ); $order->save();
  $waiting = $unwrap( $requests->find_by_intake_key( WooCommercePendingContext::intake_key( $order_id,$product,$user ) ) );
  $check( is_array( $waiting ) && 'waiting' === $waiting['status'] && empty( $waiting['version_id'] ) && 'waiting' === get_option( WooCommerceBridge::LAST_RESULT_OPTION )['status'], 'actual Woo Completed hook creates waiting intent without an issued credential' );
  $check( is_wp_error( $intake->receive( $order_id,$product,$other ) ), 'the order cannot qualify an unrelated linked customer' );
  $replayed = $unwrap( $intake->receive( $order_id,$product,$user ) ); $check( $replayed['id'] === $waiting['id'] && $replayed['idempotent_replay'], 'Completed hook replay retains the original request and enrollment' );
  $proof = new WooCommerceCompletionEvidence(); $original_proof = $unwrap( $proof->read( $order_id,$product,$user ) );
  $source_ref = WooCommercePendingContext::parse( $waiting['source_ref'] );
  $check( $source_ref['proof'] === $original_proof['sha256'], 'initial hook proof matches the final persisted native WooCommerce purchase facts' );
  $purchase_item = $order->get_item( $item ); $purchase_item->set_quantity( 3 ); $purchase_item->save();
  $changed_purchase = $unwrap( $proof->read( $order_id,$product,$user ) );
  $check( $changed_purchase['sha256'] !== $original_proof['sha256'] && 'waiting' === $unwrap( $pending->process( $waiting['id'] ) )['status'], 'changed purchased quantity invalidates original source proof instead of reusing a ready review' );
  $purchase_item->set_quantity( 2 ); $purchase_item->save(); $check( $original_proof === $unwrap( $proof->read( $order_id,$product,$user ) ), 'source proof is deterministic after exact owned purchase facts are restored' );
  $collection = new RecipientCollectionService(); wp_set_current_user( $user ); $view = $unwrap( $collection->read_for_user( $user,$waiting['registration_id'],0,$waiting['id'] ) );
  $check( array( $keys['personal'],$keys['enrollment'] ) === array_column( $view['fields'],'key' ), 'customer receives only the enabled personal and enrollment fields' );
  $check( is_wp_error( $collection->read_for_user( $other,$waiting['registration_id'],0,$waiting['id'] ) ) && is_wp_error( $collection->save_for_user( $user,$waiting['registration_id'],$view['revision'],array( $keys['result'] => '100' ),$waiting['id'] ) ), 'other accounts and recipient-written trusted scores are refused' );
  $unwrap( $collection->save_for_user( $user,$waiting['registration_id'],$view['revision'],array( $keys['personal'] => '00123',$keys['enrollment'] => 'ORDER-00012' ),$waiting['id'] ) );
  $check( 'waiting' === $unwrap( $pending->process( $waiting['id'] ) )['status'], 'customer answers still require the configured organization approval' );
  $proposal = $unwrap( ( new FieldSubmissionRepository() )->latest( $recipient['id'],'recipient',$recipient['id'],$keys['personal'] ) ); wp_set_current_user( $actor ); $unwrap( $collection->decide( $proposal['id'],$proposal['revision'],true,$actor ) );
  $manual = new ManualIssuanceService(); $review = $unwrap( $manual->review_pending( $waiting['id'] ) );
  $row = $unwrap( $requests->find( $waiting['id'] ) ); $row = $unwrap( $requests->transition( $row['id'],$row['revision'],array( 'status' => 'ready','review_sha256' => $review['review_fingerprint'],'blockers_json' => '[]' ),'readiness_confirmed' ) ); $row = $unwrap( $requests->transition( $row['id'],$row['revision'],array( 'status' => 'processing' ),'issuance_started' ) );
  $order->update_status( 'processing' );
  $check( is_wp_error( $manual->issue_pending( $row['id'],$review['review_fingerprint'] ) ) && 'waiting' === $unwrap( $pending->process( $row['id'] ) )['status'], 'an earlier approved review cannot issue after the original order loses Completed status' );
  $order->update_status( 'completed' );
  $check( 'waiting' === $unwrap( $requests->find( $waiting['id'] ) )['status'], 'a later Completed transition with a replacement completion date cannot replace original immutable purchase proof' );
  // Restore only the owned fixture's exact source facts, then use the durable worker.
  $order->set_date_completed( strtotime( $original_proof['completed_at'] . ' UTC' ) ); $order->save();
  $runtime = new \Certloom\Infrastructure\Job\JobRuntime(); $jobs = $runtime->repository();
  $unwrap( $requests->mark_due_for_recipient( $recipient['id'] ) );
  $job_key = $unwrap( $runtime->service()->create( \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT,0,array( 'schema_version' => 1,'cutoff' => current_time( 'mysql',true ),'max_id' => $waiting['id'] ),null,$waiting['id'] ) );
  $runner = new \Certloom\Application\Job\JobRunner( $runtime->service(),$jobs,array( \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT => new \Certloom\Application\Issuance\PendingIssuanceJobHandler() ) ); $unwrap( $runner->run( $job_key,bin2hex( random_bytes( 16 ) ) ) );
  $issued = $unwrap( $requests->find( $waiting['id'] ) );
  $current_proof = $proof->read( $order_id,$product,$user );
  $check( 'issued' === $issued['status'] && ! empty( $issued['version_id'] ), 'durable reassessment revalidates original purchase facts and approvals through canonical issuance: ' . $issued['status'] . ':' . implode( ',',array_column( json_decode( $issued['blockers_json'],true ) ?: array(),'code' ) ) . ':proof=' . ( is_wp_error( $current_proof ) ? 'unavailable' : ( $current_proof['sha256'] === $original_proof['sha256'] ? 'same' : 'changed' ) ) );
  $completed_job = $unwrap( $jobs->find( $job_key ) ); $check( 'succeeded' === $completed_job['status'] && 1 === $completed_job['result']['processed'] && 1 === $completed_job['result']['issued'], 'the actual durable JobRunner processes and issues the order-backed request' );
  $version = $unwrap( ( new CredentialRepository() )->find_version( $issued['version_id'] ) );
  $check( '00123' === $version['snapshot']['fields']['values'][ $keys['personal'] ] && 'ORDER-00012' === $version['snapshot']['fields']['values'][ $keys['enrollment'] ], 'canonical immutable snapshot retains leading-zero customer and enrollment values' );
  $late = $unwrap( $intake->receive( $order_id,$product,$user ) ); $check( $late['idempotent_replay'] && $late['version_id'] === $issued['version_id'], 'late order redelivery reconciles the same immutable certificate' );
  $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id=%d',SchemaManager::table( 'email_deliveries' ),$issued['version_id'] ) ), 'blank-email customer generates no Certloom delivery' );
  $second = $unwrap( wc_create_order( array( 'customer_id' => $user ) ) ); $posts[] = $second_id = $second->get_id(); $second->add_product( $product_object,1 ); $second->calculate_totals(); $second->update_status( 'completed' );
  $second_request = $unwrap( $requests->find_by_intake_key( WooCommercePendingContext::intake_key( $second_id,$product,$user ) ) );
  $check( $second_request['id'] !== $waiting['id'] && $second_request['registration_id'] !== $waiting['registration_id'] && 'waiting' === $second_request['status'], 'a repeat order has its own exact enrollment and cannot borrow previous registration answers' );
  $refund = $unwrap( wc_create_refund( array( 'order_id' => $second_id,'amount' => '1','reason' => 'QA refund hold','refund_payment' => false,'restock_items' => false ) ) ); $posts[] = $refund->get_id();
  $check( is_wp_error( $proof->read( $second_id,$product,$user ) ) && 'waiting' === $unwrap( $pending->process( $second_request['id'] ) )['status'], 'a real partial refund holds unissued intent without granting purchase qualification' );
  $check( $version === $unwrap( ( new CredentialRepository() )->find_version( $issued['version_id'] ) ), 'later purchase changes do not rewrite historical certificates' );
  $unwrap( $repo->change( $key,$repo->revision( $mapping ),'pause' ) ); $check( 'blocked' === $unwrap( $pending->process( $second_request['id'] ) )['status'], 'pausing the product mapping blocks pending assessment' );
  $check( is_wp_error( $repo->change( $key,$repo->revision( $mapping ),'activate' ) ), 'stale configuration cannot reactivate a connection' );
  $mapping = $unwrap( $repo->find( $key ) ); $unwrap( $repo->change( $key,$repo->revision( $mapping ),'activate' ) );
  $bad_metadata = $unwrap( $contract->with_program_metadata( array(),array( $keys['result'] => 'required' ),$catalog ) );
  $bad_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA commerce results ' . $nonce,'code' => 'QWR-' . $nonce,'metadata' => $bad_metadata ) ) ); $unwrap( $templates->assign( $design,'program',$bad_program ) );
  $check( is_wp_error( $repo->validate_target( $product,$bad_program,0 ) ), 'a purchase cannot invent a required source-owned assessment result' );
  $stored = get_option( WooCommercePendingMappingRepository::OPTION ); update_option( WooCommercePendingMappingRepository::OPTION,array( 'schema_version' => 1,'mappings' => array( $mapping,$mapping ) ),false );
  $check( is_wp_error( $repo->read() ) && is_wp_error( ( new \Certloom\Infrastructure\Admin\IntegrationConfigurationDependencyGuard() )->validate_program_context_change( $unwrap( ( new ProgramRepository() )->find( $program ) ) ) ), 'malformed commerce configuration fails closed in source and dependency checks' ); update_option( WooCommercePendingMappingRepository::OPTION,$stored,false );
  $check( is_wp_error( ( new \Certloom\Infrastructure\Integration\WooCommerceCompletionMappingCommand() )->save( array( 'product_id' => $product,'program_id' => $program,'active' => true ),$actor ) ), 'legacy completion-only save cannot overlap the active customer-detail flow' );
  $current = $unwrap( $requests->find( $second_request['id'] ) ); $unwrap( ( new \Certloom\Infrastructure\Repository\RecipientWorkflowStore() )->patch( 'issuance_requests',$current['id'],$current['revision'],array( 'restore_state' => 'paused' ) ) );
  $check( 'blocked' === $unwrap( $pending->process( $current['id'] ) )['status'] && is_wp_error( $collection->read_for_user( $user,$current['registration_id'],0,$current['id'] ) ), 'restored requests cannot resume or offer customer completion commands automatically' );
  $plain_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA previous commerce flow ' . $nonce,'code' => 'QWL-' . $nonce ) ) ); $unwrap( $templates->assign( $design,'program',$plain_program ) );
  $legacy = new \Certloom\Infrastructure\Integration\WooCommerceCompletionMappingCommand(); $unwrap( $legacy->save( array( 'product_id' => $fault_product,'program_id' => $plain_program,'active' => true ),$actor ) );
  foreach ( array( WooCommerceMappingStore::OPTION, \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ) as $fault_option ) { foreach ( array( 'error','zero' ) as $mode ) {
   $fault = static function ( $sql ) use ( $mode,$nonce,$fault_option ) { global $wpdb; if ( 0 === strpos( $sql,'UPDATE ' ) && false !== strpos( $sql,$fault_option ) ) { return 'error' === $mode ? 'UPDATE `__qa_missing_commerce_' . $nonce . '` SET option_value=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   foreach ( array( 'save' => static function () use ( $legacy,$fault_product,$plain_program,$actor ) { return $legacy->save( array( 'product_id' => $fault_product,'program_id' => $plain_program,'active' => false ),$actor ); },'delete' => static function () use ( $legacy,$fault_product,$actor ) { return $legacy->delete( $fault_product,$actor ); } ) as $command => $operation ) {
    $prior = $fingerprints(); $prior_options = $options(); $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query',$fault,PHP_INT_MAX ); try { $bad = $operation(); } finally { remove_filter( 'query',$fault,PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
    $check( is_wp_error( $bad ) && $prior === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], 'legacy ' . $command . ' rolls back ' . $mode . ' option/outbox failure without partial configuration or hints' );
   }
  } }
  $legacy_order = $unwrap( wc_create_order( array( 'customer_id' => $other ) ) ); $posts[] = $legacy_order_id = $legacy_order->get_id(); $legacy_order->add_product( $fault_product_object,1 ); $legacy_order->calculate_totals(); $legacy_order->update_status( 'completed' );
  $check( 'issued' === get_option( WooCommerceBridge::LAST_RESULT_OPTION )['status'], 'the actual legacy Completed hook still issues through its unchanged canonical flow' );
  $check( is_wp_error( $repo->create( $fault_product,$plain_program,0 ) ), 'an active legacy flow must be removed before opting into customer details' );
  $unwrap( $legacy->delete( $fault_product,$actor ) ); $other_key = $unwrap( $repo->create( $fault_product,$plain_program,0 ) );
  $already = $intake->receive( $legacy_order_id,$fault_product,$other );
  $check( is_wp_error( $already ) && 'certloom_woocommerce_pending_existing_receipt' === $already->get_error_code() && null === $unwrap( $requests->find_by_intake_key( WooCommercePendingContext::intake_key( $legacy_order_id,$fault_product,$other ) ) ), 'switching an issued order/product into the pending flow cannot create a second request or certificate' );
  $guest_order = $unwrap( wc_create_order() ); $posts[] = $guest_order_id = $guest_order->get_id(); $guest_order->add_product( $fault_product_object,1 ); $guest_order->calculate_totals(); $guest_order->update_status( 'completed' );
  $check( 'certloom_woocommerce_guest_unlinked' === get_option( WooCommerceBridge::LAST_RESULT_OPTION )['error_code'] && is_wp_error( $intake->receive( $guest_order_id,$fault_product,0 ) ), 'guest checkout cannot guess or claim a registered recipient by billing name or email' );
  $switch_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA commerce switched target ' . $nonce,'code' => 'QWS-' . $nonce ) ) ); $unwrap( $templates->assign( $design,'program',$switch_program ) );
  $third = $unwrap( wc_create_order( array( 'customer_id' => $user ) ) ); $posts[] = $third_id = $third->get_id(); $third->add_product( $product_object,1 ); $third->calculate_totals(); $third->update_status( 'completed' );
  $third_request = $unwrap( $requests->find_by_intake_key( WooCommercePendingContext::intake_key( $third_id,$product,$user ) ) ); $check( 'waiting' === $third_request['status'], 'another actual order retains its own unissued waiting request' );
  $closed = $unwrap( $requests->find( $second_request['id'] ) ); $unwrap( ( new \Certloom\Application\Issuance\PendingRequestAdminService() )->command( $actor,$closed['id'],$closed['revision'],'cancel' ) );
  $original = $unwrap( $repo->find( $key ) ); $unwrap( $repo->change( $key,$repo->revision( $original ),'delete' ) ); $new_key = $unwrap( $repo->create( $product,$plain_program,0 ) );
  $stable_counts = static function () { global $wpdb; $out = array(); foreach ( array( 'issuance_requests','program_registrations','credential_versions','credential_numbers' ) as $entity ) { $out[ $entity ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i',SchemaManager::table( $entity ) ) ); } return $out; }; $before_switch = $stable_counts();
  $recreated = $unwrap( $intake->receive( $order_id,$product,$user ) );
  $check( $new_key !== $key && $recreated['id'] === $issued['id'] && $recreated['version_id'] === $issued['version_id'] && (int) $recreated['program_id'] === $program && $before_switch === $stable_counts(), 'recreating a product connection for another Program retains the original certificate, intent and number' );
  $cancelled_replay = $unwrap( $intake->receive( $second_id,$product,$user ) );
  $check( $cancelled_replay['id'] === $closed['id'] && 'cancelled' === $cancelled_replay['status'] && $before_switch === $stable_counts(), 'connection recreation cannot reopen a cancelled order or create a replacement enrollment' );
  $held_replay = $unwrap( $intake->receive( $third_id,$product,$user ) );
  $check( $held_replay['id'] === $third_request['id'] && 'blocked' === $held_replay['status'] && (int) $held_replay['program_id'] === $program && $before_switch === $stable_counts(), 'unissued original request holds for its removed authority instead of inheriting the new connection' );
  $recreated_mapping = $unwrap( $repo->find( $new_key ) ); $unwrap( $repo->change( $new_key,$repo->revision( $recreated_mapping ),'delete' ) ); $unwrap( $legacy->save( array( 'product_id' => $product,'program_id' => $switch_program,'active' => true ),$actor ) );
  foreach ( array( 'issued' => $order_id,'cancelled' => $second_id,'held' => $third_id ) as $state => $native_order ) {
   WooCommerceBridge::order_completed( $native_order ); $last_switch = get_option( WooCommerceBridge::LAST_RESULT_OPTION );
   $check( 'certloom_integration_existing_pending_request' === $last_switch['error_code'] && $before_switch === $stable_counts(), 'completion-only mapping for another Program cannot reissue an original ' . $state . ' order-backed request' );
  }
  $check( $version === $unwrap( ( new CredentialRepository() )->find_version( $issued['version_id'] ) ), 'connection recreation and flow switches leave immutable issued Versions unchanged' );
  $unwrap( $legacy->delete( $product,$actor ) );
  return new WP_Error( 'qa_woocommerce_rollback','Rollback owned fixture.' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_woocommerce_failed','Rollback failed fixture.' ); }
} );
foreach ( $users as $user ) { clean_user_cache( $user ); } foreach ( $posts as $post ) { clean_post_cache( $post ); } wp_set_current_user( 0 );
$after_options = $options(); DatabaseOptions::invalidate( array_unique( array_merge( $before_options['keys'],$after_options['keys'] ) ) ); Ecapla_CertVerify_Fields::reset_runtime_cache();
$after = $fingerprints(); $changed_tables = array_keys( array_diff_assoc( $after,$before ) ); $changed_options = array();
foreach ( array_unique( array_merge( $before_options['keys'],$after_options['keys'] ) ) as $key ) { if ( ( $before_options['hashes'][ $key ] ?? null ) !== ( $after_options['hashes'][ $key ] ?? null ) ) { $changed_options[] = $key; } }
$preserved = $after === $before && $after_options['sha256'] === $before_options['sha256']; $success = null === $failure && is_wp_error( $result ) && 'qa_woocommerce_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success,'checks_passed' => count( $checks ),'checks' => $checks,'failure' => $failure,'tracked_rows_options_cron_preserved' => $preserved,'changed_tables' => $changed_tables,'changed_option_names' => $changed_options,'php' => PHP_VERSION,'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '', 'hpos' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ),JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( $success ? 0 : 1 );
