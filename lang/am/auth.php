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

    // የ§8 የመግቢያ ካርድ (step 13)። ማሽን-የተጻፈ፤ የቋንቋ ተናጋሪ ግምገማ በመጠበቅ ላይ።
    // `lang_english`/`lang_amharic` በሁለቱም የቋንቋ ፋይሎች ውስጥ ተመሳሳይ ናቸው —
    // ተጠቃሚ የራሱን ቋንቋ በራሱ ቋንቋ ተጽፎ ማግኘት አለበት።
    'login' => [
        'title' => 'ይግቡ',
        'email_label' => 'የኢሜይል አድራሻ',
        'password_label' => 'የይለፍ ቃል',
        'show_password' => 'አሳይ',
        'hide_password' => 'ደብቅ',
        'remember' => 'እንደገባሁ አቆየኝ',
        'submit' => 'ግባ',
        'submitting' => 'በመግባት ላይ…',
        'forgot' => 'የይለፍ ቃልዎን ረሱ?',
        'expired_notice' => 'ለተወሰነ ጊዜ ንቁ ስላልነበሩ ወጥተዋል። ለመቀጠል እንደገና ይግቡ።',
        'error_fallback' => 'የሆነ ችግር ተፈጥሯል። ገጹን አድሰው እንደገና ይሞክሩ።',
        'language_label' => 'ቋንቋ',
        'lang_english' => 'English',
        'lang_amharic' => 'አማርኛ',
        'continue' => 'ቀጥል',
    ],

    'throttle' => [
        'too_many_requests' => 'ከዚህ ግንኙነት በጣም ብዙ ሙከራዎች። ትንሽ ቆይተው ይሞክሩ።',
    ],

    // FR-18/FR-19/FR-20: API tokens (see the en file's note on deliberate
    // uninformativeness).
    'token' => [
        'invalid' => 'ይህ ጥያቄ ልክ የሆነ የኤፒአይ ቶከን አላካተተም።',
        'expired' => 'የዚህ ኤፒአይ ቶከን ጊዜ አልፏል። ለመቀጠል አዲስ ቶከን ይፍጠሩ ወይም ያዙሩት።',
        'insufficient_scope' => 'ይህ ቶከን የ :permission ፈቃድን አላካተተም።',
    ],

    // FR-21/AC-06/EC-08: the widget session refusal (see lang/en/auth.php).
    'widget' => [
        'origin_not_allowed' => 'ይህ ገጽ በመስኮቱ የተፈቀዱ ድረ-ገጾች ዝርዝር ውስጥ የለም። የድረ-ገጹ ባለቤት ዝርዝሩን በመስኮት ማስተካከያዎች ውስጥ ማዘመን ይችላል።',
    ],

    // FR-23/FR-24/AC-07: the webhook refusal (see lang/en/auth.php).
    'webhook' => [
        'rejected' => 'ይህ የዌብሁክ ጥያቄ ሊረጋገጥ አልቻለም።',
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

        // የ§8 የማዋቀሪያ ዊዛርድ (step 13)።
        'wizard_scan_title' => 'QR ኮዱን ስካን ያድርጉ',
        'wizard_verify_title' => 'ከመተግበሪያዎ ኮዱን ያስገቡ',
        'wizard_codes_title' => 'የመልሶ ማግኛ ኮዶችዎን ያስቀምጡ',
        'wizard_continue' => 'ቀጥል',
        'wizard_done' => 'ጨርስ',
        'copy_secret' => 'ሚስጥሩን ቅዳ',
        'copy_all_codes' => 'ሁሉንም ኮዶች ቅዳ',
        'copied' => 'ተቀድቷል',

        // የመግቢያ ካርዱ የማረጋገጫ ደረጃ (step 13, §8)።
        'challenge_title' => 'የማረጋገጫ ኮድዎን ያስገቡ',
        'challenge_intro' => 'ከማረጋገጫ መተግበሪያዎ ባለ6-አኃዝ ኮዱን ያስገቡ።',
        'use_recovery' => 'በምትኩ የመልሶ ማግኛ ኮድ ይጠቀሙ',
        'use_totp' => 'በምትኩ የማረጋገጫ መተግበሪያዎን ይጠቀሙ',
        'recovery_label' => 'የመልሶ ማግኛ ኮድ',
        'recovery_submit' => 'በመልሶ ማግኛ ኮድ ግባ',
        'recovery_used' => 'ገብተዋል። ያ የመልሶ ማግኛ ኮድ አሁን ተጠቅሟል — :count ቀርተዋል።',
    ],

    // Step 13: የክፍለ-ጊዜ ጠባቂ — የ§8 ማስጠንቀቂያ እና የEC-02 ዳግም መግቢያ መስኮት።
    'session' => [
        'timeout_warning' => 'ንቁ ካልሆኑ በሁለት ደቂቃ ገደማ ውስጥ ይወጣሉ።',
        'stay_signed_in' => 'እንደገባሁ ቆይ',
        'relogin_title' => 'ለመቀጠል እንደገና ይግቡ',
        'relogin_intro' => 'ክፍለ-ጊዜዎ አብቅቷል። ካቆሙበት ለመቀጠል እንደገና ይግቡ — በዚህ ገጽ ላይ ያለው ሥራዎ አልጠፋም።',
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
    // ማሽን-የተጻፈ፤ የቋንቋ ተናጋሪ ግምገማ በመጠበቅ ላይ (BUILD-STATUS standing item)።
    // `forgot_sent`፣ `invite_resent_note` እና `link_dead_body` የ AC-01/SEC-01
    // ወጥ መልሶች ናቸው — መለያው መኖሩን የሚያሳብቅ ልዩነት መጨመር አይፈቀድም።
    'password' => [
        'policy_failed' => 'ያ የይለፍ ቃል መስፈርቶቹን አያሟላም።',
        'rule_length' => 'ቢያንስ :min ቁምፊዎችን ይጠቀሙ።',
        'rule_classes' => 'ቢያንስ ሦስቱን ይቀላቅሉ፦ ትንንሽ ፊደላት፣ ካፒታል ፊደላት፣ ቁጥሮች፣ ምልክቶች።',
        'rule_breached' => 'ያ የይለፍ ቃል በሚታወቅ የመረጃ ጥሰት ውስጥ ታይቷል። እባክዎ ሌላ ይምረጡ።',
        'rule_reuse' => 'ያንን የይለፍ ቃል በቅርቡ ተጠቅመውታል። ከዚህ በፊት ያልተጠቀሙበትን ይምረጡ።',
        'confirm_mismatch' => 'ሁለቱ የይለፍ ቃላት አይመሳሰሉም።',
        'current_password_wrong' => 'የአሁኑ የይለፍ ቃልዎ አልተመሳሰለም።',

        'forgot_title' => 'የይለፍ ቃልዎን ዳግም ያስጀምሩ',
        'forgot_intro' => 'የሥራ ኢሜይልዎን ያስገቡ፤ አዲስ የይለፍ ቃል ለመምረጥ የሚያስችል አገናኝ እንልክልዎታለን።',
        'email_label' => 'የኢሜይል አድራሻ',
        'forgot_submit' => 'የዳግም ማስጀመሪያ አገናኝ ላክ',
        'forgot_sent' => 'ያ አድራሻ መለያ ካለው፣ የዳግም ማስጀመሪያ አገናኝ በመንገድ ላይ ነው። ለመድረስ አንድ ወይም ሁለት ደቂቃ ሊወስድ ይችላል።',

        'reset_title' => 'አዲስ የይለፍ ቃል ይምረጡ',
        'password_label' => 'አዲስ የይለፍ ቃል',
        'confirm_label' => 'አዲሱን የይለፍ ቃል ይድገሙ',
        'reset_submit' => 'የይለፍ ቃል ቀይር',
        'reset_done' => 'የይለፍ ቃልዎ ተቀይሯል። በአዲሱ ይግቡ — ሌሎች ክፍለ-ጊዜዎች በሙሉ ወጥተዋል።',

        'link_dead_title' => 'ይህ አገናኝ መጠቀም አይቻልም',
        'link_dead_body' => 'ጊዜው አልፎበት ወይም አስቀድሞ ተጠቅሞበት ሊሆን ይችላል።',
        'link_dead_again' => 'አዲስ አገናኝ ይጠይቁ',

        'invite_title' => 'የይለፍ ቃልዎን ያዘጋጁ',
        'invite_intro' => 'መለያዎ ዝግጁ ነው። መጠቀም ለመጀመር የይለፍ ቃል ይምረጡ።',
        'invite_submit' => 'የይለፍ ቃል አዘጋጅተህ ቀጥል',
        'invite_done' => 'የይለፍ ቃልዎ ተዘጋጅቷል። አሁን መግባት ይችላሉ።',
        'invite_resend' => 'አዲስ የግብዣ አገናኝ ላኩልኝ',
        'invite_resent_note' => 'ያ አድራሻ ተጋብዞ ከነበረ፣ አዲስ አገናኝ በመንገድ ላይ ነው። ለመድረስ አንድ ወይም ሁለት ደቂቃ ሊወስድ ይችላል።',

        'expired_notice' => 'የይለፍ ቃልዎ ጊዜው አልፎበታል። ለመቀጠል አዲስ ይምረጡ።',

        // Step 13: የ§9 የይለፍ ቃል ጥንካሬ መለኪያ (በጽሑፍ) እና የ§8 መገለጫ ማጠናቀቂያ።
        'strength_label' => 'የይለፍ ቃል ጥንካሬ፦ :level',
        'strength_weak' => 'በጣም አጭር',
        'strength_fair' => 'መካከለኛ',
        'strength_strong' => 'ጠንካራ',
        'name_label' => 'ስምዎ',
        'invalid_language' => 'ከሚገኙት ቋንቋዎች አንዱን ይምረጡ።',
        'invalid_name' => 'ያ ስም በጣም ረጅም ነው።',

        'change_title' => 'የይለፍ ቃል ቀይር',
        'current_password_label' => 'የአሁኑ የይለፍ ቃል',
        'change_submit' => 'የይለፍ ቃል ቀይር',
        'changed_ok' => 'የይለፍ ቃልዎ ተቀይሯል። ሌሎች ክፍለ-ጊዜዎች ወጥተዋል።',
    ],

    'mail' => [
        'reset_subject' => 'የ:workspace የይለፍ ቃልዎን ዳግም ያስጀምሩ',
        'reset_line1' => 'አንድ ሰው የ:workspace መለያዎን የይለፍ ቃል ዳግም ለማስጀመር ጠይቋል።',
        'reset_action' => 'አዲስ የይለፍ ቃል ይምረጡ',
        'reset_valid' => 'ይህ አገናኝ አንድ ጊዜ ብቻ ይሠራል፤ በ:minutes ደቂቃ ውስጥ ጊዜው ያልፋል።',
        'reset_ignore' => 'ይህ እርስዎ ካልሆኑ፣ ይህን ኢሜይል ችላ ማለት ይችላሉ — የይለፍ ቃልዎ አልተቀየረም።',
        'invite_subject' => 'ወደ :workspace ተጋብዘዋል',
        'invite_line1' => 'አስተዳዳሪ በ:workspace ላይ መለያ ፈጥሮልዎታል።',
        'invite_action' => 'የይለፍ ቃልዎን ያዘጋጁ',
        'invite_valid' => 'ይህ አገናኝ አንድ ጊዜ ብቻ ይሠራል፤ በ:days ቀናት ውስጥ ጊዜው ያልፋል።',
        'invite_ignore' => 'ይህን ካልጠበቁ፣ ይህን ኢሜይል ችላ ማለት ይችላሉ።',
        'link_fallback' => 'አዝራሩ ካልሠራ፣ ይህን አድራሻ ወደ አሳሽዎ ይቅዱ፦',
    ],

];
