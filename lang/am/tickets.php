<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Ticket strings — Amharic (Doc 06 §14; C-08)
|------------------------------------------------------------------------------
|
| MACHINE-AUTHORED (ruling R-14's standing queue): every string below requires
| native-speaker review before a real operator sees it.
|
| DESTRUCTIVE / OPERATIONAL TIER, flagged for priority review per R-14's rider:
| `fail.channel_unreachable_hold` and `fail.open_ticket_exists` — both change
| what an agent does next on live player traffic. A mistranslation of the first
| could read as "this ticket closed itself"; of the second, as "start another
| ticket", which is the duplicate BR-05 exists to prevent.
|
| Structure mirrors lang/en/tickets.php key for key; see that file for what
| belongs here and what deliberately does not (tag content is never translated).
|
*/

return [
    'status' => [
        'new' => 'አዲስ',
        'open' => 'ክፍት',
        'pending_customer' => 'የደንበኛ ምላሽ በመጠበቅ ላይ',
        'pending_internal' => 'የውስጥ ምላሽ በመጠበቅ ላይ',
        'escalated' => 'ወደ ላይ የተላከ',
        'resolved' => 'የተፈታ',
        'closed' => 'የተዘጋ',
        'spam' => 'አይፈለጌ',
    ],

    'priority' => [
        'low' => 'ዝቅተኛ',
        'normal' => 'መደበኛ',
        'high' => 'ከፍተኛ',
        'urgent' => 'አስቸኳይ',
    ],

    'pending_reason' => [
        'awaiting_customer' => 'የደንበኛውን ምላሽ በመጠበቅ ላይ',
        'awaiting_internal_department' => 'የሌላ ክፍል ምላሽ በመጠበቅ ላይ',
        'awaiting_internal_named_user' => 'የባልደረባ ምላሽ በመጠበቅ ላይ',
        'awaiting_internal_external' => 'የውጭ አካል ምላሽ በመጠበቅ ላይ',
    ],

    'fail' => [
        'illegal_transition' => 'ትኬት ከ:from ወደ :to መቀየር አይችልም።',
        'transition_not_permitted' => 'ይህን ለውጥ ለማድረግ ፈቃድ የለዎትም (:permission)።',
        'permission_expired' => 'ለዚህ ተግባር የተሰጠዎት ጊዜያዊ ፈቃድ :expires ላይ አብቅቷል። እንዲራዘም :granter ን ይጠይቁ።',
        'reason_required' => 'ይህ ለውጥ በመዝገብ ላይ ምክንያት ያስፈልገዋል።',
        'reason_too_short' => 'እባክዎ ቢያንስ :min ፊደላት ይጻፉ፤ ምክንያቱ ቀጥሎ ለሚያነበው ሰው እንዲጠቅም።',
        'channel_unreachable_hold' => 'ይህ ደንበኛ በመገናኛ መስመሩ ሊገኝ ስላልቻለ ይህ ትኬት በራሱ አይዘጋም — ውጤቱን ሰው ይወስናል።',
        'open_ticket_exists' => 'ይህ ውይይት አስቀድሞ ክፍት ትኬት አለው። ሌላ ከመጀመር ይልቅ ያንን ይክፈቱ።',
    ],
];
