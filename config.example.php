<?php

return [
    // Få adgang gennem Rejseplanen Labs, og kopiér derefter filen til config.php.
    // https://labs.rejseplanen.dk/hc/da/articles/21553113674909-Adgang-til-data-fra-Labs
    'rejseplanen_access_id' => '',

    // Password til admin.php. Siden viser ikke data, før dette er sat.
    'admin_password' => '',

    // Hvor ofte browseren poller api.php?action=check, mens siden er åben.
    'browser_poll_seconds' => 60,

    // Valgfrit: bruges af cron.php for at undgå for hyppige tjek af samme abonnement.
    'server_check_minutes' => 5,

    // Tjek kun en abonneret hverdagsafgang så mange minutter før afgang.
    'notification_window_minutes' => 60,

    // Valgfrit: udfyld faste VAPID-nøgler til Web Push.
    // Hvis de er tomme, opretter appen selv nøgler i data/vapid.json.
    'vapid_public_key' => '',
    'vapid_private_key' => '',
    'vapid_subject' => 'mailto:webpush@example.com',
];
