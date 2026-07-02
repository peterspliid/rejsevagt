<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Copenhagen');

const DB_FILE = __DIR__ . '/data/rejser.sqlite';
const VAPID_FILE = __DIR__ . '/data/vapid.json';
const API_BASE = 'https://www.rejseplanen.dk/api/';

function app_config(): array
{
    $defaults = require __DIR__ . '/config.example.php';
    $local = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
    return array_replace($defaults, $local);
}

function db(): PDO
{
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0775, true);
    }

    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            origin_id TEXT NOT NULL,
            origin_name TEXT NOT NULL,
            dest_id TEXT NOT NULL,
            dest_name TEXT NOT NULL,
            target_time TEXT NOT NULL,
            route_signature TEXT NOT NULL,
            route_label TEXT NOT NULL,
            last_snapshot_hash TEXT NOT NULL,
            last_snapshot TEXT NOT NULL,
            last_checked_at TEXT,
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            delivered_at TEXT,
            FOREIGN KEY(subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_queries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL,
            status_code INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_queries_created_at ON api_queries (created_at)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            expiration_time INTEGER NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    return $pdo;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_access_id(): string
{
    $accessId = trim((string) app_config()['rejseplanen_access_id']);
    if ($accessId === '') {
        throw new RuntimeException('Rejseplanen accessId mangler. Kopiér config.example.php til config.php, og udfyld rejseplanen_access_id.');
    }
    return $accessId;
}

function api_get(string $path, array $params): array
{
    $params = array_merge([
        'accessId' => require_access_id(),
        'format' => 'json',
        'lang' => 'da',
    ], $params);

    $url = API_BASE . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'spli-id-rejser/1.0',
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    log_api_query($path, $status);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Kunne ikke kontakte Rejseplanen: ' . $error);
    }

    $decoded = json_decode($body, true);
    if ($status >= 400) {
        $message = is_array($decoded) ? ($decoded['Error']['errorText'] ?? $decoded['errorText'] ?? $body) : $body;
        throw new RuntimeException('Rejseplanen svarede med HTTP ' . $status . ': ' . strip_tags((string) $message));
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Rejseplanen returnerede et svar, der ikke kunne læses.');
    }

    return $decoded;
}

function log_api_query(string $endpoint, int $statusCode): void
{
    db()->prepare('INSERT INTO api_queries (endpoint, status_code, created_at) VALUES (:endpoint, :status_code, :created_at)')
        ->execute([
            ':endpoint' => $endpoint,
            ':status_code' => $statusCode,
            ':created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
}

function month_bounds(string $month): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m');
    }

    $start = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('Europe/Copenhagen'));
    return [
        'month' => $start->format('Y-m'),
        'start' => $start->format(DateTimeInterface::ATOM),
        'end' => $start->modify('first day of next month')->format(DateTimeInterface::ATOM),
    ];
}

function api_query_count_for_month(string $month): int
{
    $bounds = month_bounds($month);
    $statement = db()->prepare('SELECT COUNT(*) FROM api_queries WHERE created_at >= :start AND created_at < :end');
    $statement->execute([':start' => $bounds['start'], ':end' => $bounds['end']]);
    return (int) $statement->fetchColumn();
}

function listify(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    if (array_is_list($value)) {
        return $value;
    }
    return [$value];
}

function stop_locations_from_response(array $data): array
{
    $locations = $data['LocationList']['StopLocation']
        ?? $data['StopLocation']
        ?? $data['LocationList']['Location']
        ?? null;

    if ($locations !== null) {
        return listify($locations);
    }

    return array_values(array_filter(array_map(static function (array $entry): ?array {
        return $entry['StopLocation'] ?? null;
    }, listify($data['stopLocationOrCoordLocation'] ?? []))));
}

