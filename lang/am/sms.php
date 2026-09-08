<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 14 — የSMS ማሳወቂያ ሞጁል ሕብረቁምፊዎች (SESSION-14) — machine-authored Amharic,
| R-14 native-review queue. The chip words are the §9 state vocabulary.
|------------------------------------------------------------------------------
*/

return [
    'chip' => [
        'queued' => 'ተሰልፏል',
        'held' => 'ተይዟል — ጸጥታ ሰዓታት',
        'sending' => 'በመላክ ላይ',
        'sent' => 'ተልኳል',
        'delivered' => 'ደርሷል',
        'undelivered' => 'አልደረሰም',
        'expired' => 'ተልኳል · አልተረጋገጠም',
        'skipped' => 'ተዘሏል',
        'failed' => 'አልተሳካም',
    ],
    'feature' => [
        'enabled' => 'የSMS ማሳወቂያዎች',
    ],
    'notify' => [
        'cost_threshold' => 'የSMS ወጪ የወሩን ገደብ አልፏል',
        'gateway_health' => 'የSMS መግቢያ ጤና ተለውጧል',
        'staff_security' => 'ለመለያዬ የደህንነት ማሳወቂያዎች',
    ],
    'module' => [
        'channels_title' => 'SMS',
        'settings_title' => 'የSMS ቅንብሮች',
        'off_until_gateway' => 'መግቢያ እስኪኖር ድረስ SMS ጠፍቷል።',
    ],
];
