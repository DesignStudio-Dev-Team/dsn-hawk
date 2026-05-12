<?php

declare( strict_types=1 );

namespace DSN\Hawk\Support;

final class SiteInfo {

	public static function payload(): array {
		$url    = home_url();
		$host   = parse_url( $url, PHP_URL_HOST ) ?: '';
		$domain = preg_replace( '#^www\.#i', '', $host );

		return [
			'name'          => get_bloginfo( 'name' ),
			'domain'        => $domain,
			'url'           => $url,
			'admin_email'   => get_bloginfo( 'admin_email' ),
			'wp_site_id'    => get_current_blog_id(),
			'is_multisite'  => is_multisite(),
			'timezone'      => wp_timezone_string(),
			'server_ip'     => self::serverIp( $host ),
			'email_enabled' => self::emailEnabled(),
		];
	}

	private static function serverIp( string $host ): ?string {
		$ip = $_SERVER['SERVER_ADDR'] ?? '';
		if ( is_string( $ip ) && $ip !== '' ) {
			return $ip;
		}
		if ( $host === '' ) {
			return null;
		}
		$resolved = @gethostbyname( $host );
		return ( $resolved && $resolved !== $host ) ? $resolved : null;
	}

	public static function emailEnabled(): ?bool {
		$cached = get_transient( 'dsn_hawk_email_enabled' );
		if ( $cached !== false ) {
			return $cached === 'null' ? null : (bool) (int) $cached;
		}

		$result = self::detectEmail();

		set_transient(
			'dsn_hawk_email_enabled',
			$result === null ? 'null' : ( $result ? '1' : '0' ),
			HOUR_IN_SECONDS
		);

		return $result;
	}

	private static function detectEmail(): ?bool {
		// WP Mail SMTP
		if ( class_exists( '\\WPMailSMTP\\Options' ) ) {
			try {
				$opts   = \WPMailSMTP\Options::init();
				$mailer = $opts->get( 'mail', 'mailer' );
				if ( $mailer && $mailer !== 'mail' ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// fall through
			}
		}

		// FluentSMTP
		if ( defined( 'FLUENTMAIL' ) ) {
			return true;
		}

		// Post SMTP
		if ( class_exists( '\\PostmanOptions' ) ) {
			return true;
		}

		// SendGrid official
		if ( class_exists( '\\Sendgrid\\Plugin' ) || defined( 'SENDGRID_PLUGIN_VERSION' ) ) {
			return true;
		}

		// Mailgun
		if ( class_exists( '\\Mailgun' ) || defined( 'MAILGUN_VERSION' ) ) {
			return true;
		}

		// phpmailer_init host heuristic — only works after a mail attempt, skip.
		if ( has_filter( 'pre_wp_mail' ) ) {
			return null;
		}

		return null;
	}
}
