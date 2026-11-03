<?php

return [
    // Initial state for legacy settings; an explicit administrative setting takes precedence.
    'schedule_controls_enabled' => env('CASH_BOX_SCHEDULE_CONTROLS_ENABLED', false),
];
