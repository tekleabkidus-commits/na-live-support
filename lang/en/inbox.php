<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Doc 07 — Unified Inbox strings (§14: everything under inbox.*, en + am)
|------------------------------------------------------------------------------
| Skeleton laid at S07 step 1 with the keys the registries already name;
| every later step adds its surface's keys HERE and in lang/am/inbox.php in
| the same commit (string-lint enforces; en/am parity asserted). All Amharic
| is machine-authored and joins the standing R-14 native-review queue.
*/

return [
    'notify' => [
        'assignment' => 'Ticket assigned to me',
        'mention' => 'Mentioned in an internal note',
        'follower' => 'Activity on a followed ticket',
        'escalation' => 'Ticket escalated to me',
        'sla_warning' => 'SLA warning on my ticket',
        'window_closing' => 'Reply window closing on my ticket',
        'bulk_completed' => 'Bulk operation completed',
    ],
    'feature' => [
        'voice_recording' => 'Composer voice recording',
    ],
    'selection' => [
        'empty' => 'Nothing to select — this view is empty.',
        'consumed' => 'This selection was already executed — reselect to run another action.',
    ],
    'shell' => [
        'skip_to_list' => 'Skip to ticket list',
        'skip_to_thread' => 'Skip to conversation',
        'skip_to_context' => 'Skip to ticket context',
        'region_views' => 'Views',
        'region_list' => 'Ticket list',
        'region_thread' => 'Conversation',
        'region_context' => 'Ticket context',
        'collapse_rail' => 'Collapse views rail',
        'expand_rail' => 'Expand views rail',
        'collapse_context' => 'Collapse context panel',
        'expand_context' => 'Expand context panel',
        'resize_list' => 'Resize ticket list (arrow keys)',
        'open_views' => 'Open views',
        'back_to_list' => 'Back to list',
        'open_context' => 'Open ticket context',
        'list_placeholder' => 'Select a view to load tickets.',
        'thread_placeholder' => 'Select a ticket to open the conversation.',
    ],
    'topbar' => [
        'search_placeholder' => 'Search tickets, customers, messages',
        'search_label' => 'Search',
        'new_ticket' => 'New ticket',
        'notifications' => 'Notifications',
        'notifications_count' => 'Notifications, :count unread',
        'profile' => 'Profile menu',
        'status_label' => 'Agent status',
        'status_online' => 'Online',
        'status_away' => 'Away',
    ],
    'health' => [
        'label' => 'Channel health',
        'all_healthy' => 'All channels healthy',
        'degraded' => 'A channel is degraded',
        'down' => 'A channel is down',
        'unknown' => 'Channel health unknown',
        'banner_title' => 'Channel status',
        'banner_close' => 'Close channel status',
    ],
    'views' => [
        'my_open' => 'My Open',
        'unassigned' => 'Unassigned',
        'mentions' => 'Mentions',
        'following' => 'Following',
        'sla_at_risk' => 'SLA at risk',
        'expiring_windows' => 'Expiring windows',
        'rescue' => 'Rescue',
        'section_system' => 'System',
        'section_queues' => 'Queues',
        'section_shared' => 'Shared',
        'section_mine' => 'Mine',
        'count_label' => ':view, :count tickets',
    ],
    'tabs' => [
        'ticket' => 'Ticket',
        'customer' => 'Customer',
        'copilot' => 'Copilot',
        'customer_badge' => 'Customer tab has an alert',
        'copilot_badge' => 'Copilot has a new suggestion',
        'ticket_slot' => 'Ticket properties load with the ticket.',
        'customer_slot' => 'Customer profile arrives with Doc 08.',
        'copilot_slot' => 'Copilot arrives with Doc 09.',
    ],
    'density' => [
        'label' => 'Display density',
        'comfortable' => 'Comfortable',
        'compact' => 'Compact',
    ],
];
