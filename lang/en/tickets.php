<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Ticket strings — English (Doc 06 §14; C-08; invariant N-03)
|------------------------------------------------------------------------------
|
| §14 names exactly what belongs here: "Status/priority names, pending reasons,
| dialogs, consequence copy, and failure reasons under tickets.* i18n keys
| (en + am, operator-overridable via Doc 16)".
|
| WHAT THIS FILE HAS, AND WHY IT IS NOT YET THE WHOLE LIST. Step 2 ships the
| state machine, so it ships the machine's vocabulary: the eight status names,
| the four priorities, FR-01's pending reasons, and the failure reasons the
| transition service can actually produce. Dialog and consequence copy arrives
| with the operations that own it (steps 5-7), and the §14 sweep in step 10 is
| what proves nothing was left behind.
|
| TAG CONTENT IS DELIBERATELY ABSENT. §14: "tag content is operator free-text
| with no translation layer, by design" — a tenant's tags are their words, and
| pretending to translate them would corrupt the operator's own vocabulary.
|
| The status/priority NAMES are the display half of §9's fixed tokens: the hues
| are design-system law (never re-mixed), and these are what sits beside the dot
| so nothing is ever conveyed by colour alone.
|
*/

return [
    'status' => [
        'new' => 'New',
        'open' => 'Open',
        'pending_customer' => 'Waiting on customer',
        'pending_internal' => 'Waiting on internal',
        'escalated' => 'Escalated',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'spam' => 'Spam',
    ],

    'priority' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    // FR-01's pending_reason enum. The awaiting_internal variants name WHO is
    // being waited on, which is what makes US-02's "waiting on payments team"
    // legible on a list row rather than a bare "pending".
    'pending_reason' => [
        'awaiting_customer' => 'Waiting for the customer to reply',
        'awaiting_internal_department' => 'Waiting on another department',
        'awaiting_internal_named_user' => 'Waiting on a colleague',
        'awaiting_internal_external' => 'Waiting on an external party',
    ],

    // Failure reasons — the stable codes the transition service raises (C-04).
    // These are what an agent reads when the server refuses, so each says what
    // to do next rather than restating that something failed.
    'fail' => [
        'illegal_transition' => 'A ticket cannot move from :from to :to.',
        'transition_not_permitted' => 'You do not have permission to make this change (:permission).',
        'permission_expired' => 'Your temporary permission for this action expired on :expires. Ask :granter to extend it.',
        'reason_required' => 'This change needs a reason on the record.',
        'reason_too_short' => 'Please give at least :min characters, so the reason is useful to whoever reads it next.',
        'channel_unreachable_hold' => 'This customer is unreachable on their channel, so this ticket is not closed automatically — a person decides the outcome.',
        'open_ticket_exists' => 'This conversation already has an open ticket. Open it instead of starting another.',
    ],
];
