<?php

require __DIR__ . '/lib.php';
$configured = trim((string) app_config()['rejseplanen_access_id']) !== '';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
?>
<!doctype html>
<html lang="da">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rejser</title>
  <link rel="icon" href="icon.svg" type="image/svg+xml">
  <style>
    :root {
      color-scheme: light dark;
      --ink: #172026;
      --muted: #65727c;
      --line: #d7dee3;
      --page: #eef3f1;
      --surface: #ffffff;
      --input: #ffffff;
      --panel: #f8faf8;
      --accent: #0f6b86;
      --accent-strong: #0a4e63;
      --warn: #9b4a13;
      --setup-bg: #fff7ec;
      --setup-line: #e0b989;
      --secondary: #e4eceb;
      --secondary-hover: #d4e1df;
      --danger: #7e2f27;
      --pill-bg: #edf6f7;
      --pill-ink: #0b596d;
      --error: #8e2b20;
      --good: #2f6f4f;
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
        --panel: #17242a;
        --accent: #6ab8cb;
        --accent-strong: #8fd1df;
        --warn: #f0bd75;
        --setup-bg: #2b2116;
        --setup-line: #71562f;
        --secondary: #253840;
        --secondary-hover: #30464f;
        --danger: #a94a40;
        --pill-bg: #173945;
        --pill-ink: #a9e6f0;
        --error: #ff9b8e;
        --good: #8bd3aa;
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
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 0 22px;
    }
    h1 {
      margin: 0;
      font-size: clamp(28px, 5vw, 54px);
      line-height: 1;
      letter-spacing: 0;
    }
    .status {
      max-width: 520px;
      color: var(--muted);
      font-size: 15px;
      line-height: 1.45;
    }
    .setup {
      border: 1px solid var(--setup-line);
      background: var(--setup-bg);
      color: var(--warn);
      padding: 14px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
    }
    .search {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.2fr) 150px 150px auto;
      gap: 10px;
      align-items: end;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 14px;
      box-shadow: var(--shadow);
    }
    label {
      display: grid;
      gap: 6px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 700;
    }
    input {
      width: 100%;
      height: 42px;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 0 12px;
      color: var(--ink);
      background: var(--input);
      font: inherit;
    }
    .station-field {
      position: relative;
      display: block;
    }
    .station-suggestions {
      position: absolute;
      z-index: 20;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      display: none;
      max-height: 260px;
      overflow-y: auto;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: var(--surface);
      box-shadow: var(--shadow);
    }
    .station-suggestions.open {
      display: block;
    }
    .station-suggestion,
    .station-suggestion-status {
      width: 100%;
      min-height: 40px;
      height: auto;
      border: 0;
      border-radius: 0;
      padding: 9px 12px;
      color: var(--ink);
      background: transparent;
      font: inherit;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.3;
      text-align: left;
      white-space: normal;
    }
    .station-suggestion {
      cursor: pointer;
    }
    .station-suggestion:hover,
    .station-suggestion.active {
      background: var(--secondary);
    }
    .station-suggestion-status {
      color: var(--muted);
      cursor: default;
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
      white-space: nowrap;
    }
    button:hover { background: var(--accent-strong); }
    button.secondary {
      background: var(--secondary);
      color: var(--ink);
    }
    button.secondary:hover { background: var(--secondary-hover); }
    button.danger {
      background: var(--danger);
    }
    .actions {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .notification-control {
      display: grid;
      gap: 5px;
      justify-items: end;
    }
    .notification-status {
      max-width: 260px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.35;
      text-align: right;
    }
    .secondary-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      border-radius: 6px;
      padding: 0 14px;
      background: var(--secondary);
      color: var(--ink);
      font-weight: 800;
      text-decoration: none;
      white-space: nowrap;
    }
    .secondary-link:hover {
      background: var(--secondary-hover);
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
      margin-top: 18px;
    }
    .section {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 16px;
    }
    .section h2 {
      margin: 0 0 12px;
      font-size: 18px;
      letter-spacing: 0;
    }
    .route, .subscription {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 14px;
      align-items: center;
      padding: 14px 0;
      border-top: 1px solid var(--line);
    }
    .route:first-of-type, .subscription:first-of-type { border-top: 0; }
    .times {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 16px;
      margin: 5px 0;
      font-size: 15px;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 3px 9px;
      border-radius: 999px;
      background: var(--pill-bg);
      color: var(--pill-ink);
      font-size: 13px;
      font-weight: 800;
    }
    .muted { color: var(--muted); }
    .error { color: var(--error); }
    .good { color: var(--good); }
    .empty {
      color: var(--muted);
      padding: 8px 0;
    }
    @media (max-width: 820px) {
      header { align-items: flex-start; flex-direction: column; }
      .actions { width: 100%; }
      .secondary-link { width: 100%; }
      .notification-control { width: 100%; justify-items: stretch; }
      .notification-status { max-width: none; text-align: left; }
      .search { grid-template-columns: 1fr; }
      .route, .subscription { grid-template-columns: 1fr; }
      button { width: 100%; }
    }
  </style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>Rejser</h1>
      <p class="status">Søg efter rejser, abonner på en fast hverdagsafgang, og få en browsernotifikation, når Rejseplanen melder ændrede tider eller rutedetaljer.</p>
    </div>
    <div class="actions">
      <div class="notification-control">
        <button class="secondary" id="notifyButton" type="button">Slå notifikationer til</button>
        <div class="notification-status" id="notificationStatus" aria-live="polite"></div>
      </div>
    </div>
  </header>

  <?php if (!$configured): ?>
    <div class="setup">
      Rejseplanen API 2.0 kræver et accessId. Kopiér <code>config.example.php</code> til <code>config.php</code>, og udfyld <code>rejseplanen_access_id</code>.
    </div>
  <?php endif; ?>

  <form class="search" id="searchForm">
    <label>Startstation
      <span class="station-field">
        <input name="origin" autocomplete="off" placeholder="København H" required data-station-input aria-autocomplete="list" aria-expanded="false" aria-controls="originSuggestions">
        <span class="station-suggestions" id="originSuggestions" role="listbox"></span>
      </span>
    </label>
    <label>Slutstation
      <span class="station-field">
        <input name="destination" autocomplete="off" placeholder="Roskilde St." required data-station-input aria-autocomplete="list" aria-expanded="false" aria-controls="destinationSuggestions">
        <span class="station-suggestions" id="destinationSuggestions" role="listbox"></span>
      </span>
    </label>
    <label>Dato
      <input name="date" type="date" value="<?= htmlspecialchars($today, ENT_QUOTES) ?>" required>
    </label>
    <label>Tid
      <input name="time" type="time" value="08:00" required>
    </label>
    <button type="submit">Find rejser</button>
  </form>

  <div class="grid">
    <section class="section">
      <h2>Rejser</h2>
      <div id="message" class="muted">Indtast stationer og tidspunkt for at hente rejseforslag.</div>
      <div id="routes"></div>
    </section>

    <section class="section">
      <h2>Abonnementer</h2>
      <div id="subscriptions"></div>
    </section>
  </div>
