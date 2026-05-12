<?php

declare( strict_types=1 );

namespace DSN\Hawk\Remote;

use Automatic_Upgrader_Skin;
use Core_Upgrader;
use Plugin_Upgrader;
use WP_Error;

final class RemoteUpdater {

	public function run( bool $core, array $plugins, bool $dry_run, bool $all_plugins = false ): array {
		$this->loadUpgradeApis();

		wp_version_check();
		wp_update_plugins();

		if ( $all_plugins ) {
			$plugins = $this->updateablePlugins();
		}

		$results = [
			'ok'          => true,
			'dry_run'     => $dry_run,
			'all_plugins' => $all_plugins,
			'core'        => null,
			'plugins'     => [],
		];

		if ( $core ) {
			$results['core'] = $dry_run ? $this->previewCore() : $this->updateCore();
			$results['ok']   = $results['ok'] && ! empty( $results['core']['ok'] );
		}

		foreach ( $plugins as $plugin_file ) {
			$results['plugins'][ $plugin_file ] = $dry_run
				? $this->previewPlugin( $plugin_file )
				: $this->updatePlugin( $plugin_file );

			$results['ok'] = $results['ok'] && ! empty( $results['plugins'][ $plugin_file ]['ok'] );
		}

		return $results;
	}

	private function previewCore(): array {
		$update = $this->coreUpdate();
		if ( $update === null ) {
			return [
				'ok'     => true,
				'status' => 'current',
			];
		}

		return [
			'ok'          => true,
			'status'      => 'update_available',
			'new_version' => (string) ( $update->current ?? '' ),
			'package'     => ! empty( $update->package ),
		];
	}

	private function updateCore(): array {
		$update = $this->coreUpdate();
		if ( $update === null ) {
			return [
				'ok'     => true,
				'status' => 'current',
			];
		}

		$result = ( new Core_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $update );

		if ( is_wp_error( $result ) ) {
			return $this->errorResult( $result );
		}

		if ( $result === false || $result === null ) {
			return [
				'ok'      => false,
				'status'  => 'failed',
				'message' => 'WordPress core update did not complete.',
			];
		}

		return [
			'ok'          => true,
			'status'      => 'updated',
			'new_version' => (string) ( $update->current ?? '' ),
		];
	}

	private function previewPlugin( string $plugin_file ): array {
		$validation = $this->validatePlugin( $plugin_file );
		if ( $validation !== null ) {
			return $validation;
		}

		$update = $this->pluginUpdate( $plugin_file );
		if ( $update === null ) {
			return [
				'ok'     => true,
				'status' => 'current',
			];
		}

		return [
			'ok'          => true,
			'status'      => 'update_available',
			'new_version' => (string) ( $update->new_version ?? '' ),
			'package'     => ! empty( $update->package ),
		];
	}

	private function updatePlugin( string $plugin_file ): array {
		$validation = $this->validatePlugin( $plugin_file );
		if ( $validation !== null ) {
			return $validation;
		}

		$update = $this->pluginUpdate( $plugin_file );
		if ( $update === null ) {
			return [
				'ok'     => true,
				'status' => 'current',
			];
		}

		$result = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $this->errorResult( $result );
		}

		if ( $result !== true ) {
			return [
				'ok'      => false,
				'status'  => 'failed',
				'message' => 'Plugin update did not complete.',
			];
		}

		return [
			'ok'          => true,
			'status'      => 'updated',
			'new_version' => (string) ( $update->new_version ?? '' ),
		];
	}

	private function validatePlugin( string $plugin_file ): ?array {
		$plugins = get_plugins();

		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return [
				'ok'      => false,
				'status'  => 'not_found',
				'message' => 'Plugin is not installed on this site.',
			];
		}

		return null;
	}

	private function pluginUpdate( string $plugin_file ): ?object {
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			return null;
		}

		$update = $updates->response[ $plugin_file ] ?? null;
		return is_object( $update ) ? $update : null;
	}

	private function updateablePlugins(): array {
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			return [];
		}

		return array_values( array_keys( $updates->response ) );
	}

	private function coreUpdate(): ?object {
		$updates = get_site_transient( 'update_core' );
		if ( ! is_object( $updates ) || empty( $updates->updates ) || ! is_array( $updates->updates ) ) {
			return null;
		}

		foreach ( $updates->updates as $update ) {
			if ( is_object( $update ) && isset( $update->response ) && $update->response === 'upgrade' ) {
				return $update;
			}
		}

		return null;
	}

	private function errorResult( WP_Error $error ): array {
		return [
			'ok'      => false,
			'status'  => 'failed',
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		];
	}

	private function loadUpgradeApis(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Core_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
	}
}
