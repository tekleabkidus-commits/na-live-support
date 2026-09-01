<?php

declare(strict_types=1);

// Doc 12 — workflow layer strings (S12; the namespace §14 names). Grows through
// the session's steps; every key ships en + am together (C-08/N-03).
return [
    'feature' => [
        // R-04: the tenant-wide rules-pause switch (features registry label).
        'rules_pause' => 'Pause all automation rules',
    ],
    'sla' => [
        // §8/§13/§14: the policy editor's live example — ICU-templated, the
        // accessible explanation of clock math. Placeholders: :created, :target
        // (both "Day HH:MM" in the display timezone), :zone.
        'example' => 'A ticket created :created would target first response :target (:zone).',
        'example_unreachable' => 'This calendar never opens, so no target can be computed — draw at least one weekly interval.',
        // FR-11: the chip's stage labels (Doc 07 renders the contract).
        'stage' => [
            'first_response' => 'First response',
            'next_response' => 'Next response',
            'resolution' => 'Resolution',
        ],
    ],
];
