<?php

declare( strict_types=1 );

namespace DSN\Hawk\Reports;

final class CoreThemeReport implements ReportInterface {

	public function key(): string {
		return 'core_theme';
	}

	public function isAvailable(): bool {
		return true;
	}

	public function collect(): ?array {
		global $wpdb, $wp_version;

		$core_update    = get_site_transient( 'update_core' );
		$latest_core    = '';
		$core_has_update = false;
		$core_checked    = is_object( $core_update ) && isset( $core_update->last_checked )
			? (int) $core_update->last_checked
			: 0;
		if ( is_object( $core_update ) && ! empty( $core_update->updates ) ) {
			foreach ( $core_update->updates as $upd ) {
				if ( isset( $upd->response ) && $upd->response === 'upgrade' ) {
					$core_has_update = true;
					$latest_core     = (string) ( $upd->current ?? '' );
					break;
				}
				if ( isset( $upd->current ) && $latest_core === '' ) {
					$latest_core = (string) $upd->current;
				}
			}
		}

		$active_theme = wp_get_theme();
		$parent       = $active_theme->parent();

		$theme_updates  = get_site_transient( 'update_themes' );
		$theme_response = is_object( $theme_updates ) && isset( $theme_updates->response ) && is_array( $theme_updates->response )
			? $theme_updates->response
			: [];
		$theme_new      = '';
		$theme_stylesheet = get_stylesheet();
		if ( isset( $theme_response[ $theme_stylesheet ] ) && is_array( $theme_response[ $theme_stylesheet ] ) ) {
			$theme_new = (string) ( $theme_response[ $theme_stylesheet ]['new_version'] ?? '' );
		}

		return [
			'wordpress' => [
				'version'       => (string) $wp_version,
				'latest'        => $this->stringOrNull( $latest_core ),
				'update_available' => $core_has_update,
				'update_status' => $core_has_update ? 'update_available' : ( $latest_core !== '' ? 'current' : 'unknown' ),
				'last_update_check' => $core_checked,
				'last_update_check_at' => $core_checked > 0 ? gmdate( 'c', $core_checked ) : null,
				'last_successful_update_version' => $this->stringOrNull( get_option( '_core_updated_successfully', '' ) ),
				'last_updated_at' => $this->coreModifiedAt(),
				'multisite'     => is_multisite(),
				'locale'        => get_locale(),
			],
			'theme'     => [
				'stylesheet'       => $theme_stylesheet,
				'template'         => get_template(),
				'name'             => $this->stringOrNull( $active_theme->get( 'Name' ) ),
				'version'          => $this->stringOrNull( $active_theme->get( 'Version' ) ),
				'author'           => $this->stringOrNull( wp_strip_all_tags( (string) $active_theme->get( 'Author' ) ) ),
				'parent'           => $parent ? $this->stringOrNull( $parent->get( 'Name' ) ) : null,
				'parent_version'   => $parent ? $this->stringOrNull( $parent->get( 'Version' ) ) : null,
				'update_available' => $theme_new !== '' && version_compare( $theme_new, (string) $active_theme->get( 'Version' ), '>' ),
				'new_version'      => $this->stringOrNull( $theme_new ),
			],
			'server'    => [
				'php_version'     => PHP_VERSION,
				'php_sapi'        => PHP_SAPI,
				'mysql_version'   => (string) $wpdb->db_version(),
				'memory_limit'    => (string) ini_get( 'memory_limit' ),
				'max_execution'   => (int) ini_get( 'max_execution_time' ),
				'upload_max'      => (string) ini_get( 'upload_max_filesize' ),
				'post_max'        => (string) ini_get( 'post_max_size' ),
				'wp_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
				'wp_debug'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'ssl'             => is_ssl(),
			],
		];
	}

	public function onSynced(): void {
		// no-op
	}

	private function coreModifiedAt(): ?string {
		$path = ABSPATH . WPINC . '/version.php';
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
}
