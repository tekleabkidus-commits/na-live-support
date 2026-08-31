<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 11 — የክፍሎች፣ ቡድኖች፣ ወረፋዎች እና ማዞሪያ ሕብረቁምፊዎች (§14; C-08; N-03)
|------------------------------------------------------------------------------
| The Amharic twin of lang/en/routing.php — every key lands in both files in
| the SAME commit (RoutingI18nParityTest). Not native-reviewed; the R-14 queue
| carries every string for the owner's language review.
*/

return [
    'system' => [
        // FR-16c: the orphan-move system note (persistSystemMarker code `routing_orphan_moved`, N-03).
        // FR-16a: the auto-return system note (persistSystemMarker code `routing_rescue_returned`).
        'routing_rescue_returned' => 'የተመደበው ወኪል ግንኙነት ስላጣ ወደ ወረፋው ተመልሷል',
        'routing_orphan_moved' => 'ወረፋው ስለተሰረዘ ወደ የክፍሉ ነባሪ ወረፋ ተዛውሯል',
    ],
    'estimate' => [
        // FR-13 / A-52 / SEC-05 — the band vocabulary (J15): bands only, never counts or names.
        'band' => [
            'short' => 'ወደ 5 ደቂቃ ገደማ',
            'medium' => 'ወደ 20 ደቂቃ ገደማ',
            'long' => 'ከአንድ ሰዓት በላይ',
            'off_hours' => 'በ :time ስንከፍት',
        ],
    ],
    // §14 (S11 step 14) — የመስመር መዝገብ ዓረፍተ ነገሮች (ICU-templated; machine-authored → R-14).
    'record' => [
        'department' => [
            'explicit' => 'ክፍሉ በጠሪው በግልጽ ተቀምጧል።',
            'ai_accepted' => 'የAI አስተያየት ተተግብሯል — ራስ-ተቀባይ በርቷል።',
            'ai_flagged' => 'የAI አስተያየት አልተተገበረም (ራስ-ተቀባይ ጠፍቷል) — ለግምገማ ተመልክቷል።',
            'connection_tag' => 'ከግንኙነቱ የመስመር መለያ የመጣ የውይይት ነባሪ።',
            'connection_tag_miss' => 'የግንኙነቱ መለያ ክፍል አላገኘም — ወደ ቀጣዩ ምንጭ።',
            'tenant_default' => 'የተከራይ ነባሪ ክፍል።',
            'tenant_default_flagged' => 'የተከራይ ነባሪ ክፍል (የAI አስተያየቱ ለግምገማ ተመልክቶ ይቆያል)።',
        ],
        'queue' => [
            'explicit' => 'ወረፋው በጠሪው በግልጽ ተቀምጧል።',
            'routing_tag' => 'ሕግ የመስመር መለያውን አዛምዷል።',
            'department_default' => 'የክፍሉ ነባሪ ወረፋ።',
        ],
        'rg' => [
            'forced' => 'የኃላፊነት ጨዋታ መግቢያ — ወደ RG ወረፋ ተገድዷል። ይህ መስመር የመጨረሻ ነው።',
            'missing' => 'የኃላፊነት ጨዋታ መግቢያ፣ ግን RG ወረፋ የለም — የተፈታው ወረፋ ይቆያል።',
        ],
        'position' => [
            'scored' => 'የቅድሚያ ነጥብ :score (ማበልጸጊያዎች፦ :boosts)።',
            'starved' => 'ከ:cap ደቂቃ ገደቡ በላይ ጠብቋል — ወደ ወረፋው ራስ ከፍ ተደርጓል፣ ሁሉንም ማበልጸጊያ ይበልጣል።',
        ],
        'eligibility' => [
            'none' => 'ነጻ ቦታ ያለው ብቁ ወኪል በመስመር ላይ የለም።',
            'ok' => ':count ተመዳቢ — :strategy',
        ],
        'strategy' => [
            'none' => 'ተመዳቢ ወኪል የለም',
            'rr' => ':count በዙር ውስጥ፣ ጠቋሚው ገፍቷል',
            'load' => 'ትንሽ ክፍት ትኬት ያለው ቀዳሚ (:load ክፍት)',
            'first' => 'በተጠቃሚ መለያ ቅደም ተከተል የመጀመሪያው ብቁ',
            'skill' => [
                'matched' => 'የክህሎት ማዛመድ፦ :language',
                'dropped_starved' => 'የክህሎት ማዛመድ፦ :language — በመስመር ላይ የለም፤ ከገደቡ በላይ ስለጠበቀ የቋንቋ መስፈርቱ ተትቷል',
                'dropped_nearest' => 'የክህሎት ማዛመድ፦ :language — በመስመር ላይ የለም፤ የቅርብ-ክህሎት አማራጭ፣ የቋንቋ መስፈርቱ ተትቷል',
                'hold' => 'የክህሎት ማዛመድ፦ :language — :online በመስመር ላይ፣ ክህሎቱ ያለው 0 → የሚመጥን ወኪል በመጠበቅ ላይ',
                'none' => 'የክህሎት መስፈርቶቹን የሚያሟላ የለም',
            ],
        ],
        'assign' => [
            'ok' => 'በስርዓቱ ተመድቧል።',
            'at_capacity' => 'በመፈጸሚያው ቅጽበት አቅም ሞልቷል — ወደ ቀጣዩ እጩ።',
            'claimed' => 'መጀመሪያ በሰው ተይዟል — መስመሩ ይተዋል።',
            'out_of_scope' => 'እጩው ይህን ትኬት መድረስ አይችልም — ወደ ቀጣዩ እጩ።',
        ],
        'wait' => 'በወረፋ በመጠበቅ ላይ — በሚቀጥለው የመገኘት ክስተት እንደገና ይገመገማል።',
        'engine_error' => 'የሞተር ስህተት — በክፍሉ ነባሪ ወረፋ ቆሟል። ምንም አልጠፋም፤ ቡድኑ ተነግሮታል።',
        'courtesy_hold' => 'የቀድሞው ተመዳቢ :status ነው — ከመመለሱ በፊት :minutes ደቂቃ እየተጠበቀለት ነው።',
        'manual_reassign' => 'በተቆጣጣሪ ተመድቧል — ስልቱ ታልፏል።',
        'rescue_return' => 'ወኪሉ ግንኙነት ስላጣ በስርዓቱ ወደ ወረፋው ተመልሷል።',
        'no_boosts' => 'ምንም',
    ],
    'notify' => [
        'broadcast' => 'ከተቆጣጣሪ የተላከ መልእክት',
        'no_skill_match' => 'የክህሎት ወረፋ የሚመጥን ሰው ሳይኖር እየጠበቀ ነው',
        'rescue_flagged' => 'ትኬት ወደ ማዳን ገብቷል',
        'pull_in_recurring' => 'ተመሳሳይ ጊዜያዊ መጨመር እየተደጋገመ ነው — ቋሚ ይደረግ?',
    ],
];
