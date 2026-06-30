<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$config = app_config();
$adminPassword = (string) ($config['admin_password'] ?? '');

if ($adminPassword === '') {
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="da">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Admin ikke konfigureret</title>
      <style>
        :root {
          color-scheme: light dark;
          font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          background: #eef3f1;
          color: #172026;
        }
        @media (prefers-color-scheme: dark) {
          :root {
            background: #10191d;
            color: #eef6f4;
          }
        }
        body { margin: 0; }
        main { width: min(760px, calc(100% - 28px)); margin: 0 auto; padding: 48px 0; }
        code { font-size: .95em; }
      </style>
    </head>
    <body>
    <main>
      <h1>Admin er ikke konfigureret</h1>
      <p>Sæt <code>admin_password</code> i <code>config.php</code> for at aktivere admin-siden.</p>
      <p><a href="./">Tilbage til Rejser</a></p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$providedPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
if ($providedPassword === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization = (string) $_SERVER['HTTP_AUTHORIZATION'];
    if (str_starts_with($authorization, 'Basic ')) {
        $decoded = base64_decode(substr($authorization, 6), true);
        if (is_string($decoded) && str_contains($decoded, ':')) {
            $providedPassword = substr($decoded, strpos($decoded, ':') + 1);
        }
    }
}
if (!hash_equals($adminPassword, $providedPassword)) {
    header('WWW-Authenticate: Basic realm="Rejser admin"');
    http_response_code(401);
    echo 'Login kræves.';
    exit;
}

db();
$selectedMonth = (string) ($_GET['month'] ?? '');
$bounds = month_bounds($selectedMonth);
$queryLimit = 50000;

$queryCount = api_query_count_for_month($bounds['month']);
$remaining = max(0, $queryLimit - $queryCount);
$percent = min(100, ($queryCount / $queryLimit) * 100);

$breakdown = db()->prepare(
    'SELECT endpoint, COUNT(*) AS count
     FROM api_queries
     WHERE created_at >= :start AND created_at < :end
     GROUP BY endpoint
     ORDER BY count DESC, endpoint ASC'
);
$breakdown->execute([':start' => $bounds['start'], ':end' => $bounds['end']]);
$endpointRows = $breakdown->fetchAll(PDO::FETCH_ASSOC);

$subscriptions = db()->query(
    'SELECT id, origin_name, dest_name, target_time, route_label, last_checked_at, created_at
     FROM subscriptions
     ORDER BY created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$recent = db()->prepare(
    'SELECT endpoint, status_code, created_at
     FROM api_queries
     WHERE created_at >= :start AND created_at < :end
     ORDER BY created_at DESC
     LIMIT 50'
);
$recent->execute([':start' => $bounds['start'], ':end' => $bounds['end']]);
$recentRows = $recent->fetchAll(PDO::FETCH_ASSOC);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - Rejser</title>
  <style>
    :root {
      color-scheme: light dark;
      --ink: #172026;
      --muted: #65727c;
      --line: #d7dee3;
      --page: #eef3f1;
      --surface: #ffffff;
      --input: #ffffff;
      --accent: #0f6b86;
      --accent-strong: #0a4e63;
      --bar: #77bd6e;
      --shadow: 0 10px 30px rgba(20, 61, 82, .12);
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --ink: #eef6f4;
        --muted: #aab8bd;
        --line: #2a3f47;
        --page: #10191d;
        --surface: #17242a;
        --input: #0f1a1f;
        --accent: #6ab8cb;
        --accent-strong: #8fd1df;
        --bar: #91d47f;
        --shadow: 0 10px 30px rgba(0, 0, 0, .28);
      }
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      color: var(--ink);
      background: var(--page);
    }
    main {
      width: min(1120px, calc(100% - 28px));
      margin: 0 auto;
      padding: 28px 0 36px;
    }
    header {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      align-items: end;
      padding: 18px 0 22px;
    }
    h1 {
      margin: 0;
      font-size: clamp(30px, 5vw, 54px);
      line-height: 1;
      letter-spacing: 0;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 18px;
      letter-spacing: 0;
    }
    a {
      color: var(--accent);
      font-weight: 800;
      text-decoration: none;
    }
    a:hover { color: var(--accent-strong); }
    .muted {
      color: var(--muted);
      line-height: 1.45;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }
    .section, .metric {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 16px;
      box-shadow: var(--shadow);
    }
    .metric strong {
      display: block;
      font-size: clamp(28px, 4vw, 44px);
      line-height: 1;
      margin-bottom: 8px;
    }
    .bar {
      height: 12px;
      border-radius: 999px;
      background: var(--line);
      overflow: hidden;
      margin-top: 12px;
    }
    .bar span {
      display: block;
      height: 100%;
      width: <?= e(number_format($percent, 2, '.', '')) ?>%;
      background: var(--bar);
    }
    form {
      display: flex;
      gap: 8px;
      align-items: end;
      flex-wrap: wrap;
    }
    label {
      display: grid;
      gap: 6px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 800;
    }
    input {
      height: 42px;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 0 12px;
      color: var(--ink);
      background: var(--input);
      font: inherit;
    }
    button {
      height: 42px;
      border: 0;
      border-radius: 6px;
      padding: 0 14px;
      background: var(--accent);
      color: #fff;
      font: inherit;
      font-weight: 800;
      cursor: pointer;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    th, td {
      text-align: left;
      vertical-align: top;
      padding: 11px 8px;
      border-top: 1px solid var(--line);
    }
    th {
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .stack {
      display: grid;
      gap: 14px;
      margin-top: 14px;
    }
    .empty {
      color: var(--muted);
      padding: 8px 0;
    }
    @media (max-width: 820px) {
      header { align-items: flex-start; flex-direction: column; }
      .grid { grid-template-columns: 1fr; }
      table { display: block; overflow-x: auto; }
    }
  </style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>Admin</h1>
      <p class="muted">Overblik over abonnementer og Rejseplanen API-forbrug.</p>
    </div>
    <a href="./">Tilbage til Rejser</a>
  </header>

  <form class="section" method="get">
    <label>Måned
      <input type="month" name="month" value="<?= e($bounds['month']) ?>">
    </label>
    <button type="submit">Vis måned</button>
  </form>

  <div class="grid" style="margin-top: 14px;">
    <div class="metric">
      <strong><?= e(number_format($queryCount, 0, ',', '.')) ?></strong>
      <span class="muted">API-kald i <?= e($bounds['month']) ?></span>
      <div class="bar" aria-label="Forbrug af månedlig grænse"><span></span></div>
    </div>
    <div class="metric">
      <strong><?= e(number_format($remaining, 0, ',', '.')) ?></strong>
      <span class="muted">Tilbage af 50.000</span>
    </div>
    <div class="metric">
      <strong><?= e(count($subscriptions)) ?></strong>
      <span class="muted">Aktive abonnementer</span>
    </div>
  </div>

  <div class="stack">
    <section class="section">
      <h2>Abonnementer</h2>
      <?php if (!$subscriptions): ?>
        <div class="empty">Ingen abonnementer endnu.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Rejse</th>
              <th>Afgang</th>
              <th>Rute</th>
              <th>Oprettet</th>
              <th>Sidst tjekket</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($subscriptions as $subscription): ?>
            <tr>
              <td><?= e($subscription['id']) ?></td>
              <td><?= e($subscription['origin_name']) ?> → <?= e($subscription['dest_name']) ?></td>
              <td><?= e($subscription['target_time']) ?> på hverdage</td>
              <td><?= e($subscription['route_label']) ?></td>
              <td><?= e($subscription['created_at']) ?></td>
              <td><?= e($subscription['last_checked_at'] ?: 'Ikke tjekket endnu') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="section">
      <h2>API-kald fordelt på endpoint</h2>
      <?php if (!$endpointRows): ?>
        <div class="empty">Ingen registrerede API-kald i denne måned.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Endpoint</th>
              <th>Kald</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($endpointRows as $row): ?>
            <tr>
              <td><?= e($row['endpoint']) ?></td>
              <td><?= e(number_format((int) $row['count'], 0, ',', '.')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="section">
      <h2>Seneste API-kald</h2>
      <?php if (!$recentRows): ?>
        <div class="empty">Ingen registrerede API-kald i denne måned.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Tidspunkt</th>
              <th>Endpoint</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recentRows as $row): ?>
            <tr>
              <td><?= e($row['created_at']) ?></td>
              <td><?= e($row['endpoint']) ?></td>
              <td><?= e($row['status_code']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </div>
</main>
</body>
</html>
