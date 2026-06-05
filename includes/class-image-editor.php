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
	 * Scale an image down via AJAX.
	 */
	public static function scale_image() {
		self::verify_request();

		$path          = isset( $_POST['path'] ) ? wp_normalize_path( wp_unslash( $_POST['path'] ) ) : '';
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$max_width     = isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 0;
		$max_height    = isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 0;
		$scale_percent = isset( $_POST['scale_percent'] ) ? absint( $_POST['scale_percent'] ) : 0;
		$quality       = isset( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 82;

		$quality = max( 50, min( 100, $quality ) );

		if ( '' === $path || ! self::is_allowed_path( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image path.', 'oversized-image-finder' ) ) );
		}

		if ( ! file_exists( $path ) || ! is_readable( $path ) || ! is_writable( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Image file is missing or not writable.', 'oversized-image-finder' ) ) );
		}

		if ( ! oif_is_image_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'File is not a supported image.', 'oversized-image-finder' ) ) );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'svg', 'ico' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This image format cannot be scaled here.', 'oversized-image-finder' ) ) );
		}

		if ( $attachment_id > 0 ) {
			$attached = get_attached_file( $attachment_id );
			if ( ! $attached || wp_normalize_path( $attached ) !== $path ) {
				wp_send_json_error( array( 'message' => __( 'Attachment does not match this file.', 'oversized-image-finder' ) ) );
			}
		}

		$image_info = @getimagesize( $path );
		if ( ! is_array( $image_info ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not read image dimensions.', 'oversized-image-finder' ) ) );
		}

		$orig_width  = (int) $image_info[0];
		$orig_height = (int) $image_info[1];
		$orig_size   = (int) @filesize( $path );

		if ( $orig_width < 1 || $orig_height < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image dimensions.', 'oversized-image-finder' ) ) );
		}

		$target = self::calculate_target_size( $orig_width, $orig_height, $max_width, $max_height, $scale_percent );
		if ( is_wp_error( $target ) ) {
			wp_send_json_error( array( 'message' => $target->get_error_message() ) );
		}

		if ( $target['width'] >= $orig_width && $target['height'] >= $orig_height ) {
			wp_send_json_error( array( 'message' => __( 'Target size must be smaller than the current image.', 'oversized-image-finder' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			wp_send_json_error( array( 'message' => $editor->get_error_message() ) );
		}

		$editor->set_quality( $quality );
		$resized = $editor->resize( $target['width'], $target['height'], false );
		if ( is_wp_error( $resized ) ) {
			wp_send_json_error( array( 'message' => $resized->get_error_message() ) );
		}

		$saved = $editor->save( $path );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
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

		$thresholds            = oif_check_thresholds( $updated, $settings );
		$updated['size_over']  = $thresholds['size_over'];
		$updated['dim_over']   = $thresholds['dim_over'];
		$updated['oversized']  = $thresholds['oversized'];
		$updated['severity']   = oif_get_severity( $updated, $settings );
		$updated['slow_risk']  = oif_get_slow_risk( $updated, $settings );

		OIF_Queue_Store::update_result_item( $updated );

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: 1: old size, 2: new size */
					__( 'Image scaled from %1$s to %2$s.', 'oversized-image-finder' ),
					oif_format_bytes( $orig_size ),
					oif_format_bytes( $new_size )
				),
				'item'       => $updated,
				'before'     => array(
					'width'    => $orig_width,
					'height'   => $orig_height,
					'filesize' => $orig_size,
				),
				'saved_bytes' => max( 0, $orig_size - $new_size ),
			)
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
