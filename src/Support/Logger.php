<?php

declare( strict_types=1 );

namespace DSN\Hawk\Support;

final class Logger {

	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsn_hawk_log';
	}

	public static function installTable(): void {
		global $wpdb;
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT '',
			http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			message TEXT NULL,
			payload_bytes INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function write( string $status, int $http_code, string $message, int $payload_bytes ): void {
		global $wpdb;
		$wpdb->insert(
			self::tableName(),
			[
				'created_at'    => current_time( 'mysql', true ),
				'status'        => substr( $status, 0, 32 ),
				'http_code'     => $http_code,
				'message'       => $message,
				'payload_bytes' => $payload_bytes,
			],
			[ '%s', '%s', '%d', '%s', '%d' ]
		);
		self::prune();
	}

	private static function prune( int $keep = 100 ): void {
		global $wpdb;
		$table = self::tableName();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT %d ) x )",
				$keep
			)
		);
	}

	public static function recent( int $limit = 25 ): array {
		global $wpdb;
		$table = self::tableName();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}
}
