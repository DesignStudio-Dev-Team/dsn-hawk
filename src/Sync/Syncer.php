<?php

declare( strict_types=1 );

namespace DSN\Hawk\Sync;

use DSN\Hawk\Plugin;
use DSN\Hawk\Reports\CoreThemeReport;
use DSN\Hawk\Reports\EmailLogReport;
use DSN\Hawk\Reports\FileIntegrityReport;
use DSN\Hawk\Reports\GravityFormsReport;
use DSN\Hawk\Reports\PluginsReport;
use DSN\Hawk\Reports\ReportInterface;
use DSN\Hawk\Support\Logger;
use DSN\Hawk\Support\SiteInfo;

final class Syncer {

	/**
	 * @return array{ok:bool,code:int,message:string,status:string}
	 */
	public function run(): array {
		try {
			return $this->runInternal();
		} catch ( \Throwable $e ) {
			return $this->record( 'error', 0, 'Sync failed: ' . $e->getMessage(), 0 );
		}
	}

	/**
	 * @return array{ok:bool,code:int,message:string,status:string}
	 */
	private function runInternal(): array {
		$settings = Plugin::settings();

		if ( empty( $settings['endpoint'] ) || empty( $settings['token'] ) ) {
			return $this->record( 'skipped', 0, 'Missing endpoint or token.', 0 );
		}

		$endpoint = (string) $settings['endpoint'];
		if ( ! preg_match( '#^https://#i', $endpoint ) && ! defined( 'DSN_HAWK_ALLOW_INSECURE' ) ) {
			return $this->record( 'error', 0, 'Refusing to POST to non-HTTPS endpoint.', 0 );
		}

		$executed      = [];
		$report_errors = [];
		$reports_payload = $this->collectReports( (array) ( $settings['reports'] ?? [] ), $executed, $report_errors );
		if ( ! empty( $report_errors ) ) {
			$reports_payload['_errors'] = $report_errors;
		}

		$payload = [
			'site'    => SiteInfo::payload(),
			'reports' => $reports_payload,
		];

		$bytes  = strlen( (string) wp_json_encode( $payload ) );
		$client = new HttpClient( $endpoint, (string) $settings['token'] );
		$res    = $client->post( $payload );

		if ( $res['ok'] ) {
			foreach ( $executed as $report ) {
				try {
					$report->onSynced();
				} catch ( \Throwable $e ) {
					// Commit failure on a single report shouldn't block the run.
				}
			}
			$message = empty( $report_errors ) ? 'ok' : 'ok with report errors: ' . implode( '; ', array_map( static fn ( array $error ): string => $error['report'] . ': ' . $error['message'], $report_errors ) );
			return $this->record( 'ok', $res['code'], $message, $bytes );
		}

		if ( $res['code'] === 401 ) {
			return $this->record( 'unauthorized', 401, 'Invalid token.', $bytes );
		}

		if ( $res['code'] === 422 ) {
			return $this->record( 'invalid', 422, $res['body'] !== '' ? $res['body'] : 'validation error', $bytes );
		}

		return $this->record( 'error', $res['code'], $res['message'], $bytes );
	}

	/**
	 * @param array              $enabled  Map of report_key => truthy.
	 * @param ReportInterface[]  &$executed Populated with reports that actually contributed a slice
	 *                                      (used for post-success cursor commits).
	 */
	private function collectReports( array $enabled, array &$executed, array &$errors ): array {
		$reports = [];
		foreach ( $this->availableReports() as $report ) {
			$key = $report->key();
			try {
				if ( empty( $enabled[ $key ] ) ) {
					continue;
				}
				if ( ! $report->isAvailable() ) {
					continue;
				}
				$slice = $report->collect();
			} catch ( \Throwable $e ) {
				$errors[] = [
					'report'  => $key,
					'message' => $e->getMessage(),
				];
				continue;
			}
			if ( $slice !== null ) {
				$reports[ $key ] = $slice;
				$executed[]      = $report;
			}
		}
		return $reports;
	}

	/**
	 * @return ReportInterface[]
	 */
	private function availableReports(): array {
		$reports = [
			new GravityFormsReport(),
			new EmailLogReport(),
			new PluginsReport(),
			new CoreThemeReport(),
			new FileIntegrityReport(),
		];
		/** @var ReportInterface[] $filtered */
		$filtered = apply_filters( 'dsn_hawk_reports', $reports );
		return $filtered;
	}

	private function record( string $status, int $code, string $message, int $bytes ): array {
		Logger::write( $status, $code, $message, $bytes );

		$settings                = Plugin::settings();
		$settings['last_sync']   = [
			'timestamp' => time(),
			'status'    => $status,
			'http_code' => $code,
			'message'   => $message,
		];
		update_option( Plugin::OPTION_KEY, $settings );

		return [
			'ok'      => $status === 'ok',
			'code'    => $code,
			'message' => $message,
			'status'  => $status,
		];
	}
}
