<?php

declare( strict_types=1 );

namespace DSN\Hawk\Sync;

use DSN\Hawk\Plugin;

final class Scheduler {

	public const INTERVAL_HOURLY = 'dsn_hawk_hourly';
	public const INTERVAL_DAILY  = 'daily';
	public const MANUAL          = 'manual';

	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'addIntervals' ] );
		add_action( 'update_option_' . Plugin::OPTION_KEY, [ $this, 'onSettingsChange' ], 10, 2 );
	}

	public function addIntervals( array $schedules ): array {
		$schedules[ self::INTERVAL_HOURLY ] = [
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'DSN Hawk — Hourly', 'dsn-hawk' ),
		];
		return $schedules;
	}

	public function schedule(): void {
		$settings  = Plugin::settings();
		$frequency = (string) ( $settings['frequency'] ?? self::INTERVAL_HOURLY );

		wp_clear_scheduled_hook( Plugin::CRON_HOOK );

		if ( $frequency === self::MANUAL ) {
			return;
		}

		if ( ! wp_next_scheduled( Plugin::CRON_HOOK ) ) {
			wp_schedule_event( $this->firstRunAt( $frequency ), $frequency, Plugin::CRON_HOOK );
		}
	}

	public function unschedule(): void {
		wp_clear_scheduled_hook( Plugin::CRON_HOOK );
	}

	public function onSettingsChange( mixed $old, mixed $new ): void {
		$old_frequency = is_array( $old ) ? (string) ( $old['frequency'] ?? self::INTERVAL_HOURLY ) : self::INTERVAL_HOURLY;
		$new_frequency = is_array( $new ) ? (string) ( $new['frequency'] ?? self::INTERVAL_HOURLY ) : self::INTERVAL_HOURLY;

		if ( $old_frequency === $new_frequency ) {
			return;
		}

		$this->schedule();
	}

	private function firstRunAt( string $frequency ): int {
		if ( $frequency === self::INTERVAL_DAILY ) {
			return time() + DAY_IN_SECONDS;
		}

		return time() + MINUTE_IN_SECONDS;
	}
}
