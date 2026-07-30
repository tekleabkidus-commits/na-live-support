<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Audit-coverage matrix (CLAUDE.md §2, Doc 17)
|------------------------------------------------------------------------------
|
| Every state-mutating action in the platform must have an entry here; a
| mutation without one fails the `audit-coverage` CI gate. Doc 17 owns the
| audit engine; every module registers its own actions as it lands.
|
| Entry schema (all fields required):
|   action_key      string  dot-namespaced, e.g. "ticket.status_changed"
|   entity          string  the audited aggregate, e.g. "ticket"
|   actor_types     list<string>  non-empty subset of: "staff" | "customer" | "system" | "platform_admin"
|   required_fields list<string>  payload fields the audit entry must carry,
|                                 e.g. ["old_status", "new_status"]
|   owning_doc      string  "DocNN"
|
*/

return [
    [
        'action_key' => 'tenant.provisioned',
        'entity' => 'tenant',
        'actor_types' => ['system', 'platform_admin'],
        // FR-06 lists what provisioning creates; the audit entry records that the
        // whole set landed, so "was this tenant fully provisioned" is answerable
        // from the trail rather than by inspecting eight tables.
        'required_fields' => ['steps_completed'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.provisioning_step_failed',
        'entity' => 'tenant',
        'actor_types' => ['system'],
        // FS-04 / §9: the console shows the failed step and an error reference.
        // Both are audited so a retried provisioning has a history, not just a
        // final state.
        'required_fields' => ['step', 'error_ref'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.state_changed',
        'entity' => 'tenant',
        // No 'staff': FR-05 state changes are platform actions. An operator's own
        // admin cannot suspend or archive their tenant.
        'actor_types' => ['system', 'platform_admin'],
        'required_fields' => ['old_state', 'new_state'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.setting_changed',
        'entity' => 'tenant_setting',
        'actor_types' => ['staff', 'system', 'platform_admin'],
        // FR-07: "audit entry on every change (old → new)". Both values are
        // required fields precisely so that a change cannot be audited as merely
        // having happened.
        'required_fields' => ['key', 'old_value', 'new_value'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.setting_write_conflict',
        'entity' => 'tenant_setting',
        'actor_types' => ['staff', 'system', 'platform_admin'],
        // EC-06: two admins race, last write wins, and BOTH writes are audited.
        // The loser's attempt is a separate entry — otherwise the trail would show
        // one change where a human made two, and the conflict toast would be the
        // only record that the other edit ever existed.
        'required_fields' => ['key', 'attempted_value', 'winning_value'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.quota_changed',
        'entity' => 'tenant',
        'actor_types' => ['platform_admin'],
        // EC-09: lowering a quota below current usage takes no destructive action,
        // so the audit entry is the only evidence of what changed and when.
        'required_fields' => ['quota', 'old_value', 'new_value'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.key_created',
        'entity' => 'tenant_key',
        'actor_types' => ['staff', 'platform_admin'],
        // SEC-03: plaintext is shown once and never stored, so the audit trail
        // records the key's identity (prefix, kind) and never its secret.
        'required_fields' => ['kind', 'prefix'],
        'owning_doc' => 'Doc01',
    ],
    [
        'action_key' => 'tenant.key_revoked',
        'entity' => 'tenant_key',
        'actor_types' => ['staff', 'platform_admin', 'system'],
        // 'system' because archiving a tenant revokes every key (FR-05).
        'required_fields' => ['kind', 'prefix'],
        'owning_doc' => 'Doc01',
    ],
];
