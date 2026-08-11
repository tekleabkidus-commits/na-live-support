<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 07 — የተዋሃደ የገቢ መልእክት ሳጥን ሕብረቁምፊዎች (§14: inbox.*)
|------------------------------------------------------------------------------
| Machine-authored Amharic — every string here is queued for native review
| (standing ruling R-14). Structure mirrors lang/en/inbox.php exactly.
*/

return [
    'notify' => [
        'assignment' => 'ቲኬት ለእኔ ተመድቧል',
        'mention' => 'በውስጣዊ ማስታወሻ ውስጥ ተጠቅሻለሁ',
        'follower' => 'በምከታተለው ቲኬት ላይ እንቅስቃሴ',
        'escalation' => 'ቲኬት ወደ እኔ ከፍ ተደርጓል',
        'sla_warning' => 'በእኔ ቲኬት ላይ የSLA ማስጠንቀቂያ',
        'window_closing' => 'በእኔ ቲኬት ላይ የመልስ መስኮት እየተዘጋ ነው',
        'bulk_completed' => 'የጅምላ ተግባር ተጠናቋል',
    ],
    'feature' => [
        'voice_recording' => 'የመልእክት አዘጋጅ የድምፅ ቀረጻ',
    ],
    'selection' => [
        'empty' => 'ምንም የሚመረጥ የለም — ይህ እይታ ባዶ ነው።',
        'consumed' => 'ይህ ምርጫ አስቀድሞ ተፈጽሟል — ሌላ ተግባር ለማካሄድ እንደገና ይምረጡ።',
    ],
];
