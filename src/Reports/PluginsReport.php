<?php

declare( strict_types=1 );

namespace DSN\Hawk\Reports;

final class PluginsReport implements ReportInterface {

	public function key(): string {
		return 'plugins';
	}

	public function isAvailable(): bool {
		return true;
	}

	public function collect(): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all             = get_plugins();
		$active_list     = (array) get_option( 'active_plugins', [] );
		$auto_updates    = (array) get_site_option( 'auto_update_plugins', [] );
		$update_site     = get_site_transient( 'update_plugins' );
		$update_info     = is_object( $update_site ) && isset( $update_site->response ) && is_array( $update_site->response )
			? $update_site->response
			: [];
		$no_update       = is_object( $update_site ) && isset( $update_site->no_update ) && is_array( $update_site->no_update )
			? $update_site->no_update
			: [];
		$checked         = is_object( $update_site ) && isset( $update_site->last_checked )
			? (int) $update_site->last_checked
			: 0;

		$plugins = [];
		foreach ( $all as $file => $data ) {
			$slug = $this->slugFromFile( $file );

			$new_version = '';
			$tested      = '';
			$meta        = $update_info[ $file ] ?? ( $no_update[ $file ] ?? null );
			if ( is_object( $meta ) ) {
				$new_version = isset( $meta->new_version ) ? (string) $meta->new_version : '';
				$tested      = isset( $meta->tested ) ? (string) $meta->tested : '';
			}
			$modified_at = $this->pluginModifiedAt( $file );

			$plugins[] = [
				'file'            => $file,
				'slug'            => $slug,
				'name'            => $this->stringOrNull( $data['Name'] ?? null ) ?? $slug,
				'version'         => $this->stringOrNull( $data['Version'] ?? null ),
				'latest_version'  => $this->stringOrNull( $new_version !== '' ? $new_version : ( $data['Version'] ?? null ) ),
				'author'          => $this->stringOrNull( wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ) ),
				'plugin_uri'      => $this->stringOrNull( $data['PluginURI'] ?? null ),
				'is_active'       => in_array( $file, $active_list, true ) || is_plugin_active_for_network( $file ),
				'is_network'      => is_plugin_active_for_network( $file ),
				'auto_update'     => in_array( $file, $auto_updates, true ),
				'update_available' => $new_version !== '' && version_compare( $new_version, (string) ( $data['Version'] ?? '0' ), '>' ),
				'update_status'   => $this->updateStatus( $file, $update_info, $no_update, $new_version, (string) ( $data['Version'] ?? '' ) ),
				'new_version'     => $this->stringOrNull( $new_version ),
				'tested_up_to'    => $this->stringOrNull( $tested ),
				'last_modified'   => $modified_at,
				'last_updated_at' => $modified_at,
			];
		}

		return [
			'plugins'            => $plugins,
			'last_update_check'  => $checked,
			'last_update_check_at' => $checked > 0 ? gmdate( 'c', $checked ) : null,
		];
	}

	private function slugFromFile( string $file ): string {
		$parts = explode( '/', $file );
		return $parts[0] ?? $file;
	}

	private function updateStatus( string $file, array $update_info, array $no_update, string $new_version, string $current_version ): string {
		if ( $new_version !== '' && version_compare( $new_version, $current_version, '>' ) ) {
			return 'update_available';
		}
		if ( isset( $no_update[ $file ] ) ) {
			return 'current';
		}
		return 'unknown';
	}

	private function pluginModifiedAt( string $file ): ?string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$mtime = filemtime( $path );
		return $mtime ? gmdate( 'c', $mtime ) : null;
	}

	private function stringOrNull( mixed $value ): ?string {
		if ( $value === null ) {
			return null;
		}

		$value = trim( (string) $value );
		return $value !== '' ? $value : null;
	}

	public function onSynced(): void {
		// no-op
	}
}
