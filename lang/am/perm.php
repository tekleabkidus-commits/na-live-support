<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Permission labels — Amharic (Doc 03 FR-01, §14; C-08; N-03)
|------------------------------------------------------------------------------
|
| One entry per catalog key in registries/permissions.php; CatalogSnapshotTest
| asserts completeness in BOTH locales. Amharic is machine-authored and queued
| for native review (standing queue; ruling R-14 flags the destructive tier).
|
*/

return [
    'tickets' => [
        'view' => 'ትኬቶችን መመልከት',
        'create' => 'ትኬት መፍጠር',
        'edit' => 'ትኬት ማስተካከል',
        'reply' => 'በትኬት መመለስ',
        'note' => 'የውስጥ ማስታወሻ ማከል',
        'assign' => 'ትኬት መመደብ',
        'assign_self' => 'ትኬት ለራስ መውሰድ',
        'status' => [
            'change' => 'የትኬት ሁኔታ መቀየር',
        ],
        'priority' => [
            'change' => 'የትኬት ቅድሚያ መቀየር',
        ],
        'department' => [
            'change' => 'ትኬት ወደ ሌላ ክፍል ማዛወር',
        ],
        'close' => 'ትኬት መዝጋት',
        'reopen' => 'ትኬት እንደገና መክፈት',
        'merge' => 'ትኬቶችን ማዋሃድ',
        'split' => 'ትኬት መክፈል',
        'escalate' => 'ትኬት ማሳደግ',
        'follow' => 'ትኬት መከተል',
        'tags' => [
            'manage' => 'የትኬት መለያዎችን ማስተዳደር',
        ],
        'fields' => [
            'edit' => 'የትኬት መስኮች ማስተካከል',
        ],
        'spam' => [
            'mark' => 'እንደ አይፈለጌ ምልክት ማድረግ',
        ],
        'delete' => 'ትኬት መሰረዝ',
        'export' => 'ትኬቶችን ማውረድ',
    ],
    'conversations' => [
        'view' => 'ውይይቶችን መመልከት',
        'history' => [
            'full' => 'ሙሉ የውይይት ታሪክ ማየት',
        ],
        'transcript' => [
            'export' => 'የውይይት ግልባጭ ማውረድ',
        ],
    ],
    'customers' => [
        'view' => 'ደንበኞችን መመልከት',
        'create' => 'ደንበኛ መፍጠር',
        'edit' => 'መገለጫ ማስተካከል',
        'notes' => [
            'add' => 'ማስታወሻ ማከል',
            'view' => 'ማስታወሻዎችን ማየት',
        ],
        'identity' => [
            'link' => 'ማንነት ማገናኘት',
            'unlink' => 'ማንነት መነጠል',
        ],
        'merge' => 'መገለጫዎችን ማዋሃድ',
        'block' => 'ደንበኛ ማገድ',
        'rg_flag' => [
            'view' => 'የኃላፊነት ቁማር ምልክቶችን ማየት',
            'set' => 'የኃላፊነት ቁማር ምልክት ማስቀመጥ',
        ],
        'export' => 'ደንበኞችን ማውረድ',
        'delete' => 'ደንበኛ መሰረዝ',
    ],
    'telegram' => [
        'send' => 'በቴሌግራም መላክ',
        'message' => [
            'delete' => 'መልዕክት መሰረዝ',
        ],
        'bots' => [
            'view' => 'ቦቶችን ማየት',
            'manage' => 'ቦቶችን ማስተዳደር',
        ],
        'connections' => [
            'manage' => 'ግንኙነቶችን ማስተዳደር',
        ],
        'health' => [
            'view' => 'የቻናል ጤንነት ማየት',
        ],
    ],
    'webchat' => [
        'respond' => 'በድር ውይይት መመለስ',
        'widgets' => [
            'view' => 'የውይይት ዊጀቶችን ማየት',
            'configure' => 'የውይይት ዊጀቶችን ማዋቀር',
        ],
    ],
    'sms' => [
        'send' => 'የኤስኤምኤስ ማሳወቂያ መላክ',
        'templates' => [
            'view' => 'የኤስኤምኤስ አብነቶችን ማየት',
            'create' => 'የኤስኤምኤስ አብነት መፍጠር',
            'edit' => 'የኤስኤምኤስ አብነት ማስተካከል',
            'delete' => 'የኤስኤምኤስ አብነት መሰረዝ',
        ],
        'gateways' => [
            'view' => 'የኤስኤምኤስ መተላለፊያዎችን ማየት',
            'manage' => 'የኤስኤምኤስ መተላለፊያዎችን ማስተዳደር',
        ],
        'optout' => [
            'manage' => 'የኤስኤምኤስ አለመቀበል ማስተዳደር',
        ],
    ],
    'ai' => [
        'copilot' => [
            'use' => 'የAI ኮፓይለት መጠቀም',
            'insert_draft' => 'የኮፓይለት ረቂቅ ማስገባት',
        ],
        'chatbot' => [
            'configure' => 'የAI ቻትቦት ማዋቀር',
        ],
        'guardrails' => [
            'edit' => 'የAI መከላከያዎችን ማስተካከል',
        ],
        'thresholds' => [
            'edit' => 'የAI ገደቦችን ማስተካከል',
        ],
        'decisions' => [
            'view' => 'የAI ውሳኔዎችን ማየት',
        ],
        'killswitch' => 'የAI ማቆሚያ መጠቀም',
        'providers' => [
            'manage' => 'የAI አቅራቢዎችን ማስተዳደር',
        ],
    ],
    'kb' => [
        'view_internal' => 'የውስጥ ጽሑፎችን ማየት',
        'articles' => [
            'create' => 'ጽሑፍ መፍጠር',
            'edit' => 'ጽሑፍ ማስተካከል',
            'delete' => 'ጽሑፍ መሰረዝ',
            'publish' => 'ጽሑፍ ማተም',
        ],
        'categories' => [
            'manage' => 'ምድቦችን ማስተዳደር',
        ],
        'feedback' => [
            'view' => 'የጽሑፍ አስተያየት ማየት',
        ],
    ],
    'automation' => [
        'rules' => [
            'view' => 'የአውቶሜሽን ደንቦችን ማየት',
            'edit' => 'የአውቶሜሽን ደንቦችን ማስተካከል',
            'activate' => 'የአውቶሜሽን ደንቦችን ማንቃት',
        ],
    ],
    'macros' => [
        'personal' => [
            'manage' => 'የግል ማክሮዎችን ማስተዳደር',
        ],
        'shared' => [
            'manage' => 'የጋራ ማክሮዎችን ማስተዳደር',
        ],
    ],
    'forms' => [
        'view' => 'ቅጾችን ማየት',
        'edit' => 'ቅጾችን ማስተካከል',
        'publish' => 'ቅጾችን ማተም',
    ],
    'fields' => [
        'manage' => 'የተለዩ መስኮችን ማስተዳደር',
    ],
    'sla' => [
        'policies' => [
            'view' => 'የSLA ፖሊሲዎችን ማየት',
            'edit' => 'የSLA ፖሊሲዎችን ማስተካከል',
        ],
    ],
    'org' => [
        'departments' => [
            'manage' => 'ክፍሎችን ማስተዳደር',
        ],
        'teams' => [
            'manage' => 'ቡድኖችን ማስተዳደር',
        ],
        'queues' => [
            'manage' => 'ወረፋዎችን ማስተዳደር',
            'pull_in' => 'ከወረፋ ሥራ መሳብ',
        ],
        'business_hours' => [
            'edit' => 'የሥራ ሰዓታት ማስተካከል',
        ],
    ],
    'admin' => [
        'users' => [
            'view' => 'ተጠቃሚዎችን ማየት',
            'manage' => 'ተጠቃሚዎችን ማስተዳደር',
            'disable' => 'ተጠቃሚ ማሰናከል',
        ],
        'roles' => [
            'view' => 'ሚናዎችን ማየት',
            'manage' => 'ሚናዎችን ማስተዳደር',
        ],
        'overrides' => [
            'manage' => 'የተጠቃሚ ልዩ ፈቃዶችን ማስተዳደር',
        ],
        '2fa' => [
            'reset' => 'የተጠቃሚ 2FA ዳግም ማስጀመር',
        ],
    ],
    'reports' => [
        'view' => 'ሪፖርቶችን ማየት',
        'dashboards' => [
            'create' => 'ዳሽቦርዶችን መፍጠር',
            'share' => 'ዳሽቦርዶችን ማጋራት',
        ],
        'export' => 'ሪፖርት ማውረድ',
        'schedule' => 'ሪፖርቶችን መርሐግብር ማስያዝ',
        'supervisor' => [
            'live' => 'የቀጥታ ተቆጣጣሪ ሰሌዳ ማየት',
        ],
        'agents' => [
            'individual' => 'የግለሰብ ወኪል መለኪያዎችን ማየት',
        ],
    ],
    'supervision' => [
        'monitor' => 'ውይይቶችን መከታተል',
        'whisper' => 'ለወኪል በሹክሹክታ መምከር',
        'reassign' => 'ሥራ እንደገና መመደብ',
        'agent_status' => [
            'force' => 'የወኪል ሁኔታ ማስገደድ',
        ],
        'broadcast' => 'ለወኪሎች ማሰራጨት',
    ],
    'settings' => [
        'view' => 'ቅንብሮችን ማየት',
        'edit' => 'ቅንብሮችን ማስተካከል',
    ],
    'branding' => [
        'edit' => 'ብራንዲንግ ማስተካከል',
    ],
    'features' => [
        'toggle' => 'ባህሪያትን ማብራት/ማጥፋት',
    ],
    'languages' => [
        'manage' => 'ቋንቋዎችን ማስተዳደር',
    ],
    'audit' => [
        'view' => 'የኦዲት መዝገብ ማየት',
        'export' => 'የኦዲት መዝገብ ማውረድ',
    ],
    'apikeys' => [
        'machine' => [
            'manage' => 'የማሽን ኤፒአይ ቁልፎችን ማስተዳደር',
        ],
    ],
    'views' => [
        'share' => 'የተቀመጡ እይታዎችን ማጋራት',
        'manage' => 'የተቀመጡ እይታዎችን መፍጠር እና ማስተካከል',
    ],
];
