<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

try {
    db();
    $notifications = check_subscriptions(false);
    echo 'Abonnementer tjekket. Nye notifikationer: ' . count($notifications) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