</main>

<script>
const state = { lastSearch: null, pollSeconds: 60, vapidPublicKey: '' };
const $ = (selector) => document.querySelector(selector);

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[char]);
}

async function api(url, options = {}) {
  const response = await fetch(url, options);
  const payload = await response.json();
  if (!payload.ok) throw new Error(payload.error || 'Forespørgslen mislykkedes.');
  return payload;
}

async function initConfig() {
  const config = await api('api.php?action=config');
  state.pollSeconds = Math.max(20, Number(config.browserPollSeconds || 60));
  state.vapidPublicKey = config.vapidPublicKey || '';
}

function displayTime(pair) {
  if (!pair) return '';
  const date = pair.rtDate || pair.date || '';
  const time = pair.rtTime || pair.time || '';
  const planned = pair.time && pair.rtTime && pair.time !== pair.rtTime ? ` (planlagt ${pair.time})` : '';
  return `${date} ${time}${planned}`.trim();
}

function displayDateTime(value) {
  if (!value) return 'Ikke tjekket endnu';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('da-DK', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}
function renderRoutes(payload) {
  state.lastSearch = payload;
  const routes = $('#routes');
  $('#message').textContent = `${payload.origin.name} til ${payload.destination.name} fra ${payload.time}`;

  if (!payload.routes.length) {
    routes.innerHTML = '<div class="empty">Ingen rejser fundet.</div>';
    return;
  }

  routes.innerHTML = payload.routes.map((route, index) => {
    const summary = route.snapshot.summary;
    const legs = route.snapshot.legs.map((leg) => `<span class="pill">${escapeHtml(leg.name)}</span>`).join(' ');
    return `
      <div class="route">
        <div>
          <strong>${escapeHtml(route.label)}</strong>
          <div class="times">
            <span>Afgang: ${escapeHtml(displayTime(summary.departure))}</span>
            <span>Ankomst: ${escapeHtml(displayTime(summary.arrival))}</span>
            ${summary.duration ? `<span>Varighed: ${escapeHtml(summary.duration)}</span>` : ''}
          </div>
          <div>${legs}</div>
        </div>
        <button type="button" data-subscribe="${index}">Abonnér</button>
      </div>`;
  }).join('');
}

async function loadSubscriptions() {
  const payload = await api('api.php?action=subscriptions');
  const container = $('#subscriptions');
  if (!payload.subscriptions.length) {
    container.innerHTML = '<div class="empty">Du abonnerer ikke på nogen rejser endnu.</div>';
    return;
  }
  container.innerHTML = payload.subscriptions.map((sub) => `
    <div class="subscription">
      <div>
        <strong>${escapeHtml(sub.origin)} → ${escapeHtml(sub.destination)}</strong>
        <div class="muted">${escapeHtml(sub.route)} kl. ${escapeHtml(sub.time)} på hverdage</div>
        <div class="muted">Sidst tjekket: ${escapeHtml(displayDateTime(sub.lastCheckedAt))}</div>
      </div>
      <button class="danger" type="button" data-unsubscribe="${sub.id}">Fjern</button>
    </div>
  `).join('');
}

async function enableNotifications() {
  const button = $('#notifyButton');
  const status = $('#notificationStatus');

  if (!('Notification' in window)) {
    status.textContent = 'Denne browser understøtter ikke notifikationer.';
    return false;
  }
  if (!window.isSecureContext) {
    status.textContent = 'Notifikationer kræver HTTPS.';
    return false;
  }

  let enabled = false;
  button.disabled = true;
  status.textContent = 'Beder om tilladelse...';

  try {
    if (Notification.permission === 'default') {
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        status.textContent = 'Notifikationer blev ikke slået til.';
        return false;
      }
    }

    if (Notification.permission === 'denied') {
      status.textContent = 'Notifikationer er blokeret i browseren.';
      return false;
    }

    if ('serviceWorker' in navigator) {
      const registration = await navigator.serviceWorker.register('sw.js');
      await navigator.serviceWorker.ready;
      await registerPushSubscription(registration);
    }

    enabled = true;
    button.textContent = 'Notifikationer er slået til';
    status.textContent = '';
    return true;
  } catch (error) {
    console.warn(error);
    status.textContent = 'Kunne ikke slå notifikationer til.';
    return false;
  } finally {
    button.disabled = enabled;
  }
}

