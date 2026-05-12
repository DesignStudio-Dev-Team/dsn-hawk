<?php

declare( strict_types=1 );

namespace DSN\Hawk;

use DSN\Hawk\Remote\RemoteUpdateController;
use DSN\Hawk\Settings\SettingsPage;
use DSN\Hawk\Support\EmailLog;
use DSN\Hawk\Support\Logger;
use DSN\Hawk\Sync\Scheduler;
use DSN\Hawk\Sync\Syncer;
use DSN\Hawk\Updater\GitHubUpdater;

final class Plugin {

	public const OPTION_KEY = 'dsn_hawk_settings';
	public const CRON_HOOK  = 'dsn_hawk_sync';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		load_plugin_textdomain( 'dsn-hawk', false, dirname( DSN_HAWK_BASENAME ) . '/languages' );

		( new SettingsPage() )->register();
		( new Scheduler() )->register();
		( new RemoteUpdateController() )->register();

		// Always record outbound email so the EmailLogReport has data when enabled.
		EmailLog::registerHook();

		add_action( self::CRON_HOOK, [ $this, 'runSync' ] );

		( new GitHubUpdater(
			DSN_HAWK_FILE,
			DSN_HAWK_SLUG,
			DSN_HAWK_GH_REPO,
			DSN_HAWK_VERSION
		) )->register();
	}

	public function runSync(): void {
		try {
			( new Syncer() )->run();
		} catch ( \Throwable $e ) {
			Logger::write( 'error', 0, $e->getMessage(), 0 );
		}
	}

	public static function onActivate(): void {
		Logger::installTable();
		EmailLog::installTable();
		( new Scheduler() )->schedule();

		$existing = get_option( self::OPTION_KEY );
		if ( ! is_array( $existing ) ) {
			update_option( self::OPTION_KEY, self::defaultSettings() );
		}
	}

	public static function onDeactivate(): void {
		( new Scheduler() )->unschedule();
	}

	public static function defaultSettings(): array {
		return [
			'endpoint'  => defined( 'DSN_HAWK_DEFAULT_ENDPOINT' ) ? DSN_HAWK_DEFAULT_ENDPOINT : '',
			'token'     => '',
			'frequency' => 'dsn_hawk_hourly',
			'reports'   => [
				'gravity_forms'             => true,
				'gravity_forms_entries'     => true,
				'gravity_forms_entries_pii' => false,
				'email_log'                 => true,
				'plugins'                   => true,
				'core_theme'                => true,
				'file_integrity'            => false,
			],
			'last_sync' => [
				'timestamp' => 0,
				'status'    => '',
				'http_code' => 0,
				'message'   => '',
			],
		];
	}

	public static function settings(): array {
		$opts = get_option( self::OPTION_KEY );
		return is_array( $opts ) ? array_replace_recursive( self::defaultSettings(), $opts ) : self::defaultSettings();
	}
}
