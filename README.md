# Rejser

En lille PHP- og SQLite-app til at søge rejser i Rejseplanen og abonnere på tilbagevendende hverdagsafgange.

## Opsætning

1. Kopiér `config.example.php` til `config.php`.
2. Udfyld `rejseplanen_access_id` i `config.php`.
3. Udfyld `admin_password` i `config.php`, hvis admin-siden skal bruges.
4. Åbn siden gennem PHP eller en normal webserver:

```bash
php -S 127.0.0.1:8080
```

Rejseplanen API 2.0 kræver et access ID fra Rejseplanen Labs:
https://labs.rejseplanen.dk/hc/da/articles/21553113674909-Adgang-til-data-fra-Labs

## Notifikationer

Browsernotifikationer bruger Web Push, når browseren understøtter det. Når notifikationer slås til,
registrerer siden et push-abonnement i SQLite, og `cron.php` sender nye ændringsnotifikationer via
service workeren, selv når siden ikke er åben.

Hvis Web Push ikke er tilgængeligt, bruger siden stadig den gamle fallback: mens siden er åben,
poller den `api.php?action=check` og viser ventende notifikationer gennem `sw.js`.

Til serverside-tjek køres dette fra cron:

```cron
*/5 * * * * cd /ssd/web/www/spli.id/rejser && /usr/bin/php cron.php
```

Cron gemmer registrerede ændringer i SQLite og forsøger at levere dem med Web Push. Hvis der ikke
findes aktive push-abonnementer, modtager browseren dem næste gang siden er åben og poller.

Hvert abonnement er tilbagevendende: det overvåger den samme valgte rejse på det abonnerede tidspunkt hver hverdag. For at spare Rejseplanen API-kald kører tjek kun, når hverdagsafgangen ligger inden for de næste `notification_window_minutes`; standarden er 60 minutter.

## Admin

Admin-siden ligger på `admin.php` og kræver HTTP Basic-login med passwordet fra `admin_password` i `config.php`.

Siden viser aktive abonnementer, månedens registrerede Rejseplanen API-kald, endpoint-fordeling og de seneste API-kald. Tællingen starter fra det tidspunkt, hvor `api_queries`-loggen blev tilføjet.
