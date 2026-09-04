<?php

declare(strict_types=1);

// Doc 13 — Web Chat Widget strings, Amharic (machine-authored; R-14 queue).

return [
    'feature' => [
        'voice_recording' => 'የዊጀት የድምጽ ማስታወሻዎች',
    ],
    'identity' => [
        // EC-04: the STAFF-visible internal note on a site identity switch (never rendered to the visitor).
        'switched_note' => 'የድረ-ገጽ ማንነት ተቀይሯል፦ ድረ-ገጹ አሁን :asserted ይላል (ቀድሞ :previous ነበር)። ምንም እንደገና አልተገናኘም — አንድ ሰው ከሆኑ በእጅ ይገምግሙና ያዋህዱ።',
    ],
    // STOP-5A (a): the STAFF-visible system lines a pre-chat phone drops (never rendered to the visitor).
    'prechat' => [
        'phone_provided' => 'በቅድመ-ውይይት ቅጹ የተሰጠ ስልክ፦ :phone (በሠራተኛ የተሰበሰበ፣ አልተገናኘም)። የእሱ/የእሷ ከሆነ ከመገለጫው ያገናኙት።',
        'phone_conflict' => 'በቅድመ-ውይይት ቅጹ የተሰጠ ስልክ፦ :phone — ይህ ቁጥር አስቀድሞ በሌላ ደንበኛ ላይ አለ። ምንም አልተያያዘም፤ ከማገናኘትዎ በፊት ሁለቱን መገለጫዎች ይገምግሙ።',
    ],
    // FR-06/FR-15 inline refusals (R-D13-5.1: calm, no failure vocabulary).
    'form' => [
        'required' => 'ለመቀጠል ይህ ያስፈልጋል።',
        'invalid_phone' => 'እባክዎ ልንደርስበት የምንችለውን ስልክ ቁጥር ያስገቡ፣ ለምሳሌ +251 9xx xxx xxx።',
        'invalid_email' => 'እባክዎ የኢሜይል አድራሻውን ያረጋግጡ።',
        'invalid_number' => 'እባክዎ ቁጥር ያስገቡ።',
        'invalid_choice' => 'እባክዎ ከአማራጮቹ አንዱን ይምረጡ።',
        'invalid_file' => 'እባክዎ ፋይሉን እንደገና ያያይዙ።',
        'invalid_text' => 'እባክዎ ጽሑፍ ያስገቡ።',
        'too_long' => 'ትንሽ ረዘም ብሏል — እባክዎ ያሳጥሩት።',
        'invalid_format' => 'እባክዎ ቅርጸቱን ያረጋግጡ።',
        'unknown_step' => 'ይህ ደረጃ ከቅጹ አካል አይደለም።',
        'refused' => 'ጥቂት መልሶች ትኩረት ያስፈልጋቸዋል።',
    ],
];
