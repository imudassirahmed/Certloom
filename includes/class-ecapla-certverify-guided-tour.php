<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user, non-blocking Dashboard product tour.
 *
 * The tour is deliberately presentation-only. It points at real Certloom
 * actions and persists only tour progress; it never mutates credential or
 * setup authority.
 */
class Ecapla_CertVerify_Guided_Tour {
	const META_KEY = 'certloom_guided_tour_v1';
	const VERSION  = 1;
	const STEP_COUNT = 6;

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'version'      => self::VERSION,
			'status'       => 'new',
			'last_step'    => 0,
			'started_at'   => '',
			'updated_at'   => '',
			'completed_at' => '',
			'dismissed_at' => '',
		);
	}

	/** @return array<string,mixed> */
	public static function get_state( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$saved = $user_id ? get_user_meta( $user_id, self::META_KEY, true ) : array();
		$state = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		$status = sanitize_key( (string) $state['status'] );
		$state['status'] = in_array( $status, array( 'new', 'in_progress', 'completed', 'dismissed' ), true ) ? $status : 'new';
		$state['last_step'] = min( self::STEP_COUNT - 1, max( 0, absint( $state['last_step'] ) ) );
		foreach ( array( 'started_at', 'updated_at', 'completed_at', 'dismissed_at' ) as $key ) {
			$state[ $key ] = sanitize_text_field( (string) $state[ $key ] );
		}
		$state['version'] = self::VERSION;
		return $state;
	}

	/**
	 * Returns the six Dashboard steps. The selectors deliberately point at real
	 * actions already present in the Dashboard rather than tutorial-only markup.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function steps( $profile = 'general_other' ) {
		$engine = new \Certloom\Application\Setup\PurposePresetEngine();
		$profile = $engine->normalize_profile( $profile );
		$recommendation = $engine->recommend( $profile );
		$program_meaning = __( 'a repeatable credential scheme or category', 'certloom' );
		if ( ! is_wp_error( $recommendation ) && ! empty( $recommendation['hierarchy']['program'] ) ) {
			$program_meaning = (string) $recommendation['hierarchy']['program'];
		}

		return array(
			array(
				'id'     => 'issue',
				'target' => '.certloom-dashboard-actions .button-primary',
				'title'  => __( 'Issue a credential', 'certloom' ),
				'body'   => __( 'Start here when you need to issue one credential. The issuance screen keeps hierarchy optional and shows additional detail only when you need it.', 'certloom' ),
				'action' => __( 'Open issuance', 'certloom' ),
			),
			array(
				'id'     => 'fields',
				'target' => '.certloom-dashboard-quick-grid a[href*="page=certloom-fields"]',
				'title'  => __( 'Shape the credential data', 'certloom' ),
				'body'   => __( 'Manage Fields controls the reusable data you can capture and expose. Setup has already suggested fields for your selected profile; refine them here when needed.', 'certloom' ),
				'action' => __( 'Open fields', 'certloom' ),
			),
			array(
				'id'     => 'programs',
				'target' => '.certloom-dashboard-quick-grid a[href*="page=certloom-domain-programs"]',
				'title'  => __( 'Use Programs when structure helps', 'certloom' ),
				'body'   => sprintf(
					/* translators: %s: profile-specific meaning of a Program. */
					__( 'Programs are optional. For your current profile, a Program can represent %s. Use one when you want repeatable defaults, grouping or automation.', 'certloom' ),
					$program_meaning
				),
				'action' => __( 'Open Programs', 'certloom' ),
			),
			array(
				'id'     => 'design',
				'target' => '.certloom-dashboard-quick-grid a[href*="page=certloom-templates"]',
				'title'  => __( 'Control presentation separately', 'certloom' ),
				'body'   => __( 'Designs control reusable certificate artwork. They do not own credential validity or the credential record itself, and a credential can remain details-only.', 'certloom' ),
				'action' => __( 'Open Designs', 'certloom' ),
			),
			array(
				'id'     => 'automations',
				'target' => '.certloom-dashboard-quick-grid a[href*="page=certloom-automations"]',
				'title'  => __( 'Automate repeatable issuance', 'certloom' ),
				'body'   => __( 'Automations is where provider events, conditions, issuance choices and repeat policy come together. Manual issuance remains available alongside automation.', 'certloom' ),
				'action' => __( 'Open Automations', 'certloom' ),
			),
			array(
				'id'     => 'analytics',
				'target' => '.certloom-dashboard-quick-grid a[href*="page=certloom-analytics"]',
				'title'  => __( 'Review what is happening', 'certloom' ),
				'body'   => __( 'Analytics summarizes issuance and verification activity. Use it to spot operational changes without turning the Dashboard into a reporting screen.', 'certloom' ),
				'action' => __( 'Open Analytics', 'certloom' ),
			),
		);
	}

	/** @return bool */
	public static function should_auto_start( array $state, $wizard_completed ) {
		return (bool) $wizard_completed && in_array( (string) ( $state['status'] ?? 'new' ), array( 'new', 'in_progress' ), true );
	}

	/** @return true|WP_Error */
	public static function apply_event( $event, $step = 0, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'certloom_tour_user_required', __( 'Certloom could not identify the administrator for this tour.', 'certloom' ) );
		}
		$event = sanitize_key( (string) $event );
		if ( ! in_array( $event, array( 'start', 'restart', 'progress', 'dismiss', 'complete' ), true ) ) {
			return new WP_Error( 'certloom_tour_event_invalid', __( 'Certloom could not identify that tour action.', 'certloom' ) );
		}
		$state = self::get_state( $user_id );
		$now = current_time( 'mysql' );
		$step = min( self::STEP_COUNT - 1, max( 0, absint( $step ) ) );

		if ( 'restart' === $event ) {
			$state = self::defaults();
			$state['status'] = 'in_progress';
			$state['started_at'] = $now;
			$state['updated_at'] = $now;
		} elseif ( 'dismiss' === $event ) {
			$state['status'] = 'dismissed';
			$state['dismissed_at'] = $now;
			$state['updated_at'] = $now;
		} elseif ( 'complete' === $event ) {
			$state['status'] = 'completed';
			$state['last_step'] = self::STEP_COUNT - 1;
			$state['completed_at'] = $now;
			$state['dismissed_at'] = '';
			$state['updated_at'] = $now;
		} else {
			$state['status'] = 'in_progress';
			$state['last_step'] = $step;
			$state['dismissed_at'] = '';
			$state['completed_at'] = '';
			if ( '' === (string) $state['started_at'] ) { $state['started_at'] = $now; }
			$state['updated_at'] = $now;
		}
		$state['version'] = self::VERSION;
		$stored = update_user_meta( $user_id, self::META_KEY, $state );
		if ( false === $stored && $state !== get_user_meta( $user_id, self::META_KEY, true ) ) {
			return new WP_Error( 'certloom_tour_save_failed', __( 'Certloom could not save the guided-tour progress.', 'certloom' ) );
		}
		return true;
	}

	/** AJAX boundary used only for the current user's tour progress. */
	public static function handle_ajax() {
		if ( ! Ecapla_CertVerify_Permissions::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update the Certloom tour.', 'certloom' ) ), 403 );
		}
		check_ajax_referer( 'certloom_guided_tour', 'nonce' );
		$event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$step  = isset( $_POST['step'] ) ? absint( $_POST['step'] ) : 0;
		$result = self::apply_event( $event, $step );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'state' => self::get_state() ) );
	}
}
