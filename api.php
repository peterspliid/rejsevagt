<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

try {
    db();
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($action === 'config') {
        json_response([
            'ok' => true,
            'configured' => trim((string) app_config()['rejseplanen_access_id']) !== '',
            'browserPollSeconds' => (int) app_config()['browser_poll_seconds'],
            'vapidPublicKey' => vapid_keys()['publicKey'],
        ]);
    }

    if ($action === 'stations') {
        json_response(['ok' => true, 'stations' => station_search((string) ($_GET['q'] ?? ''))]);
    }

    if ($action === 'routes') {
        $origin = resolve_station((string) ($_GET['origin'] ?? ''));
        $dest = resolve_station((string) ($_GET['destination'] ?? ''));
        $time = (string) ($_GET['time'] ?? '');
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new RuntimeException('Tidspunktet skal være HH:MM.');
        }
        $date = (string) ($_GET['date'] ?? date('Y-m-d'));
        $routes = trip_search($origin['id'], $dest['id'], $date, $time);
        json_response([
            'ok' => true,
            'origin' => $origin,
            'destination' => $dest,
            'date' => $date,
            'time' => $time,
            'routes' => $routes,
        ]);
    }

    if ($action === 'subscribe') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        foreach (['origin', 'destination', 'time', 'route'] as $field) {
            if (!isset($input[$field])) {
                throw new RuntimeException('Mangler ' . $field . '.');
            }
        }
        $route = $input['route'];
        if (!is_array($route) || !isset($route['snapshot'])) {
            throw new RuntimeException('Ugyldig rute.');
        }
        if (!is_array($input['origin']) || !is_array($input['destination'])) {
            throw new RuntimeException('Ugyldig station.');
        }
        $targetTime = route_planned_departure_time($route, (string) $input['time']);
        $snapshot = subscription_snapshot_from_route($route, $targetTime);
        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $duplicate = find_duplicate_subscription($input['origin'], $input['destination'], $targetTime, $route);
        if ($duplicate !== null) {
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
                ':last_snapshot_hash' => $snapshot['snapshot_hash'],
                ':last_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':last_checked_at' => $now,
                ':id' => (int) $duplicate['id'],
            ]);
            json_response(['ok' => true, 'id' => (int) $duplicate['id'], 'duplicate' => true]);
        }

        db()->prepare(
            'INSERT INTO subscriptions
             (origin_id, origin_name, dest_id, dest_name, target_time, route_signature, route_label,
              last_snapshot_hash, last_snapshot, last_checked_at, created_at)
             VALUES
             (:origin_id, :origin_name, :dest_id, :dest_name, :target_time, :route_signature, :route_label,
              :last_snapshot_hash, :last_snapshot, :last_checked_at, :created_at)'
        )->execute([
            ':origin_id' => (string) $input['origin']['id'],
            ':origin_name' => (string) $input['origin']['name'],
            ':dest_id' => (string) $input['destination']['id'],
            ':dest_name' => (string) $input['destination']['name'],
            ':target_time' => $targetTime,
            ':route_signature' => (string) $route['signature'],
            ':route_label' => (string) $route['label'],
            ':last_snapshot_hash' => $snapshot['snapshot_hash'],
            ':last_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':last_checked_at' => $now,
            ':created_at' => $now,
        ]);
        json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }

    if ($action === 'push-subscribe') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        save_push_subscription($input, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        json_response(['ok' => true]);
    }

    if ($action === 'subscriptions') {
        $rows = db()->query('SELECT * FROM subscriptions ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $subscriptions = array_map(static function (array $row): array {
            $snapshot = decode_snapshot($row['last_snapshot'] ?? '');
            return [
                'id' => (int) $row['id'],
                'origin' => $row['origin_name'],
                'destination' => $row['dest_name'],
                'time' => route_planned_departure_time(['snapshot' => $snapshot], (string) $row['target_time']),
                'route' => $row['route_label'],
                'lastCheckedAt' => $row['last_checked_at'],
                'createdAt' => $row['created_at'],
            ];
        }, $rows);
        json_response(['ok' => true, 'subscriptions' => $subscriptions]);
    }

    if ($action === 'unsubscribe') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM subscriptions WHERE id = :id')->execute([':id' => $id]);
        json_response(['ok' => true]);
    }

    if ($action === 'check') {
        $created = check_subscriptions(false);
        $rows = db()->query(
            'SELECT id, title, body, created_at FROM notifications
             WHERE delivered_at IS NULL ORDER BY created_at ASC LIMIT 10'
        )->fetchAll(PDO::FETCH_ASSOC);
        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        db()->exec("UPDATE notifications SET delivered_at = " . db()->quote($now) . " WHERE delivered_at IS NULL");
        json_response(['ok' => true, 'created' => $created, 'notifications' => $rows]);
    }

    json_response(['ok' => false, 'error' => 'Ukendt handling.'], 404);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
