<?php
/**
 * File-based storage for large scan queues and results.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OIF_Queue_Store {

	const DIR_NAME = 'oif-scan';

	/**
	 * Get scan storage directory path.
	 *
	 * @return string
	 */
	public static function get_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Save scan queue to disk.
	 *
	 * @param array<int, array<string, mixed>> $queue Queue entries.
	 * @return bool
	 */
	public static function save_queue( $queue ) {
		$path = self::get_dir() . '/queue.json';
		$json = wp_json_encode( $queue );

		if ( ! $json ) {
			return false;
		}

		return false !== file_put_contents( $path, $json, LOCK_EX );
	}

	/**
	 * Load the full scan queue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function load_queue() {
		$path = self::get_dir() . '/queue.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get a slice of the queue.
	 *
	 * @param int $offset Start index.
	 * @param int $limit  Number of items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_queue_slice( $offset, $limit ) {
		$queue = self::load_queue();
		return array_slice( $queue, $offset, $limit );
	}

	/**
	 * Append result items as JSON lines.
	 *
	 * @param array<int, array<string, mixed>> $items Result items.
	 */
	public static function append_results( $items ) {
		if ( empty( $items ) ) {
			return;
		}

		$path    = self::get_dir() . '/results.jsonl';
		$lines   = '';
		foreach ( $items as $item ) {
			$line = wp_json_encode( $item );
			if ( $line ) {
				$lines .= $line . "\n";
			}
		}

		file_put_contents( $path, $lines, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Load all results, sorted largest-first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function load_results_sorted() {
		$final = self::get_dir() . '/results-final.json';
		if ( file_exists( $final ) ) {
			$data = json_decode( (string) file_get_contents( $final ), true );
			return is_array( $data ) ? $data : array();
		}

		$path = self::get_dir() . '/results.jsonl';
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$items = array();
		$fh    = fopen( $path, 'r' );
		if ( ! $fh ) {
			return array();
		}

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$item = json_decode( trim( $line ), true );
			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}
		fclose( $fh );

		usort(
			$items,
			function ( $a, $b ) {
				return (int) ( $b['filesize'] ?? 0 ) <=> (int) ( $a['filesize'] ?? 0 );
			}
		);

		return $items;
	}

	/**
	 * Load previously finalized scan results.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function load_existing_final_results() {
		$final = self::get_dir() . '/results-final.json';
		if ( ! file_exists( $final ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $final ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Finalize results: sort, merge with previous scan, and write JSON file.
	 *
	 * @param bool $merge_existing Whether to merge with a previous results file.
	 * @return array<int, array<string, mixed>>
	 */
	public static function finalize_results( $merge_existing = true ) {
		$items    = self::load_results_sorted();
		$existing = $merge_existing ? self::load_existing_final_results() : array();

		if ( ! empty( $existing ) ) {
			$items = oif_merge_scan_results( $existing, $items );
		}

		$final = self::get_dir() . '/results-final.json';
		$json  = wp_json_encode( $items );

		if ( $json ) {
			file_put_contents( $final, $json, LOCK_EX );
		}

		$jsonl = self::get_dir() . '/results.jsonl';
		if ( file_exists( $jsonl ) ) {
			wp_delete_file( $jsonl );
		}

		return $items;
	}

	/**
	 * Update one item in finalized results.
	 *
	 * @param array<string, mixed> $updated Updated item.
	 */
	public static function update_result_item( $updated ) {
		$items = self::load_existing_final_results();
		if ( empty( $items ) ) {
			return;
		}

		$name = OIF_Scanned_Registry::get_name_from_entry( $updated );
		foreach ( $items as $index => $item ) {
			if ( OIF_Scanned_Registry::get_name_from_entry( $item ) === $name ) {
				$items[ $index ] = array_merge( $item, $updated );
				break;
			}
		}

		$items = oif_sort_by_size_desc( $items );
		$json  = wp_json_encode( $items );
		if ( $json ) {
			file_put_contents( self::get_dir() . '/results-final.json', $json, LOCK_EX );
		}
	}

	/**
	 * Delete queue file only.
	 */
	public static function cleanup_queue_only() {
		$path = self::get_dir() . '/queue.json';
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Remove all scan files.
	 */
	public static function cleanup() {
		$dir = self::get_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array( 'queue.json', 'results.jsonl', 'results-final.json' );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
