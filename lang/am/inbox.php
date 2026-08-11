<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 07 — የተዋሃደ የገቢ መልእክት ሳጥን ሕብረቁምፊዎች (§14: inbox.*)
|------------------------------------------------------------------------------
| Machine-authored Amharic — every string here is queued for native review
| (standing ruling R-14). Structure mirrors lang/en/inbox.php exactly.
*/

return [
    'notify' => [
        'assignment' => 'ቲኬት ለእኔ ተመድቧል',
        'mention' => 'በውስጣዊ ማስታወሻ ውስጥ ተጠቅሻለሁ',
        'follower' => 'በምከታተለው ቲኬት ላይ እንቅስቃሴ',
        'escalation' => 'ቲኬት ወደ እኔ ከፍ ተደርጓል',
        'sla_warning' => 'በእኔ ቲኬት ላይ የSLA ማስጠንቀቂያ',
        'window_closing' => 'በእኔ ቲኬት ላይ የመልስ መስኮት እየተዘጋ ነው',
        'bulk_completed' => 'የጅምላ ተግባር ተጠናቋል',
    ],
    'feature' => [
        'voice_recording' => 'የመልእክት አዘጋጅ የድምፅ ቀረጻ',
    ],
    'selection' => [
        'empty' => 'ምንም የሚመረጥ የለም — ይህ እይታ ባዶ ነው።',
        'consumed' => 'ይህ ምርጫ አስቀድሞ ተፈጽሟል — ሌላ ተግባር ለማካሄድ እንደገና ይምረጡ።',
    ],
    'shell' => [
        'skip_to_list' => 'ወደ ቲኬት ዝርዝር ዝለል',
        'skip_to_thread' => 'ወደ ውይይት ዝለል',
        'skip_to_context' => 'ወደ ቲኬት አውድ ዝለል',
        'region_views' => 'እይታዎች',
        'region_list' => 'የቲኬት ዝርዝር',
        'region_thread' => 'ውይይት',
        'region_context' => 'የቲኬት አውድ',
        'collapse_rail' => 'የእይታዎች አሞሌ አሳንስ',
        'expand_rail' => 'የእይታዎች አሞሌ አስፋ',
        'collapse_context' => 'የአውድ ፓነል አሳንስ',
        'expand_context' => 'የአውድ ፓነል አስፋ',
        'resize_list' => 'የቲኬት ዝርዝር መጠን ቀይር (የቀስት ቁልፎች)',
        'open_views' => 'እይታዎችን ክፈት',
        'back_to_list' => 'ወደ ዝርዝር ተመለስ',
        'open_context' => 'የቲኬት አውድ ክፈት',
        'list_placeholder' => 'ቲኬቶችን ለመጫን እይታ ይምረጡ።',
        'thread_placeholder' => 'ውይይቱን ለመክፈት ቲኬት ይምረጡ።',
    ],
    'topbar' => [
        'search_placeholder' => 'ቲኬቶች፣ ደንበኞች፣ መልእክቶች ፈልግ',
        'search_label' => 'ፍለጋ',
        'new_ticket' => 'አዲስ ቲኬት',
        'notifications' => 'ማሳወቂያዎች',
        'notifications_count' => 'ማሳወቂያዎች፣ :count ያልተነበቡ',
        'profile' => 'የመገለጫ ምናሌ',
        'status_label' => 'የወኪል ሁኔታ',
        'status_online' => 'መስመር ላይ',
        'status_away' => 'ራቅ ብሏል',
    ],
    'health' => [
        'label' => 'የቻናል ጤንነት',
        'all_healthy' => 'ሁሉም ቻናሎች ጤናማ ናቸው',
        'degraded' => 'አንድ ቻናል ተዳክሟል',
        'down' => 'አንድ ቻናል ወድቋል',
        'unknown' => 'የቻናል ጤንነት አይታወቅም',
        'banner_title' => 'የቻናል ሁኔታ',
        'banner_close' => 'የቻናል ሁኔታን ዝጋ',
    ],
    'views' => [
        'my_open' => 'የእኔ ክፍት',
        'unassigned' => 'ያልተመደቡ',
        'mentions' => 'መጠቀሶች',
        'following' => 'የምከታተላቸው',
        'sla_at_risk' => 'SLA አደጋ ላይ',
        'expiring_windows' => 'የሚያበቁ መስኮቶች',
        'rescue' => 'ማዳን',
        'section_system' => 'ስርዓት',
        'section_queues' => 'ወረፋዎች',
        'section_shared' => 'የተጋሩ',
        'section_mine' => 'የእኔ',
        'count_label' => ':view፣ :count ቲኬቶች',
    ],
    'tabs' => [
        'ticket' => 'ቲኬት',
        'customer' => 'ደንበኛ',
        'copilot' => 'ኮፓይለት',
        'customer_badge' => 'የደንበኛ ትር ማንቂያ አለው',
        'copilot_badge' => 'ኮፓይለት አዲስ አስተያየት አለው',
        'ticket_slot' => 'የቲኬት ባህሪያት ከቲኬቱ ጋር ይጫናሉ።',
        'customer_slot' => 'የደንበኛ መገለጫ ከሰነድ 08 ጋር ይመጣል።',
        'copilot_slot' => 'ኮፓይለት ከሰነድ 09 ጋር ይመጣል።',
    ],
    'density' => [
        'label' => 'የማሳያ ጥግግት',
        'comfortable' => 'ምቹ',
        'compact' => 'የታመቀ',
    ],
];
