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
    // §14 (S11 step 14) — the routing-record SENTENCES: ICU-templated, slot-safe (slots are
    // counts, scores and localized language names ONLY — agent facts render separately and
    // elide by role, SEC-02). The stored record prose stays the canonical English data.
    'record' => [
        'department' => [
            'explicit' => 'Department set explicitly by the caller.',
            'ai_accepted' => 'AI suggestion applied — auto-accept is on.',
            'ai_flagged' => 'AI suggestion NOT applied (auto-accept off) — flagged for review.',
            'connection_tag' => 'Conversation default from the connection routing tag.',
            'connection_tag_miss' => 'The connection tag matched no department — next source.',
            'tenant_default' => 'Tenant default department.',
            'tenant_default_flagged' => 'Tenant default department (the AI suggestion stays flagged for review).',
        ],
        'queue' => [
            'explicit' => 'Queue set explicitly by the caller.',
            'routing_tag' => 'A rule matched the routing tag.',
            'department_default' => 'Department default queue.',
        ],
        'rg' => [
            'forced' => 'Responsible-gambling intake — FORCED to the RG queue. This routing is final.',
            'missing' => 'Responsible-gambling intake, but no RG queue exists — the resolved queue stands.',
        ],
        'position' => [
            'scored' => 'Priority score :score (boosts: :boosts).',
            'starved' => 'Waited past the :cap-minute cap — promoted to the head of the queue, every boost outranked.',
        ],
        'eligibility' => [
            'none' => 'No eligible agent online with a free slot.',
            'ok' => ':count routable — :strategy',
        ],
        'strategy' => [
            'none' => 'no routable agent',
            'rr' => ':count in the rotation, pointer advanced',
            'load' => 'least open tickets first (:load open)',
            'first' => 'first eligible by user id',
            'skill' => [
                'matched' => 'skill match: :language',
                'dropped_starved' => 'skill match: :language — nobody online with it; waited past the cap, so the language requirement dropped',
                'dropped_nearest' => 'skill match: :language — nobody online with it; nearest-skill fallback, language requirement dropped',
                'hold' => 'skill match: :language — :online online, 0 with the skill → holding for a matching agent',
                'none' => 'nobody satisfies the skill requirements',
            ],
        ],
        'assign' => [
            'ok' => 'Assigned by the system.',
            'at_capacity' => 'At capacity at the moment of commit — next candidate.',
            'claimed' => 'Claimed by a person first — routing yields.',
            'out_of_scope' => 'The candidate cannot reach this ticket — next candidate.',
        ],
        'wait' => 'Waiting in the queue — re-evaluated on the next availability event.',
        'engine_error' => 'Engine error — parked in the department default queue. Nothing is lost; the team is alerted.',
        'courtesy_hold' => 'The previous assignee is :status — holding :minutes minutes for them before re-routing.',
        'manual_reassign' => 'Assigned by a supervisor — the strategy was bypassed.',
        'rescue_return' => 'Returned to the queue by the system after the agent lost connection.',
        'no_boosts' => 'none',
    ],
    'notify' => [
        'broadcast' => 'Broadcast from a supervisor',
        'no_skill_match' => 'A skill queue is holding with nobody to match',
        'rescue_flagged' => 'A ticket entered Rescue',
        'pull_in_recurring' => 'The same pull-in keeps recurring — make it permanent?',
    ],
];
