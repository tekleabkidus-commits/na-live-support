<?php

declare(strict_types=1);

// Doc 12 — የስራ ፍሰት ንብርብር ሕብረቁምፊዎች (S12)። Machine-authored Amharic — R-14
// native-review queue; the rules-pause switch is DESTRUCTIVE-TIER flagged
// (it stops every tenant automation instantly).
return [
    'feature' => [
        'rules_pause' => 'ሁሉንም አውቶማቲክ ህጎች ለጊዜው አቁም',
    ],
    'forms' => [
        // FR-22: the publish-validation block labels (SchemaValidator's codes).
        'block' => [
            'invalid_shape' => 'የቅጹ ትርጉም የተሳሳተ ነው፦ :detail',
            'forward_reference' => '":at" ወደ ":field" ያመለክታል፣ እሱ ግን በኋለኛ ደረጃ ላይ ነው — ሁኔታዎች ወደ ኋላ ብቻ መመልከት ይችላሉ።',
            'unknown_field' => '":field" የሚባል መስክ የለም።',
            'retired_field' => '":field" ተሰናብቷል — ከማተምዎ በፊት ቅጹን ያርትዑ።',
            'required_hidden' => '":field" ግዴታ ነው ግን አንዳንዴ ተደብቋል። በምትኩ "ሲታይ ግዴታ" ያድርጉት።',
            'incomplete_language' => ':at የ:language ጽሑፉ ይጎድለዋል።',
            'upload_unsupported' => 'ግዴታ የሆነ የፋይል መስክ በ":channel" ላይ መመለስ አይቻልም — ያ ቻናል ምንም ስቀላ አይቀበልም።',
        ],
        // FR-22: the usage-banner section headings.
        'usage' => [
            'bot_menus' => 'የቦት ምናሌዎች',
            'rules' => 'የራስ-ሰር ህጎች',
            'widget_shortcuts' => 'የዊጀት አቋራጮች',
        ],
    ],
    'csat' => [
        // FR-25: the 1-tap prompt, the polite hour-73 refusal (EC-11), the
        // optional comment prompt, and the thanks.
        'prompt' => 'እንዴት ነበርን? ኮከብ ይንኩ — 1 (ደካማ) እስከ 5 (በጣም ጥሩ)።',
        'window_closed' => 'እናመሰግናለን — የዚህ ደረጃ መስጫ ጊዜ አልፏል፣ ግን አስተያየትዎ በውይይቱ ውስጥ ሁሌም እንኳን ደህና መጣ።',
        'comment_prompt' => 'እናመሰግናለን! መጨመር የሚፈልጉት ነገር አለ?',
        'thanks' => 'ስለ አስተያየትዎ እናመሰግናለን!',
    ],
    'flow' => [
        // FR-21: the conversational renderer's own voice (friendly retries,
        // the offer of a person, numbered-choice instruction, FS-04's recap).
        'retry' => 'ይቅርታ፣ ያ ትክክል አይመስልም — እባክዎ እንደገና ይሞክሩ።',
        'offer_human' => 'ይህ አስቸጋሪ ይመስላል። በማንኛውም ጊዜ /human ብለው ይመልሱ — ሰው ከዚህ ይቀጥላል፤ የጻፉት ሁሉ ተያይዞ ይቆያል።',
        'choose_number' => 'የምርጫዎን ቁጥር ይመልሱ።',
        'resumed_recap' => 'ካቆምንበት እንቀጥላለን — ቀደም ያሉ መልሶችዎ ተቀምጠዋል።',
    ],
    'macros' => [
        'bundled_skipped' => 'የማክሮው ጽሁፍ ገብቷል፣ ነገር ግን ":type" ተዘሏል — ያ ፈቃድ የለዎትም።',
        'bundled_failed' => 'የማክሮው ጽሁፍ ገብቷል፣ ነገር ግን ":type" እዚህ ሊተገበር አልቻለም።',
    ],
    'sla' => [
        'example' => ':created የተከፈተ ቲኬት የመጀመሪያ ምላሽ ዒላማው :target ይሆናል (:zone)።',
        'example_unreachable' => 'ይህ የስራ ሰዓት መርሃ ግብር በጭራሽ አይከፈትም — ቢያንስ አንድ ሳምንታዊ ክፍተት ይሳሉ።',
        'stage' => [
            'first_response' => 'የመጀመሪያ ምላሽ',
            'next_response' => 'ቀጣይ ምላሽ',
            'resolution' => 'መፍትሄ',
        ],
    ],
    // FR-03 notify — machine-authored am (R-14 queue; non-destructive tier).
    'notify' => [
        'rule' => 'የደንብ ማሳወቂያ',
    ],
    // FR-03 run AI task — machine-authored am (R-14 queue; non-destructive tier).
    'ai_task' => [
        'summary_note' => 'የAI ማጠቃለያ (በደንብ የተፈጠረ)፦ :summary',
        'translation_note' => 'የመጨረሻው የደንበኛ መልእክት ወደ :language የተተረጎመ (በደንብ የተፈጠረ)፦ :text',
    ],
    // ── §8/§9 (S12 step 14): Doc 12's OWN surfaces — machine-authored am → R-14 queue; DESTRUCTIVE TIER
    //    FLAGGED for native review: rules.activate_confirm / deactivate_confirm / *_verb, fields.deactivate_*,
    //    forms.publish_confirm / publish_verb, hub.paused_banner.
    'ui' => [
        'nav' => ['backoffice' => 'የኋላ ቢሮ', 'organization' => 'ድርጅት', 'people' => 'ሰዎች እና ሚናዎች', 'channels' => 'ቻናሎች', 'sla' => 'የSLA ፖሊሲዎች', 'automation' => 'አውቶሜሽን', 'hours' => 'የስራ ሰዓታት', 'workflow' => 'የስራ ፍሰት', 'macros' => 'ማክሮዎች', 'fields' => 'ብጁ መስኮች', 'forms' => 'ቅጾች', 'csat' => 'የCSAT ዳሰሳዎች'],
        'hub' => ['title' => 'የስራ ፍሰት', 'summary' => ':rules ደንቦች · :policies የSLA ፖሊሲዎች · :forms ቅጾች · :macros ማክሮዎች', 'readonly_notice' => 'የደንብ አርታዒውንና የቅጽ ገንቢውን ለማርትዕ ቢያንስ 1024 ፒክሰል ስፋት ያለው ማያ ያስፈልጋል። እዚህ ሁሉም ነገር ሊነበብ ይችላል።', 'paused_banner' => 'ሁሉም የአውቶሜሽን ደንቦች ለዚህ የስራ ቦታ ቆመዋል (የR-04 ማብሪያ)። እንደገና እስኪበራ ድረስ ምንም አይሰራም።'],
        'common' => ['save' => 'አስቀምጥ', 'save_draft' => 'እንደ ረቂቅ አስቀምጥ', 'cancel' => 'ሰርዝ', 'name' => 'ስም', 'description' => 'መግለጫ', 'active' => 'ንቁ', 'inactive' => 'ንቁ ያልሆነ', 'draft' => 'ረቂቅ', 'position' => 'ቦታ', 'move_up' => 'ወደ ላይ ውሰድ', 'move_down' => 'ወደ ታች ውሰድ', 'created' => 'ተፈጠረ', 'updated' => 'ተዘምኗል', 'never' => 'በጭራሽ', 'refused' => 'ለውጡ ውድቅ ተደርጓል፦ :detail', 'saved' => 'ተቀምጧል።', 'conflict' => 'ይህን ከከፈቱ በኋላ ሌላ ሰው ቀይሮታል። የእነሱን ስሪት ለማየት እንደገና ይጫኑ።', 'language_en' => 'እንግሊዝኛ', 'language_am' => 'አማርኛ', 'confirm_verb' => 'አረጋግጥ'],
        'rules' => [
            'title' => 'የአውቶሜሽን ደንቦች', 'summary' => ':active ንቁ · :draft ረቂቆች · የቦታ ቅደም ተከተል የአፈጻጸም ቅደም ተከተል ነው (BR-01)', 'new' => '+ አዲስ ደንብ', 'empty' => 'እስካሁን ደንቦች የሉም። ደንቦች በተዘረዘሩበት ቅደም ተከተል ይሰራሉ — የሚያዩት ነው የሚተኮሰው።',
            'col_position' => '#', 'col_name' => 'ደንብ', 'col_trigger' => 'ቀስቃሽ', 'col_matched' => 'የተዛመዱ', 'col_last_run' => 'የመጨረሻ አፈጻጸም', 'col_state' => 'ሁኔታ', 'guard_trips' => ':count የጥበቃ ማቆሚያዎች', 'revision_pending' => 'ተስተካክሏል — ለመንቃት የሚጠብቅ ረቂቅ ክለሳ አለ',
            'activate' => 'አንቃ', 'deactivate' => 'አቦዝን', 'activate_confirm' => '":name" ይነቃ? በሚቀጥለው ተዛማጅ ክስተት በቦታ :position መስራት ይጀምራል።', 'deactivate_confirm' => '":name" ይቦዝን? ወዲያውኑ መስራት ያቆማል፤ ታሪኩ ይቆያል።', 'activate_verb' => 'ደንብ አንቃ', 'deactivate_verb' => 'ደንብ አቦዝን',
            'floor_warning' => 'የአንቂ አቅም ልዩነት፦ ይህን ደንብ ያነቃው ሰው :missing የለውም። ለማደስ እንደገና ያንቁ (SEC-01)።',
            'editor_title' => 'የደንብ አርታዒ', 'panel_trigger' => 'ቀስቃሽ', 'panel_conditions' => 'ሁኔታዎች', 'panel_actions' => 'ተግባራት', 'trigger_label' => 'መቼ', 'schedule_label' => 'መርሃ ግብር (cron፣ የተከራይ ሰዓት ዞን፣ ቢያንስ በየ5 ደቂቃው)', 'schedule_preview' => 'ቀጣይ አፈጻጸም፦ :at', 'cooldown_label' => 'በቲኬት የማቀዝቀዣ ጊዜ (ሰከንዶች)', 'run_window_label' => 'የአፈጻጸም መስኮት (አማራጭ)',
            'group_all' => 'ሁሉም እነዚህ', 'group_any' => 'ከእነዚህ ማንኛውም', 'add_condition' => 'ሁኔታ ጨምር', 'add_group' => 'ቡድን ጨምር', 'remove' => 'አስወግድ', 'indent' => 'ወደ ቡድን አስገባ', 'outdent' => 'ከቡድን አውጣ', 'fact' => 'እውነታ', 'operator' => 'ኦፕሬተር', 'value' => 'እሴት', 'keyword_set' => 'የቁልፍ ቃላት ስብስብ', 'keyword_sets_title' => 'የቁልፍ ቃላት ስብስቦች', 'keyword_set_new' => 'አዲስ የቁልፍ ቃላት ስብስብ', 'keyword_set_words' => 'ቁልፍ ቃላት (በየመስመሩ አንድ)', 'keyword_set_retired_warning' => 'ይህ የቁልፍ ቃላት ስብስብ ጡረታ ወጥቷል — ሁኔታው እንደ ሐሰት ይገመገማል።', 'removed_option_warning' => 'ይህ እሴት ከመስኩ ተወግዷል — ሁኔታው ማየት ያስፈልገዋል (EC-08)።',
            'notify_user_ids' => 'የተጠቃሚ መለያዎች (በኮማ የተለዩ)', 'notify_role_ids' => 'የሚና መለያዎች (በኮማ የተለዩ)',
            'add_action' => 'ተግባር ጨምር', 'action_type' => 'ተግባር',
            'action_card' => ['set_status' => 'ሁኔታ አዘጋጅ', 'set_priority' => 'ቅድሚያ አዘጋጅ', 'assign' => 'መድብ (ወረፋ / ወኪል በራውቲንግ)', 'transfer_department' => 'ክፍል አዛውር', 'add_tags' => 'መለያዎች ጨምር', 'remove_tags' => 'መለያዎች አስወግድ', 'set_custom_field' => 'ብጁ መስክ አዘጋጅ', 'send_macro_reply' => 'ማክሮ እንደ ምላሽ ላክ', 'add_note' => 'የውስጥ ማስታወሻ ጨምር', 'notify' => 'ተጠቃሚዎችን / ሚናዎችን አሳውቅ', 'send_sms_template' => 'የSMS አብነት ላክ', 'webhook_out' => 'ዌብሁክ ውጪ (የተፈረመ)', 'run_ai_task' => 'የAI ተግባር አስፈጽም', 'escalate' => 'አሳድግ', 'suppress' => 'ግብይትን አፍን'],
            'sms_template_help' => 'የአብነት ማጣቀሻ ብቻ — ከደንቦች ነጻ-ጽሁፍ SMS የለም (BR-04)።', 'sms_template_missing' => 'ከማስቀመጥዎ በፊት አብነት ይምረጡ።', 'webhook_destination_help' => 'መድረሻዎች በአስተዳዳሪ የሚተዳደረው የፍቃድ ዝርዝር ናቸው፤ URL እዚህ ሊተየብ አይችልም (SEC-02)።', 'destinations_title' => 'የዌብሁክ መድረሻዎች', 'destination_new' => 'መድረሻ ጨምር', 'destination_url' => 'HTTPS URL', 'destination_refused' => 'ውድቅ ተደርጓል፦ :detail', 'destination_auto_disabled' => 'ከ8 ያልተሳኩ አቅርቦቶች በኋላ በራስ-ሰር ተሰናክሏል — ዒላማው ከተስተካከለ በኋላ እንደገና ያንቁ።',
            'ai_task' => ['summarize_thread' => 'ውይይትን አጠቃልል → የውስጥ ማስታወሻ', 'translate_last_message' => 'የመጨረሻውን የደንበኛ መልእክት ተርጉም → የውስጥ ማስታወሻ', 'classify_refresh' => 'ምደባን አድስ (በመጠባበቅ ላይ)'],
            'dry_run_title' => 'የሙከራ አፈጻጸም', 'dry_run_help' => 'የቅርብ ጊዜ ክስተት ይምረጡና የትኞቹ ሁኔታዎች እንደሚዛመዱ፣ የትኞቹ ተግባራት እንደሚሰሩ፣ የትኞቹ ጥበቃዎች እንደሚቆሙ ይመልከቱ — ምንም አይተኮስም።', 'dry_run_pick' => 'የናሙና ክስተት (የመጨረሻዎቹ 7 ቀናት)', 'dry_run_run' => 'የሙከራ አፈጻጸም አስኪድ', 'dry_run_matched' => 'ይህ ደንብ ይዛመድ ነበር', 'dry_run_unmatched' => 'ይህ ደንብ አይዛመድም ነበር', 'dry_run_truth_true' => 'ይዛመዳል', 'dry_run_truth_false' => 'አይዛመድም', 'dry_run_actual' => 'የተገመገመ እሴት፦ :value', 'dry_run_would_run' => 'ይሰራ ነበር', 'dry_run_skipped' => 'ተዘሏል፦ :note', 'dry_run_guard' => 'ጥበቃ፦ :guard',
            'log_title' => 'የአፈጻጸም መዝገብ', 'log_empty' => 'በማቆያ መስኮቱ ውስጥ ምንም አፈጻጸም የለም።', 'log_matched' => 'ተዛምዷል', 'log_unmatched' => 'አልተዛመደም',
        ],
        'sla' => ['title' => 'የSLA ፖሊሲዎች', 'summary' => ':count ፖሊሲዎች · በቅደም ተከተል የመጀመሪያው ተዛማጅ ያሸንፋል', 'new' => '+ አዲስ ፖሊሲ', 'empty' => 'እስካሁን የSLA ፖሊሲዎች የሉም።', 'match_summary' => 'ይዛመዳል፦ :summary', 'match_any' => 'እያንዳንዱ ቲኬት', 'targets' => 'ዒላማዎች', 'priority' => 'ቅድሚያ', 'first_response' => 'የመጀመሪያ ምላሽ', 'next_response' => 'ቀጣይ ምላሽ', 'resolution' => 'መፍትሄ', 'minutes' => 'ደቂቃ', 'clock_mode' => 'ሰዓት', 'clock_calendar' => 'የስራ ሰዓታት (የቀን መቁጠሪያ)', 'clock_24x7' => '24 × 7', 'calendar' => 'የቀን መቁጠሪያ', 'warning_pct' => 'ማስጠንቀቂያ (% ከዒላማ)', 'pause_title' => 'ሰዓቱን አቁም…', 'pause_pending_customer' => 'ደንበኛውን በመጠበቅ ላይ', 'pause_pending_internal' => 'የውስጥ ቡድንን በመጠበቅ ላይ', 'pause_channel_unreachable' => 'ደንበኛው በቻናሉ ላይ ሊደረስበት አይችልም', 'retarget_title' => 'ቅድሚያ በደረጃ መካከል ሲቀየር', 'retarget_keep' => 'የመጀመሪያውን ዒላማ ጠብቅ', 'retarget_remaining' => 'የቀረውን ጊዜ ወደ አዲሱ ዒላማ አስተካክል', 'retarget_explain' => 'የቀረው % ከአሮጌው ዒላማ × አዲሱ ዒላማ (EC-03)። 50 % የቀረው የ40 ደቂቃ ዒላማ በ20 ደቂቃ ዒላማ ስር 10 ደቂቃ ይቀራል።', 'example_title' => 'የቀጥታ ምሳሌ', 'snapshot_notice' => 'የተታጠቁ ቲኬቶች የጀመሩበትን ፖሊሲና የቀን መቁጠሪያ ይጠብቃሉ፤ ይህ ለውጥ ለአዲስ ቲኬቶች ብቻ ይተገበራል (BR-03)።'],
        'hours' => ['title' => 'የስራ ሰዓታት', 'summary' => ':count የቀን መቁጠሪያዎች · የሰዓት ዞን :timezone', 'new' => '+ አዲስ የቀን መቁጠሪያ', 'empty' => 'እስካሁን የቀን መቁጠሪያዎች የሉም — ያለ አንድ፣ SLAዎች 24 × 7 ይሰራሉ።', 'tenant_default' => 'የስራ ቦታ ነባሪ', 'department_override' => 'ለ :department መሻር', 'timezone' => 'የሰዓት ዞን', 'grid_title' => 'ሳምንታዊ ሰዓታት', 'grid_help' => 'በፍርግርጉ ላይ ክፍተቶችን ይሳሉ፣ ወይም ከታች ያለውን ዝርዝር ያርትዑ — ሁለቱም ተመሳሳይ ሰዓታትን ያርትዓሉ።', 'list_title' => 'ክፍተቶች (ተደራሽ አርታዒ)', 'open' => 'ክፍት', 'close' => 'ዝግ', 'add_interval' => 'ክፍተት ጨምር', 'remove_interval' => 'አስወግድ', 'closed_all_day' => 'ዝግ', 'holidays' => 'በዓላት', 'holiday_add' => 'በዓል ጨምር', 'holiday_date' => 'ቀን', 'holiday_name' => 'ስም', 'holiday_recurring' => 'በየዓመቱ', 'prospective_notice' => 'ለውጦች ከአሁን ጀምሮ ለሚፈጠሩ ቲኬቶች ይተገበራሉ፤ የታጠቁ የSLA ሰዓቶች የጀመሩበትን የቀን መቁጠሪያ ይጠብቃሉ (BR-03, EC-05)።', 'diff_title' => 'ከነባሪው ይለያል', 'diff_none' => 'ከስራ ቦታው ነባሪ ጋር ተመሳሳይ።', 'weekday' => ['1' => 'ሰኞ', '2' => 'ማክሰኞ', '3' => 'ረቡዕ', '4' => 'ሐሙስ', '5' => 'ዓርብ', '6' => 'ቅዳሜ', '7' => 'እሁድ']],
        'macros' => ['title' => 'ማክሮዎች እና የተቀመጡ ምላሾች', 'summary' => ':personal የግል · :team የቡድን · :tenant የተጋሩ', 'new' => '+ አዲስ ማክሮ', 'empty' => 'እስካሁን ማክሮዎች የሉም።', 'scope' => 'ወሰን', 'scope_personal' => 'የግል', 'scope_team' => 'ቡድን', 'scope_tenant' => 'የተጋራ (ሁሉም)', 'kind' => 'ዓይነት', 'kind_macro' => 'ማክሮ', 'kind_saved_reply' => 'የተቀመጠ ምላሽ', 'body' => 'አካል', 'variables' => 'ተለዋዋጮች', 'insert_variable' => 'ተለዋዋጭ አስገባ', 'bundled' => 'የታሸጉ ተግባራት (በማስገባትና-መላክ ላይ የሚተገበሩ)', 'usage' => ':count ጊዜ ጥቅም ላይ ውሏል', 'preview_title' => 'ከናሙና ቲኬት ጋር ቅድመ-እይታ', 'preview_ticket' => 'የናሙና ቲኬት', 'preview_run' => 'ቅድመ-እይታ', 'preview_unresolved' => 'ያልተፈቱ፦ :variables — ይህ ማክሮ እንዳለ ሊላክ አይችልም (T-06)።', 'preview_clean' => 'እያንዳንዱ ተለዋዋጭ ይፈታል።'],
        'fields' => ['title' => 'ብጁ መስኮች', 'summary' => ':ticket የቲኬት መስኮች · :customer የደንበኛ መስኮች', 'new' => '+ አዲስ መስክ', 'tab_ticket' => 'የቲኬት መስኮች', 'tab_customer' => 'የደንበኛ መስኮች', 'entity' => 'አካል', 'key' => 'ቁልፍ (የማይለወጥ)', 'label' => 'መለያ', 'type' => 'ዓይነት', 'type_locked' => 'ዓይነት ተቆልፏል', 'validation' => 'ማረጋገጫ', 'agent_editable' => 'ወኪሎች ማርትዕ ይችላሉ', 'customer_visible' => 'ለደንበኛ የሚታይ', 'options' => 'አማራጮች', 'option_value' => 'እሴት', 'option_label' => 'መለያ', 'option_add' => 'አማራጭ ጨምር', 'option_remove' => 'አማራጭ አስወግድ', 'option_stability_warning' => 'አማራጭ ማስወገድ መለያውን በያለፉ መልሶች ላይ ይጠብቃል፤ የሚያመለክተውን ማንኛውንም ደንብ ያሳያል (EC-08)።', 'retired_section' => 'ጡረታ የወጡ መስኮች', 'deactivate' => 'አቦዝን', 'reactivate' => 'እንደገና አንቃ', 'deactivate_confirm' => '":label" ይቦዝን? የሚያነቡት ደንቦች እንደ ባዶ ይገመግማሉ፤ የሚጠቀሙት ቅጾች ከሚቀጥለው ህትመታቸው በፊት መስተካከል አለባቸው። የተቀመጡ እሴቶች ይቆያሉ (EC-14)።', 'deactivate_verb' => 'መስክ አቦዝን', 'where_used_title' => 'የት ጥቅም ላይ ውሏል', 'where_used_forms' => 'ቅጾች', 'where_used_rules' => 'ደንቦች', 'where_used_views' => 'እይታዎች / ማጣሪያዎች', 'where_used_none' => 'እስካሁን የትም ጥቅም ላይ አልዋለም።'],
        'forms' => ['title' => 'ቅጾች', 'summary' => ':count ቅጾች', 'new' => '+ አዲስ ቅጽ', 'empty' => 'እስካሁን ቅጾች የሉም።', 'builder_title' => 'የቅጽ ገንቢ', 'palette' => 'የመስክ ዓይነቶች', 'palette_existing' => 'ነባር ብጁ መስኮች', 'canvas' => 'ደረጃዎች', 'step' => 'ደረጃ :n', 'add_step' => 'ደረጃ ጨምር', 'add_field' => ':type መስክ ጨምር', 'place_menu' => 'አስቀምጥ…', 'inspector' => 'መስክ', 'label_per_language' => 'መለያ (:language)', 'help_per_language' => 'የእገዛ ጽሁፍ (:language)', 'required' => 'ግዴታ', 'required_when_visible' => 'ሲታይ ግዴታ', 'conditional' => 'አሳይ መቼ', 'conditional_sentence' => 'አሳይ :field :op :value ሲሆን', 'completeness' => 'ከ:total ቋንቋዎች :done ተጠናቅቀዋል', 'preview_title' => 'የቀጥታ ቅድመ-እይታ', 'preview_widget' => 'ዊጀት', 'preview_telegram' => 'ቴሌግራም', 'preview_language' => 'የቅድመ-እይታ ቋንቋ', 'publish' => 'አትም', 'publish_confirm' => 'የ":name" ስሪት :version ይታተም? በመሂድ ላይ ያሉ የቴሌግራም ፍሰቶች በአሁኑ ስሪታቸው ይጠናቀቃሉ፤ አዲስ ማስረከቢያዎች ይህን ይጠቀማሉ (EC-06)።', 'publish_verb' => 'ቅጽ አትም', 'publish_blocked' => 'እነዚህ እስኪስተካከሉ ድረስ ማተም ታግዷል፦', 'usage_banner' => 'ጥቅም ላይ ውሏል በ', 'history' => 'የስሪት ታሪክ', 'version' => 'ስሪት :version', 'diff_added' => 'ተጨምሯል፦ :fields', 'diff_removed' => 'ተወግዷል፦ :fields', 'diff_changed' => 'ተቀይሯል፦ :fields', 'schema_json' => 'ትርጓሜ (JSON)'],
        'csat' => ['title' => 'የCSAT ዳሰሳዎች', 'summary' => 'በአንድ መፍትሄ አንድ ጥያቄ — ማቀዝቀዣ :cooldown ሰዓት፣ መስኮት :window ሰዓት', 'cooldown' => 'ለተመሳሳይ ደንበኛ በዳሰሳዎች መካከል ማቀዝቀዣ (ሰዓታት)', 'window' => 'የምላሽ መስኮት (ሰዓታት)', 'comment_prompt' => 'ከኮከብ ደረጃ በኋላ አስተያየት ጠይቅ', 'labels_title' => 'በቋንቋ የአዝራር መለያዎች', 'labels_help' => 'ለደንበኛ የሚታዩት መለያዎች በእንግሊዝኛና በአማርኛ ይላካሉ፤ የኦፕሬተር መሻሮች በቁልፍ የነጭ-መለያ ቅንብሮች ማያ (Doc 16) ናቸው።', 'event_note' => 'እያንዳንዱ ዳሰሳ ለሪፖርት csat.sent / csat.received / csat.expired ያወጣል (Doc 15)።'],
    ],
];
