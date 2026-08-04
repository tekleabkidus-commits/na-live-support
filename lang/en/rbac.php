<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| RBAC strings — English (Doc 03 T-02, SEC-02, §14; C-08; N-03)
|------------------------------------------------------------------------------
*/

return [
    // The staff 403's detail (T-02's diagram: the deny NAMES the key for
    // staff clients — an authenticated colleague learning what their own role
    // lacks, never an enumeration surface).
    'denied' => 'Your role does not include the :permission permission.',

    // §8 warnings and error states (B6-S07..S10 draw these).
    'warning' => [
        'soft_limit' => 'You have more roles than we would recommend. Consider consolidating before creating another.',
        'override_sprawl' => 'More than the recommended number of people carry an exception for this permission — that is usually the signal to create a role.',
    ],
    'blocked' => [
        'last_admin' => 'This is the last person who can manage roles and users. Give someone else those rights first.',
        'role_assigned' => 'This role is assigned to :count person(s) right now. Move them to another role first.',
    ],
    'conflict' => [
        'role_edited' => 'Someone else changed this role while you were editing it. Review their changes and re-apply yours.',
    ],

    // ── The four B6 screens' copy (S19 step 9). Values feed Inertia `copy`
    // props server-side; the drawn frames' wording is the reference.
    'ui' => [
        'shell' => [
            'tenant_logo' => 'Tenant logo',
            'search' => 'Search…',
            'notifications' => 'Notifications',
            'online' => 'Online',
            'backoffice' => 'Backoffice',
            'people' => 'People',
            'users' => 'Users',
            'roles' => 'Roles',
        ],
        'roles' => [
            'title' => 'Roles & permissions',
            'breadcrumb' => 'People · Roles',
            'create' => 'New role',
            'clone' => 'Clone',
            'compare' => 'Compare',
            'delete' => 'Delete role',
            'edit' => 'Edit',
            'col_role' => 'Role',
            'col_description' => 'Description',
            'col_people' => 'People',
            'col_high_risk' => 'High-risk',
            'col_updated' => 'Updated',
            'seeded' => 'SEEDED',
            'empty' => 'You have the nine we seeded, and nothing of your own yet',
            'empty_hint' => 'Clone a template to start from something close, or build a role from a blank matrix.',
            'delete_confirm_title' => 'Delete “:name”?',
            'delete_confirm_body' => 'Nobody holds this role. Deleting it keeps its history and frees its name.',
            'delete_blocked_title' => 'Blocked — :count people have this role right now',
            'delete_blocked_move' => 'Move these :count people →',
            'soft_limit_title' => ':count roles — more than we would recommend',
            'high_risk_badge' => 'high-risk',
            'cancel' => 'Cancel',
            'confirm_delete' => 'Delete',
            'phone_notice' => 'Editing requires a larger screen',
        ],
        'editor' => [
            'breadcrumb' => 'People · Roles',
            'scope_title' => 'What can they see?',
            'scope_own' => 'Own',
            'scope_team' => 'Team',
            'scope_dept' => 'Department',
            'scope_all' => 'All',
            'scope_helper' => 'People with this role can see :module belonging to: :scope.',
            'search' => 'Filter permissions… ( / )',
            'summary' => ':permissions permissions · :high high-risk · :people people affected',
            'save' => 'Save role',
            'saving' => 'Saving…',
            'unsaved_title' => 'You have unsaved changes',
            'unsaved_body' => 'Leaving now discards them.',
            'unsaved_stay' => 'Keep editing',
            'unsaved_leave' => 'Discard changes',
            'impact_title' => 'Save “:name”?',
            'impact_added' => 'Added',
            'impact_removed' => 'Removed',
            'impact_people' => ':count people are affected right now.',
            'impact_confirm' => 'Save changes',
            'pending_note' => 'High-risk additions wait for a second admin (four-eyes is on): :keys',
            'select_all' => 'All of :module',
            'phone_notice' => 'Editing requires a larger screen',
            'phone_notice_body' => 'The matrix has :count checkboxes; on a phone this screen is read-only on purpose.',
            'keyboard_hint' => 'Arrows move · Space toggles · / finds',
            'loading' => 'Loading the matrix…',
            'error_reload' => 'Something went wrong — reload to continue.',
            'conflict_reapply' => 'Re-apply my change',
        ],
        'overrides' => [
            'title' => 'Overrides & effective permissions',
            'breadcrumb' => 'People · Users',
            'current_role' => 'Current role',
            'explorer_label' => 'Check any permission',
            'explorer_placeholder' => 'Type a permission key…',
            'verdict_allowed_role' => 'Allowed — from the role',
            'verdict_allowed_override' => 'Allowed — user exception',
            'verdict_denied_override' => 'Denied — user exception',
            'verdict_denied_implicit' => 'Denied — not granted',
            'verdict_by' => 'set by :name',
            'verdict_expires' => 'expires :date',
            'active_title' => 'Active exceptions',
            'empty' => 'No exceptions for this user.',
            'add' => 'Add an exception',
            'effect_allow' => 'Allow',
            'effect_deny' => 'Deny',
            'expiry_label' => 'Expires (optional)',
            'reason_label' => 'Why? (required, at least 10 characters)',
            'submit' => 'Add exception',
            'remove' => 'Remove',
            'expires_chip' => 'expires in',
            'pending_note' => 'High-risk allow — waiting for a second admin.',
        ],
        'pending' => [
            'title' => 'Waiting for approval',
            'breadcrumb' => 'People · Roles',
            'waiting_chip' => 'Awaiting approval',
            'requested_line' => ':name requested a high-risk permission',
            'self_expires' => 'Removes itself in :time',
            'inert_note' => 'This permission is not in effect yet. If you do nothing, nobody gains any new access.',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'decision_recorded' => 'Your decision is recorded in your name.',
            'why_title' => 'Why a second person',
            'why_body' => 'High-risk permissions are never granted by one person alone. The requester cannot approve their own request, so at least two approvers must always exist.',
            'nobody_title' => 'If nobody answers',
            'nobody_body' => 'The request removes itself within 24 hours. It never lingers undecided; the requester can ask again.',
            'empty' => 'Nothing is waiting for approval.',
            'affects' => 'affects :count people',
        ],
    ],
];
