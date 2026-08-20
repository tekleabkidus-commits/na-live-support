<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doc 09 — AI surface strings (C-08: every user-visible string is a key)
|--------------------------------------------------------------------------
|
| rg.template is the PLATFORM-SEEDED responsible-gaming customer template
| (FR-13/FR-18): operator content editable in WORDING per language (the
| guardrails config overrides it when published); presence is not optional
| and generation NEVER replaces it (BR-03). §14: an endpoint cannot enable a
| language missing its RG template — the completeness gate (step 13).
| Destructive-tier for R-14 review: the RG template (flagged at close).
|
*/

return [
    'rg' => [
        'template' => 'Thank you for telling us how you feel — that took courage. We are connecting you with a member of our team right now who can help. If you would like to take a break, you can set deposit limits or pause your account at any time, and free, confidential support is available from your local gambling-help line. You are not alone in this.',
    ],
    'handoff' => [
        'template' => 'Thanks for your message. A member of our team will be with you shortly.',
    ],
];
