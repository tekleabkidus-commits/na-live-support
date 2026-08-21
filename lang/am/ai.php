<?php

declare(strict_types=1);

// Doc 09 — machine-authored Amharic; joins the R-14 native-review queue.
// DESTRUCTIVE TIER: rg.template (the RG customer template — wording changes
// must survive review first and unsoftened).

return [
    'rg' => [
        'template' => 'ስሜትዎን ስለነገሩን እናመሰግናለን — ይህ ድፍረት ይጠይቃል። አሁኑኑ ሊረዳዎ ከሚችል የቡድናችን አባል ጋር እያገናኘንዎ ነው። እረፍት መውሰድ ከፈለጉ በማንኛውም ጊዜ የተቀማጭ ገደብ ማስቀመጥ ወይም አካውንትዎን ማቆም ይችላሉ፤ እንዲሁም ከአካባቢዎ የቁማር ድጋፍ መስመር ነፃ እና ሚስጥራዊ ድጋፍ ማግኘት ይችላሉ። በዚህ ውስጥ ብቻዎን አይደሉም።',
    ],
    // FR-19a/BR-05: disclosure WORDING (operator-overridable via guardrails.disclosure.<lang>); PRESENCE is code (Disclosure gate).
    'disclosure' => [
        'sentence' => 'እኔ የAI ረዳት ነኝ',
    ],
    'greeting' => [
        'first' => 'ሰላም! :disclosure — ለዚህ የድጋፍ ቡድን። በፈለጉበት ጊዜ የሰው ባልደረባ በአንድ መልእክት ርቀት ላይ ነው።',
    ],
    'handoff' => [
        'template' => 'ስለመልእክትዎ እናመሰግናለን። የቡድናችን አባል በቅርቡ ያነጋግርዎታል።',
    ],
    'notify' => [
        'anomaly' => 'የAI ያልተለመደ ሁኔታ ማንቂያ',
        'digest' => 'የAI ዕለታዊ ማጠቃለያ',
        'killswitch_changed' => 'የAI ማቋረጫ ማብሪያ ተቀይሯል',
        'quota_80' => 'የAI ዕለታዊ የቶከን ኮታ 80% ደርሷል',
    ],
    'deflection' => [
        'template' => 'በውርርድ ምክር ወይም ትንበያ ልረዳዎ አልችልም — እያንዳንዱ ውጤት እርግጠኛ አይደለም፣ እና ምርጫው ሁልጊዜ የእርስዎ ነው። ከፈለጉ ገደብ እንዴት እንደሚያስቀምጡ ማሳየት ወይም ወደ ኃላፊነት ያለው ጨዋታ ምንጮቻችን ልመራዎ እችላለሁ።',
    ],
    'unsupported_language' => [
        'template' => 'ይቅርታ፣ አሁን በእንግሊዝኛ ወይም በአማርኛ ብቻ ልረዳ እችላለሁ። ከቡድናችን አባል ጋር እያገናኘሁዎት ነው።',
    ],
    'copilot' => [
        'notice' => [
            'low_confidence' => 'ዝቅተኛ እርግጠኝነት — ረቂቅ አልቀረበም። ከታች ባሉት የእውቀት መሠረት አገናኞች ያረጋግጡ።',
            'verify_reference' => 'ዝቅተኛ እርግጠኝነት — ከመላክዎ በፊት ማጣቀሻውን በእጅ ያረጋግጡ።',
            'retrieval_degraded' => 'የእውቀት ፍለጋ ተዳክሟል — ይህ ረቂቅ ጥቅሶች ላይኖሩት ይችላል።',
            'regenerate_exhausted' => 'ለዚህ መልእክት የድጋሚ ማመንጨት ገደብ ደርሷል — በምትኩ የእውቀት አገናኞች እየታዩ ነው።',
            'cooldown' => 'እባክዎ እንደገና ከማመንጨትዎ በፊት ትንሽ ይጠብቁ።',
            'digit_mismatch' => 'ውድቅ ተደርጓል፦ ውጤቱ ቁጥር፣ መጠን ወይም ማጣቀሻ ቀይሯል። ምንም አልገባም።',
            'unavailable' => 'ረዳቱ ለዚህ ጥያቄ ውጤት ማምጣት አልቻለም።',
            'degraded' => 'የAI እገዛ ለጊዜው አይገኝም — ከታች ያሉት የእውቀት አገናኞች አሁንም ይሠራሉ።',
            'busy' => 'ረዳቱ ተጠምዷል — ከትንሽ ቆይታ በኋላ እንደገና ይሞክሩ።',
        ],
        'state' => [
            'artifact' => 'የቀረበ',
            'low_confidence' => 'ዝቅተኛ እርግጠኝነት',
            'locked' => 'ተቆልፏል',
            'refused' => 'ውድቅ ተደርጓል',
            'degraded' => 'አይገኝም',
            'cooldown' => 'በማቀዝቀዝ ላይ',
            'busy' => 'ተጠምዷል',
        ],
        'kind' => [
            'draft_reply' => 'የተጠቆመ መልስ',
            'improve_text' => 'የተሻሻለ ጽሑፍ',
            'translate' => 'ትርጉም',
            'note_draft' => 'የውይይት ማጠቃለያ (የማስታወሻ ረቂቅ)',
        ],
    ],
    // FR-17 supervisor surface (S09 step 11): anomaly alert templates + the
    // digest's TEMPLATE bullets (the fallback when the model's six lines fail
    // the guards — numbers verbatim, never invented).
    'supervisor' => [
        'anomaly' => [
            'intent_delta' => 'የ:intent ውይይቶች ከ:since ጀምሮ +:pct%',
            'intent_new' => 'የ:intent ውይይቶች ከ:since ጀምሮ ታይተዋል (መነሻ የለም)',
            'volume_delta' => 'የውይይት መጠን ከ:since ጀምሮ +:pct%',
            'sentiment_drop' => 'አማካይ ስሜት ከ:since ጀምሮ በ:drop ነጥብ ቀንሷል',
        ],
        'digest' => [
            'tickets' => ':created ቲኬቶች ተከፍተዋል፣ :resolved ተፈትተዋል',
            'ai_resolved' => ':count ውይይቶች ያለ ቲኬት በረዳቱ ተፈትተዋል',
            'handoffs' => ':count ወደ ወኪሎች ማስተላለፎች (:reasons)',
            'rg' => ':count የኃላፊነት ጨዋታ ምልክቶች በረዳቱ ተነስተዋል',
            'anomalies' => ':count ያልተለመዱ ሁኔታ ማንቂያዎች',
            'intents' => 'ዋና ዓላማዎች፦ :intents',
        ],
    ],
];
