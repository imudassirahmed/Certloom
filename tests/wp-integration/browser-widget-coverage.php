<?php
/** Current native widget/editor fixtures. No license override and no ordinary record replacement. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$project = dirname( __DIR__, 2 );
$snapshot = json_decode( file_get_contents( $project . '/output/program-recipient-20261003/private-snapshot.json' ), true );
if ( ! is_array( $snapshot ) || ! is_file( $snapshot['path'] ) || ! hash_equals( $snapshot['sha256'], hash_file( 'sha256', $snapshot['path'] ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); require 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
wp_set_current_user( 1 );
function wc_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; }
function wc_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wc_digest( array $tables ) { global $wpdb; $out = array(); foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); wc_check( '' === (string) $wpdb->last_error && is_array( $rows ), 'Baseline read failed.' ); $out[$table] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; }
function wc_non_css_digest() { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE meta_key<>%s ORDER BY 1', $wpdb->postmeta, '_elementor_css' ), ARRAY_A ); wc_check( '' === (string) $wpdb->last_error && is_array( $rows ), 'Checked non-cache metadata read required.' ); return hash( 'sha256', wp_json_encode( $rows ) ); }
$pointer = $project . '/output/program-recipient-20261004/widget-native-journal.json'; $mode = $argv[1] ?? '';
try {
	if ( 'stage' === $mode ) {
		wc_check( ! is_file( $pointer ), 'Existing owned widget fixture needs cleanup first.' );
		if ( 'public' === ( $argv[2] ?? '' ) ) {
			$parent = json_decode( file_get_contents( $project . '/output/program-recipient-20261004/public-widget-journal.json' ), true ); $parent_path = is_array( $parent ) ? realpath( $parent['path'] ) : false;
			wc_check( $parent_path && basename( $parent_path ) === 'journal.json' && realpath( dirname( dirname( $parent_path ) ) ) === realpath( sys_get_temp_dir() ) && preg_match( '/^certloom-public-widgets-[a-f0-9]{16}$/D', basename( dirname( $parent_path ) ) ) && hash_equals( $parent['sha256'], hash_file( 'sha256', $parent_path ) ), 'Exact active public fixture required.' );
			$parent_state = json_decode( file_get_contents( $parent_path ), true ); $number = $parent_state['cases']['summary']['number'];
		} else {
			$bulk = json_decode( file_get_contents( $project . '/output/program-recipient-20261004/native-bulk-app-public.json' ), true ); wc_check( is_array( $bulk ), 'Owned native issuance fixture required.' );
			$nonce = substr( $bulk['field'], strlen( 'qa_native_result_' ) ); $number = 'QA-NATIVE-' . $nonce . '-0';
		}
		$dir = sys_get_temp_dir() . '/certloom-native-widgets-' . bin2hex( random_bytes( 8 ) ); wc_check( mkdir( $dir, 0700 ), 'Private fixture directory failed.' );
		$path = $dir . '/journal.json'; $token = bin2hex( random_bytes( 16 ) );
		$state = array( 'token' => $token, 'pages' => array(), 'number' => $number, 'pro_enabled' => \Certloom\Infrastructure\Verification\ProVerificationAccess::enabled(), 'before' => wc_digest( array( $wpdb->posts, $wpdb->postmeta ) ), 'before_non_css' => wc_non_css_digest() );
		$save = static function () use ( &$state, $path, $pointer ) { wc_check( false !== file_put_contents( $path, wp_json_encode( $state ) ), 'Owned widget journal write failed.' ); wc_check( false !== file_put_contents( $pointer, wp_json_encode( array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ) ) ), 'Owned widget pointer write failed.' ); };
		$save(); $content = ''; $widgets = array(); $i = 0;
		$add = static function ( $type, array $settings ) use ( &$widgets, &$i ) { ++$i; $widgets[] = array( 'id' => sprintf( 'a%06d', $i ), 'elType' => 'container', 'isInner' => false, 'settings' => array(), 'elements' => array( array( 'id' => sprintf( 'b%06d', $i ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() ) ) ); };
		foreach ( array( 'professional', 'meridian', 'halo', 'axis', 'nova', 'forge' ) as $style ) {
			$content .= '<!-- wp:certloom/verification ' . wp_json_encode( array( 'design' => $style, 'heading' => 'QA ' . $style ) ) . ' /-->';
			$add( 'professional' === $style ? 'certloom' : 'certloom-' . $style, array( 'heading' => 'QA ' . $style ) );
		}
		$content .= '<!-- wp:certloom/credential ' . wp_json_encode( array( 'number' => $number ) ) . ' /--><!-- wp:certloom/my-credentials /-->';
		$add( 'certloom-credential', array( 'certificate_number' => $number ) );
		foreach ( \Certloom\Infrastructure\Verification\ProVerificationStudio::choices() as $preset => $label ) {
			$content .= '<!-- wp:certloom/verification-studio ' . wp_json_encode( array( 'preset' => $preset, 'heading' => 'QA ' . $label ) ) . ' /-->';
			$add( 'certloom-pro-verification', array( 'preset' => $preset, 'heading' => 'QA ' . $label ) );
		}
		foreach ( array( 'block' => $content, 'elementor' => '' ) as $kind => $body ) {
			$id = wc_ok( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA Current widgets ' . $token . ' ' . $kind, 'post_content' => $body ), true ) ); $state['pages'][$kind] = $id;
			update_post_meta( $id, '_certloom_widget_qa_owner', $token ); $save();
			if ( 'elementor' === $kind ) { update_post_meta( $id, '_elementor_edit_mode', 'builder' ); update_post_meta( $id, '_elementor_template_type', 'wp-page' ); update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION ); update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $widgets ) ) ); }
		}
		$public = array( 'pages' => array_map( 'get_permalink', $state['pages'] ), 'ids' => $state['pages'], 'number' => $number, 'pro_enabled' => $state['pro_enabled'] );
		wc_check( false !== file_put_contents( $project . '/output/program-recipient-20261004/widget-native-public.json', wp_json_encode( $public ) ), 'Public widget fixture details failed.' );
		echo wp_json_encode( array( 'staged' => true, 'block_types' => 4, 'elementor_types' => 8, 'licensing_unchanged' => true ) ) . "\n"; exit;
	}
	wc_check( 'restore' === $mode && is_file( $pointer ), 'Owned widget journal required.' ); $record = json_decode( file_get_contents( $pointer ), true ); $path = realpath( $record['path'] );
	wc_check( $path && basename( $path ) === 'journal.json' && realpath( dirname( dirname( $path ) ) ) === realpath( sys_get_temp_dir() ) && preg_match( '/^certloom-native-widgets-[a-f0-9]{16}$/D', basename( dirname( $path ) ) ) && hash_equals( $record['sha256'], hash_file( 'sha256', $path ) ), 'Exact owned widget journal required.' );
	$state = json_decode( file_get_contents( $path ), true );
	foreach ( $state['pages'] as $kind => $id ) { $post = get_post( $id ); wc_check( $post && $post->post_title === 'QA Current widgets ' . $state['token'] . ' ' . $kind && get_post_meta( $id, '_certloom_widget_qa_owner', true ) === $state['token'], 'Changed or unowned page retained.' ); wc_check( (bool) wp_delete_post( $id, true ), 'Owned page cleanup failed.' ); }
	$after = wc_digest( array_keys( $state['before'] ) ); $cache_preserved = false;
	if ( $state['before'] !== $after ) {
		wc_check( $state['before'][$wpdb->posts] === $after[$wpdb->posts] && isset( $state['before_non_css'] ) && hash_equals( $state['before_non_css'], wc_non_css_digest() ), 'Unrelated page or media changes retained for review.' );
		$cache_preserved = true; // Elementor can regenerate its existing global Kit CSS on frontend reads.
	}
	wc_check( $state['pro_enabled'] === \Certloom\Infrastructure\Verification\ProVerificationAccess::enabled(), 'Commercial authority changed.' );
	wc_check( unlink( $path ) && rmdir( dirname( $path ) ) && unlink( $pointer ), 'Exact widget journal cleanup failed.' );
	echo wp_json_encode( array( 'restored' => true, 'original_pages_and_non_cache_metadata_preserved' => true, 'generated_elementor_css_progress_preserved' => $cache_preserved, 'licensing_unchanged' => true ) ) . "\n";
} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 ); }
