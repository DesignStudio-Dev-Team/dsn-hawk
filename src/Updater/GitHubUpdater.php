<?php

declare( strict_types=1 );

namespace DSN\Hawk\Updater;

/**
 * Self-updater that polls GitHub Releases for a new tag and hands the
 * attached zip asset to WordPress's plugin updater. Re-uses the same
 * plugin folder across updates via `upgrader_source_selection`.
 */
final class GitHubUpdater {

	private const TRANSIENT = 'dsn_hawk_gh_release';
	private const TTL       = 12 * HOUR_IN_SECONDS;

	public function __construct(
		private string $pluginFile,
		private string $slug,
		private string $repo,
		private string $version
	) {}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'injectUpdate' ] );
		add_filter( 'plugins_api', [ $this, 'pluginInfo' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'renameSource' ], 10, 4 );
		add_filter( 'upgrader_pre_download', [ $this, 'beforeDownload' ], 10, 3 );
	}

	public function injectUpdate( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$release = $this->latestRelease();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = $this->normaliseVersion( (string) ( $release['tag_name'] ?? '' ) );
		if ( $remote_version === '' ) {
			return $transient;
		}

		if ( version_compare( $remote_version, $this->version, '<=' ) ) {
			return $transient;
		}

		$zip_url = $this->zipUrl( $release );
		if ( $zip_url === '' ) {
			return $transient;
		}

		$basename = plugin_basename( $this->pluginFile );

		$update = (object) [
			'id'            => $basename,
			'slug'          => $this->slug,
			'plugin'        => $basename,
			'new_version'   => $remote_version,
			'url'           => $this->releaseUrl( $release ),
			'package'       => $zip_url,
			'tested'        => '',
			'requires_php'  => '8.1',
			'compatibility' => new \stdClass(),
		];

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		$transient->response[ $basename ] = $update;

		return $transient;
	}

	public function pluginInfo( mixed $result, string $action, object $args ): mixed {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latestRelease();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = $this->normaliseVersion( (string) ( $release['tag_name'] ?? '' ) );
		$body           = (string) ( $release['body'] ?? '' );

		return (object) [
			'name'          => 'DSN Hawk',
			'slug'          => $this->slug,
			'version'       => $remote_version !== '' ? $remote_version : $this->version,
			'author'        => '<a href="https://designstudionetwork.com">Juan Tamayo, DesignStudio Network</a>',
			'homepage'      => $this->releaseUrl( $release ),
			'requires'      => '6.0',
			'requires_php'  => '8.1',
			'download_link' => $this->zipUrl( $release ),
			'sections'      => [
				'description' => 'WordPress agent that reports site health + configuration to the DSN Skyline Laravel admin.',
				'changelog'   => $body !== '' ? wp_kses_post( nl2br( esc_html( $body ) ) ) : 'See GitHub releases.',
			],
		];
	}

	/**
	 * GitHub release zip assets aren't served with the correct WP folder name
	 * unless we build them deliberately. Force the extracted directory to
	 * match the installed plugin folder so updates overwrite in place.
	 */
	public function renameSource( mixed $source, mixed $remote_source, mixed $upgrader, mixed $hook_extra = null ): mixed {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}

		$plugin_slug = $this->slug;

		$hook_plugin = '';
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) ) {
			$hook_plugin = (string) $hook_extra['plugin'];
		}

		$ours = $hook_plugin === plugin_basename( $this->pluginFile );

		// Heuristic fallback: source dir mentions our repo name.
		if ( ! $ours ) {
			$basename_src = basename( rtrim( $source, '/\\' ) );
			if ( stripos( $basename_src, 'dsn-hawk' ) === false ) {
				return $source;
			}
		}

		$desired = trailingslashit( $remote_source ) . $plugin_slug;

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Ensures our download URL is fetched fresh when WP starts the upgrade.
	 * WP handles the actual download; we just return false to let it proceed.
	 */
	public function beforeDownload( mixed $reply, mixed $package, mixed $upgrader ): mixed {
		return $reply;
	}

	private function latestRelease(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = sprintf( 'https://api.github.com/repos/%s/releases/latest', rawurlencode( $this->repo ) );

		// rawurlencode escapes the '/' — undo.
		$url = str_replace( '%2F', '/', $url );

		$args = [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'DSN-Hawk/' . $this->version,
			],
		];

		$token = defined( 'DSN_HAWK_GH_TOKEN' ) ? (string) constant( 'DSN_HAWK_GH_TOKEN' ) : '';
		if ( $token !== '' ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			set_transient( self::TRANSIENT, [], MINUTE_IN_SECONDS * 15 );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			set_transient( self::TRANSIENT, [], MINUTE_IN_SECONDS * 15 );
			return null;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		set_transient( self::TRANSIENT, $decoded, self::TTL );
		return $decoded;
	}

	private function zipUrl( array $release ): string {
		$assets = $release['assets'] ?? [];
		if ( is_array( $assets ) ) {
			foreach ( $assets as $asset ) {
				$name = (string) ( $asset['name'] ?? '' );
				$url  = (string) ( $asset['browser_download_url'] ?? '' );
				if ( $url !== '' && str_ends_with( strtolower( $name ), '.zip' ) ) {
					return $url;
				}
			}
		}

		// Fallback: source zipball. Will need rename via upgrader_source_selection.
		return (string) ( $release['zipball_url'] ?? '' );
	}

	private function releaseUrl( array $release ): string {
		return (string) ( $release['html_url'] ?? ( 'https://github.com/' . $this->repo . '/releases' ) );
	}

	private function normaliseVersion( string $tag ): string {
		return ltrim( trim( $tag ), 'vV' );
	}
}
