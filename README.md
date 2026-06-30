# Rejsevagt

Rejsevagt overvåger faste hverdagsafgange i Rejseplanen og sender en browsernotifikation, når tider eller rutedetaljer ændrer sig kort før afgang.

Appen er en lille PHP- og SQLite-løsning uden brugerkonti. Abonnementer er fælles for installationen, og browsernotifikationer leveres med Web Push, når browseren understøtter det.

## Funktioner

- Søg rejser mellem danske stationer via Rejseplanen API 2.0.
- Abonnér på en fast hverdagsafgang.
- Tjek kun afgange inden for den konfigurerede notifikationsperiode for at spare API-kald.
- Send Web Push-notifikationer fra cron, også når siden ikke er åben.
- Brug browser-polling som fallback, mens siden er åben.
- Se API-forbrug og abonnementer på en simpel admin-side.

## Opsætning

1. Kopiér `config.example.php` til `config.php`.
2. Udfyld `rejseplanen_access_id` i `config.php`.
3. Udfyld `admin_password` i `config.php`, hvis admin-siden skal bruges.
4. Servér mappen med PHP eller en normal webserver.

Lokal udviklingsserver:

```bash
php -S 127.0.0.1:8080
```

Rejseplanen API 2.0 kræver et access ID fra Rejseplanen Labs:
https://labs.rejseplanen.dk/hc/da/articles/21553113674909-Adgang-til-data-fra-Labs

## Konfiguration

De vigtigste indstillinger ligger i `config.php`:

```php
return [
    'rejseplanen_access_id' => '',
    'admin_password' => '',
    'browser_poll_seconds' => 60,
    'server_check_minutes' => 5,
    'notification_window_minutes' => 60,
    'vapid_public_key' => '',
    'vapid_private_key' => '',
    'vapid_subject' => 'mailto:webpush@example.com',
];
```

Hvis VAPID-nøglerne er tomme, opretter appen selv `data/vapid.json` første gang push-konfigurationen bruges.

## Notifikationer

Browsernotifikationer bruger Web Push, når browseren understøtter det. Når notifikationer slås til, registrerer siden et push-abonnement i SQLite, og `cron.php` sender nye ændringsnotifikationer via service workeren, selv når siden ikke er åben.

Hvis Web Push ikke er tilgængeligt, bruger siden stadig fallback: mens siden er åben, poller den `api.php?action=check` og viser ventende notifikationer gennem `sw.js`.

## Cron

Til serverside-tjek køres dette fra cron:

```cron
*/5 * * * * cd /path/to/rejsevagt && /usr/bin/php cron.php >/dev/null 2>&1
```

Hvert abonnement er tilbagevendende: det overvåger den samme valgte rejse på det abonnerede tidspunkt hver hverdag. For at spare Rejseplanen API-kald kører tjek kun, når hverdagsafgangen ligger inden for de næste `notification_window_minutes`; standarden er 60 minutter.

## Admin

Admin-siden ligger på `admin.php` og kræver HTTP Basic-login med passwordet fra `admin_password` i `config.php`.

Siden viser aktive abonnementer, månedens registrerede Rejseplanen API-kald, endpoint-fordeling og de seneste API-kald.

## Verifikation

```bash
php -l lib.php
php -l api.php
php -l index.php
php -l admin.php
php -l cron.php
```

Web Push-konfiguration:

```bash
php -r '$_GET=["action"=>"config"]; require "api.php";'
```

Bemærk: `php cron.php` kan sende rigtige notifikationer på live data, hvis en abonneret afgang ligger inden for notifikationsvinduet.
