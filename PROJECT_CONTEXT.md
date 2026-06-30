# Project Context: Rejser

This project is a small PHP + SQLite web app served by Apache at:

`https://spli.id/rejser`

It uses Rejseplanen API 2.0 to search public transit routes between two stations, lets a user subscribe to a selected recurring weekday departure, and sends browser notifications when that subscribed route changes shortly before departure.

## Current Behavior

- Main page: `index.php`
- Admin page: `admin.php`
- JSON API: `api.php`
- Shared app logic and SQLite schema: `lib.php`
- Cron checker: `cron.php`
- Browser notification service worker: `sw.js`
- SQLite database: `data/rejser.sqlite`

Subscriptions are recurrent weekday subscriptions. If a user subscribes to a route at `08:00`, the system treats it as the same route at `08:00` every weekday.

To save Rejseplanen quota, cron does not check all subscriptions all day. It only calls Rejseplanen when a subscribed weekday departure is within the next `notification_window_minutes`, default `60`. Cron currently runs every 5 minutes, so a route inside the one-hour window is checked up to 12 times.

## Cron

Installed crontab entry:

```cron
*/5 * * * * cd /ssd/web/www/spli.id/rejser && /usr/bin/php cron.php >/dev/null 2>&1
```

`cron.php` calls `check_subscriptions(false)`.

## Configuration

`config.php` is intentionally local and should be created from `config.example.php`.

Expected config keys:

```php
return [
    'rejseplanen_access_id' => '',
    'admin_password' => '',
    'browser_poll_seconds' => 60,
    'server_check_minutes' => 5,
    'notification_window_minutes' => 60,
];
```

`rejseplanen_access_id` is required for Rejseplanen API calls.

`admin_password` is required before `admin.php` exposes data. The admin page uses HTTP Basic authentication.

## Database Tables

Created automatically in `lib.php`:

- `subscriptions`
  - Stores selected route, origin/destination IDs/names, target time, route signature, snapshot/template, and last check timestamp.
- `notifications`
  - Stores detected changes until the browser polls and marks them delivered.
- `api_queries`
  - Logs each actual Rejseplanen request with endpoint, status code, and timestamp.

The admin page counts monthly API usage from `api_queries`. Query counting only starts from the time this table/logging was added; historical API calls before that are not represented.

## Rejseplanen API Usage

`api_get()` in `lib.php` is the only low-level Rejseplanen request wrapper. It logs every request into `api_queries`.

Endpoints currently used:

- `location.name`
  - Used for station autocomplete and station resolution.
- `trip`
  - Used for route search and subscription checks.

Important: Rejseplanen API 1.0 is shut down. This app uses API 2.0 at:

`https://www.rejseplanen.dk/api/`

API 2.0 requires `accessId`.

## Notifications

Browser notifications require:

- the site to be served over HTTPS
- the user to grant notification permission
- `sw.js` to be registered

Cron detects and stores changes while the browser is closed. Browsers that have granted notification
permission register Web Push endpoints, so cron can deliver notifications through `sw.js` even when
the page is closed. Open-page polling via `api.php?action=check` remains as a fallback.

## Admin

Open:

`https://spli.id/rejser/admin.php`

Admin shows:

- API calls in selected month
- remaining quota out of 50,000
- active subscription count
- all subscriptions
- endpoint breakdown
- latest API calls

## Styling and Language

The UI is in Danish.

Dark mode is automatic through `prefers-color-scheme: dark`.

## Permissions Notes

SQLite must be writable by Apache/PHP. The current expected ownership/permission setup is:

- `data/`: group `www-data`, mode `2775`
- `data/rejser.sqlite`: group `www-data`, mode `664`

The user `peterspliid` is a member of `www-data`.

SQLite `-wal` and `-shm` files are transient and may be owned by `www-data`.

## Verification Commands

Useful checks:

```bash
php -l index.php
php -l api.php
php -l lib.php
php -l admin.php
php cron.php
```

Database/log check:

```bash
php -r 'require "lib.php"; db(); echo db()->query("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"api_queries\"")->fetchColumn(), PHP_EOL; echo api_query_count_for_month(date("Y-m")), PHP_EOL;'
```

## Known Caveats

- No user accounts exist; subscriptions are shared globally.
- Browser notifications use Web Push when available. Open-page polling remains as a fallback for browsers without a usable Push API subscription.
- Admin is protected only by HTTP Basic password from `config.php`.
- Subscription route matching relies on a route signature based on leg names and origin/destination names.
- If Rejseplanen changes response structure, `normalize_trip()` in `lib.php` is the likely place to update.
