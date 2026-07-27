<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Settings registry (CLAUDE.md §2, Doc 01 FR-07 / T-04)
|------------------------------------------------------------------------------
|
| No setting may be read or written unless registered here; the settings
| engine (Doc 01, Session 1) loads this file as its source of truth.
|
| Entry schema (all fields required):
|   key             string  dot-namespaced, e.g. "sms.quiet_hours"
|   type            string  one of: "string" | "int" | "bool" | "json" | "encrypted-string"
|   default         mixed   must validate against `type` and `validation`
|   validation      string  Laravel validation rule string, e.g. "required|integer|min:0"
|   tenant_editable bool    false = platform-only setting
|   owning_doc      string  "DocNN"
|
| Reminder (CLAUDE.md §8): keys that would disable AI disclosure, the
| starvation cap, four-eyes erasure approval, or the contrast validator are
| forbidden and will be rejected in review — do not add them.
|
*/

return [
    // Intentionally empty in Session 0. First entries arrive with Doc 01.
];
