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
    'notify' => [
        'anomaly' => 'AI anomaly alert',
        'digest' => 'AI daily digest',
        'killswitch_changed' => 'AI kill switch changed',
        'quota_80' => 'AI daily token quota at 80%',
    ],
    'deflection' => [
        'template' => 'I can\'t help with betting advice or predictions — every outcome is uncertain, and the choice is always yours. If you\'d like, I can share how to set limits or point you to our responsible-play resources.',
    ],
    'unsupported_language' => [
        'template' => 'Sorry, I can only help in English or Amharic right now. I\'m connecting you with a member of our team.',
    ],
    // FR-14/§8 Copilot notices (amber inline strips) + card states (S09 step 10).
    'copilot' => [
        'notice' => [
            'low_confidence' => 'Low confidence — no draft suggested. Verify with the knowledge base links below.',
            'verify_reference' => 'Low confidence — verify the reference manually before sending.',
            'retrieval_degraded' => 'Knowledge search is degraded — this draft may lack citations.',
            'regenerate_exhausted' => 'Regenerate limit reached for this message — showing knowledge links instead.',
            'cooldown' => 'Please wait a moment before regenerating.',
            'digit_mismatch' => 'Rejected: the result changed a number, amount or reference. Nothing was inserted.',
            'unavailable' => 'The assistant could not produce a result for this request.',
            'degraded' => 'AI assistance is temporarily unavailable — the knowledge links below still work.',
            'busy' => 'The assistant is busy — try again in a moment.',
        ],
        'state' => [
            'artifact' => 'Suggested',
            'low_confidence' => 'Low confidence',
            'locked' => 'Locked',
            'refused' => 'Rejected',
            'degraded' => 'Unavailable',
            'cooldown' => 'Cooling down',
            'busy' => 'Busy',
        ],
        'kind' => [
            'draft_reply' => 'Suggested reply',
            'improve_text' => 'Improved text',
            'translate' => 'Translation',
            'note_draft' => 'Thread summary (note draft)',
        ],
    ],
    // FR-17 supervisor surface (S09 step 11): anomaly alert templates + the
    // digest's TEMPLATE bullets (the fallback when the model's six lines fail
    // the guards — numbers verbatim, never invented).
    'supervisor' => [
        'anomaly' => [
            'intent_delta' => ':intent conversations +:pct% since :since',
            'intent_new' => ':intent conversations appeared since :since (no baseline)',
            'volume_delta' => 'Conversation volume +:pct% since :since',
            'sentiment_drop' => 'Average sentiment dropped :drop points since :since',
        ],
        'digest' => [
            'tickets' => ':created tickets opened, :resolved resolved',
            'ai_resolved' => ':count conversations resolved by the assistant without a ticket',
            'handoffs' => ':count handoffs to agents (:reasons)',
            'rg' => ':count responsible-gaming flags raised by the assistant',
            'anomalies' => ':count anomaly alerts',
            'intents' => 'Top intents: :intents',
        ],
    ],
];
