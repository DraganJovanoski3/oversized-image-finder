<?php
/**
 * Lightweight registry of already-scanned image filenames.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OIF_Scanned_Registry {

	const FILE_NAME = 'scanned-names.txt';

	/**
	 * Get registry file path.
	 *
	 * @return string
	 */
	public static function get_file_path() {
		return OIF_Queue_Store::get_dir() . '/' . self::FILE_NAME;
	}

	/**
	 * Normalize a filename for registry storage.
	 *
	 * @param string $name Filename.
	 * @return string
	 */
	public static function normalize_name( $name ) {
		return strtolower( trim( (string) $name ) );
	}

	/**
	 * Get registry name from a queue entry or result item.
	 *
	 * @param array<string, mixed> $entry Queue entry or scan item.
	 * @return string
	 */
	public static function get_name_from_entry( $entry ) {
		if ( ! empty( $entry['filename'] ) ) {
			return self::normalize_name( $entry['filename'] );
		}

		$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';
		return self::normalize_name( basename( $path ) );
	}

	/**
	 * Load scanned names into a lookup set.
	 *
	 * @return array<string, bool>
	 */
	public static function load_set() {
		$path = self::get_file_path();
		$set  = array();

		if ( ! file_exists( $path ) ) {
			return $set;
		}

		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return $set;
		}

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$name = self::normalize_name( $line );
			if ( '' !== $name ) {
				$set[ $name ] = true;
			}
		}

		fclose( $fh );

		return $set;
	}

	/**
	 * Count remembered scanned names.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::load_set() );
	}

	/**
	 * Remove queue entries that were already scanned.
	 *
	 * @param array<int, array<string, mixed>> $queue Scan queue.
	 * @return array{queue: array<int, array<string, mixed>>, skipped: int}
	 */
	public static function filter_queue( $queue ) {
		$registry = self::load_set();
		if ( empty( $registry ) ) {
			return array(
				'queue'   => $queue,
				'skipped' => 0,
			);
		}

		$filtered = array();
		$skipped  = 0;

		foreach ( $queue as $entry ) {
			$name = self::get_name_from_entry( $entry );
			if ( '' === $name || isset( $registry[ $name ] ) ) {
				++$skipped;
				continue;
			}

			$filtered[] = $entry;
		}

		return array(
			'queue'   => $filtered,
			'skipped' => $skipped,
		);
	}

	/**
	 * Remember scanned image filenames.
	 *
	 * @param array<int, array<string, mixed>> $items Scanned items.
	 */
	public static function register_items( $items ) {
		if ( empty( $items ) ) {
			return;
		}

		$existing = self::load_set();
		$lines    = '';

		foreach ( $items as $item ) {
			$name = self::get_name_from_entry( $item );
			if ( '' === $name || isset( $existing[ $name ] ) ) {
				continue;
			}

			$existing[ $name ] = true;
			$lines            .= $name . "\n";
		}

		if ( '' === $lines ) {
			return;
		}

		file_put_contents( self::get_file_path(), $lines, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Clear scanned filename history.
	 */
	public static function clear() {
		$path = self::get_file_path();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
