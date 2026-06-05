<?php
/**
 * Image scanner for Oversized Image Finder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OIF_Scanner {

	/**
	 * Scan scope configuration.
	 *
	 * @var array{media_library: bool, uploads: bool, theme_plugins: bool}
	 */
	private $scope;

	/**
	 * Plugin settings.
	 *
	 * @var array<string, int>
	 */
	private $settings;

	/**
	 * Discovered file paths (dedupe key).
	 *
	 * @var array<string, bool>
	 */
	private $seen_paths = array();

	/**
	 * Constructor.
	 *
	 * @param array{media_library: bool, uploads: bool, theme_plugins: bool} $scope    Scan scope.
	 * @param array<string, int>                                             $settings Plugin settings.
	 */
	public function __construct( $scope, $settings = null ) {
		$this->scope    = oif_sanitize_scope( $scope );
		$this->settings = null !== $settings ? $settings : oif_get_settings();
	}

	/**
	 * Build a flat list of all files to scan.
	 *
	 * @return array<int, array{source: string, path: string, attachment_id?: int}>
	 */
	public function collect_scan_queue() {
		$queue = array();

		if ( $this->scope['media_library'] ) {
			$queue = array_merge( $queue, $this->collect_media_library_queue() );
		}

		if ( $this->scope['uploads'] ) {
			$queue = array_merge( $queue, $this->collect_directory_queue( wp_normalize_path( WP_CONTENT_DIR . '/uploads' ), 'uploads' ) );
		}

		if ( $this->scope['theme_plugins'] ) {
			$queue = array_merge( $queue, $this->collect_theme_plugin_queue() );
		}

		return $queue;
	}

	/**
	 * Process a batch of queue items.
	 *
	 * @param array<int, array{source: string, path: string, attachment_id?: int}> $batch Queue batch.
	 * @return array{items: array<int, array<string, mixed>>, skipped: int}
	 */
	public function process_batch( $batch ) {
		$items   = array();
		$skipped = 0;

		foreach ( $batch as $entry ) {
			$item = $this->build_item_from_entry( $entry );
			if ( null === $item ) {
				++$skipped;
				continue;
			}

			$thresholds         = oif_check_thresholds( $item, $this->settings );
			$item['size_over']  = $thresholds['size_over'];
			$item['dim_over']   = $thresholds['dim_over'];
			$item['oversized']  = $thresholds['oversized'];
			$item['severity']   = oif_get_severity( $item, $this->settings );

			$items[] = $item;
		}

		return array(
			'items'   => $items,
			'skipped' => $skipped,
		);
	}

	/**
	 * Collect Media Library attachment queue entries.
	 *
	 * @return array<int, array{source: string, path: string, attachment_id: int}>
	 */
	private function collect_media_library_queue() {
		$queue = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif', 'image/svg+xml' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			$path = get_attached_file( $attachment_id );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}

			$normalized = wp_normalize_path( $path );
			if ( isset( $this->seen_paths[ $normalized ] ) ) {
				continue;
			}

			$this->seen_paths[ $normalized ] = true;
			$queue[] = array(
				'source'        => 'media_library',
				'path'          => $normalized,
				'attachment_id' => (int) $attachment_id,
			);
		}

		return $queue;
	}

	/**
	 * Collect theme and plugin directory queue entries.
	 *
	 * @return array<int, array{source: string, path: string}>
	 */
	private function collect_theme_plugin_queue() {
		$queue       = array();
		$directories = array();

		$stylesheet = get_stylesheet_directory();
		$template   = get_template_directory();

		if ( $stylesheet ) {
			$directories[] = wp_normalize_path( $stylesheet );
		}
		if ( $template && $template !== $stylesheet ) {
			$directories[] = wp_normalize_path( $template );
		}

		if ( is_dir( WP_PLUGIN_DIR ) ) {
			$plugin_dirs = glob( trailingslashit( WP_PLUGIN_DIR ) . '*', GLOB_ONLYDIR );
			if ( is_array( $plugin_dirs ) ) {
				foreach ( $plugin_dirs as $plugin_dir ) {
					$directories[] = wp_normalize_path( $plugin_dir );
				}
			}
		}

		$directories = array_unique( $directories );

		foreach ( $directories as $directory ) {
			$queue = array_merge( $queue, $this->collect_directory_queue( $directory, 'theme_plugins' ) );
		}

		return $queue;
	}

	/**
	 * Recursively collect image files from a directory.
	 *
	 * @param string $directory Directory path.
	 * @param string $source    Source label.
	 * @return array<int, array{source: string, path: string}>
	 */
	private function collect_directory_queue( $directory, $source ) {
		$queue = array();

		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return $queue;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( Exception $e ) {
			return $queue;
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = wp_normalize_path( $file->getPathname() );
			if ( ! oif_is_image_file( $path ) ) {
				continue;
			}

			if ( isset( $this->seen_paths[ $path ] ) ) {
				continue;
			}

			$this->seen_paths[ $path ] = true;
			$queue[] = array(
				'source' => $source,
				'path'   => $path,
			);
		}

		return $queue;
	}

	/**
	 * Build a result item from a queue entry.
	 *
	 * @param array{source: string, path: string, attachment_id?: int} $entry Queue entry.
	 * @return array<string, mixed>|null
	 */
	private function build_item_from_entry( $entry ) {
		$path = isset( $entry['path'] ) ? wp_normalize_path( $entry['path'] ) : '';

		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		if ( ! oif_is_image_file( $path ) ) {
			return null;
		}

		$filesize = @filesize( $path );
		if ( false === $filesize ) {
			return null;
		}

		$attachment_id = isset( $entry['attachment_id'] ) ? (int) $entry['attachment_id'] : 0;
		$width         = 0;
		$height        = 0;
		$mime          = '';
		$upload_date   = '';
		$usage_count   = 0;
		$in_library    = false;

		if ( $attachment_id > 0 ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $metadata ) ) {
				$width  = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
				$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
			}

			$mime = get_post_mime_type( $attachment_id );
			if ( ! $mime ) {
				$mime = wp_check_filetype( $path )['type'];
			}

			$post = get_post( $attachment_id );
			if ( $post ) {
				$upload_date = $post->post_date;
			}

			$usage_count = $this->get_attachment_usage_count( $attachment_id, $path );
			$in_library  = true;
		} else {
			$image_info = @getimagesize( $path );
			if ( is_array( $image_info ) ) {
				$width  = isset( $image_info[0] ) ? (int) $image_info[0] : 0;
				$height = isset( $image_info[1] ) ? (int) $image_info[1] : 0;
				$mime   = isset( $image_info['mime'] ) ? $image_info['mime'] : '';
			}

			if ( ! $mime ) {
				$mime = wp_check_filetype( $path )['type'];
			}
		}

		$url = oif_path_to_url( $path );
		if ( ! $url && $attachment_id > 0 ) {
			$url = wp_get_attachment_url( $attachment_id );
		}

		return array(
			'path'          => $path,
			'filename'      => basename( $path ),
			'filesize'      => (int) $filesize,
			'filesize_h'    => oif_format_bytes( $filesize ),
			'width'         => $width,
			'height'        => $height,
			'dimensions'    => ( $width && $height ) ? $width . ' × ' . $height : '—',
			'mime'          => $mime ? $mime : '—',
			'extension'     => strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) ),
			'location'      => oif_get_relative_path( $path ),
			'source'        => $entry['source'],
			'attachment_id' => $attachment_id,
			'in_library'    => $in_library,
			'upload_date'   => $upload_date,
			'usage_count'   => $usage_count,
			'url'           => $url ? $url : '',
		);
	}

	/**
	 * Count posts/pages using an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path          File path.
	 * @return int
	 */
	private function get_attachment_usage_count( $attachment_id, $path ) {
		global $wpdb;

		$url       = wp_get_attachment_url( $attachment_id );
		$basename  = basename( $path );
		$like_url  = $url ? '%' . $wpdb->esc_like( $url ) . '%' : '';
		$like_file = '%' . $wpdb->esc_like( $basename ) . '%';
		$like_id   = '%' . $wpdb->esc_like( 'wp-image-' . $attachment_id ) . '%';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts}
				WHERE post_status IN ('publish', 'draft', 'pending', 'future', 'private')
				AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset')
				AND (
					post_content LIKE %s
					OR post_content LIKE %s
					OR post_content LIKE %s
				)",
				$like_url,
				$like_file,
				$like_id
			)
		);

		$thumb_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
				WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
				$attachment_id
			)
		);

		return $count + $thumb_count;
	}
}
