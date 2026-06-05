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
		add_action( 'wp_ajax_oif_finish_scan', array( __CLASS__, 'finish_scan' ) );
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
	 * Start a new scan — build queue and store on disk.
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

		self::clear_scan_data();

		$scanner = new OIF_Scanner( $scope );
		$queue   = $scanner->collect_scan_queue();

		if ( ! OIF_Queue_Store::save_queue( $queue ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save scan queue.', 'oversized-image-finder' ) ) );
		}

		$settings   = oif_get_settings();
		$batch_size = max( 10, min( 200, (int) $settings['batch_size'] ) );

		$state = array(
			'processed'  => 0,
			'total'      => count( $queue ),
			'skipped'    => 0,
			'scope'      => $scope,
			'batch_size' => $batch_size,
			'started_at' => time(),
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
		if ( ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'No active scan. Please start a new scan.', 'oversized-image-finder' ) ) );
		}

		$processed  = (int) ( $state['processed'] ?? 0 );
		$total      = (int) ( $state['total'] ?? 0 );
		$batch_size = (int) ( $state['batch_size'] ?? 50 );

		if ( $processed >= $total ) {
			wp_send_json_error( array( 'message' => __( 'Scan already completed.', 'oversized-image-finder' ) ) );
		}

		$batch   = OIF_Queue_Store::get_queue_slice( $processed, $batch_size );
		$scanner = new OIF_Scanner( isset( $state['scope'] ) ? $state['scope'] : array() );
		$result  = $scanner->process_batch( $batch );

		OIF_Queue_Store::append_results( $result['items'] );

		$state['processed'] = $processed + count( $batch );
		$state['skipped']   = (int) ( $state['skipped'] ?? 0 ) + (int) $result['skipped'];

		$done = $state['processed'] >= $total;

		if ( $done ) {
			$completed = self::complete_scan( $state, false );

			wp_send_json_success(
				array_merge(
					$completed,
					array(
						'done'        => true,
						'batch_items' => $result['items'],
						'message'     => __( 'Scan complete. Showing largest files first.', 'oversized-image-finder' ),
					)
				)
			);
		}

		$settings    = oif_get_settings();
		$per_page    = (int) $settings['per_page'];
		$top_preview = isset( $state['top_preview'] ) && is_array( $state['top_preview'] ) ? $state['top_preview'] : array();
		$top_preview = array_merge( $top_preview, $result['items'] );
		$top_preview = array_slice( oif_sort_by_size_desc( $top_preview ), 0, $per_page );
		$state['top_preview'] = $top_preview;

		set_transient( OIF_TRANSIENT_STATE, $state, HOUR_IN_SECONDS * 2 );

		wp_send_json_success(
			array(
				'done'        => false,
				'processed'   => $state['processed'],
				'total'       => $total,
				'skipped'     => $state['skipped'],
				'batch_items' => $result['items'],
				'preview'     => $top_preview,
			)
		);
	}

	/**
	 * Return cached scan results with pagination.
	 */
	public static function get_results() {
		self::verify_request();

		$cached = get_transient( OIF_TRANSIENT_RESULTS );
		$page   = max( 1, isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1 );
		$filter = isset( $_POST['filter'] ) ? sanitize_key( $_POST['filter'] ) : 'largest_slow';

		if ( ! is_array( $cached ) ) {
			wp_send_json_success(
				array(
					'has_results' => false,
					'items'       => array(),
					'total_items' => 0,
					'page'        => 1,
					'total_pages' => 0,
				)
			);
		}

		$settings  = oif_get_settings();
		$per_page  = max( 10, min( 200, (int) $settings['per_page'] ) );
		$all_items = OIF_Queue_Store::load_results_sorted();
		$filtered  = self::filter_items( $all_items, $filter, $settings );
		$total     = count( $filtered );
		$pages     = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$offset    = ( $page - 1 ) * $per_page;
		$items     = array_slice( $filtered, $offset, $per_page );

		$rank_offset = $offset;
		foreach ( $items as $index => $item ) {
			$items[ $index ]['rank'] = $rank_offset + $index + 1;
		}

		wp_send_json_success(
			array(
				'has_results'  => true,
				'items'        => $items,
				'total_items'  => $total,
				'all_count'    => count( $all_items ),
				'page'         => $page,
				'total_pages'  => $pages,
				'per_page'     => $per_page,
				'total'        => isset( $cached['total'] ) ? (int) $cached['total'] : 0,
				'skipped'      => isset( $cached['skipped'] ) ? (int) $cached['skipped'] : 0,
				'scanned_at'   => isset( $cached['scanned_at'] ) ? (int) $cached['scanned_at'] : 0,
				'slow_count'   => isset( $cached['slow_count'] ) ? (int) $cached['slow_count'] : 0,
				'largest_kb'   => isset( $cached['largest_kb'] ) ? (float) $cached['largest_kb'] : 0,
				'safe_line_at' => self::find_safe_line_rank( $filtered, $settings ),
				'partial'      => ! empty( $cached['partial'] ),
				'processed'    => isset( $cached['processed'] ) ? (int) $cached['processed'] : 0,
			)
		);
	}

	/**
	 * Filter items by mode.
	 *
	 * @param array<int, array<string, mixed>> $items    All items.
	 * @param string                           $filter   Filter mode.
	 * @param array<string, int>               $settings Plugin settings.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_items( $items, $filter, $settings ) {
		$max_bytes  = (int) $settings['max_file_size_kb'] * 1024;
		$slow_bytes = (int) $settings['slow_threshold_kb'] * 1024;
		$max_width  = (int) $settings['max_width'];
		$max_height = (int) $settings['max_height'];

		$filtered = array_filter(
			$items,
			function ( $item ) use ( $filter, $max_bytes, $slow_bytes, $max_width, $max_height ) {
				$filesize = (int) ( $item['filesize'] ?? 0 );
				$width    = (int) ( $item['width'] ?? 0 );
				$height   = (int) ( $item['height'] ?? 0 );

				switch ( $filter ) {
					case 'largest_slow':
						return $filesize >= $slow_bytes;
					case 'oversized':
						return $filesize > $max_bytes || $width > $max_width || $height > $max_height;
					case 'size':
						return $filesize > $max_bytes;
					case 'dimensions':
						return $width > $max_width || $height > $max_height;
					case 'all':
					default:
						return true;
				}
			}
		);

		return array_values( $filtered );
	}

	/**
	 * Find rank where files drop below slow threshold.
	 *
	 * @param array<int, array<string, mixed>> $items    Sorted items.
	 * @param array<string, int>               $settings Plugin settings.
	 * @return int
	 */
	private static function find_safe_line_rank( $items, $settings ) {
		$slow_bytes = (int) $settings['slow_threshold_kb'] * 1024;

		foreach ( $items as $index => $item ) {
			if ( (int) ( $item['filesize'] ?? 0 ) < $slow_bytes ) {
				return $index + 1;
			}
		}

		return 0;
	}

	/**
	 * Stop an active scan early and keep results scanned so far.
	 */
	public static function finish_scan() {
		self::verify_request();

		$state = get_transient( OIF_TRANSIENT_STATE );
		if ( ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'No active scan to finish.', 'oversized-image-finder' ) ) );
		}

		$processed = (int) ( $state['processed'] ?? 0 );
		if ( $processed <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No images scanned yet. Wait for at least one batch to complete.', 'oversized-image-finder' ) ) );
		}

		$completed = self::complete_scan( $state, true );

		wp_send_json_success(
			array_merge(
				$completed,
				array(
					'done'     => true,
					'partial'  => true,
					'message'  => __( 'Scan stopped early. Showing largest files found so far.', 'oversized-image-finder' ),
				)
			)
		);
	}

	/**
	 * Finalize scan results and persist metadata.
	 *
	 * @param array<string, mixed> $state   Scan state.
	 * @param bool                 $partial Whether the scan was stopped early.
	 * @return array<string, mixed>
	 */
	private static function complete_scan( $state, $partial ) {
		$items    = OIF_Queue_Store::finalize_results();
		$settings = oif_get_settings();
		$ttl      = max( 1, (int) $settings['cache_ttl_hours'] ) * HOUR_IN_SECONDS;
		$slow_kb  = (int) $settings['slow_threshold_kb'];
		$total    = (int) ( $state['total'] ?? 0 );

		$slow_count = 0;
		foreach ( $items as $item ) {
			if ( (int) ( $item['filesize'] ?? 0 ) >= $slow_kb * 1024 ) {
				++$slow_count;
			}
		}

		$final = array(
			'total'       => $total,
			'processed'   => (int) ( $state['processed'] ?? 0 ),
			'skipped'     => (int) ( $state['skipped'] ?? 0 ),
			'scope'       => isset( $state['scope'] ) ? $state['scope'] : array(),
			'scanned_at'  => time(),
			'item_count'  => count( $items ),
			'slow_count'  => $slow_count,
			'largest_kb'  => ! empty( $items[0]['filesize'] ) ? round( (int) $items[0]['filesize'] / 1024, 1 ) : 0,
			'storage'     => 'file',
			'partial'     => $partial,
		);

		set_transient( OIF_TRANSIENT_RESULTS, $final, $ttl );
		delete_transient( OIF_TRANSIENT_STATE );
		OIF_Queue_Store::cleanup_queue_only();

		$preview = array_slice( $items, 0, (int) $settings['per_page'] );

		return array(
			'processed'  => $final['processed'],
			'total'        => $total,
			'skipped'      => $final['skipped'],
			'preview'      => $preview,
			'slow_count'   => $slow_count,
			'largest_kb'   => $final['largest_kb'],
			'partial'      => $partial,
			'item_count'   => $final['item_count'],
		);
	}

	/**
	 * Clear cached scan results.
	 */
	public static function clear_cache() {
		self::verify_request();
		self::clear_scan_data();

		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'oversized-image-finder' ) ) );
	}

	/**
	 * Remove scan transients and files.
	 */
	private static function clear_scan_data() {
		delete_transient( OIF_TRANSIENT_RESULTS );
		delete_transient( OIF_TRANSIENT_STATE );
		OIF_Queue_Store::cleanup();
	}

}
