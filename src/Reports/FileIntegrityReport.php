<?php

declare( strict_types=1 );

namespace DSN\Hawk\Reports;

final class FileIntegrityReport implements ReportInterface {

	private const CACHE_KEY = 'dsn_hawk_file_integrity';
	private const CACHE_TTL = DAY_IN_SECONDS;
	private const MAX_UPLOADS_FILES = 50;

	public function key(): string {
		return 'file_integrity';
	}

	public function isAvailable(): bool {
		return true;
	}

	public function collect(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = [
			'core_mismatches'    => [],
			'core_checksum_ok'   => false,
			'scanned_core_files' => 0,
			'suspect_uploads'    => [],
			'scanned_at'         => time(),
		];

		$checksums = $this->coreChecksums();
		if ( is_array( $checksums ) ) {
			$result['core_checksum_ok']   = true;
			[ $mismatches, $scanned ]      = $this->diffCore( $checksums );
			$result['core_mismatches']    = $mismatches;
			$result['scanned_core_files'] = $scanned;
		}

		$result['suspect_uploads'] = $this->scanUploads();

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * @return array<string,string>|null map of path => md5
	 */
	private function coreChecksums(): ?array {
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		global $wp_version, $wp_local_package;

		if ( ! function_exists( 'get_core_checksums' ) ) {
			return null;
		}

		$locale = $wp_local_package ?: 'en_US';
		$map    = get_core_checksums( (string) $wp_version, $locale );
		return is_array( $map ) ? $map : null;
	}

	/**
	 * @param array<string,string> $checksums
	 * @return array{0: array<int,string>, 1: int}
	 */
	private function diffCore( array $checksums ): array {
		$mismatches = [];
		$scanned    = 0;

		foreach ( $checksums as $rel => $expected ) {
			// Only look at wp-admin and wp-includes per the README scope.
			if ( strncmp( $rel, 'wp-admin/', 9 ) !== 0 && strncmp( $rel, 'wp-includes/', 12 ) !== 0 ) {
				continue;
			}
			$path = ABSPATH . $rel;
			if ( ! is_readable( $path ) ) {
				$mismatches[] = $rel;
				continue;
			}

			$actual = @md5_file( $path );
			++$scanned;
			if ( $actual === false || $actual === null ) {
				continue;
			}
			if ( strtolower( (string) $actual ) !== strtolower( (string) $expected ) ) {
				$mismatches[] = $rel;
			}

			// Runaway guard.
			if ( count( $mismatches ) > 200 ) {
				break;
			}
		}

		return [ $mismatches, $scanned ];
	}

	/**
	 * @return array<int,string>
	 */
	private function scanUploads(): array {
		$dir  = wp_upload_dir();
		$base = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
		if ( $base === '' || ! is_dir( $base ) ) {
			return [];
		}

		$suspects = [];
		try {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS )
			);
			foreach ( $it as $file ) {
				if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( in_array( $ext, [ 'php', 'phtml', 'phar', 'php5', 'php7', 'php8' ], true ) ) {
					$suspects[] = str_replace( $base, '', $file->getPathname() );
					if ( count( $suspects ) >= self::MAX_UPLOADS_FILES ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			// swallow — reported at Skyline as empty suspects, not a hard failure.
		}

		return $suspects;
	}

	public function onSynced(): void {
		// no-op
	}
}
