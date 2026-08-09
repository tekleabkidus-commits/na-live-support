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
        'connection_paused' => 'የተገናኘው የድጋፍ መለያ ይህን ቦት አቁሟል — ምላሾችን ለመቀጠል ከስልኩ እንደገና ያገናኙት።',
        'rate_limited' => 'መስመሩ የመላኪያ ፍጥነት ገድቧል፤ መልእክቱ በራስ‑ሰር እንደገና ይሞከራል።',
        'network_error' => 'የአውታረ መረብ ችግር መላኩን አቋርጦታል፤ በራስ‑ሰር እንደገና ይሞከራል።',
        'channel_unavailable' => 'መስመሩ ለጊዜው አይገኝም፤ መላኩ በራስ‑ሰር እንደገና ይሞከራል።',
        'credentials_unavailable' => 'የቻናል ማረጋገጫዎች ለጊዜው አይገኙም፤ መላክ በራስ-ሰር እንደገና ይሞከራል።',
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
    'transcript' => [
        'watermark' => 'በ:name የተላከ ቅጂ፣ :at',
        'deleted_tombstone' => '[መልእክቱ ተሰርዟል]',
    ],
    'lane' => [
        'skipped' => 'የተጣበቀ መልእክት ወደ ጎን ተደርጎ አዳዲስ መልሶች እንዲላኩ ተፈቅዷል፤ እሱም መሞከሩን ይቀጥላል።',
    ],
    // Doc 04 FS-05 — the dead-letter ops view (R-17 class-2 fill).
    'dead_letters' => [
        'title' => 'የቆሙ መልእክቶች',
        'breadcrumb' => 'ውይይቶች › የቆሙ መልእክቶች',
        'intro' => 'ሦስት ጊዜ በሂደት ያልተሳኩ ገቢ መልእክቶች ከሙሉ ይዘታቸው ጋር እዚህ ይቆማሉ። ምንም አይጠፋም — «እንደገና ላክ» መልእክቱን ወደ ሂደት ይመልሰዋል።',
        'empty' => 'የቆሙ መልእክቶች የሉም። ገቢ መልእክቶች በመደበኛነት እየፈሰሱ ነው።',
        'col_parked_at' => 'የቆመበት ጊዜ',
        'col_channel' => 'ቻናል',
        'col_error' => 'ስህተት',
        'col_attempts' => 'ሙከራዎች',
        'col_actions' => 'እርምጃዎች',
        'payload_show' => 'ጥሬ ይዘቱን አሳይ',
        'payload_hide' => 'ጥሬ ይዘቱን ደብቅ',
        'redrive' => 'እንደገና ላክ',
        'redrive_confirm' => 'ይህን መልእክት ወደ ሂደት መልሰን እንላክ? ሦስት ጊዜ ተጨማሪ ካልተሳካ እንደገና እዚህ ይቆማል።',
        'redrive_yes' => 'አዎ፣ እንደገና ላክ',
        'redrive_cancel' => 'ይቅር',
        'redriven_ok' => 'መልእክቱ እንደገና ተልኳል።',
        'not_parked' => 'ይህ ንጥል አስቀድሞ እንደገና ተልኳል — ምናልባት አሁን በሌላ ኦፕሬተር።',
        'load_more' => 'ተጨማሪ አሳይ',
    ],
];
