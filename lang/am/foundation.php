<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Foundation strings — Amharic (Doc 01 FR-09, §14; AC-06)
|------------------------------------------------------------------------------
|
| ⚠ NOT YET REVIEWED BY A NATIVE SPEAKER.
|
| These translations are machine-authored. AC-06 proves what it can prove
| mechanically — that every foundation key resolves in Amharic with zero key
| leakage and zero missing-translation telemetry — and that is genuinely worth
| proving, because a missing key renders as `foundation.tenant.banner.suspended`
| on an operator's screen.
|
| What AC-06 CANNOT prove is that the Amharic is good. Tone, register, and the
| specific vocabulary an Ethiopian betting operator's players expect are a
| native-speaker judgement. A standing pre-launch item is recorded in
| docs/BUILD-STATUS.md: no real operator sees these strings until a native speaker
| has reviewed them.
|
*/

return [
    'tenant' => [
        'banner' => [
            'provisioning' => 'ይህ የሥራ ቦታ በማዘጋጀት ላይ ነው። አንዳንድ ገጽታዎች እስካሁን አይገኙም።',
            'suspended' => 'ይህ የሥራ ቦታ ታግዷል። የሚመጡ መልእክቶች እየተቀበሉና እየተቀመጡ ናቸው፣ ነገር ግን ምላሽ መላክ አይቻልም። እባክዎ የመለያ አስተዳዳሪዎን ያግኙ።',
            'archived' => 'ይህ የሥራ ቦታ በማህደር ተቀምጧል፤ ከዚህ በኋላ መቀየር አይቻልም።',
            'active' => '',
        ],
        'state_forbidden' => 'ይህ የሥራ ቦታ :state ነው፤ ስለዚህ ይህ ተግባር አይገኝም።',
    ],

    'error' => [
        'quota_exceeded' => 'የአጠቃቀም ገደብ ተደርሷል',
        'tenant_state_forbidden' => 'ለዚህ የሥራ ቦታ አይገኝም',
        'unregistered_setting' => 'ያልታወቀ ማስተካከያ',
        'setting_write_conflict' => 'ሌላ ሰው ከእርስዎ በፊት ቀይሮታል',
        'invalid_pagination' => 'ልክ ያልሆነ የገጽ ጥያቄ',
        'validation_failed' => 'የተወሰኑ ዝርዝሮች ማስተካከል ያስፈልጋቸዋል',
        // Doc 02 SEC-06: refused because the request arrived over cleartext.
        'invalid_credentials' => 'መግባት አልተሳካም',
        'rate_limited' => 'በጣም ብዙ ሙከራዎች',
        'insecure_transport' => 'ደህንነቱ የተጠበቀ ግንኙነት ያስፈልጋል',
        'insecure_transport_detail' => 'ይህ አገልግሎት የሚገኘው በተመሰጠረ ግንኙነት ብቻ ነው። https ተጠቅመው እንደገና ይገናኙ።',
    ],

    'provisioning' => [
        'step' => [
            'tenant_record' => 'የሥራ ቦታ በመፍጠር ላይ',
            'default_settings' => 'ነባሪ ማስተካከያዎችን በመተግበር ላይ',
            'default_roles' => 'ሚናዎችን በማዘጋጀት ላይ',
            'sla_policy' => 'የአገልግሎት ዒላማዎችን በመተግበር ላይ',
            'business_hours' => 'የሥራ ሰዓቶችን በማዘጋጀት ላይ',
            'knowledge_base' => 'የእውቀት ማከማቻውን በማዘጋጀት ላይ',
        ],
        'failed' => 'ማዘጋጀቱ በዚህ ደረጃ መጠናቀቅ አልቻለም። ማጣቀሻ: :error_ref',
    ],

    'feature' => [
        'emergency_read_only' => 'የአደጋ ጊዜ ንባብ-ብቻ ሁነታ',
    ],
];
