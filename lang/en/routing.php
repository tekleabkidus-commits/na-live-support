<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 11 — Departments, Teams, Queues & Routing strings (§14; C-08; N-03)
|------------------------------------------------------------------------------
|
| Opened at S11 step 1 with the notification-category labels the registry
| references; every later surface adds its keys here and in lang/am/routing.php
| in the SAME commit (RoutingI18nParityTest, step 14). Destructive-tier strings
| (delete/restructure confirmations, force status, the RG replacement choice)
| are flagged for the R-14 queue when they land. Routing-record sentences are
| ICU-templated keys with slot-safe grammar (§14) — added at step 5.
|
*/

return [
    'system' => [
        // FR-16c: the orphan-move system note (persistSystemMarker code `routing_orphan_moved`, N-03).
        // FR-16a: the auto-return system note (persistSystemMarker code `routing_rescue_returned`).
        'routing_rescue_returned' => 'Returned to the queue — the assigned agent lost connection',
        'routing_orphan_moved' => 'Moved to the department default queue — its queue was deleted',
    ],
    'estimate' => [
        // FR-13 / A-52 / SEC-05 — the band vocabulary (J15): bands only, never counts or names.
        'band' => [
            'short' => 'about 5 minutes',
            'medium' => 'about 20 minutes',
            'long' => 'over an hour',
            'off_hours' => 'when we open at :time',
        ],
    ],
    'notify' => [
        'broadcast' => 'Broadcast from a supervisor',
        'no_skill_match' => 'A skill queue is holding with nobody to match',
        'rescue_flagged' => 'A ticket entered Rescue',
        'pull_in_recurring' => 'The same pull-in keeps recurring — make it permanent?',
    ],
];
