self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('message', (event) => {
  if (!event.data || event.data.type !== 'notify') {
    return;
  }

  self.registration.showNotification(event.data.title, {
    body: event.data.body,
    tag: event.data.tag || 'rejser-change',
    icon: 'icon.svg',
    badge: 'icon.svg',
    data: { url: './' },
  });
});

self.addEventListener('push', (event) => {
  let payload = {};
  if (event.data) {
    try {
      payload = event.data.json();
    } catch (error) {
      payload = { title: 'Rejse ændret', body: event.data.text() };
    }
  }

  event.waitUntil(self.registration.showNotification(payload.title || 'Rejse ændret', {
    body: payload.body || '',
    tag: payload.tag || 'rejser-change',
    icon: 'icon.svg',
    badge: 'icon.svg',
    data: { url: payload.url || './' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : './';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (const client of windowClients) {
        if ('focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      return clients.openWindow ? clients.openWindow(targetUrl) : Promise.resolve();
    })
  );
});

self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(self.registration.pushManager.subscribe(event.oldSubscription.options));
});
