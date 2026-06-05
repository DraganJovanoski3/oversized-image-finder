<?php
/**
 * AJAX handlers for Oversized Image Finder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OIF_Ajax {

	/**
	 * Register AJAX hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_oif_start_scan', array( __CLASS__, 'start_scan' ) );
		add_action( 'wp_ajax_oif_scan_batch', array( __CLASS__, 'scan_batch' ) );
		add_action( 'wp_ajax_oif_get_results', array( __CLASS__, 'get_results' ) );
		add_action( 'wp_ajax_oif_clear_cache', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Verify AJAX request permissions and nonce.
	 */
	private static function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'oversized-image-finder' ) ), 403 );
		}

		check_ajax_referer( 'oif_scan_nonce', 'nonce' );
	}

	/**
	 * Start a new scan — build queue and store state.
	 */
	public static function start_scan() {
		self::verify_request();

		$scope = array(
			'media_library' => ! empty( $_POST['scope_media_library'] ),
			'uploads'       => ! empty( $_POST['scope_uploads'] ),
			'theme_plugins' => ! empty( $_POST['scope_theme_plugins'] ),
		);

		if ( ! $scope['media_library'] && ! $scope['uploads'] && ! $scope['theme_plugins'] ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one scan scope.', 'oversized-image-finder' ) ) );
		}

		delete_transient( OIF_TRANSIENT_RESULTS );
		delete_transient( OIF_TRANSIENT_STATE );

		$scanner = new OIF_Scanner( $scope );
		$queue   = $scanner->collect_scan_queue();

		$settings   = oif_get_settings();
		$batch_size = max( 10, min( 200, (int) $settings['batch_size'] ) );

		$state = array(
			'queue'        => $queue,
			'total'        => count( $queue ),
			'processed'    => 0,
			'skipped'      => 0,
			'results'      => array(),
			'scope'        => $scope,
			'batch_size'   => $batch_size,
			'started_at'   => time(),
		);

		set_transient( OIF_TRANSIENT_STATE, $state, HOUR_IN_SECONDS * 2 );

		wp_send_json_success(
			array(
				'total'      => $state['total'],
				'batch_size' => $batch_size,
				'message'    => sprintf(
					/* translators: %d: number of images */
					__( 'Found %d images to scan.', 'oversized-image-finder' ),
					$state['total']
				),
			)
		);
	}

	/**
	 * Process the next batch of images.
	 */
	public static function scan_batch() {
		self::verify_request();

		$state = get_transient( OIF_TRANSIENT_STATE );
		if ( ! is_array( $state ) || empty( $state['queue'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No active scan. Please start a new scan.', 'oversized-image-finder' ) ) );
		}

		$batch_size = isset( $state['batch_size'] ) ? (int) $state['batch_size'] : 50;
		$batch      = array_splice( $state['queue'], 0, $batch_size );

		$scanner = new OIF_Scanner( isset( $state['scope'] ) ? $state['scope'] : array() );
		$result  = $scanner->process_batch( $batch );

		$state['results']   = array_merge( $state['results'], $result['items'] );
		$state['processed'] = (int) $state['processed'] + count( $batch );
		$state['skipped']   = (int) $state['skipped'] + (int) $result['skipped'];

		$done = empty( $state['queue'] );

		if ( $done ) {
			$settings = oif_get_settings();
			$ttl      = max( 1, (int) $settings['cache_ttl_hours'] ) * HOUR_IN_SECONDS;

			$final = array(
				'items'      => $state['results'],
				'total'      => (int) $state['total'],
				'skipped'    => (int) $state['skipped'],
				'scope'      => $state['scope'],
				'scanned_at' => time(),
			);

			set_transient( OIF_TRANSIENT_RESULTS, $final, $ttl );
			delete_transient( OIF_TRANSIENT_STATE );

			wp_send_json_success(
				array(
					'done'       => true,
					'processed'  => $state['processed'],
					'total'      => $state['total'],
					'skipped'    => $state['skipped'],
					'message'    => __( 'Scan complete.', 'oversized-image-finder' ),
				)
			);
		}

		set_transient( OIF_TRANSIENT_STATE, $state, HOUR_IN_SECONDS * 2 );

		wp_send_json_success(
			array(
				'done'      => false,
				'processed' => $state['processed'],
				'total'     => $state['total'],
				'skipped'   => $state['skipped'],
			)
		);
	}

	/**
	 * Return cached scan results.
	 */
	public static function get_results() {
		self::verify_request();

		$cached = get_transient( OIF_TRANSIENT_RESULTS );
		if ( ! is_array( $cached ) ) {
			wp_send_json_success(
				array(
					'has_results' => false,
					'items'       => array(),
				)
			);
		}

		wp_send_json_success(
			array(
				'has_results' => true,
				'items'       => isset( $cached['items'] ) ? $cached['items'] : array(),
				'total'       => isset( $cached['total'] ) ? (int) $cached['total'] : 0,
				'skipped'     => isset( $cached['skipped'] ) ? (int) $cached['skipped'] : 0,
				'scanned_at'  => isset( $cached['scanned_at'] ) ? (int) $cached['scanned_at'] : 0,
			)
		);
	}

	/**
	 * Clear cached scan results.
	 */
	public static function clear_cache() {
		self::verify_request();

		delete_transient( OIF_TRANSIENT_RESULTS );
		delete_transient( OIF_TRANSIENT_STATE );

		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'oversized-image-finder' ) ) );
	}
}
