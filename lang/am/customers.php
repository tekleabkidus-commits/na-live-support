<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 08 — የደንበኛ መገለጫዎች እና ማንነት (C-08)
|------------------------------------------------------------------------------
|
| Machine-authored Amharic — queued for native review (R-14 standing queue).
|
*/

return [
    'block' => [
        'cascade_reason' => 'የደንበኛ-ደረጃ እገዳ ተከታታይ፦ :reason',
        'cascade_restore_reason' => 'የደንበኛ-ደረጃ እገዳ መነሳት — በተከታታዩ የታገዱ ውይይቶች እየተመለሱ ነው።',
    ],
    'merge' => [
        'linked_work_note' => 'በደንበኛ ውህደት ምክንያት እንደ የተያያዘ ሥራ ተለይቷል — ሁለቱም መገለጫዎች ክፍት ጥያቄ ነበራቸው (የተፈቀደ ባለብዙ-ክፍት)።',
    ],
    'identity' => [
        'duplicate' => 'ይህ :type አስቀድሞ በሌላ መገለጫ ላይ ነው — አንድ እሴት በአንድ ደንበኛ ላይ ብቻ ሊሆን ይችላል (በምትኩ ይዋሃዱ?)።',
    ],
    'notify' => [
        'merge_completed' => 'የደንበኛ ውህደት ተጠናቋል',
        'export_ready' => 'የደንበኛ ኤክስፖርት ዝግጁ ነው',
    ],
    'panel' => [
        'not_found' => 'ደንበኛው አልተገኘም።',
        'empty' => 'የደንበኛውን መረጃ ለማየት ውይይት ይክፈቱ።',
        'loading' => 'ደንበኛ በመጫን ላይ…',
        'error' => 'ይህ ክፍል መጫን አልቻለም።',
        'retry' => 'እንደገና ሞክር',
        'identities' => 'መለያዎች',
        'betting' => 'የውርርድ ዝርዝሮች',
        'history' => 'ታሪክ',
        'recent_tickets' => 'የቅርብ ጊዜ ቲኬቶች',
        'notes' => 'ማስታወሻዎች',
        'files' => 'ፋይሎች',
        'tickets_line' => ':count ቲኬቶች · :open ክፍት',
        'rating_line' => 'ከ5 · :count ደረጃዎች',
        'last_contact' => 'ከመጨረሻው ግንኙነት ጀምሮ',
        'no_name' => 'ደንበኛ #:short — ገና ስም የለም',
        'blocked_banner' => 'ይህ ደንበኛ ታግዷል። :reason',
        'rg_banner' => 'የኃላፊነት ውርርድ ምልክት ተቀምጧል።',
        'unreachable' => 'ሊደረስበት አይችልም',
        'from_channel' => 'ከ:channel',
        'by_staff' => 'በሠራተኛ የተጨመረ',
    ],
    'files' => [
        'not_found' => 'ፋይሉ አልተገኘም።',
        'not_servable' => 'ይህ ፋይል ሊወርድ አይችልም — የደህንነት ፍተሻውን አላለፈም።',
    ],
    'export' => [
        'not_ready' => 'ይህ ኤክስፖርት ገና ዝግጁ አይደለም — የተሟላ ማህደር የለም።',
        'watermark' => 'የደንበኛ መረጃ ኤክስፖርት — በ:requester የተጠየቀ በ:at። በመረጃ ጥበቃ ፖሊሲ መሠረት ይያዙ።',
    ],
    'notes' => [
        'onboarding_title' => 'ማስታወሻዎች በመገለጫው ላይ ይቆያሉ',
        'onboarding_body' => 'የደንበኛ ማስታወሻዎች ውስጣዊ ናቸው። ወደ ደንበኛው በፍጹም አይደርሱም፣ ወደ ውይይትም አይዛወሩም።',
    ],
    'fields' => [
        'betting_username' => 'የውርርድ የተጠቃሚ ስም',
        'player_id' => 'የተጫዋች መለያ',
        'brand' => 'ብራንድ',
        'loyalty_tier' => 'የታማኝነት ደረጃ',
        'staff_collected' => 'በሠራተኛ የተመዘገበ',
        'retired_refusal' => 'መስኩ :field ተቋርጧል — እሴቱ ለንባብ ብቻ ነው።',
        'retired' => 'የተቋረጠ መስክ',
        'duplicate_hint' => 'በሌሎች :count መገለጫዎች ላይም አለ',
    ],
];
