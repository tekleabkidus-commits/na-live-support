<?php

declare(strict_types=1);

// Doc 12 — workflow layer strings (S12; the namespace §14 names). Grows through
// the session's steps; every key ships en + am together (C-08/N-03).
return [
    'feature' => [
        // R-04: the tenant-wide rules-pause switch (features registry label).
        'rules_pause' => 'Pause all automation rules',
    ],
    'forms' => [
        // FR-22: the publish-validation block labels (SchemaValidator's codes).
        'block' => [
            'invalid_shape' => 'The form definition is malformed: :detail',
            'forward_reference' => '":at" points at ":field", which lives in a LATER step — conditions may only look back.',
            'unknown_field' => '":field" does not exist as a field.',
            'retired_field' => '":field" is retired — edit the form before publishing.',
            'required_hidden' => '":field" is required but sometimes hidden. Make it "required when visible" instead.',
            'incomplete_language' => ':at is missing its :language text.',
            'upload_unsupported' => 'A required file field cannot be answered on ":channel" — that channel accepts no uploads.',
        ],
        // FR-22: the usage-banner section headings.
        'usage' => [
            'bot_menus' => 'Bot menus',
            'rules' => 'Automation rules',
            'widget_shortcuts' => 'Widget shortcuts',
        ],
    ],
    'macros' => [
        // EC-09: the inline notices when a bundled action cannot apply.
        'bundled_skipped' => 'The macro text was inserted, but ":type" was skipped — you do not have that permission.',
        'bundled_failed' => 'The macro text was inserted, but ":type" could not be applied here.',
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
