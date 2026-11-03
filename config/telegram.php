<?php

return [
    // C328: transport availability only. Each bot/destination defaults to paused
    // in persistence; Administration explicitly activates verified destinations.
    // This optional deployment gate can pause every bot for maintenance.
    'ca_bundle' => env('TELEGRAM_CA_BUNDLE'),
    'scheduled_worker_enabled' => env('TELEGRAM_SCHEDULED_WORKER_ENABLED', false),
    'sending_enabled' => env('TELEGRAM_TRANSPORT_ENABLED', true),
];
