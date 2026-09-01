<?php

declare(strict_types=1);

// Doc 12 — የስራ ፍሰት ንብርብር ሕብረቁምፊዎች (S12)። Machine-authored Amharic — R-14
// native-review queue; the rules-pause switch is DESTRUCTIVE-TIER flagged
// (it stops every tenant automation instantly).
return [
    'feature' => [
        'rules_pause' => 'ሁሉንም አውቶማቲክ ህጎች ለጊዜው አቁም',
    ],
    'sla' => [
        'example' => ':created የተከፈተ ቲኬት የመጀመሪያ ምላሽ ዒላማው :target ይሆናል (:zone)።',
        'example_unreachable' => 'ይህ የስራ ሰዓት መርሃ ግብር በጭራሽ አይከፈትም — ቢያንስ አንድ ሳምንታዊ ክፍተት ይሳሉ።',
        'stage' => [
            'first_response' => 'የመጀመሪያ ምላሽ',
            'next_response' => 'ቀጣይ ምላሽ',
            'resolution' => 'መፍትሄ',
        ],
    ],
];
