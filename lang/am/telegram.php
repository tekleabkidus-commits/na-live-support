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

    // Doc 05 §14 — admin UI copy for the B6-S01/S02/S03 screens (step 10).
    'ui' => [
        'bots' => [
            'title' => 'የቴሌግራም ቦቶች',
            'subtitle' => 'ቦቶችን ይመዝግቡ፣ የግንኙነት ዘዴ ይምረጡ፣ ጤናቸውንም ይመልከቱ።',
            'empty_title' => 'እስካሁን ቦት የለም',
            'empty_body' => 'የቴሌግራም መልእክቶችን መቀበል ለመጀመር የመጀመሪያ ቦትዎን ከ BotFather ቶከን ጋር ያክሉ።',
            'col_bot' => 'ቦት',
            'col_mode' => 'ሁነታ',
            'col_health' => 'ጤና',
            'col_connections' => 'ግንኙነቶች',
            'mode_direct' => 'ቀጥታ ቦት',
            'mode_business' => 'የቢዝነስ መለያ',
            'mode_hybrid' => 'ድብልቅ',
            'health_healthy' => 'ጤናማ',
            'health_degraded' => 'የቀነሰ',
            'health_down' => 'የወደቀ',
            'wizard_title' => 'ቦት አክል',
            'token_label' => 'የ BotFather ቶከን',
            'token_placeholder' => 'ቶከኑን ይለጥፉ — እንደገና አይታይም',
            'token_rejected' => 'ቶከኑ በቴሌግራም ተቀባይነት አላገኘም። አረጋግጠው እንደገና ይሞክሩ።',
            'token_validated' => 'ቶከን ተረጋግጧል እና ዌብhook ተረጋግጧል።',
            'mode_title' => 'የግንኙነት ዘዴ',
            'mode_recommended' => 'የሚመከር',
            'mode_help_direct' => 'ተጫዋቾች ቦቱን በትዕዛዞችና ምናሌዎች ያናግራሉ።',
            'mode_help_business' => 'ተጫዋቾች ሰው የሚመስል የድጋፍ መለያ ያናግራሉ፤ ቦቱ ያገናኛል።',
            'mode_help_hybrid' => 'ሁለቱም መስመሮች ንቁ — አንድ ደንበኛ፣ ሁለት ውይይቶች።',
        ],
        'connections' => [
            'step_botfather' => 'በ BotFather ውስጥ የቢዝነስ ሁነታን ያንቁ',
            'step_phone' => 'በድጋፍ ስልኩ ላይ ቦቱን እንደ ቻትቦት ያክሉ',
            'step_confirm' => 'መለያውን እዚህ ያረጋግጡ',
            'title' => 'የቢዝነስ ግንኙነቶች',
            'checklist_title' => 'የድጋፍ መለያ ያገናኙ',
            'step_done' => 'ተጠናቋል',
            'step_active' => 'በሂደት ላይ',
            'step_pending' => 'አልተጀመረም',
            'confirm_title' => 'ይህ የእኛ መለያ መሆኑን ያረጋግጡ',
            'confirm_account' => 'መለያውን ያገናኙ',
            'confirm_type_label' => 'ለማረጋገጥ የመለያውን ስም ይተይቡ',
            'confirm_yes' => 'አዎ፣ አገናኝ',
            'confirm_reject' => 'አትቀበል',
            'state_pending' => 'በመጠበቅ ላይ',
            'state_active' => 'ንቁ',
            'state_paused' => 'የቆመ',
            'state_revoked' => 'የተሰረዘ',
            'col_account' => 'መለያ',
            'col_state' => 'ሁኔታ',
            'col_last_inbound' => 'የመጨረሻ ገቢ',
            'col_sends_blocked' => 'የታገዱ መላኪያዎች',
        ],
        'window' => [
            'title' => 'የመልስ መስኮት',
            'readonly_notice' => 'የመልስ መስኮቱን ማስተካከል ትልቅ ስክሪን ይፈልጋል። እዚህ ዋጋዎቹ ለንባብ ብቻ ናቸው።',
            'window_hours_label' => 'የመስኮት ርዝመት (ሰዓታት)',
            'window_hours_help' => 'ደንበኛ ከጻፈ በኋላ በመለያው ስም ለምን ያህል ጊዜ መመለስ እንደሚችሉ።',
            'window_hours_bounds' => 'ዝቅተኛ 1፣ ከፍተኛ 24 — የመድረኩ ጣሪያ ከቴሌግራም ባህሪ ጋር ይመሳሰላል።',
            'preview_label' => 'ቅድመ እይታ',
            'chip_closes_in' => 'ይዘጋል በ',
            'chip_expired' => 'መስኮቱ አልፎበታል',
            'chip_no_window' => 'መስኮት የለም',
            'calibration_title' => 'የካሊbration መዝገብ',
            'calibration_observed' => 'የታየ መስኮት',
            'calibration_none' => 'እስካሁን ካሊbration የለም — መስኮቱ ከመጀመሪያው ቀደም ብሎ መዘጋት ይማራል።',
            'simulate_expiry' => 'የመስኮት ማብቂያ አስመስል',
        ],
    ],
];
