<?php

declare(strict_types=1);

// Doc 10 — machine-authored Amharic; joins the R-14 native-review queue.
// Opened at S10 step 1 (tombstone states, FR-16). Destructive-tier keys are
// flagged here as they land.

return [
    'tombstone' => [
        'current' => 'ይህ ጽሑፍ ወቅታዊ ነው።',
        'retired' => 'ይህ ጽሑፍ ተዘምኗል ወይም ተወግዷል።',
        'unknown' => 'ይህ ጽሑፍ ከአሁን በኋላ አይገኝም።',
        'read_current' => 'ወቅታዊውን እትም ያንብቡ',
    ],
];
