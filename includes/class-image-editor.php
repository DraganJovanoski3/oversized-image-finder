<?php
/**
 * Scale/resize images from the scan results table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OIF_Image_Editor {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_oif_scale_image', array( __CLASS__, 'scale_image' ) );
		add_action( 'wp_ajax_oif_bulk_scale', array( __CLASS__, 'bulk_scale' ) );
	}

	/**
	 * Verify AJAX request.
	 */
	private static function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'oversized-image-finder' ) ), 403 );
		}

		check_ajax_referer( 'oif_scan_nonce', 'nonce' );
	}

	/**
	 * Scale a single image via AJAX.
	 */
	public static function scale_image() {
		self::verify_request();

		$path          = isset( $_POST['path'] ) ? wp_normalize_path( wp_unslash( $_POST['path'] ) ) : '';
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		$result = self::scale_file(
			$path,
			$attachment_id,
			self::get_scale_params_from_request()
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Scale multiple images in one AJAX request.
	 */
	public static function bulk_scale() {
		self::verify_request();

		$raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items     = is_string( $raw_items ) ? json_decode( $raw_items, true ) : array();

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'No images selected.', 'oversized-image-finder' ) ) );
		}

		$settings   = oif_get_settings();
		$max_batch  = max( 1, min( 20, (int) $settings['bulk_scale_batch'] ) );
		$items      = array_slice( $items, 0, $max_batch );
		$params     = self::get_scale_params_from_request();
		$scaled     = 0;
		$skipped    = 0;
		$errors     = array();
		$saved      = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				++$skipped;
				continue;
			}

			$path          = isset( $item['path'] ) ? wp_normalize_path( $item['path'] ) : '';
			$attachment_id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
			$result        = self::scale_file( $path, $attachment_id, $params );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'filename' => isset( $item['filename'] ) ? $item['filename'] : basename( $path ),
					'message'  => $result->get_error_message(),
				);
				++$skipped;
				continue;
			}

			++$scaled;
			$saved += (int) ( $result['saved_bytes'] ?? 0 );
		}

		wp_send_json_success(
			array(
				'scaled'      => $scaled,
				'skipped'     => $skipped,
				'saved_bytes' => $saved,
				'errors'      => $errors,
			)
		);
	}

	/**
	 * Read scale parameters from the current request.
	 *
	 * @return array{max_width: int, max_height: int, scale_percent: int, quality: int}
	 */
	private static function get_scale_params_from_request() {
		$quality = isset( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 82;

		return array(
			'max_width'     => isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 0,
			'max_height'    => isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 0,
			'scale_percent' => isset( $_POST['scale_percent'] ) ? absint( $_POST['scale_percent'] ) : 0,
			'quality'       => max( 50, min( 100, $quality ) ),
		);
	}

	/**
	 * Scale one image file.
	 *
	 * @param string               $path          Absolute file path.
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, int>   $params        Scale parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function scale_file( $path, $attachment_id, $params ) {
		$max_width     = (int) $params['max_width'];
		$max_height    = (int) $params['max_height'];
		$scale_percent = (int) $params['scale_percent'];
		$quality       = (int) $params['quality'];

		if ( '' === $path || ! self::is_allowed_path( $path ) ) {
			return new WP_Error( 'oif_invalid_path', __( 'Invalid image path.', 'oversized-image-finder' ) );
		}

		if ( ! file_exists( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			return new WP_Error( 'oif_file_unavailable', __( 'Image file is missing or not writable.', 'oversized-image-finder' ) );
		}

		if ( ! oif_is_image_file( $path ) ) {
			return new WP_Error( 'oif_not_image', __( 'File is not a supported image.', 'oversized-image-finder' ) );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'svg', 'ico' ), true ) ) {
			return new WP_Error( 'oif_format_unsupported', __( 'This image format cannot be scaled here.', 'oversized-image-finder' ) );
		}

		if ( $attachment_id > 0 ) {
			$attached = get_attached_file( $attachment_id );
			if ( ! $attached || wp_normalize_path( $attached ) !== $path ) {
				return new WP_Error( 'oif_attachment_mismatch', __( 'Attachment does not match this file.', 'oversized-image-finder' ) );
			}
		}

		$image_info = @getimagesize( $path );
		if ( ! is_array( $image_info ) ) {
			return new WP_Error( 'oif_no_dimensions', __( 'Could not read image dimensions.', 'oversized-image-finder' ) );
		}

		$orig_width  = (int) $image_info[0];
		$orig_height = (int) $image_info[1];
		$orig_size   = (int) @filesize( $path );

		if ( $orig_width < 1 || $orig_height < 1 ) {
			return new WP_Error( 'oif_invalid_dimensions', __( 'Invalid image dimensions.', 'oversized-image-finder' ) );
		}

		$target = self::calculate_target_size( $orig_width, $orig_height, $max_width, $max_height, $scale_percent );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $target['width'] >= $orig_width && $target['height'] >= $orig_height ) {
			return new WP_Error( 'oif_not_smaller', __( 'Target size must be smaller than the current image.', 'oversized-image-finder' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->set_quality( $quality );
		$resized = $editor->resize( $target['width'], $target['height'], false );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$saved_file = $editor->save( $path );
		if ( is_wp_error( $saved_file ) ) {
			return $saved_file;
		}

		if ( $attachment_id > 0 ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
			if ( ! is_wp_error( $metadata ) && is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		$new_size   = (int) @filesize( $path );
		$new_info   = @getimagesize( $path );
		$new_width  = is_array( $new_info ) ? (int) $new_info[0] : $target['width'];
		$new_height = is_array( $new_info ) ? (int) $new_info[1] : $target['height'];
		$settings   = oif_get_settings();

		$updated = array(
			'path'          => $path,
			'filename'      => basename( $path ),
			'filesize'      => $new_size,
			'filesize_h'    => oif_format_bytes( $new_size ),
			'width'         => $new_width,
			'height'        => $new_height,
			'dimensions'    => $new_width . ' × ' . $new_height,
			'mime'          => get_post_mime_type( $attachment_id ) ?: ( is_array( $new_info ) && ! empty( $new_info['mime'] ) ? $new_info['mime'] : '' ),
			'extension'     => strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) ),
			'location'      => oif_get_relative_path( $path ),
			'attachment_id' => $attachment_id,
			'in_library'    => $attachment_id > 0,
			'url'           => $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : oif_path_to_url( $path ),
		);

		$thresholds           = oif_check_thresholds( $updated, $settings );
		$updated['size_over'] = $thresholds['size_over'];
		$updated['dim_over']  = $thresholds['dim_over'];
		$updated['oversized'] = $thresholds['oversized'];
		$updated['severity']  = oif_get_severity( $updated, $settings );
		$updated['slow_risk'] = oif_get_slow_risk( $updated, $settings );

		OIF_Queue_Store::update_result_item( $updated );

		return array(
			'message'     => sprintf(
				/* translators: 1: old size, 2: new size */
				__( 'Image scaled from %1$s to %2$s.', 'oversized-image-finder' ),
				oif_format_bytes( $orig_size ),
				oif_format_bytes( $new_size )
			),
			'item'        => $updated,
			'before'      => array(
				'width'    => $orig_width,
				'height'   => $orig_height,
				'filesize' => $orig_size,
			),
			'saved_bytes' => max( 0, $orig_size - $new_size ),
		);
	}

	/**
	 * Ensure path stays inside wp-content.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_allowed_path( $path ) {
		$wp_content = wp_normalize_path( WP_CONTENT_DIR );
		return strpos( $path, $wp_content ) === 0;
	}

	/**
	 * Calculate target dimensions.
	 *
	 * @param int $orig_width  Original width.
	 * @param int $orig_height Original height.
	 * @param int $max_width   Max width.
	 * @param int $max_height  Max height.
	 * @param int $scale_percent Scale percent.
	 * @return array{width:int,height:int}|WP_Error
	 */
	private static function calculate_target_size( $orig_width, $orig_height, $max_width, $max_height, $scale_percent ) {
		if ( $scale_percent > 0 ) {
			$scale_percent = max( 5, min( 95, $scale_percent ) );
			return array(
				'width'  => max( 1, (int) round( $orig_width * ( $scale_percent / 100 ) ) ),
				'height' => max( 1, (int) round( $orig_height * ( $scale_percent / 100 ) ) ),
			);
		}

		if ( $max_width < 1 && $max_height < 1 ) {
			return new WP_Error( 'oif_invalid_target', __( 'Enter a max width, max height, or scale percentage.', 'oversized-image-finder' ) );
		}

		if ( $max_width < 1 ) {
			$max_width = $orig_width;
		}
		if ( $max_height < 1 ) {
			$max_height = $orig_height;
		}

		$ratio_w = $max_width / $orig_width;
		$ratio_h = $max_height / $orig_height;
		$ratio   = min( $ratio_w, $ratio_h, 1 );

		return array(
			'width'  => max( 1, (int) round( $orig_width * $ratio ) ),
			'height' => max( 1, (int) round( $orig_height * $ratio ) ),
		);
	}
}
