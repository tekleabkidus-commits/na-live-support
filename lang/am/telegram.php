<?php

declare(strict_types=1);

// Doc 05 — machine-authored Amharic; every string here is queued for the
// standing native review (R-14). Structure mirrors lang/en/telegram.php
// exactly (§14: en/am structurally identical).
return [
    'notify' => [
        'connection_state' => 'የቴሌግራም ግንኙነት ቆሟል ወይም ተቋርጧል',
        'misconfigured_source' => 'በቀጥታ ሁነታ ቦት ላይ የቢዝነስ መልእክቶች እየደረሱ ነው',
        'connection_pending' => 'የቴሌግራም ግንኙነት ማረጋገጫ በመጠበቅ ላይ',
        'connection_flap_digest' => 'የቴሌግራም ግንኙነት አለመረጋጋት ማጠቃለያ',
        'window_closing' => 'የመልስ መስኮት በቅርቡ ይዘጋል',
    ],

    'state' => [
        'pending' => 'በመጠበቅ ላይ',
        'active' => 'ንቁ',
        'paused' => 'የቆመ',
        'revoked' => 'የተሰረዘ',
    ],

    'callback' => [
        'neutral_toast' => 'ይህ አዝራር ጊዜው አልፎበታል — መልእክቱን እንደገና ይላኩ ወይም /menu ይተይቡ።',
    ],

    'mode' => [
        'direct' => 'ቀጥታ ቦት',
        'business' => 'የቢዝነስ መለያ',
        'hybrid' => 'ድብልቅ',
    ],
];
