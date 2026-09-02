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
    'csat' => [
        // FR-25: the 1-tap prompt, the polite hour-73 refusal (EC-11), the
        // optional comment prompt, and the thanks.
        'prompt' => 'እንዴት ነበርን? ኮከብ ይንኩ — 1 (ደካማ) እስከ 5 (በጣም ጥሩ)።',
        'window_closed' => 'እናመሰግናለን — የዚህ ደረጃ መስጫ ጊዜ አልፏል፣ ግን አስተያየትዎ በውይይቱ ውስጥ ሁሌም እንኳን ደህና መጣ።',
        'comment_prompt' => 'እናመሰግናለን! መጨመር የሚፈልጉት ነገር አለ?',
        'thanks' => 'ስለ አስተያየትዎ እናመሰግናለን!',
    ],
    'flow' => [
        // FR-21: the conversational renderer's own voice (friendly retries,
        // the offer of a person, numbered-choice instruction, FS-04's recap).
        'retry' => 'ይቅርታ፣ ያ ትክክል አይመስልም — እባክዎ እንደገና ይሞክሩ።',
        'offer_human' => 'ይህ አስቸጋሪ ይመስላል። በማንኛውም ጊዜ /human ብለው ይመልሱ — ሰው ከዚህ ይቀጥላል፤ የጻፉት ሁሉ ተያይዞ ይቆያል።',
        'choose_number' => 'የምርጫዎን ቁጥር ይመልሱ።',
        'resumed_recap' => 'ካቆምንበት እንቀጥላለን — ቀደም ያሉ መልሶችዎ ተቀምጠዋል።',
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
    // FR-03 notify — machine-authored am (R-14 queue; non-destructive tier).
    'notify' => [
        'rule' => 'የደንብ ማሳወቂያ',
    ],
    // FR-03 run AI task — machine-authored am (R-14 queue; non-destructive tier).
    'ai_task' => [
        'summary_note' => 'የAI ማጠቃለያ (በደንብ የተፈጠረ)፦ :summary',
        'translation_note' => 'የመጨረሻው የደንበኛ መልእክት ወደ :language የተተረጎመ (በደንብ የተፈጠረ)፦ :text',
    ],
];
