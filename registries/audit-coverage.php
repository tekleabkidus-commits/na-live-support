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

    /*
    |--------------------------------------------------------------------------
    | Doc 02 §4.4 — the password lifecycle (FR-14–17, SEC-02, EC-04, EC-11)
    |--------------------------------------------------------------------------
    |
    | Written per ACCOUNT, in that account's tenant: a reset request for an
    | address in three tenants leaves three trails, each visible to its own
    | operator and no other (the multi-candidate rule's audit shape). Nothing
    | here carries the token, its hash, or the address (SEC-08).
    */

    [
        'action_key' => 'auth.password.reset_requested',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.password.reset_completed',
        'entity' => 'user',
        // 'origin' distinguishes nothing today (always reset) but keeps the
        // three PasswordChanger doors symmetric in exports.
        'actor_types' => ['staff'],
        'required_fields' => ['origin', 'ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.password.changed',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['origin', 'ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.invite.issued',
        'entity' => 'user',
        // 'system' until Doc 03's admin surface exists to supply a staff actor;
        // the service accepts an actorId and records 'staff' the day it is wired.
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.invite.resent',
        'entity' => 'user',
        // 'system': EC-04's resend is requested by an UNAUTHENTICATED person;
        // recording them as a staff actor would fabricate a principal.
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ip'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.invite.accepted',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['origin', 'ip'],
        'owning_doc' => 'Doc02',
    ],

    /*
    |--------------------------------------------------------------------------
    | Doc 02 §4.5 — API tokens (FR-18/FR-19/FR-20, T-05, BR-03, BR-04)
    |--------------------------------------------------------------------------
    |
    | BR-03 is why every actor type here is 'staff': a machine token's actions
    | are attributed to its visible service-account USER, which audits exactly
    | like a human — 'system' never appears on a token action, by rule.
    | The payloads carry name/abilities here and NOT on the bus (step-9 ruling:
    | events are identifier-only; this table is tenant-scoped and
    | access-controlled, the bus is neither).
    */

    [
        'action_key' => 'auth.token.created',
        'entity' => 'personal_access_token',
        'actor_types' => ['staff'],
        // `kind` is personal|machine; a rotation additionally records
        // `rotation_of` naming the predecessor (FR-19 "all audited").
        'required_fields' => ['kind', 'name', 'abilities'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.token.revoked',
        'entity' => 'personal_access_token',
        // reason: self_revoked | revoked_by_admin | user_disabled (BR-04).
        'actor_types' => ['staff'],
        'required_fields' => ['reason'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.token.scope_denied',
        'entity' => 'personal_access_token',
        // FR-20/AC-05: which permission the T-05 intersection lacked. user_id
        // duplicates the actor id so the entry survives actor-column reshaping
        // in Doc 17's export (same duplication as the session entries).
        'actor_types' => ['staff'],
        'required_fields' => ['permission', 'user_id'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.service_account.created',
        'entity' => 'user',
        // Q-B: created (find-or-create) inside the machine-token store, by the
        // admin the route authenticated.
        'actor_types' => ['staff'],
        'required_fields' => ['name'],
        'owning_doc' => 'Doc02',
    ],
    [
        'action_key' => 'auth.user.disabled',
        'entity' => 'user',
        // 'system' until Doc 03's admin surface exists to supply a staff actor
        // (the same posture as auth.invite.issued); UserDisabler records
        // 'staff' whenever an actorId is passed.
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['revoked_token_count'],
        'owning_doc' => 'Doc02',
    ],

    [
        // SEC-05 (S19): the dedicated allowlist-violation entry beside the
        // generic auth.login.failed(reason=ip_blocked) row.
        'action_key' => 'auth.ip.blocked',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['ip'],
        'owning_doc' => 'Doc02',
    ],

    /*
    |--------------------------------------------------------------------------
    | Doc 03 — RBAC (FR-12; S19). Audit carries the OLD/NEW payloads the
    | events deliberately omit (SEC-07 keeps diffs out of the 13-month
    | stream; the append-only audit table is their home).
    */

    [
        'action_key' => 'rbac.role.created',
        'entity' => 'role',
        // 'system': the FR-10 provisioning seeder creates the nine templates.
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['new'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.role.updated',
        'entity' => 'role',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.role.deleted',
        'entity' => 'role',
        'actor_types' => ['staff'],
        'required_fields' => ['old'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.user.role_changed',
        'entity' => 'user',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['old_role_id', 'new_role_id'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.override.added',
        'entity' => 'user',
        // SEC-04: the mandatory ≥10-char reason is audited here.
        'actor_types' => ['staff'],
        'required_fields' => ['permission', 'effect', 'expires_at', 'reason'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.override.removed',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['permission'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.override.expired',
        'entity' => 'user',
        // The cleanup pass, never a person (FR-09 — resolver is enforcement
        // truth; this only tidies).
        'actor_types' => ['system'],
        'required_fields' => ['permission'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.highrisk.granted',
        'entity' => 'permission_grant',
        'actor_types' => ['staff'],
        'required_fields' => ['permission', 'subject_type', 'subject_id', 'pending'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.highrisk.confirmed',
        'entity' => 'permission_grant',
        // AC-05/R-9: decided_by <> requested_by is a table constraint; the
        // audit entry names both.
        'actor_types' => ['staff'],
        'required_fields' => ['permission', 'requested_by'],
        'owning_doc' => 'Doc03',
    ],
    [
        'action_key' => 'rbac.highrisk.rejected',
        'entity' => 'permission_grant',
        'actor_types' => ['staff'],
        'required_fields' => ['permission', 'requested_by'],
        'owning_doc' => 'Doc03',
    ],
    [
        // FR-11: inbound on a blocked conversation is stored quarantined with NO
        // fan-out except this entry — the audit trail is the only trace until
        // Doc 06's spam disposition reads the flag.
        'action_key' => 'conversation.message_quarantined',
        'entity' => 'message',
        'actor_types' => ['customer'],
        'required_fields' => ['conversation_id', 'message_id'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FR-15's 10-min cap fired: a stuck head was demoted and the lane
        // drained past it — an accepted out-of-order risk worth an audit trail
        // (interpretation A-4 names the event and requires this entry).
        'action_key' => 'conversation.lane_skipped',
        'entity' => 'conversation',
        'actor_types' => ['system'],
        'required_fields' => ['conversation_id', 'message_id'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FR-17: internal notes are state-mutating staff actions; the note body
        // stays in the row — the audit entry carries identifiers only.
        'action_key' => 'conversation.note_added',
        'entity' => 'message',
        'actor_types' => ['staff'],
        'required_fields' => ['conversation_id', 'message_id'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FR-21/FR-22: every edit audited, customer- and staff-originated both.
        'action_key' => 'conversation.message_edited',
        'entity' => 'message',
        'actor_types' => ['customer', 'staff'],
        'required_fields' => ['conversation_id', 'message_id'],
        'owning_doc' => 'Doc04',
    ],
    [
        'action_key' => 'conversation.message_deleted',
        'entity' => 'message',
        'actor_types' => ['customer', 'staff'],
        'required_fields' => ['conversation_id', 'message_id'],
        'owning_doc' => 'Doc04',
    ],
    [
        // AC-06: ACCESS to preserved history is itself the audited mutation of
        // record — who read the disputed content, and when.
        'action_key' => 'conversation.history_viewed',
        'entity' => 'message',
        'actor_types' => ['staff'],
        'required_fields' => ['conversation_id', 'message_id'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FR-24/SEC-05: exports carry who, what format, and whether notes rode
        // along (the double-confirmed case a dispute review will ask about).
        'action_key' => 'conversation.transcript_exported',
        'entity' => 'conversation',
        'actor_types' => ['staff'],
        'required_fields' => ['conversation_id', 'format', 'notes_included'],
        'owning_doc' => 'Doc04',
    ],
];
