<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 13 — Web Chat Widget strings (SESSION-13). Every user-visible widget
| string is a key here (N-03, C-08); en + am from birth. Customer-facing keys
| under `widget.*` are swept by the failure-vocabulary gate (R-D13-5.1) from
| step 6 on. Machine-authored Amharic joins the R-14 queue.
|------------------------------------------------------------------------------
*/

return [
    'feature' => [
        'voice_recording' => 'Widget voice notes',
    ],
    'identity' => [
        // EC-04: the STAFF-visible internal note on a site identity switch (never rendered to the visitor).
        'switched_note' => 'Site identity changed: the site now asserts :asserted (was :previous). Nothing was re-linked — review and merge by hand if these are one person.',
    ],
    // STOP-5A (a): the STAFF-visible system lines a pre-chat phone drops (never rendered to the visitor;
    // the phone is a staff-collected value — an identity only through Doc 08's FR-09 staff door).
    'prechat' => [
        'phone_provided' => 'Phone provided in the pre-chat form: :phone (staff-collected, not linked). Link it from the profile if it is theirs.',
        'phone_conflict' => 'Phone provided in the pre-chat form: :phone — this number already sits on another customer. Nothing was attached; review both profiles before linking.',
    ],
    // ── The PANEL's customer-facing copy (S13 step 8; every key swept by the vocabulary gate) ──
    'tabs' => ['home' => 'Home', 'messages' => 'Messages', 'help' => 'Help'],
    'launcher' => [
        'open' => 'Open support chat',
        'close' => 'Close support chat',
        'unread' => '{1} :count unread|[2,*] :count unread',
    ],
    'header' => [
        'assistant' => 'Support assistant',
        'team' => 'Support',
        'connecting' => 'Connecting you to a specialist',
        'minimize' => 'Minimize',
        'close' => 'Close',
        'menu' => 'More options',
    ],
    'overflow' => [
        'sound_on' => 'Sound on',
        'sound_off' => 'Sound off',
        'language' => 'Language',
        'transcript' => 'Download transcript',
        'privacy' => 'Privacy',
    ],
    'home' => [
        'greeting' => 'Hello 👋 Ask us anything about your account, deposits or withdrawals.',
        'greeting_off_hours' => 'Leave a message now and we will reply in the morning.',
        'band_short' => 'Typically replies in a few minutes',
        'band_medium' => 'Typically replies within the hour',
        'band_long' => 'Typically replies within a few hours',
        'band_off_hours' => 'Our team opens at :time',
        'quick_answers' => 'Quick answers',
        'resume' => 'Continue where you left off',
        'resume_meta' => ':who replied :when · :count messages',
        'external' => 'opens the site',
        'no_account' => 'No account needed — start typing and we will pick it up.',
    ],
    'messages' => [
        'empty_title' => 'No messages yet',
        'empty_body' => 'Ask us anything — or start with one of these.',
        'load_earlier' => 'Load earlier messages',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'ai_label' => 'AI assistant',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'sending' => 'Sending',
        'joined' => ':name joined',
        'handoff' => 'Connecting you to a specialist — they can see your conversation, so you will not need to repeat anything.',
        'deleted' => 'Message deleted',
    ],
    'composer' => [
        'placeholder' => 'Write a message…',
        'send' => 'Send',
        'attach' => 'Attach',
        'paused' => 'Messaging is paused for this conversation.',
        'offline' => 'You are offline — messages will send when you reconnect',
        'offline_hint' => 'Keep typing — it will send',
        'queued' => 'Sending when you are back',
    ],
    'queue' => [
        'position' => '#:n in line',
        'waiting' => 'Waiting for the next available agent',
        'band_short' => 'about 5 min',
        'band_medium' => 'about an hour',
        'band_long' => 'a few hours',
        'usually' => 'Usually about :band at this time of day.',
        'hold' => 'You can keep the page open or close it — we will hold your place either way.',
        'announce' => 'You are number :n in line',
    ],
    'csat' => [
        'question' => 'How did we do?',
        'optional' => 'Optional — closing this is fine too.',
        'star' => ':n of 5',
        'thanks' => 'Thank you.',
    ],
    'notify' => [
        'title' => 'Get a heads-up when we reply?',
        'body' => 'We can play a sound and show a notification while you are on another tab.',
        'yes' => 'Yes, notify me',
        'no' => 'Not now',
    ],
    'retry' => [
        'title' => 'Chat is not loading',
        'body' => 'Something on our side is down. Nothing you typed has been lost.',
        'action' => 'Try again',
        'urgent' => 'Urgent? The support number is on the payments page.',
    ],
    'a11y' => [
        'dialog' => 'Support chat',
        'log' => 'Conversation',
        'tabs' => 'Sections',
        'new_customer' => 'You wrote: :text',
        'new_staff' => ':name wrote: :text',
        'new_ai' => 'AI assistant wrote: :text',
        'new_system' => ':text',
        'typing' => ':name is typing',
        'attachment' => 'Attachment',
    ],
    // FR-06/FR-15 inline refusals (R-D13-5.1: calm, no failure vocabulary).
    'form' => [
        'required' => 'This one is needed to continue.',
        'invalid_phone' => 'Please enter a phone number we can reach, like +251 9xx xxx xxx.',
        'invalid_email' => 'Please check the email address.',
        'invalid_number' => 'Please enter a number.',
        'invalid_choice' => 'Please pick one of the options.',
        'invalid_file' => 'Please attach the file again.',
        'invalid_text' => 'Please enter some text.',
        'too_long' => 'That is a bit long — please shorten it.',
        'invalid_format' => 'Please check the format.',
        'unknown_step' => 'This step is no longer part of the form.',
        'refused' => 'A few answers need attention.',
    ],
];
