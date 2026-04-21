# DSN Hawk

WordPress plugin that collects site health + configuration reports and pushes them to the **Skyline** admin panel in the DSN Laravel app.

Hawk is the agent that runs **on each WordPress site**. Skyline is the central Laravel dashboard that **receives and displays** Hawk reports. Hawk knows nothing about other sites; Skyline aggregates everything.

---

## Status

**v0.1 — in development.** First feature: Gravity Forms report.

---

## Architecture

```
┌─────────────────────┐         POST /api/v1/hawk/sync        ┌─────────────────────┐
│  WordPress Site     │  ──────────────────────────────────▶  │   Laravel (DSN)     │
│                     │     Bearer: WP_SYNC_TOKEN             │                     │
│  DSN Hawk plugin    │     JSON { site, reports }            │   Skyline admin     │
└─────────────────────┘                                        └─────────────────────┘
```

- Hawk collects data locally on the WP site.
- Hawk POSTs a single payload per sync run to Skyline's `/api/v1/hawk/sync` endpoint.
- Skyline upserts the site by domain; first sync auto-creates the `skyline_sites` row.
- Each "report" inside the payload is a namespaced slice (e.g. `gravity_forms`, later `email_log`, `plugins`).
- Skyline treats missing report keys as "no update" — Hawk can push partial payloads.

---

## Plugin Settings (WP Admin)

Settings page: **Settings → DSN Hawk**

| Setting | Description |
|---|---|
| **Skyline endpoint URL** | e.g. `https://dsn.example.com/api/v1/hawk/sync` |
| **Sync token** | Bearer token, matches Laravel `WP_SYNC_TOKEN` env var |
| **Sync frequency** | daily / hourly / manual (via WP-Cron) |
| **Enabled reports** | checkboxes per report type |
| **Manual "Sync now" button** | triggers an immediate push, shows last response |

Store in `wp_options` under the key `dsn_hawk_settings` (single serialized array).

---

## Payload Contract

One unified endpoint, one payload shape. Hawk sends everything it has in a single POST.

```json
{
  "site": {
    "domain": "example.com",
    "url": "https://example.com",
    "server_ip": "192.0.2.10",
    "email_enabled": true
  },
  "reports": {
    "gravity_forms": {
      "forms": [
        {
          "id": "1",
          "title": "Contact Form",
          "is_active": true,
          "total_entries_count": 42,
          "notifications": [
            { "to": "leads@example.com", "bcc": "archive@example.com" }
          ],
          "entries": []
        }
      ]
    }
  }
}
```

### `site` fields

| Field | Source |
|---|---|
| `domain` | `parse_url(home_url(), PHP_URL_HOST)` — Skyline normalizes (strips protocol, `www.`, path) |
| `url` | `home_url()` |
| `server_ip` | `$_SERVER['SERVER_ADDR']` (fallback: `gethostbyname($host)`) |
| `email_enabled` | Boolean. True if WP can send mail — see **Email sending detection** below |

### Response

- `200 { "status": "ok" }` — success
- `401 { "status": "unauthorized" }` — bad bearer token
- `422` — validation error (log + retry on next cycle)

---

## Report: Gravity Forms (v0.1)

**Goal:** show every form on the site, whether it's active, where notifications go, and whether the site can email.

### Collection

Only runs if the Gravity Forms plugin is active. Check with:

```php
if ( ! class_exists( 'GFAPI' ) ) {
    return null; // skip this report
}
```

For each form returned by `GFAPI::get_forms( true /* active */ )` **and** `GFAPI::get_forms( false /* inactive */ )`:

| Payload field | Source |
|---|---|
| `id` | `$form['id']` (cast to string) |
| `title` | `$form['title']` |
| `is_active` | `! $form['is_trash'] && $form['is_active']` |
| `total_entries_count` | `GFAPI::count_entries( $form['id'] )` |
| `notifications[]` | iterate `$form['notifications']` — extract `to` and `bcc` |
| `entries[]` | **leave empty `[]` for v0.1** (wire up later if needed for debugging) |

### Notification extraction

Gravity Forms stores notifications as an associative array keyed by notification ID. Each has `to`, `bcc`, `toType` (`email` | `field` | `routing`). For `email` type, `to` is a literal address; for `field`, it's a field ID reference — send the raw value, Skyline will display as-is.

### Email sending detection (`site.email_enabled`)

Heuristic (fast, no outbound test email):

1. `true` if any of these is present: WP Mail SMTP plugin active w/ configured mailer, Post SMTP, FluentSMTP, SendGrid official plugin, Mailgun plugin — and their respective "test connection" status is OK.
2. `true` if `wp_mail()` is not short-circuited and `phpmailer_init` has a non-default `Host`.
3. `false` if an SMTP plugin is active but reports "not configured" / last test failed.
4. `null` (unknown) otherwise.

Cache the result in a transient for 1 hour to avoid re-running on every sync.

---

## File Structure

```
dsn-hawk/
├── dsn-hawk.php              # Main plugin file (headers + bootstrap)
├── README.md
├── composer.json             # PSR-4 autoload for src/
├── src/
│   ├── Plugin.php            # bootstrap, hooks
│   ├── Settings/
│   │   └── SettingsPage.php  # admin settings UI
│   ├── Sync/
│   │   ├── Syncer.php        # builds + POSTs payload
│   │   ├── Scheduler.php     # WP-Cron registration
│   │   └── HttpClient.php    # wp_remote_post wrapper + retry
│   ├── Reports/
│   │   ├── ReportInterface.php
│   │   ├── GravityFormsReport.php
│   │   ├── EmailLogReport.php    # (future)
│   │   └── PluginsReport.php     # (future)
│   └── Support/
│       └── SiteInfo.php      # domain, IP, email-sending check
└── assets/
    └── admin.css
```

