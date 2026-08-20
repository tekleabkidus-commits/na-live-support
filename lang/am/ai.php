<?php

declare(strict_types=1);

// Doc 09 — machine-authored Amharic; joins the R-14 native-review queue.
// DESTRUCTIVE TIER: rg.template (the RG customer template — wording changes
// must survive review first and unsoftened).

return [
    'rg' => [
        'template' => 'ስሜትዎን ስለነገሩን እናመሰግናለን — ይህ ድፍረት ይጠይቃል። አሁኑኑ ሊረዳዎ ከሚችል የቡድናችን አባል ጋር እያገናኘንዎ ነው። እረፍት መውሰድ ከፈለጉ በማንኛውም ጊዜ የተቀማጭ ገደብ ማስቀመጥ ወይም አካውንትዎን ማቆም ይችላሉ፤ እንዲሁም ከአካባቢዎ የቁማር ድጋፍ መስመር ነፃ እና ሚስጥራዊ ድጋፍ ማግኘት ይችላሉ። በዚህ ውስጥ ብቻዎን አይደሉም።',
    ],
    'handoff' => [
        'template' => 'ስለመልእክትዎ እናመሰግናለን። የቡድናችን አባል በቅርቡ ያነጋግርዎታል።',
    ],
    'notify' => [
        'killswitch_changed' => 'የAI ማቋረጫ ማብሪያ ተቀይሯል',
        'quota_80' => 'የAI ዕለታዊ የቶከን ኮታ 80% ደርሷል',
    ],
    'deflection' => [
        'template' => 'በውርርድ ምክር ወይም ትንበያ ልረዳዎ አልችልም — እያንዳንዱ ውጤት እርግጠኛ አይደለም፣ እና ምርጫው ሁልጊዜ የእርስዎ ነው። ከፈለጉ ገደብ እንዴት እንደሚያስቀምጡ ማሳየት ወይም ወደ ኃላፊነት ያለው ጨዋታ ምንጮቻችን ልመራዎ እችላለሁ።',
    ],
    'unsupported_language' => [
        'template' => 'ይቅርታ፣ አሁን በእንግሊዝኛ ወይም በአማርኛ ብቻ ልረዳ እችላለሁ። ከቡድናችን አባል ጋር እያገናኘሁዎት ነው።',
    ],
];
