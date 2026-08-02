<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Authentication copy — Amharic (Doc 02 §14)
|------------------------------------------------------------------------------
|
| MACHINE-AUTHORED, PENDING NATIVE REVIEW. BUILD-STATUS carries this as a
| standing pre-launch item: every `am` string needs a native speaker's eye before
| a real operator sees it. Tests can prove no key is missing and nothing leaks;
| they cannot prove the register is right for an Ethiopian agent at shift start.
|
| The enumeration constraint applies to the translation too: `failed.generic`
| must stay non-committal in Amharic. A translator who "improves" it into
| "account not found" reopens AC-01 in one language only — the worst possible
| version of that bug, because the English tests stay green.
|
*/

return [

    'failed' => [
        'generic' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        'locked' => 'በጣም ብዙ ሙከራዎች። እባክዎ ቆይተው ይሞክሩ፣ ወይም የይለፍ ቃልዎን ዳግም ያስጀምሩ።',
        'disabled_user' => 'ይህ መለያ ጠፍቷል። አስተዳዳሪዎን ያግኙ።',
        'suspended_tenant' => 'ይህ የሥራ ቦታ ለጊዜው አይገኝም። አስተዳዳሪዎን ያግኙ።',
        'ip_blocked' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        'bad_credentials' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        // Owner ruling R-2: recorded in events/audit as its own category, but the
        // PERSON always sees the generic text — a distinct message here would
        // confirm the address exists in more than one workspace (AC-01).
        'ambiguous_tenant' => 'እነዚህ መረጃዎች ከመለያ ጋር አይዛመዱም። አረጋግጠው እንደገና ይሞክሩ።',
        '2fa_failed' => 'ያ ኮድ አልሰራም። አረጋግጠው እንደገና ይሞክሩ።',
    ],

    'throttle' => [
        'too_many_requests' => 'ከዚህ ግንኙነት በጣም ብዙ ሙከራዎች። ትንሽ ቆይተው ይሞክሩ።',
    ],

    // Machine-authored, pending native review (the standing pre-launch item).
    '2fa' => [
        'no_pending' => 'መጀመሪያ በይለፍ ቃልዎ ይግቡ።',
        'one_proof_required' => 'የማረጋገጫ መተግበሪያ ኮድዎን ወይም አንድ የመልሶ ማግኛ ኮድ ያስገቡ።',
        'voided' => 'በጣም ብዙ የተሳሳቱ ኮዶች። ከመጀመሪያው እንደገና ይግቡ።',
        'enrolment_superseded' => 'በሌላ ትር አዲስ ማዋቀር ተጀምሯል። አዲሱን QR ኮድ ስካን አድርገው እንደገና ይሞክሩ።',
        'already_enabled' => 'ባለሁለት-ደረጃ ማረጋገጫ አስቀድሞ በርቷል። እንደገና ለማዋቀር መጀመሪያ ያጥፉት።',
        'user_not_found' => 'ያ ተጠቃሚ የለም።',
        'confirm_name_mismatch' => 'የጻፉት ስም ከዚህ ተጠቃሚ ጋር አይዛመድም። ምንም አልተቀየረም።',
        'not_enabled_for_user' => 'ይህ ተጠቃሚ ባለሁለት-ደረጃ ማረጋገጫ አላበራም።',
        'title' => 'ባለሁለት-ደረጃ ማረጋገጫ',
        'status_on' => 'በርቷል',
        'status_off' => 'ጠፍቷል',
        'enable' => 'ባለሁለት-ደረጃ ማረጋገጫ ያዋቅሩ',
        'scan_instructions' => 'ይህን QR ኮድ በማረጋገጫ መተግበሪያዎ ስካን ያድርጉ፣ ወይም ሚስጥሩን በእጅ ያስገቡ።',
        'manual_secret' => 'በእጅ የሚገባ ሚስጥር',
        'code_label' => 'ከመተግበሪያዎ ባለ6-አኃዝ ኮድ ያስገቡ',
        'confirm_action' => 'አብራ',
        'codes_warning' => 'እነዚህን የመልሶ ማግኛ ኮዶች አሁን ያስቀምጡ። አንድ ጊዜ ብቻ ይታያሉ፤ እያንዳንዱም አንድ ጊዜ ብቻ ይሰራል።',
        'codes_saved' => 'ኮዶቹን አስቀምጫለሁ',
        'download_codes' => 'ኮዶቹን አውርድ',
        'regenerate_codes' => 'አዲስ የመልሶ ማግኛ ኮዶች ፍጠር',
        'disable' => 'ባለሁለት-ደረጃ ማረጋገጫ አጥፋ',
        'disable_code_label' => 'ለማረጋገጥ የአሁኑን ኮድ ያስገቡ',
        'low_codes_banner' => ':count የመልሶ ማግኛ ኮዶች ቀርተዋል። በቅርቡ አዲስ ስብስብ ይፍጠሩ።',
    ],

    'devices' => [
        'title' => 'መሣሪያዎች',
        'subtitle' => 'አሁን የገቡባቸው ቦታዎች በሙሉ። የማያውቁትን ይሰርዙ።',
        'column_device' => 'መሣሪያ',
        'column_first_seen' => 'መጀመሪያ የታየው',
        'column_last_active' => 'መጨረሻ ንቁ የነበረው',
        'column_ip' => 'የአይፒ አድራሻ',
        'column_location' => 'አካባቢ',
        'current_badge' => 'ይህ መሣሪያ',
        'location_unavailable' => 'አካባቢ አይገኝም',
        'revoke' => 'ሰርዝ',
        'revoke_confirm' => 'መሰረዙን አረጋግጥ',
        'revoke_others' => 'ሌሎች ክፍለ-ጊዜዎችን በሙሉ ሰርዝ',
        'revoke_others_title' => 'ሌሎች ክፍለ-ጊዜዎችን በሙሉ ይሰረዙ?',
        'revoke_others_body' => 'ከዚህ በስተቀር ሁሉም ክፍለ-ጊዜዎች ወዲያውኑ ይወጣሉ፤ በሌሎች መሣሪያዎች ላይ የተከፈቱ ትሮችም ጭምር።',
        'cancel' => 'ተወው',
        'only_here' => 'የገቡት እዚህ ብቻ ነው።',
        'load_failed' => 'ክፍለ-ጊዜዎችዎን መጫን አልተቻለም። እንደገና ለመሞከር ገጹን ያድሱ።',
        'cannot_revoke_current' => 'ይህ አሁን ያሉበት ክፍለ-ጊዜ ነው። ለመጨረስ መውጫውን ይጠቀሙ።',
        'session_not_found' => 'ያ ክፍለ-ጊዜ ከእንግዲህ የለም።',
    ],

];
