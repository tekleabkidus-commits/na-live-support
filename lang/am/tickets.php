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
        'resolution_requires_substance' => 'ደንበኛው በዚህ ትኬት ላይ ምላሽ አላገኘም። ይፋዊ ምላሽ ይላኩ፣ ወይም ውጤቱን የሚያብራራ የመፍትሔ ማስታወሻ ያክሉ።',
        'reopen_window_passed' => 'የዚህ ትኬት እንደገና የመክፈቻ ጊዜ አልፏል። በዚሁ ውይይት ውስጥ አዲስ ትኬት ይክፈቱ — ወደዚህኛው ተያይዞ ይመዘገባል።',

        // FR-20 merge / unmerge (step 6).
        'cross_customer_merge' => 'እነዚህ ትኬቶች የተለያዩ ደንበኞች ናቸው። መጀመሪያ የደንበኛ መዝገቦቹን ያዋህዱ፣ ከዚያ ትኬቶቹን።',
        'target_out_of_scope' => 'የመዋሃጃው መድረሻ ትኬት ከእይታ ወሰንዎ ውጭ ነው። መክፈት የሚችሉትን መድረሻ ይምረጡ።',
        'merge_target_spam' => 'አይፈለጌ ትኬት መዋሃድ መቀበል አይችልም።',
        'merge_source_closed' => 'የተዘጋ ትኬት ወደ ሌላ ሊዋሃድ አይችልም — ወደ እሱ ያዋህዱ፣ ወይም መጀመሪያ እንደገና ይክፈቱት።',
        'merge_source_spam' => 'አይፈለጌ ትኬት ወደ ንቁ ትኬት ሊዋሃድ አይችልም። አይፈለጌ ካልሆነ መጀመሪያ ምልክቱን ያንሱ።',
        'merge_cycle' => 'ይህ ውህደት ትኬቶቹን በክብ እርስ በርስ ያመለክታቸዋል። በአንድ አቅጣጫ ብቻ ያዋህዱ።',
        'merged_superseded' => 'ይህ ትኬት ወደ ሌላ ተዋህዷል እና ለንባብ ብቻ ነው። በቀጣዩ ትኬት ላይ ይስሩ፣ ወይም በ24 ሰዓት ውስጥ ውህደቱን ይቀልብሱ።',
        'not_merged' => 'ይህ ትኬት ወደ ምንም አልተዋሃደም — የሚቀለበስ ውህደት የለም።',
        'unmerge_window_passed' => 'የ24 ሰዓቱ የመቀልበሻ ጊዜ አልፏል። ይህ ውህደት አሁን ቋሚ ነው።',

        // FR-21 split (step 6).
        'split_cap_reached' => 'ይህ ውይይት አስቀድሞ :max የተያያዙ ትኬቶች አሉት። መጀመሪያ ያሉትን የተያያዙ ስራዎች ይፍቱ ወይም ያስተላልፉ።',
        'split_messages_foreign' => 'ምርጫው የዚህ ውይይት አካል ያልሆኑ መልዕክቶችን ይዟል። እንደገና መርጠው ይሞክሩ።',

        // FR-23 escalation (step 6).
        'escalation_target_invalid' => 'ያ ሰው ከስራ ውጭ ሆኗል ወይም ይህን ትኬት ማየት አይችልም። ሌላ ሰው ይምረጡ፣ ወይም የተቆጣጣሪ ግምገማ ይጠቀሙ።',

        // FR-09 spam (step 7).
        'not_spam' => 'ይህ ትኬት አይፈለጌ ተብሎ አልተመዘገበም — የሚነሳ ምልክት የለም።',
        'spam_terminal' => 'የ7 ቀኑ ምልክት የማንሳት ጊዜ አልፏል፤ ይህ የአይፈለጌ ምልክት አሁን ቋሚ ነው። ደንበኛው እንደገና ከጻፈ አዲስ ትኬት በራሱ ይከፈታል።',

        // FR-25 የጅምላ ሂደት ማሳያ የሚያሳያቸው የነጠላ ትኬት ውድቀት ምክንያቶች (step 7)።
        'already_claimed' => 'ሌላ ሰው ይህን ትኬት ቀድሞ ወስዷል።',
        'assignee_out_of_scope' => 'ያ ሰው ይህን ትኬት ማየት አይችልም፤ ስለዚህ ሊመደብለት አይችልም።',
        'operation_refused' => 'ይህ ትኬት ተግባሩን አልተቀበለም። ዝርዝሩን ለማየት ትኬቱን ይክፈቱ።',
        'sequence_contention' => 'ስርዓቱ አዲስ ትኬት ለመቁጠር ለአፍታ ተጨናንቆ ነበር። ያልተሳኩትን እንደገና ይሞክሩ።',
        'error' => 'በዚህ ትኬት ላይ ያልተጠበቀ ስህተት ተከስቷል። ታልፏል፤ የቀረው ስብስብ ቀጥሏል።',
    ],

    // የስርዓት ማስታወሻ ጽሑፎች (NotesWriter::addSystemNote — በሚጻፉበት ቅጽበት ይተረጎማሉ፤
    // ማስታወሻ የማይለወጥ ታሪካዊ መዝገብ ነው)።
    'note' => [
        'split_origin' => 'መከፈል፦ :count መልዕክት(ዎች) ወደ የተያያዘ ትኬት #:new ተዛውረዋል። ይህ ትኬት (#:origin) ዋናውን ውይይት ይይዛል።',
        'escalation_rerouted' => 'ይህ ትኬት (#:number) ወደ ላይ የተላከለት ሰው ከስራ ውጭ ሆኗል። ወደ ላይ መላኩ አሁን ወደ ተቆጣጣሪ ግምገማ ይሄዳል።',
    ],

    // የ§8 የተዋሃደ-ትኬት ባነር (Doc 07 ከ merged_into ያሳያል፤ ሰንሰለቶች በ EC-03
    // መሠረት እስከ መጨረሻው ቀጣይ ትኬት ይፈታሉ)።
    'banner' => [
        'merged_into' => 'ይህ ትኬት ወደ #:number ተዋህዷል። ተከታይ ጥያቄዎች እዚያ ይቀጥላሉ።',
    ],

    // የ§13 የሁኔታ መምረጫ፦ እያንዳንዱ ሕጋዊ ምርጫ ከውጤቱ መግለጫ ጋር ይነገራል።
    'consequence' => [
        'new' => 'ትኬቱን ወደ ያልተነካው ወረፋ ይመልሰዋል።',
        'open' => 'ትኬቱ በንቃት እየተሰራበት መሆኑን ያመለክታል።',
        'pending_customer' => 'ደንበኛውን ይጠብቃል፤ ምላሻቸው ወደ ክፍት ይመልሰዋል።',
        'pending_internal' => 'በውስጥ ያለ ሌላ ቡድን ወይም ሰው ይጠብቃል።',
        'escalated' => 'ትኬቱን ከግዴታ ምክንያት ጋር ወደ ላይ ይልካል፤ ተቀባዩ ይነገራል።',
        'resolved' => 'ደንበኛውን ያሳውቃል እና በራስ-መዝጊያ ቆጠራ ይጀምራል።',
        'closed' => 'ትኬቱን ይጨርሳል፤ ደንበኛው ለተወሰነ ጊዜ እንደገና መክፈት ይችላል።',
        'spam' => 'ትኬቱን ከሁሉም መለኪያዎች ያገልላል፤ ለ7 ቀናት ሊቀለበስ ይችላል።',
    ],

    // የሁኔታ ማሳያዎችና የቅንብር ጽሑፍ (Doc 06 §8, SS-03)።
    'affordance' => [
        'resolved_countdown' => 'ደንበኛው ካልመለሰ በ:hours ሰዓት ውስጥ ይዘጋል።',
        'closed_reopen_countdown' => 'ደንበኛው ይህን ትኬት ለተጨማሪ :days ቀን(ናት) እንደገና መክፈት ይችላል።',
        'closed_reason_required' => 'አሁን እንደገና ለመክፈት ምክንያት መመዝገብ ያስፈልጋል።',
    ],

    'settings' => [
        'autoclose_change_note' => 'ለውጡ ከአሁን በኋላ ለሚፈቱ ትኬቶች ብቻ ይተገበራል። አስቀድመው የተፈቱ ትኬቶች የተሰጣቸውን የመዝጊያ ጊዜ ይይዛሉ።',
        'reopen_change_note' => 'እንደገና የመክፈቻው ጊዜ ደንበኛው በሚመልስበት ቅጽበት ባለው ዋጋ ይለካል።',
    ],
];