function urlBase64ToUint8Array(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

async function registerPushSubscription(registration) {
  if (!('PushManager' in window) || !state.vapidPublicKey) return;

  const existing = await registration.pushManager.getSubscription();
  const subscription = existing || await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(state.vapidPublicKey),
  });

  await api('api.php?action=push-subscribe', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(subscription),
  });
}

function updateNotificationUi() {
  const button = $('#notifyButton');
  const status = $('#notificationStatus');
  if (!('Notification' in window)) {
    status.textContent = 'Denne browser understøtter ikke notifikationer.';
    button.disabled = true;
    return;
  }
  if (Notification.permission === 'granted') {
    button.textContent = 'Notifikationer er slået til';
    button.disabled = true;
    status.textContent = '';
  } else if (Notification.permission === 'denied') {
    status.textContent = 'Notifikationer er blokeret i browseren.';
  }
}

async function sendBrowserNotification(title, body, tag) {
  if (Notification.permission !== 'granted') return;
  if ('serviceWorker' in navigator) {
    const registration = await navigator.serviceWorker.ready;
    if (registration.active) {
      registration.active.postMessage({ type: 'notify', title, body, tag });
      return;
    }
  }
  new Notification(title, { body, tag });
}

async function pollChanges() {
  try {
    const payload = await api('api.php?action=check');
    for (const note of payload.notifications) {
      await sendBrowserNotification(note.title, note.body, `rejser-${note.id}`);
    }
    if (payload.notifications.length) await loadSubscriptions();
  } catch (error) {
    console.warn(error);
  } finally {
    window.setTimeout(pollChanges, state.pollSeconds * 1000);
  }
}

