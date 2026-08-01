<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Authentication copy — Amharic (Doc 02 §14)
|------------------------------------------------------------------------------
|
| MACHINE-AUTHORED, PENDING NATIVE REVIEW. BUILD-STATUS carries this as a
| standing pre-launch item: every `am` string needs a native speaker's eye before
| a real operator sees it. Tests can prove no key is missing and nothing leaks;
| they cannot prove the register is right for an Ethiopian agent at shift start.
|
| The enumeration constraint applies to the translation too: `failed.generic`
| must stay non-committal in Amharic. A translator who "improves" it into
| "account not found" reopens AC-01 in one language only — the worst possible
| version of that bug, because the English tests stay green.
|
*/

return [

    'failed' => [
        'generic' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        'locked' => 'በጣም ብዙ ሙከራዎች። እባክዎ ቆይተው ይሞክሩ፣ ወይም የይለፍ ቃልዎን ዳግም ያስጀምሩ።',
        'disabled_user' => 'ይህ መለያ ጠፍቷል። አስተዳዳሪዎን ያግኙ።',
        'suspended_tenant' => 'ይህ የሥራ ቦታ ለጊዜው አይገኝም። አስተዳዳሪዎን ያግኙ።',
        'ip_blocked' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        'bad_credentials' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        // Owner ruling R-2: recorded in events/audit as its own category, but the
        // PERSON always sees the generic text — a distinct message here would
        // confirm the address exists in more than one workspace (AC-01).
        'ambiguous_tenant' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        '2fa_failed' => 'ያ ኮድ አልሰራም። አረጋግጠው እንደገና ይሞክሩ።',
    ],

    'throttle' => [
        'too_many_requests' => 'ከዚህ ግንኙነት በጣም ብዙ ሙከራዎች። ትንሽ ቆይተው ይሞክሩ።',
    ],

];
