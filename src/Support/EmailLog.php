<?php

declare( strict_types=1 );

namespace DSN\Hawk\Support;

/**
 * Persistent counter for wp_mail() calls. Row per sent email — one column
 * per signal we need on the Skyline side. Pruned to 30 days.
 */
final class EmailLog {

	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsn_hawk_email_log';
	}

	public static function installTable(): void {
		global $wpdb;
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			recipient_domain VARCHAR(191) NOT NULL DEFAULT '',
			subject_hash CHAR(16) NOT NULL DEFAULT '',
			recipients_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY recipient_domain (recipient_domain)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function registerHook(): void {
		add_action( 'wp_mail', [ self::class, 'record' ] );
	}

	/**
	 * Fired before wp_mail dispatches. Returns args unchanged.
	 *
	 * @param array $atts
	 */
	public static function record( $atts ): array {
		if ( ! is_array( $atts ) ) {
			return is_array( $atts ) ? $atts : [];
		}

		$to      = $atts['to'] ?? '';
		$subject = (string) ( $atts['subject'] ?? '' );

		$recipients = self::flattenRecipients( $to );
		if ( empty( $recipients ) ) {
			return $atts;
		}

		$domain_counts = [];
		foreach ( $recipients as $addr ) {
			$d = self::domainOf( $addr );
			if ( $d === '' ) {
				continue;
			}
			$domain_counts[ $d ] = ( $domain_counts[ $d ] ?? 0 ) + 1;
		}

		if ( empty( $domain_counts ) ) {
			return $atts;
		}

		global $wpdb;
		$table = self::tableName();
		$now   = current_time( 'mysql', true );
		$hash  = substr( hash( 'sha256', self::fingerprintSubject( $subject ) ), 0, 16 );

		foreach ( $domain_counts as $domain => $count ) {
			$wpdb->insert(
				$table,
				[
					'created_at'       => $now,
					'recipient_domain' => substr( $domain, 0, 191 ),
					'subject_hash'     => $hash,
					'recipients_count' => $count,
				],
				[ '%s', '%s', '%s', '%d' ]
			);
		}

		self::prune();
		return $atts;
	}

	public static function summary(): array {
		global $wpdb;
		$table = self::tableName();

		$counts = [
			'1h'  => (int) $wpdb->get_var(
				"SELECT COALESCE(SUM(recipients_count), 0) FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
			),
			'24h' => (int) $wpdb->get_var(
				"SELECT COALESCE(SUM(recipients_count), 0) FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)"
			),
			'7d'  => (int) $wpdb->get_var(
				"SELECT COALESCE(SUM(recipients_count), 0) FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
			),
		];

		$top_domains = (array) $wpdb->get_results(
			"SELECT recipient_domain AS domain, SUM(recipients_count) AS total
			 FROM {$table}
			 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
			 GROUP BY recipient_domain
			 ORDER BY total DESC
			 LIMIT 10",
			ARRAY_A
		);

		$top_subjects = (array) $wpdb->get_results(
			"SELECT subject_hash, COUNT(*) AS hits
			 FROM {$table}
			 WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
			 GROUP BY subject_hash
			 ORDER BY hits DESC
			 LIMIT 10",
			ARRAY_A
		);

		// Anomaly: 24h > 3x rolling 7d average of daily volumes.
		$avg_daily = $counts['7d'] / 7;
		$anomaly   = $avg_daily > 0 && $counts['24h'] > ( 3 * $avg_daily );

		return [
			'counts'       => $counts,
			'top_domains'  => array_map(
				static fn ( $r ) => [
					'domain' => (string) $r['domain'],
					'total'  => (int) $r['total'],
				],
				$top_domains
			),
			'top_subjects' => array_map(
				static fn ( $r ) => [
					'hash' => (string) $r['subject_hash'],
					'hits' => (int) $r['hits'],
				],
				$top_subjects
			),
			'anomaly'      => $anomaly,
		];
	}

	private static function prune( int $days = 30 ): void {
		global $wpdb;
		$table = self::tableName();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	private static function flattenRecipients( mixed $to ): array {
		if ( is_string( $to ) ) {
			$to = preg_split( '/[,;]+/', $to ) ?: [];
		}
		if ( ! is_array( $to ) ) {
			return [];
		}
		$out = [];
		foreach ( $to as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$trim = trim( $entry );
			if ( $trim === '' ) {
				continue;
			}
			// strip "Name <addr@d>" formatting
			if ( preg_match( '/<([^>]+)>/', $trim, $m ) ) {
				$trim = $m[1];
			}
			if ( is_email( $trim ) ) {
				$out[] = strtolower( $trim );
			}
		}
		return $out;
	}

	private static function domainOf( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( $at === false ) {
			return '';
		}
		return substr( $email, $at + 1 );
	}

	/**
	 * Normalise subjects so "Order #12345" and "Order #67890" hash the same.
	 */
	private static function fingerprintSubject( string $subject ): string {
		$s = strtolower( trim( $subject ) );
		$s = preg_replace( '/\d+/', '#', $s ) ?? $s;
		$s = preg_replace( '/\s+/', ' ', $s ) ?? $s;
		return $s;
	}
}