function debounce(fn, delay) {
  let timer = null;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

function closeStationSuggestions(field) {
  field.panel.classList.remove('open');
  field.input.setAttribute('aria-expanded', 'false');
  field.activeIndex = -1;
}

function renderStationSuggestions(field, stations, status = '') {
  field.items = stations;
  field.activeIndex = -1;

  if (status) {
    field.panel.innerHTML = `<span class="station-suggestion-status">${escapeHtml(status)}</span>`;
  } else {
    field.panel.innerHTML = stations.map((station, index) => `
      <button class="station-suggestion" type="button" role="option" data-station-index="${index}">
        ${escapeHtml(station.name)}
      </button>
    `).join('');
  }

  field.panel.classList.add('open');
  field.input.setAttribute('aria-expanded', 'true');
}

function setActiveStationSuggestion(field, index) {
  const buttons = [...field.panel.querySelectorAll('.station-suggestion')];
  if (!buttons.length) return;
  field.activeIndex = (index + buttons.length) % buttons.length;
  buttons.forEach((button, buttonIndex) => {
    button.classList.toggle('active', buttonIndex === field.activeIndex);
    button.setAttribute('aria-selected', buttonIndex === field.activeIndex ? 'true' : 'false');
  });
  buttons[field.activeIndex].scrollIntoView({ block: 'nearest' });
}

function chooseStationSuggestion(field, station) {
  field.input.value = station.name;
  closeStationSuggestions(field);
  field.input.focus();
}

async function updateStationSuggestions(field) {
  const q = field.input.value.trim();
  field.requestId += 1;
  const requestId = field.requestId;

  if (q.length < 2) {
    closeStationSuggestions(field);
    field.items = [];
    return;
  }

  renderStationSuggestions(field, [], 'Søger...');

  try {
    const payload = await api(`api.php?action=stations&q=${encodeURIComponent(q)}`);
    if (requestId !== field.requestId) return;
    if (!payload.stations.length) {
      renderStationSuggestions(field, [], 'Ingen stationer fundet');
      return;
    }
    renderStationSuggestions(field, payload.stations);
  } catch (error) {
    if (requestId !== field.requestId) return;
    console.warn(error);
    renderStationSuggestions(field, [], 'Kunne ikke hente stationer');
  }
}

function setupStationAutocomplete(input) {
  const field = {
    input,
    panel: document.getElementById(input.getAttribute('aria-controls')),
    items: [],
    activeIndex: -1,
    requestId: 0,
  };
  const debouncedUpdate = debounce(() => updateStationSuggestions(field), 250);

  input.addEventListener('input', debouncedUpdate);
  input.addEventListener('focus', () => {
    if (field.items.length) renderStationSuggestions(field, field.items);
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeStationSuggestions(field);
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveStationSuggestion(field, field.activeIndex + 1);
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveStationSuggestion(field, field.activeIndex - 1);
      return;
    }
    if (event.key === 'Enter' && field.activeIndex >= 0 && field.items[field.activeIndex]) {
      event.preventDefault();
      chooseStationSuggestion(field, field.items[field.activeIndex]);
    }
  });
  field.panel.addEventListener('mousedown', (event) => event.preventDefault());
  field.panel.addEventListener('click', (event) => {
    const button = event.target.closest('[data-station-index]');
    if (!button) return;
    chooseStationSuggestion(field, field.items[Number(button.dataset.stationIndex)]);
  });
  document.addEventListener('click', (event) => {
    if (!input.closest('.station-field').contains(event.target)) closeStationSuggestions(field);
  });
}

$('#searchForm').addEventListener('submit', async (event) => {
  event.preventDefault();
  $('#message').textContent = 'Henter rejser...';
  $('#routes').innerHTML = '';
  const params = new URLSearchParams(new FormData(event.currentTarget));
  try {
    renderRoutes(await api(`api.php?action=routes&${params.toString()}`));
  } catch (error) {
    $('#message').innerHTML = `<span class="error">${escapeHtml(error.message)}</span>`;
  }
});

$('#routes').addEventListener('click', async (event) => {
  const button = event.target.closest('[data-subscribe]');
  if (!button || !state.lastSearch) return;
  await enableNotifications();
  const route = state.lastSearch.routes[Number(button.dataset.subscribe)];
  await api('api.php?action=subscribe', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      origin: state.lastSearch.origin,
      destination: state.lastSearch.destination,
      time: state.lastSearch.time,
      route,
    }),
  });
  button.textContent = 'Abonneret';
  button.disabled = true;
  await loadSubscriptions();
});

$('#subscriptions').addEventListener('click', async (event) => {
  const button = event.target.closest('[data-unsubscribe]');
  if (!button) return;
  const body = new URLSearchParams({ id: button.dataset.unsubscribe });
  await api('api.php?action=unsubscribe', { method: 'POST', body });
  await loadSubscriptions();
});

$('#notifyButton').addEventListener('click', enableNotifications);
document.querySelectorAll('[data-station-input]').forEach(setupStationAutocomplete);

initConfig()
  .catch((error) => { $('#message').innerHTML = `<span class="error">${escapeHtml(error.message)}</span>`; })
  .finally(() => {
    updateNotificationUi();
    loadSubscriptions().catch(console.warn);
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js')
        .then((registration) => {
          if (Notification.permission === 'granted') return registerPushSubscription(registration);
          return null;
        })
        .catch(console.warn);
    }
    pollChanges();
  });
</script>
</body>
</html>