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
    'refused' => [
        'unknown_event' => 'ይህ የአብነት ክስተት አይደለም።',
        'body_too_long' => 'አብነቱ ባዶ ነው ወይም ከ1000 ቁምፊ ገደብ በላይ ነው።',
        'arbitrary_url' => 'አብነት URL ሊይዝ አይችልም — {{link}} ይጠቀሙ፤ አገናኞች ወደ መድረኩ ገጾች ብቻ ይፈታሉ (SEC-05)።',
        'variable_not_allowed' => 'ይህ ክስተት ከተጠቀሙት ተለዋዋጮች አንዱን አያቀርብም።',
        'link_domain_unset' => '{{link}} ከመታተሙ በፊት የአጭር-አገናኝ ጎራ (የSMS ቅንብሮች) ያስፈልገዋል።',
        'duplicate_template' => 'በአንድ ክስተት በአንድ ቋንቋ አንድ አብነት ብቻ።',
        'template_not_found' => 'እንዲህ ያለ አብነት የለም።',
        'version_not_found' => 'እንዲህ ያለ እትም የለም።',
        'fixture_phone_required' => 'ከምርት ውጭ የሙከራ መልእክት የ+251-90000xxxx ናሙና ቁጥርን ብቻ ያነጣጥራል።',
    ],
];
