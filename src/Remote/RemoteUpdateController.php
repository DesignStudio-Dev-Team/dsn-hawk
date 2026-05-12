<?php

declare( strict_types=1 );

namespace DSN\Hawk\Remote;

use DSN\Hawk\Sync\Syncer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RemoteUpdateController {

	private const NAMESPACE = 'dsn-hawk/v1';
	private const ROUTE     = '/updates/run';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'authorize' ],
				'args'                => [
					'dry_run' => [
						'type'    => 'boolean',
						'default' => false,
					],
					'core'    => [
						'type'    => 'boolean',
						'default' => false,
					],
					'all_plugins' => [
						'type'    => 'boolean',
						'default' => false,
					],
					'plugins' => [
						'type'    => 'array',
						'default' => [],
						'items'   => [
							'type' => 'string',
						],
					],
				],
			]
		);
	}

	public function authorize( WP_REST_Request $request ): bool|WP_Error {
		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lock_key = 'dsn_hawk_remote_update_lock';
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'dsn_hawk_update_locked', 'Another remote update is already running.', [ 'status' => 409 ] );
		}

		$core    = (bool) $request->get_param( 'core' );
		$all_plugins = (bool) $request->get_param( 'all_plugins' );
		$plugins = $this->pluginsParam( $request->get_param( 'plugins' ) );
		$dry_run = (bool) $request->get_param( 'dry_run' );

		if ( ! $core && ! $all_plugins && empty( $plugins ) ) {
			return new WP_Error( 'dsn_hawk_no_updates_requested', 'Request must include core=true, all_plugins=true, or at least one plugin file.', [ 'status' => 422 ] );
		}

		set_transient( $lock_key, time(), 15 * MINUTE_IN_SECONDS );

		try {
			$result = ( new RemoteUpdater() )->run( $core, $plugins, $dry_run, $all_plugins );

			if ( ! $dry_run ) {
				$sync = ( new Syncer() )->run();
				$result['post_update_sync'] = $sync;
			}
		} finally {
			delete_transient( $lock_key );
		}

		return new WP_REST_Response( $result, ! empty( $result['ok'] ) ? 200 : 207 );
	}

	private function pluginsParam( mixed $plugins ): array {
		if ( ! is_array( $plugins ) ) {
			return [];
		}

		$out = [];
		foreach ( $plugins as $plugin ) {
			$plugin = sanitize_text_field( (string) $plugin );
			if ( $plugin !== '' ) {
				$out[] = $plugin;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
