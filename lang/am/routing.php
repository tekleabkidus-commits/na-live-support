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
    'estimate' => [
        // FR-13 / A-52 / SEC-05 — the band vocabulary (J15): bands only, never counts or names.
        'band' => [
            'short' => 'ወደ 5 ደቂቃ ገደማ',
            'medium' => 'ወደ 20 ደቂቃ ገደማ',
            'long' => 'ከአንድ ሰዓት በላይ',
            'off_hours' => 'በ :time ስንከፍት',
        ],
    ],
    'notify' => [
        'broadcast' => 'ከተቆጣጣሪ የተላከ መልእክት',
        'no_skill_match' => 'የክህሎት ወረፋ የሚመጥን ሰው ሳይኖር እየጠበቀ ነው',
        'rescue_flagged' => 'ትኬት ወደ ማዳን ገብቷል',
        'pull_in_recurring' => 'ተመሳሳይ ጊዜያዊ መጨመር እየተደጋገመ ነው — ቋሚ ይደረግ?',
    ],
];