### Report interface

Each report is self-contained:

```php
interface ReportInterface {
    public function key(): string;            // e.g. "gravity_forms"
    public function isAvailable(): bool;      // e.g. Gravity Forms plugin active?
    public function collect(): ?array;        // payload slice, or null to skip
}
```

The Syncer iterates enabled reports, calls `collect()` on each, and assembles the final `reports` object.

---

## WP-Cron

Register a custom interval on activation:

```php
add_filter( 'cron_schedules', function ( $s ) {
    $s['dsn_hawk_hourly'] = [ 'interval' => HOUR_IN_SECONDS, 'display' => 'DSN Hawk — Hourly' ];
    return $s;
} );

register_activation_hook( __FILE__, function () {
    if ( ! wp_next_scheduled( 'dsn_hawk_sync' ) ) {
        wp_schedule_event( time() + 60, 'dsn_hawk_hourly', 'dsn_hawk_sync' );
    }
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dsn_hawk_sync' );
} );

add_action( 'dsn_hawk_sync', [ Syncer::class, 'run' ] );
```

On high-traffic sites, recommend hooking to a real system cron hitting `wp-cron.php` rather than relying on WP's request-triggered cron.

---

## HTTP Client

Use `wp_remote_post()`. Important knobs:

```php
wp_remote_post( $endpoint, [
    'timeout'     => 15,
    'blocking'    => true,
    'headers'     => [
        'Authorization' => 'Bearer ' . $token,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'User-Agent'    => 'DSN-Hawk/' . DSN_HAWK_VERSION,
    ],
    'body'        => wp_json_encode( $payload ),
    'data_format' => 'body',
] );
```

Retry strategy:
- On `5xx` or `WP_Error`: store the payload in a transient, retry on next scheduled run.
- On `401`: disable sync, surface "Invalid token" notice in WP admin.
- On `422`: log to the plugin's own log table (or `error_log`), don't retry — fix the payload first.

---

## Logging

Own table: `{prefix}dsn_hawk_log` — columns `id, created_at, status, http_code, message, payload_bytes`. Keep last 100 runs for debugging. Shown on the settings page.

---

## Security

- **No secrets in JS.** Settings page serves the token only to users with `manage_options`.
- **Token rotation:** new token in Laravel env → update in plugin settings → next sync uses it.
- **No entry PII by default.** v0.1 skips form entries; when entries are enabled (future), strip IPs and hash email fields unless the admin opts in.
- **HTTPS only.** Refuse to POST to `http://` endpoints unless a debug constant `DSN_HAWK_ALLOW_INSECURE` is defined.
- **Nonce-protect** the "Sync now" button and settings form.

---

## Roadmap

### v0.2 — Email Log Report (compromise detection)

**Why:** a sudden burst of outbound email is the earliest signal of a compromised site (spam relay, phishing, hijacked form plugin).

Collect:
- Count of `wp_mail()` calls in last 1h / 24h / 7d (hook `wp_mail` and store counters in an own table).
- Top 10 recipient domains.
- Top 10 subject-line fingerprints (hashed, not full subject).
- Bounce rate if SMTP plugin exposes it.
- Anomaly flag: `true` if 24h volume > N× rolling 7d average.

Skyline side: site list gets a red/yellow/green email-health dot. Detail page shows the time series.

### v0.3 — Plugin Inventory Report

Collect installed plugins (active + inactive):
- slug, name, version, `is_active`, auto-update status, last-update date.
- Compare against a Skyline-side allowlist / known-vulnerable list.
- Flag plugins that haven't been updated in > 2 years or are abandoned on WP.org.

### v0.4 — Core + Theme Report

- WordPress core version and whether it's current.
- Active theme, child theme, last update.
- PHP version, MySQL version, memory limit.

### v0.5 — File Integrity (optional, heavier)

- Checksums of `wp-admin/`, `wp-includes/`, active theme files.
- Diff against WP.org checksums for core.
- Flag unexpected PHP files in `uploads/`.

### Later

- Two-way: Skyline can send a "refresh now" webhook to the site.
- Per-report push frequency.
- Selective disable from Skyline side.

---

## Development

```bash
# Clone into a local WP install
cd wp-content/plugins
git clone <repo> dsn-hawk
cd dsn-hawk
composer install

# Activate in WP admin → Plugins
# Configure in Settings → DSN Hawk
```

### Local Skyline

Point the endpoint URL at your Laravel dev server — e.g. `http://dsn.test/api/v1/hawk/sync`. Temporarily define `DSN_HAWK_ALLOW_INSECURE` in `wp-config.php` to allow `http://`.

### Testing a sync run

From WP-CLI:

```bash
wp eval '(new \DSN\Hawk\Sync\Syncer())->run();'
```

Or click **Sync now** on the settings page.

---

## Compatibility

- WordPress: 6.0+
- PHP: 8.1+
- Gravity Forms: 2.5+ (only required if the GF report is enabled)

---

## License

Proprietary — internal DSN tool. Not for public distribution.
