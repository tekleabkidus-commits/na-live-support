<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Feature-flag registry — registry #7 (CLAUDE.md §2, Doc 01 FR-11, ADR 0005)
|------------------------------------------------------------------------------
|
| Every module registers its feature-flag keys here so Doc 16's Backoffice can
| enumerate and toggle them without code knowledge (FR-11). No flag may be read
| or written unless registered — FeatureRegistry throws on an unknown key, the
| same posture as the settings engine.
|
| Entry schema (all fields required):
|   key               string  dot-namespaced, e.g. "widget.file_upload"
|   label_i18n_key    string  what Doc 16 shows an operator (N-03: never raw text)
|   default           bool    the value every tenant starts with
|   tenant_toggleable bool    false = platform-only switch, invisible to operators
|   owning_doc        string  "DocNN"
|
| N-19 (flags hide, they never destroy): registered keys must never appear in a
| migration — a flag may guard rendering and routing, never schema or deletes.
| tests/Feature/Foundation/FeatureRegistryTest.php greps for exactly that.
|
*/

return [
    [
        // FS-03's runbook switch: "enable emergency read-only mode flag while
        // diagnosing" an isolation failure. Platform-only by nature — an operator
        // must not be able to lift the platform out of a safety mode.
        'key' => 'platform.emergency_read_only',
        'label_i18n_key' => 'foundation.feature.emergency_read_only',
        'default' => false,
        'tenant_toggleable' => false,
        'owning_doc' => 'Doc01',
    ],
    [
        // Doc 07 FR-18: "voice recording behind flag". The flag gates the
        // composer affordance's RENDERING only (N-19 — flags hide, never
        // destroy); the recording rides the existing Doc 04 attachment chain
        // when on. Default OFF; tenant-toggleable so an operator can opt in.
        'key' => 'inbox.voice_recording',
        'label_i18n_key' => 'inbox.feature.voice_recording',
        'default' => false,
        'tenant_toggleable' => true,
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 12 R-04: the tenant-wide "pause all rules" switch — instant, an
        // evaluation short-circuit only (N-19: it hides behavior, destroys
        // nothing; armed SLA instances and logs are untouched). Lives in the
        // incident runbook beside Doc 09's AI kill switches. default false =
        // rules RUN; true = paused.
        'key' => 'automation.rules_pause',
        'label_i18n_key' => 'workflow.feature.rules_pause',
        'default' => false,
        'tenant_toggleable' => true,
        'owning_doc' => 'Doc12',
    ],
];
