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

    /*
    |--------------------------------------------------------------------------
    | Doc 02 — Authentication, Sessions & Security Core
    |--------------------------------------------------------------------------
    |
    | THESE WRITE SYNCHRONOUSLY (owner ruling; Doc 17 FR-02). Auth events are the
    | one class written straight to the append-only audit table rather than
    | through the outbox: an authentication decision must leave a record even if
    | the queue is dead, and FS-01 already says staff auth fails CLOSED when
    | infrastructure is down — a trail that depends on a working relay would go
    | silent in exactly the incident it exists to reconstruct. AuditLogger's
    | direct insert is that path; it shares schema and insert code with the
    | future outbox consumer, so Doc 17 changes nothing here.
    |
    | Payload fields carry NO credential material and no email (SEC-08): the
    | audit trail is access-controlled and tenant-scoped, but it is also
    | exportable (US-06), and an exported trail full of addresses is a breach
    | waiting for a misdirected file.
    */

    [
        'action_key' => 'auth.login.succeeded',
        'entity' => 'user',
        'actor_types' => ['staff'],
        // FR-04 names exactly what a successful login records. `ip` and
        // `user_agent` are here rather than in the event payload because this
        // table is tenant-scoped and access-controlled; the bus is neither.
        'required_fields' => ['ip', 'device_id', 'new_device'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.login.failed',
        'entity' => 'user',
        // 'system': a failure against a NON-EXISTENT account has no staff actor
        // to attribute, and inventing one would put a fictional principal in the
        // trail.
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['reason', 'ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.logout',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.lockout.locked',
        'entity' => 'user',
        // 'system': the lock is applied BY the platform in response to attempts,
        // not by any principal. The person making the attempts is, by
        // definition, not authenticated.
        'actor_types' => ['system'],
        // AC-02 wants the trail to answer "why is this account locked" without a
        // log dive. Never the credential that was tried (SEC-08).
        'required_fields' => ['reason', 'failed_attempts'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.lockout.unlocked',
        'entity' => 'user',
        // 'staff' for AC-02's audited admin unlock; 'system' for the EC-11
        // reset path, where no admin is involved and inventing one would be a
        // lie in the trail.
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['reason'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.session.revoked',
        'entity' => 'user',
        // 'staff' when an operator revokes from the devices screen (FR-12);
        // 'system' for the cap eviction (FR-10) and the tenant-suspension sweep
        // (Doc 01 EC-03), where no human pressed anything.
        'actor_types' => ['staff', 'system', 'platform_admin'],
        'required_fields' => ['reason', 'session_id'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.session.revoked_others',
        'entity' => 'user',
        // FR-12's bulk action, audited ONCE per press — including the empty
        // case, where no per-session auth.session.revoked entry exists to
        // carry the fact that the button was pressed at all.
        'actor_types' => ['staff'],
        'required_fields' => ['count', 'reason'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.2fa.enrolment_started',
        'entity' => 'user',
        // A pending secret was written. Not yet a security change (the account
        // still logs in with password alone until confirm), but a mutation the
        // trail must be able to place before the auth.2fa.enabled that follows.
        'actor_types' => ['staff'],
        'required_fields' => ['enrolment_id'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.2fa.enabled',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['enrolment_id'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.2fa.codes_regenerated',
        'entity' => 'user',
        // FR-07's banner path: a fresh batch supersedes the old one. The batch
        // rows themselves are the durable record (no DELETE grant); this entry
        // is the who-and-when.
        'actor_types' => ['staff'],
        'required_fields' => ['count'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.2fa.disabled',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => [],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.2fa.reset_by_admin',
        'entity' => 'user',
        // FR-08's high-visibility entry: the ADMIN is the actor, the target is
        // the entity, and the payload names the admin again so an export that
        // flattens actor columns still carries who did it.
        'actor_types' => ['staff'],
        'required_fields' => ['admin_id'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.2fa.challenge_failed',
        'entity' => 'user',
        // Per-attempt trace (FR-07); the fifth attempt's void reaches lockout
        // through auth.lockout.locked when it tips the counter over.
        'actor_types' => ['staff'],
        'required_fields' => ['attempt'],
        'owning_doc' => 'Doc02',
    ],
];
