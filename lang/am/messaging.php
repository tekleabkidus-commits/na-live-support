<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Messaging strings — Amharic (Doc 04 §8, §14, FR-14; C-08)
|------------------------------------------------------------------------------
|
| MACHINE-AUTHORED (ruling R-14's standing queue): every string below requires
| native-speaker review before a real operator sees it. None is destructive-tier;
| all are delivery-status guidance.
|
*/

return [
    'fail' => [
        'blocked_by_user' => 'ደንበኛው ይህን የመልእክት መስመር አግዷል — በምትኩ የኤስኤምኤስ ማሳወቂያ ይሞክሩ።',
        'window_expired' => 'የዚህ መስመር የመልስ ጊዜ አልፏል።',
        'invalid_content' => 'መስመሩ ይህን ይዘት እንደማይሰራ አድርጎ አልተቀበለውም።',
        'rate_limited' => 'መስመሩ የመላኪያ ፍጥነት ገድቧል፤ መልእክቱ በራስ‑ሰር እንደገና ይሞከራል።',
        'network_error' => 'የአውታረ መረብ ችግር መላኩን አቋርጦታል፤ በራስ‑ሰር እንደገና ይሞከራል።',
        'channel_unavailable' => 'መስመሩ ለጊዜው አይገኝም፤ መላኩ በራስ‑ሰር እንደገና ይሞከራል።',
        'too_large' => 'አባሪው ከተፈቀደው መጠን :max_mb ሜባ ይበልጣል።',
        'unknown' => 'መልእክቱ ባልታወቀ ምክንያት አልተላከም።',
    ],
    'state' => [
        'queued' => 'በተራ ላይ',
        'sending' => 'በመላክ ላይ…',
        'sent' => 'ተልኳል',
        'delivered' => 'ደርሷል',
        'failed_retryable' => 'እንደገና በመሞከር ላይ…',
        'failed_final' => 'አልተላከም',
        'canceled' => 'ተሰርዟል',
    ],
    'cancel_reason' => [
        'conversation_blocked' => 'ውይይቱ ከመላክ በፊት ስለታገደ ተሰርዟል።',
    ],
    'draft' => [
        'updated_elsewhere' => 'ይህ ረቂቅ በሌላ መሣሪያ ላይ ተዘምኗል፤ አዲሱ ስሪት እየታየ ነው።',
    ],
    'lane' => [
        'skipped' => 'የተጣበቀ መልእክት ወደ ጎን ተደርጎ አዳዲስ መልሶች እንዲላኩ ተፈቅዷል፤ እሱም መሞከሩን ይቀጥላል።',
    ],
];
