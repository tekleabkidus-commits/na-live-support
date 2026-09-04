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
];
