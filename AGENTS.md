# Rejser Codebase Notes

Read this first when starting work in this directory.

## App Summary

This is a small PHP + SQLite web app served at `https://spli.id/rejser`.

It uses Rejseplanen API 2.0 to:

- search public transport routes between Danish stations
- let a user subscribe to a recurring weekday departure
- create browser notifications when a subscribed route changes shortly before departure

There are no user accounts. Subscriptions are shared globally.

## Important Files

- `index.php` - main UI, route search, station autocomplete, subscriptions list, browser notification handling
- `api.php` - JSON API used by the frontend
- `lib.php` - shared config, database schema, Rejseplanen API wrapper, station search, trip normalization, subscription checks
- `cron.php` - calls `check_subscriptions(false)` for scheduled checks
- `admin.php` - HTTP Basic protected admin page for API usage and subscriptions
- `config.php` - local secrets/config, not a template; contains the Rejseplanen access ID
- `config.example.php` - expected config keys and defaults
- `data/rejser.sqlite` - SQLite database
- `sw.js` - service worker used for browser notifications and Web Push delivery
- `PROJECT_CONTEXT.md` - longer project notes

## Configuration

Expected `config.php` shape:

```php
return [
    'rejseplanen_access_id' => '',
    'admin_password' => '',
    'browser_poll_seconds' => 60,
    'server_check_minutes' => 5,
    'notification_window_minutes' => 60,
    'vapid_public_key' => '',
    'vapid_private_key' => '',
    'vapid_subject' => 'mailto:webpush@spli.id',
];
```

If VAPID keys are empty, `lib.php` auto-generates and stores them in `data/vapid.json`.

Do not print or expose the Rejseplanen access ID or VAPID private key.

## Rejseplanen API Notes

Base URL in `lib.php`:

`https://www.rejseplanen.dk/api/`

Endpoints used:

- `location.name` for station autocomplete and station resolution
- `trip` for route search and subscription checks

Important response-shape detail: `location.name` can return stations under `stopLocationOrCoordLocation`, where each item contains `StopLocation`. `lib.php` handles this via `stop_locations_from_response()`.

Rejseplanen often returns multiple stop IDs with the same displayed station name. `station_search()` deduplicates by station name before returning suggestions.

Rejseplanen duration values can be ISO-8601 strings like `PT41M`. `lib.php` formats these through `format_duration()` before the UI displays them.

## Frontend Notes

Station autocomplete is custom JavaScript in `index.php`, not native `<datalist>`. It fetches `api.php?action=stations&q=...`, shows a dropdown, supports click selection, and supports ArrowUp/ArrowDown/Enter/Escape.

Subscription timestamps are sent as ISO strings from the API and formatted in the browser by `displayDateTime()`.

When notifications are enabled, `index.php` registers `sw.js`, subscribes with `PushManager`, and posts the serialized subscription to `api.php?action=push-subscribe`. If notification permission was already granted, page load also syncs the push subscription.

## Database Tables

`lib.php` creates these tables automatically:

- `subscriptions`
- `notifications`
- `api_queries`
- `push_subscriptions`

`api_queries` logs each Rejseplanen request for monthly usage reporting in `admin.php`.

`push_subscriptions` stores browser Push API endpoints and keys. Endpoint rows are shared globally like route subscriptions. Web Push delivery removes expired endpoints on HTTP 404/410.

## Cron Behavior

Installed cron context from project notes:

```cron
*/5 * * * * cd /ssd/web/www/spli.id/rejser && /usr/bin/php cron.php >/dev/null 2>&1
```

`check_subscriptions(false)` only checks weekday departures inside the `notification_window_minutes` window and respects `server_check_minutes` to avoid excessive API calls.

When a changed route creates a `notifications` row, `lib.php` immediately attempts Web Push delivery to registered endpoints. If no push endpoint exists or push delivery fails, the existing browser polling fallback can still deliver undelivered notifications while the page is open.

## Verification Commands

Useful checks after edits:

```bash
php -l lib.php
php -l api.php
php -l index.php
php -l admin.php
php -l cron.php
php cron.php
```

Be careful with `php cron.php` on live data: it can call Rejseplanen and send real Web Push notifications if a subscribed departure is inside the notification window.

Web Push registration/config check:

```bash
php -r '$_GET=["action"=>"config"]; require "api.php";'
sqlite3 data/rejser.sqlite 'SELECT id, substr(endpoint,1,70), user_agent, updated_at FROM push_subscriptions ORDER BY updated_at DESC LIMIT 5;'
```

Local Web Push crypto smoke test without sending a notification:

```bash
php -r 'require "lib.php"; $client=openssl_pkey_new(["private_key_type"=>OPENSSL_KEYTYPE_EC,"curve_name"=>"prime256v1"]); $details=openssl_pkey_get_details($client); $pub=base64url_encode("\x04".$details["ec"]["x"].$details["ec"]["y"]); $auth=base64url_encode(random_bytes(16)); echo strlen(encrypt_push_payload("hello", $pub, $auth)), " encrypted bytes\n";'
```

Live station lookup check:

```bash
php -r '$_GET=["action"=>"stations","q"=>"København"]; require "api.php";'
```

Live route check:

```bash
php -r 'require "lib.php"; $o=resolve_station("København H"); $d=resolve_station("Roskilde"); $routes=trip_search($o["id"],$d["id"],date("Y-m-d"),"12:00"); echo count($routes), " routes ", ($routes[0]["snapshot"]["summary"]["duration"] ?? ""), "\n";'
```

## Environment Notes

The local filesystem sandbox may fail with `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`. In this environment, normal reads/edits sometimes need escalated command execution even inside the writable project root.

Prefer `rg`/`sed` for inspection and keep changes scoped. If `apply_patch` fails with the bwrap error, use a careful PHP stdin edit script and verify replacement counts.