function station_search(string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $data = api_get('location.name', [
        'input' => $query,
        'type' => 'S',
        'maxNo' => 8,
    ]);
    $locations = stop_locations_from_response($data);

    $stations = [];
    $seenNames = [];
    foreach (listify($locations) as $location) {
        $id = (string) ($location['id'] ?? $location['extId'] ?? '');
        $name = trim((string) ($location['name'] ?? ''));
        $key = mb_strtolower($name, 'UTF-8');
        if ($id === '' || $name === '' || isset($seenNames[$key])) {
            continue;
        }
        $seenNames[$key] = true;
        $stations[] = ['id' => $id, 'name' => $name];
    }

    return $stations;
}

function resolve_station(string $query): array
{
    $matches = station_search($query);
    if ($matches === []) {
        throw new RuntimeException('Ingen station fundet for "' . $query . '".');
    }
    return $matches[0];
}

function trip_search(string $originId, string $destId, string $date, string $time): array
{
    $data = api_get('trip', [
        'originId' => $originId,
        'destId' => $destId,
        'date' => $date,
        'time' => $time,
        'numF' => 6,
        'numB' => 0,
        'passlist' => 0,
    ]);
    $trips = $data['TripList']['Trip'] ?? $data['Trip'] ?? $data['TripList'] ?? [];

    return array_values(array_map('normalize_trip', listify($trips)));
}

function normalize_trip(array $trip): array
{
    $origin = $trip['Origin'] ?? [];
    $dest = $trip['Destination'] ?? [];
    $legs = listify($trip['LegList']['Leg'] ?? $trip['Leg'] ?? []);
    $normalizedLegs = array_values(array_map(static function (array $leg): array {
        $origin = $leg['Origin'] ?? [];
        $dest = $leg['Destination'] ?? [];
        $product = $leg['Product'] ?? [];
        $name = (string) ($leg['name'] ?? $product['name'] ?? $product['displayNumber'] ?? $product['catOut'] ?? $leg['type'] ?? 'Walk');
        return [
            'name' => trim($name),
            'type' => (string) ($leg['type'] ?? $product['catCode'] ?? ''),
            'origin' => (string) ($origin['name'] ?? ''),
            'destination' => (string) ($dest['name'] ?? ''),
            'dep' => time_pair($origin),
            'arr' => time_pair($dest),
            'cancelled' => truthy($origin['cancelled'] ?? $leg['cancelled'] ?? false) || truthy($dest['cancelled'] ?? false),
        ];
    }, $legs));

    $snapshot = [
        'summary' => [
            'origin' => (string) ($origin['name'] ?? ($normalizedLegs[0]['origin'] ?? '')),
            'destination' => (string) ($dest['name'] ?? ($normalizedLegs[array_key_last($normalizedLegs)]['destination'] ?? '')),
            'departure' => time_pair($origin ?: ($legs[0]['Origin'] ?? [])),
            'arrival' => time_pair($dest ?: ($legs[array_key_last($legs)]['Destination'] ?? [])),
            'duration' => format_duration((string) ($trip['duration'] ?? '')),
        ],
        'legs' => $normalizedLegs,
    ];

    $label = route_label($normalizedLegs);
    $signature = route_signature($normalizedLegs);

    return [
        'signature' => $signature,
        'label' => $label,
        'snapshot' => $snapshot,
        'snapshot_hash' => snapshot_hash($snapshot),
    ];
}

function format_duration(string $duration): string
{
    if ($duration === '') {
        return '';
    }

    if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $duration, $matches)) {
        return $duration;
    }

    $days = (int) ($matches[1] ?? 0);
    $hours = (int) ($matches[2] ?? 0);
    $minutes = (int) ($matches[3] ?? 0);
    $seconds = (int) ($matches[4] ?? 0);
    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' d';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' t';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' min';
    }
    if ($seconds > 0 && $parts === []) {
        $parts[] = $seconds . ' sek';
    }

    return $parts === [] ? '0 min' : implode(' ', $parts);
}
function time_pair(array $node): array
{
    return [
        'date' => (string) ($node['date'] ?? ''),
        'time' => (string) ($node['time'] ?? ''),
        'rtDate' => (string) ($node['rtDate'] ?? ''),
        'rtTime' => (string) ($node['rtTime'] ?? ''),
    ];
}

