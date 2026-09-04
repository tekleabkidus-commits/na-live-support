<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 13 — Web Chat Widget strings (SESSION-13). Every user-visible widget
| string is a key here (N-03, C-08); en + am from birth. Customer-facing keys
| under `widget.*` are swept by the failure-vocabulary gate (R-D13-5.1) from
| step 6 on. Machine-authored Amharic joins the R-14 queue.
|------------------------------------------------------------------------------
*/

return [
    'feature' => [
        'voice_recording' => 'Widget voice notes',
    ],
    'identity' => [
        // EC-04: the STAFF-visible internal note on a site identity switch (never rendered to the visitor).
        'switched_note' => 'Site identity changed: the site now asserts :asserted (was :previous). Nothing was re-linked — review and merge by hand if these are one person.',
    ],
    // STOP-5A (a): the STAFF-visible system lines a pre-chat phone drops (never rendered to the visitor;
    // the phone is a staff-collected value — an identity only through Doc 08's FR-09 staff door).
    'prechat' => [
        'phone_provided' => 'Phone provided in the pre-chat form: :phone (staff-collected, not linked). Link it from the profile if it is theirs.',
        'phone_conflict' => 'Phone provided in the pre-chat form: :phone — this number already sits on another customer. Nothing was attached; review both profiles before linking.',
    ],
    // FR-06/FR-15 inline refusals (R-D13-5.1: calm, no failure vocabulary).
    'form' => [
        'required' => 'This one is needed to continue.',
        'invalid_phone' => 'Please enter a phone number we can reach, like +251 9xx xxx xxx.',
        'invalid_email' => 'Please check the email address.',
        'invalid_number' => 'Please enter a number.',
        'invalid_choice' => 'Please pick one of the options.',
        'invalid_file' => 'Please attach the file again.',
        'invalid_text' => 'Please enter some text.',
        'too_long' => 'That is a bit long — please shorten it.',
        'invalid_format' => 'Please check the format.',
        'unknown_step' => 'This step is no longer part of the form.',
        'refused' => 'A few answers need attention.',
    ],
];
