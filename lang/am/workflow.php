<?php

declare(strict_types=1);

// Doc 12 — የስራ ፍሰት ንብርብር ሕብረቁምፊዎች (S12)። Machine-authored Amharic — R-14
// native-review queue; the rules-pause switch is DESTRUCTIVE-TIER flagged
// (it stops every tenant automation instantly).
return [
    'feature' => [
        'rules_pause' => 'ሁሉንም አውቶማቲክ ህጎች ለጊዜው አቁም',
    ],
    'forms' => [
        // FR-22: the publish-validation block labels (SchemaValidator's codes).
        'block' => [
            'invalid_shape' => 'የቅጹ ትርጉም የተሳሳተ ነው፦ :detail',
            'forward_reference' => '":at" ወደ ":field" ያመለክታል፣ እሱ ግን በኋለኛ ደረጃ ላይ ነው — ሁኔታዎች ወደ ኋላ ብቻ መመልከት ይችላሉ።',
            'unknown_field' => '":field" የሚባል መስክ የለም።',
            'retired_field' => '":field" ተሰናብቷል — ከማተምዎ በፊት ቅጹን ያርትዑ።',
            'required_hidden' => '":field" ግዴታ ነው ግን አንዳንዴ ተደብቋል። በምትኩ "ሲታይ ግዴታ" ያድርጉት።',
            'incomplete_language' => ':at የ:language ጽሑፉ ይጎድለዋል።',
            'upload_unsupported' => 'ግዴታ የሆነ የፋይል መስክ በ":channel" ላይ መመለስ አይቻልም — ያ ቻናል ምንም ስቀላ አይቀበልም።',
        ],
        // FR-22: the usage-banner section headings.
        'usage' => [
            'bot_menus' => 'የቦት ምናሌዎች',
            'rules' => 'የራስ-ሰር ህጎች',
            'widget_shortcuts' => 'የዊጀት አቋራጮች',
        ],
    ],
    'macros' => [
        'bundled_skipped' => 'የማክሮው ጽሁፍ ገብቷል፣ ነገር ግን ":type" ተዘሏል — ያ ፈቃድ የለዎትም።',
        'bundled_failed' => 'የማክሮው ጽሁፍ ገብቷል፣ ነገር ግን ":type" እዚህ ሊተገበር አልቻለም።',
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
