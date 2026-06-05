<?php
/**
 * Helper functions for Oversized Image Finder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed image file extensions.
 *
 * @return string[]
 */
function oif_get_image_extensions() {
	return array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico', 'avif', 'svg' );
}

/**
 * Check if a file path has an image extension.
 *
 * @param string $path File path.
 * @return bool
 */
function oif_is_image_file( $path ) {
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	return in_array( $ext, oif_get_image_extensions(), true );
}

/**
 * Format bytes to human-readable size.
 *
 * @param int $bytes File size in bytes.
 * @return string
 */
function oif_format_bytes( $bytes ) {
	$bytes = max( 0, (int) $bytes );
	if ( $bytes < 1024 ) {
		return $bytes . ' B';
	}
	if ( $bytes < 1048576 ) {
		return round( $bytes / 1024, 1 ) . ' KB';
	}
	return round( $bytes / 1048576, 2 ) . ' MB';
}

/**
 * Get relative path from wp-content directory.
 *
 * @param string $absolute_path Absolute file path.
 * @return string
 */
function oif_get_relative_path( $absolute_path ) {
	$wp_content = wp_normalize_path( WP_CONTENT_DIR );
	$path       = wp_normalize_path( $absolute_path );

	if ( strpos( $path, $wp_content ) === 0 ) {
		return ltrim( substr( $path, strlen( $wp_content ) ), '/' );
	}

	return $path;
}

/**
 * Convert absolute path to public URL when possible.
 *
 * @param string $absolute_path Absolute file path.
 * @return string
 */
function oif_path_to_url( $absolute_path ) {
	$wp_content = wp_normalize_path( WP_CONTENT_DIR );
	$path       = wp_normalize_path( $absolute_path );

	if ( strpos( $path, $wp_content ) === 0 ) {
		$relative = ltrim( substr( $path, strlen( $wp_content ) ), '/' );
		return content_url( $relative );
	}

	return '';
}

/**
 * Determine severity badge for an image row.
 *
 * @param array<string, mixed> $item   Image data.
 * @param array<string, int>   $settings Plugin settings.
 * @return string high|medium|info
 */
function oif_get_severity( $item, $settings ) {
	$size_over = ! empty( $item['size_over'] );
	$dim_over  = ! empty( $item['dim_over'] );

	if ( $size_over && $dim_over ) {
		return 'high';
	}
	if ( $size_over || $dim_over ) {
		return 'medium';
	}
	return 'info';
}

/**
 * Check if image exceeds configured thresholds.
 *
 * @param array<string, mixed> $item     Image data.
 * @param array<string, int>   $settings Plugin settings.
 * @return array{size_over: bool, dim_over: bool, oversized: bool}
 */
function oif_check_thresholds( $item, $settings ) {
	$max_bytes = (int) $settings['max_file_size_kb'] * 1024;
	$width     = isset( $item['width'] ) ? (int) $item['width'] : 0;
	$height    = isset( $item['height'] ) ? (int) $item['height'] : 0;
	$filesize  = isset( $item['filesize'] ) ? (int) $item['filesize'] : 0;

	$size_over = $filesize > $max_bytes;
	$dim_over  = $width > (int) $settings['max_width'] || $height > (int) $settings['max_height'];

	return array(
		'size_over'  => $size_over,
		'dim_over'   => $dim_over,
		'oversized'  => $size_over || $dim_over,
	);
}

/**
 * Sanitize scan scope from request.
 *
 * @param array<string, mixed> $scope Raw scope input.
 * @return array{media_library: bool, uploads: bool, theme_plugins: bool}
 */
function oif_sanitize_scope( $scope ) {
	if ( ! is_array( $scope ) ) {
		$scope = array();
	}

	return array(
		'media_library'  => ! empty( $scope['media_library'] ),
		'uploads'        => ! empty( $scope['uploads'] ),
		'theme_plugins'  => ! empty( $scope['theme_plugins'] ),
	);
}
