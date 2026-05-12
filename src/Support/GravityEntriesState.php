<?php

declare( strict_types=1 );

namespace DSN\Hawk\Support;

/**
 * Per-form backfill/incremental state for Gravity Forms entry sync.
 *
 * Option shape:
 *   [ form_id => [ 'cursor' => int, 'backfilled' => bool, 'updated_at' => int ] ]
 *
 * cursor     = last entry ID successfully shipped to Skyline.
 * backfilled = true once the initial (historic) backfill has drained.
 *              After that, only new entries (id > cursor) are shipped each run.
 */
final class GravityEntriesState {

	public const OPTION_KEY = 'dsn_hawk_gf_entries_state';

	public static function all(): array {
		$state = get_option( self::OPTION_KEY );
		return is_array( $state ) ? $state : [];
	}

	public static function for( int|string $form_id ): array {
		$all = self::all();
		$key = (string) $form_id;
		$row = $all[ $key ] ?? [];

		return [
			'cursor'     => isset( $row['cursor'] ) ? (int) $row['cursor'] : 0,
			'backfilled' => ! empty( $row['backfilled'] ),
			'updated_at' => isset( $row['updated_at'] ) ? (int) $row['updated_at'] : 0,
		];
	}

	public static function set( int|string $form_id, int $cursor, bool $backfilled ): void {
		$all                       = self::all();
		$key                       = (string) $form_id;
		$all[ $key ]               = [
			'cursor'     => $cursor,
			'backfilled' => $backfilled,
			'updated_at' => time(),
		];
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function reset(): void {
		delete_option( self::OPTION_KEY );
	}
}
