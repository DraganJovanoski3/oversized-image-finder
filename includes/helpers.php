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
 * Detect WordPress auto-generated thumbnail/resized files.
 *
 * @param string $path File path.
 * @return bool
 */
function oif_is_wp_thumbnail( $path ) {
	$filename = basename( $path );
	return (bool) preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp|avif)$/i', $filename );
}

/**
 * Determine if an image is likely slowing the site down.
 *
 * @param array<string, mixed> $item     Image data.
 * @param array<string, int>   $settings Plugin settings.
 * @return string slow|ok
 */
function oif_get_slow_risk( $item, $settings ) {
	$slow_bytes = (int) $settings['slow_threshold_kb'] * 1024;
	$filesize   = isset( $item['filesize'] ) ? (int) $item['filesize'] : 0;

	if ( $filesize >= $slow_bytes ) {
		return 'slow';
	}

	return 'ok';
}

/**
 * Sort items by file size descending (largest first).
 *
 * @param array<int, array<string, mixed>> $items Image items.
 * @return array<int, array<string, mixed>>
 */
function oif_sort_by_size_desc( $items ) {
	usort(
		$items,
		function ( $a, $b ) {
			return (int) ( $b['filesize'] ?? 0 ) <=> (int) ( $a['filesize'] ?? 0 );
		}
	);

	return $items;
}

/**
 * Get file size for a queue entry (fast path for sorting).
 *
 * @param array{path: string, attachment_id?: int} $entry Queue entry.
 * @return int
 */
function oif_get_entry_filesize( $entry ) {
	$path = isset( $entry['path'] ) ? wp_normalize_path( $entry['path'] ) : '';
	if ( '' === $path || ! file_exists( $path ) ) {
		return 0;
	}

	$attachment_id = isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0;
	if ( $attachment_id > 0 ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) && ! empty( $metadata['filesize'] ) ) {
			return (int) $metadata['filesize'];
		}
	}

	$size = @filesize( $path );
	return false !== $size ? (int) $size : 0;
}

/**
 * Limit queue to the N largest files first.
 *
 * @param array<int, array<string, mixed>> $queue Full queue.
 * @param int                              $limit Max images to scan (0 = all).
 * @return array{queue: array<int, array<string, mixed>>, found: int, limited: bool, scan_limit: int}
 */
function oif_limit_queue_largest_first( $queue, $limit ) {
	$found = count( $queue );
	$limit = max( 0, (int) $limit );

	if ( 0 === $limit || $found <= $limit ) {
		return array(
			'queue'      => $queue,
			'found'      => $found,
			'limited'    => false,
			'scan_limit' => 0 === $limit ? $found : $limit,
		);
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}

	foreach ( $queue as $index => $entry ) {
		$queue[ $index ]['sort_size'] = oif_get_entry_filesize( $entry );
	}

	usort(
		$queue,
		function ( $a, $b ) {
			return (int) ( $b['sort_size'] ?? 0 ) <=> (int) ( $a['sort_size'] ?? 0 );
		}
	);

	$queue = array_slice( $queue, 0, $limit );

	return array(
		'queue'      => $queue,
		'found'      => $found,
		'limited'    => true,
		'scan_limit' => $limit,
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