function truthy(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes';
}

function route_label(array $legs): string
{
    $names = array_values(array_filter(array_map(static fn (array $leg): string => trim((string) $leg['name']), $legs)));
    return $names === [] ? 'Ukendt rute' : implode(' → ', $names);
}

function route_signature(array $legs): string
{
    $parts = array_map(static function (array $leg): string {
        return implode('|', [
            trim((string) $leg['name']),
            trim((string) $leg['origin']),
            trim((string) $leg['destination']),
        ]);
    }, $legs);
    return hash('sha256', implode('||', $parts));
}

function snapshot_hash(array $snapshot): string
{
    return hash('sha256', json_encode(monitor_snapshot($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function stable_route_signature_from_snapshot(mixed $snapshot): string
{
    if (!is_array($snapshot)) {
        return '';
    }

    $legs = listify($snapshot['legs'] ?? []);
    if ($legs === []) {
        return '';
    }

    $parts = array_map(static function (array $leg): string {
        return implode('|', [
            stable_leg_name((string) ($leg['name'] ?? ''), (string) ($leg['type'] ?? '')),
            normalize_match_text((string) ($leg['origin'] ?? '')),
            normalize_match_text((string) ($leg['destination'] ?? '')),
        ]);
    }, $legs);

    return hash('sha256', implode('||', $parts));
}

function stable_route_signature(array $route): string
{
    return stable_route_signature_from_snapshot($route['snapshot'] ?? []);
}

function stable_leg_name(string $name, string $type): string
{
    $normalized = normalize_match_text($name);
    $type = normalize_match_text($type);

    if (preg_match('/^(ic|icl|re|r|tog|train|lyntog)\b/u', $normalized) || in_array($type, ['ic', 'icl', 're', 'r'], true)) {
        $normalized = preg_replace('/\b\d+\b/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }

    return trim($normalized);
}

function normalize_match_text(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return $value;
}

function subscription_snapshot_from_route(array $route, string $targetTime): array
{
    $snapshot = $route['snapshot'] ?? [];
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    $snapshot['target_time'] = $targetTime;
    $snapshotHash = snapshot_hash($snapshot);
    $snapshot['snapshot_hash'] = $snapshotHash;
    return $snapshot;
}

function route_planned_departure_time(array $route, string $fallback): string
{
    $departure = $route['snapshot']['summary']['departure'] ?? [];
    if (!is_array($departure)) {
        return $fallback;
    }

    $time = (string) ($departure['time'] ?? '');
    if ($time === '') {
        $time = (string) ($departure['rtTime'] ?? '');
    }

    return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : $fallback;
}

function decode_snapshot(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function find_duplicate_subscription(array $origin, array $destination, string $targetTime, array $route): ?array
{
    $statement = db()->prepare(
        'SELECT *
         FROM subscriptions
         WHERE origin_id = :origin_id
           AND dest_id = :dest_id
           AND target_time = :target_time
         ORDER BY created_at ASC'
    );
    $statement->execute([
        ':origin_id' => (string) $origin['id'],
        ':dest_id' => (string) $destination['id'],
        ':target_time' => $targetTime,
    ]);

    $signature = (string) ($route['signature'] ?? '');
    $stableSignature = stable_route_signature($route);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($signature !== '' && hash_equals((string) $row['route_signature'], $signature)) {
            return $row;
        }

        if ($stableSignature !== '' && hash_equals(stable_route_signature_from_snapshot(decode_snapshot($row['last_snapshot'] ?? '')), $stableSignature)) {
            return $row;
        }
    }

    return null;
}

function monitor_snapshot(mixed $snapshot): mixed
{
    if (!is_array($snapshot)) {
        return $snapshot;
    }

    foreach ($snapshot as $key => $value) {
        if ($key === 'date' || $key === 'rtDate') {
            unset($snapshot[$key]);
            continue;
        }
        if (is_array($value)) {
            $snapshot[$key] = monitor_snapshot($value);
        }
    }

    return $snapshot;
}

function check_subscriptions(bool $force = false): array
{
    $config = app_config();
    $windowMinutes = max(1, (int) $config['notification_window_minutes']);
    $minimumAge = max(1, (int) $config['server_check_minutes']);
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen'));
    $rows = db()->query('SELECT * FROM subscriptions ORDER BY created_at ASC')->fetchAll(PDO::FETCH_ASSOC);
    $created = [];

    foreach ($rows as $row) {
        if (!$force && !subscription_due_for_check($row, $now, $windowMinutes, $minimumAge)) {
            continue;
        }

        $routes = trip_search(
            (string) $row['origin_id'],
            (string) $row['dest_id'],
            $now->format('Y-m-d'),
            (string) $row['target_time']
        );
        $route = find_matching_route($routes, (string) $row['route_signature'], $row['last_snapshot'] ?? null) ?? ($routes[0] ?? null);
        if ($route === null) {
            update_subscription_checked_at((int) $row['id'], $now);
            continue;
        }

        $snapshot = subscription_snapshot_from_route($route, (string) $row['target_time']);
        $oldHash = (string) $row['last_snapshot_hash'];
        $newHash = (string) $snapshot['snapshot_hash'];

        if ($oldHash !== '' && $newHash !== $oldHash) {
            $notification = create_change_notification($row, $route, $snapshot, $newHash, $now);
            if ($notification !== null) {
                $created[] = $notification;
            }
        }

        db()->prepare(
            'UPDATE subscriptions
             SET route_signature = :route_signature,
                 route_label = :route_label,
                 last_snapshot_hash = :last_snapshot_hash,
                 last_snapshot = :last_snapshot,
                 last_checked_at = :last_checked_at
             WHERE id = :id'
        )->execute([
            ':route_signature' => (string) $route['signature'],
            ':route_label' => (string) $route['label'],
            ':last_snapshot_hash' => $newHash,
            ':last_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':last_checked_at' => $now->format(DateTimeInterface::ATOM),
            ':id' => (int) $row['id'],
        ]);
    }

    return $created;
}

function subscription_due_for_check(array $row, DateTimeImmutable $now, int $windowMinutes, int $minimumAge): bool
{
    if ((int) $now->format('N') >= 6) {
        return false;
    }

    $departure = DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . (string) $row['target_time'], $now->getTimezone());
    if (!$departure instanceof DateTimeImmutable) {
        return false;
    }

    $secondsUntilDeparture = $departure->getTimestamp() - $now->getTimestamp();
    if ($secondsUntilDeparture < 0 || $secondsUntilDeparture > ($windowMinutes * 60)) {
        return false;
    }

    if (!empty($row['last_checked_at'])) {
        $lastChecked = new DateTimeImmutable((string) $row['last_checked_at']);
        if (($now->getTimestamp() - $lastChecked->getTimestamp()) < ($minimumAge * 60)) {
            return false;
        }
    }

    return true;
}

function find_matching_route(array $routes, string $signature, mixed $previousSnapshot = null): ?array
{
    foreach ($routes as $route) {
        if ((string) ($route['signature'] ?? '') === $signature) {
            return $route;
        }
    }

    $stableSignature = stable_route_signature_from_snapshot(decode_snapshot($previousSnapshot));
    if ($stableSignature === '') {
        return null;
    }

    foreach ($routes as $route) {
        if (hash_equals(stable_route_signature($route), $stableSignature)) {
            return $route;
        }
    }

    return null;
}

function update_subscription_checked_at(int $id, DateTimeImmutable $now): void
{
    db()->prepare('UPDATE subscriptions SET last_checked_at = :last_checked_at WHERE id = :id')
        ->execute([':last_checked_at' => $now->format(DateTimeInterface::ATOM), ':id' => $id]);
}

function create_change_notification(array $row, array $route, array $snapshot, string $hash, DateTimeImmutable $now): ?array
{
    $notificationHash = hash('sha256', (string) $row['id'] . '|' . $hash);
    $exists = db()->prepare('SELECT id FROM notifications WHERE subscription_id = :subscription_id AND hash = :hash LIMIT 1');
    $exists->execute([':subscription_id' => (int) $row['id'], ':hash' => $notificationHash]);
    if ($exists->fetchColumn()) {
        return null;
    }

    $title = 'Rejse ændret: ' . (string) $row['origin_name'] . ' → ' . (string) $row['dest_name'];
    $body = notification_body($row, $route, $snapshot);
    db()->prepare(
        'INSERT INTO notifications (subscription_id, title, body, hash, created_at)
         VALUES (:subscription_id, :title, :body, :hash, :created_at)'
    )->execute([
        ':subscription_id' => (int) $row['id'],
        ':title' => $title,
        ':body' => $body,
        ':hash' => $notificationHash,
        ':created_at' => $now->format(DateTimeInterface::ATOM),
    ]);

    $notification = [
        'id' => (int) db()->lastInsertId(),
        'title' => $title,
        'body' => $body,
        'created_at' => $now->format(DateTimeInterface::ATOM),
    ];
    deliver_notification_pushes($notification, $now);

    return $notification;
}

function notification_body(array $row, array $route, array $snapshot): string
{
    $summary = $snapshot['summary'] ?? [];
    $departure = display_time_pair($summary['departure'] ?? []);
    $arrival = display_time_pair($summary['arrival'] ?? []);
    $parts = [(string) ($route['label'] ?? $row['route_label'])];
    if ($departure !== '') {
        $parts[] = 'Afgang ' . $departure;
    }
    if ($arrival !== '') {
        $parts[] = 'ankomst ' . $arrival;
    }
    return implode(', ', $parts);
}

function display_time_pair(mixed $pair): string
{
    if (!is_array($pair)) {
        return '';
    }
    $time = (string) ($pair['rtTime'] ?: $pair['time'] ?: '');
    if ($time === '') {
        return '';
    }
    $planned = isset($pair['rtTime'], $pair['time']) && $pair['rtTime'] !== '' && $pair['time'] !== '' && $pair['rtTime'] !== $pair['time']
        ? ' (planlagt ' . $pair['time'] . ')'
        : '';
    return $time . $planned;
}

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64url_decode(string $encoded): string
{
    $padding = strlen($encoded) % 4;
    if ($padding > 0) {
        $encoded .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
    if (!is_string($decoded)) {
        throw new InvalidArgumentException('Kunne ikke afkode base64url-værdi.');
    }

    return $decoded;
}

function normalize_push_subscription(array $input): array
{
    $endpoint = is_string($input['endpoint'] ?? null) ? trim($input['endpoint']) : '';
    $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
    $p256dh = is_string($keys['p256dh'] ?? null) ? trim($keys['p256dh']) : '';
    $auth = is_string($keys['auth'] ?? null) ? trim($keys['auth']) : '';

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        throw new InvalidArgumentException('Ugyldig browser-subscription.');
    }

    return [
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'expiration_time' => isset($input['expirationTime']) && is_numeric($input['expirationTime']) ? (int) $input['expirationTime'] : null,
    ];
}

function save_push_subscription(array $input, ?string $userAgent = null): int
{
    $subscription = normalize_push_subscription($input);
    $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

    db()->prepare(
        'INSERT INTO push_subscriptions (endpoint, p256dh, auth, expiration_time, user_agent, created_at, updated_at)
         VALUES (:endpoint, :p256dh, :auth, :expiration_time, :user_agent, :created_at, :updated_at)
         ON CONFLICT(endpoint) DO UPDATE SET
            p256dh = excluded.p256dh,
            auth = excluded.auth,
            expiration_time = excluded.expiration_time,
            user_agent = excluded.user_agent,
            updated_at = excluded.updated_at'
    )->execute([
        ':endpoint' => $subscription['endpoint'],
        ':p256dh' => $subscription['p256dh'],
        ':auth' => $subscription['auth'],
        ':expiration_time' => $subscription['expiration_time'],
        ':user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $lookup = db()->prepare('SELECT id FROM push_subscriptions WHERE endpoint = :endpoint');
    $lookup->execute([':endpoint' => $subscription['endpoint']]);
    $id = $lookup->fetchColumn();
    if (!is_numeric($id)) {
        throw new RuntimeException('Kunne ikke gemme browser-subscription.');
    }

    return (int) $id;
}

function vapid_keys(): array
{
    $config = app_config();
    $configuredPublic = trim((string) ($config['vapid_public_key'] ?? ''));
    $configuredPrivate = trim((string) ($config['vapid_private_key'] ?? ''));
    $subject = trim((string) ($config['vapid_subject'] ?? 'mailto:webpush@example.com'));

    if ($configuredPublic !== '' && $configuredPrivate !== '') {
        return ['publicKey' => $configuredPublic, 'privatePem' => $configuredPrivate, 'subject' => $subject];
    }

    if (is_file(VAPID_FILE)) {
        $existing = json_decode((string) file_get_contents(VAPID_FILE), true);
        if (is_array($existing) && isset($existing['privatePem'], $existing['publicKey'])) {
            return $existing;
        }
    }

    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0775, true);
    }

    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if ($key === false || !openssl_pkey_export($key, $privatePem)) {
        throw new RuntimeException('Kunne ikke generere VAPID-nøgler.');
    }

    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
        throw new RuntimeException('Kunne ikke læse VAPID nøgledata.');
    }

    $payload = [
        'privatePem' => $privatePem,
        'publicKey' => base64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']),
        'subject' => $subject,
        'createdAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ];
    file_put_contents(VAPID_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod(VAPID_FILE, 0666);

    return $payload;
}

function der_to_jose_signature(string $derSignature, int $partLength = 32): string
{
    $offset = 0;
    if (ord($derSignature[$offset++]) !== 0x30) {
        throw new RuntimeException('Ugyldig DER-signatur.');
    }

    $sequenceLength = ord($derSignature[$offset++]);
    if ($sequenceLength & 0x80) {
        $bytes = $sequenceLength & 0x7f;
        $sequenceLength = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $sequenceLength = ($sequenceLength << 8) | ord($derSignature[$offset++]);
        }
    }

    if (ord($derSignature[$offset++]) !== 0x02) {
        throw new RuntimeException('Ugyldig DER-signatur.');
    }
    $rLength = ord($derSignature[$offset++]);
    $r = substr($derSignature, $offset, $rLength);
    $offset += $rLength;

    if (ord($derSignature[$offset++]) !== 0x02) {
        throw new RuntimeException('Ugyldig DER-signatur.');
    }
    $sLength = ord($derSignature[$offset++]);
    $s = substr($derSignature, $offset, $sLength);

    return str_pad(ltrim($r, "\x00"), $partLength, "\x00", STR_PAD_LEFT)
        . str_pad(ltrim($s, "\x00"), $partLength, "\x00", STR_PAD_LEFT);
}

function ec_public_key_pem_from_raw(string $rawKey): string
{
    $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
    if ($prefix === false) {
        throw new RuntimeException('Kunne ikke bygge EC nøgle.');
    }

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($prefix . $rawKey), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function create_vapid_jwt(string $audience): string
{
    $keys = vapid_keys();
    $header = base64url_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $claims = base64url_encode((string) json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 60 * 60,
        'sub' => $keys['subject'] ?? 'mailto:webpush@example.com',
    ], JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $claims;

    $privateKey = openssl_pkey_get_private($keys['privatePem']);
    if ($privateKey === false || !openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Kunne ikke signere VAPID token.');
    }

    return $signingInput . '.' . base64url_encode(der_to_jose_signature($signature));
}

function encrypt_push_payload(string $plaintext, string $receiverPublicKey, string $authSecret): string
{
    $uaPublic = base64url_decode($receiverPublicKey);
    $authBytes = base64url_decode($authSecret);
    $senderKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($senderKey === false) {
        throw new RuntimeException('Kunne ikke oprette afsendernøgle.');
    }

    $senderDetails = openssl_pkey_get_details($senderKey);
    if (!is_array($senderDetails) || !isset($senderDetails['ec']['x'], $senderDetails['ec']['y'])) {
        throw new RuntimeException('Kunne ikke læse afsendernøgle.');
    }

    $senderPublicRaw = "\x04" . $senderDetails['ec']['x'] . $senderDetails['ec']['y'];
    $receiverKey = openssl_pkey_get_public(ec_public_key_pem_from_raw($uaPublic));
    if ($receiverKey === false) {
        throw new RuntimeException('Kunne ikke læse browserens push-nøgle.');
    }

    $sharedSecret = openssl_pkey_derive($receiverKey, $senderKey, 32);
    if (!is_string($sharedSecret) || $sharedSecret === '') {
        throw new RuntimeException('Kunne ikke udlede push shared secret.');
    }

    $prkKey = hash_hmac('sha256', $sharedSecret, $authBytes, true);
    $keyInfo = "WebPush: info\x00" . $uaPublic . $senderPublicRaw;
    $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);
    $recordPlaintext = $plaintext . "\x02";
    $recordSize = max(strlen($recordPlaintext) + 16, 18);
    $ciphertext = openssl_encrypt($recordPlaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if (!is_string($ciphertext)) {
        throw new RuntimeException('Kunne ikke kryptere push payload.');
    }

    return $salt . pack('N', $recordSize) . chr(strlen($senderPublicRaw)) . $senderPublicRaw . $ciphertext . $tag;
}

function push_audience(string $endpoint): string
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new InvalidArgumentException('Ugyldigt push endpoint.');
    }

    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $isDefaultPort = ($parts['scheme'] === 'https' && (int) $parts['port'] === 443)
            || ($parts['scheme'] === 'http' && (int) $parts['port'] === 80);
        if (!$isDefaultPort) {
            $origin .= ':' . $parts['port'];
        }
    }

    return $origin;
}

function send_web_push(array $subscription, array $payload): array
{
    $keys = vapid_keys();
    $jwt = create_vapid_jwt(push_audience((string) $subscription['endpoint']));
    $body = encrypt_push_payload(
        (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (string) $subscription['p256dh'],
        (string) $subscription['auth']
    );

    $ch = curl_init((string) $subscription['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'TTL: 3600',
            'Urgency: high',
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['publicKey'],
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Push request fejlede: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
}

function delete_push_subscription_by_id(int $subscriptionId): void
{
    db()->prepare('DELETE FROM push_subscriptions WHERE id = :id')->execute([':id' => $subscriptionId]);
}

function deliver_notification_pushes(array $notification, DateTimeImmutable $now): void
{
    $rows = db()->query('SELECT id, endpoint, p256dh, auth FROM push_subscriptions ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return;
    }

    $sent = 0;
    foreach ($rows as $row) {
        try {
            $result = send_web_push($row, [
                'title' => (string) $notification['title'],
                'body' => (string) $notification['body'],
                'tag' => 'rejser-' . (int) $notification['id'],
                'url' => './',
            ]);
            if (in_array($result['status'], [404, 410], true)) {
                delete_push_subscription_by_id((int) $row['id']);
                continue;
            }
            if ($result['status'] >= 200 && $result['status'] < 300) {
                $sent++;
            } else {
                error_log('Web Push failed with HTTP ' . $result['status'] . ': ' . trim($result['body']));
            }
        } catch (Throwable $e) {
            error_log('Web Push failed: ' . $e->getMessage());
        }
    }

    if ($sent > 0) {
        db()->prepare('UPDATE notifications SET delivered_at = :delivered_at WHERE id = :id')
            ->execute([':delivered_at' => $now->format(DateTimeInterface::ATOM), ':id' => (int) $notification['id']]);
    }
}