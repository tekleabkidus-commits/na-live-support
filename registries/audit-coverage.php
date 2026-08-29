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
    // ── Doc 08 (Customers) — registered at S08 step 4, same-commit as the
    //    RgFlagService that emits them (SEC-02: high-visibility, reasons
    //    mandatory both directions).
    [
        'action_key' => 'customer.identity.linked',
        'entity' => 'customer',
        // channel_auto rows are written by the resolver (system); staff adds
        // phone/email by hand (FR-09).
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['type'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.identity.unlinked',
        'entity' => 'customer',
        // FR-10: high-risk, human-only, reason mandatory (SEC-02); the entry
        // carries the MOVED COUNTS so the audit trail answers "what did that
        // unlink actually take" without replaying the move-set query.
        'actor_types' => ['staff'],
        'required_fields' => ['reason', 'type', 'new_customer_id', 'moved_conversations', 'moved_tickets'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.merged',
        'entity' => 'customer',
        // FR-11/SEC-02: human-only consolidation, reason mandatory; the entry
        // carries the snapshot id + moved counts so the trail answers "what
        // did that merge take" without replaying the plan.
        'actor_types' => ['staff'],
        'required_fields' => ['reason', 'snapshot_id', 'merged_id', 'moved_conversations', 'moved_tickets'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.unmerged',
        'entity' => 'customer',
        // FR-13: audited both ways (SEC-02's plain reading — reason mandatory
        // on the undo too; the D09 frame draws no reason input, filed for the
        // step-11 frame mapping, the document wins).
        'actor_types' => ['staff'],
        'required_fields' => ['reason', 'snapshot_id', 'restored_id'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.merge_compensated',
        'entity' => 'customer',
        // FS-02: the saga crashed and the restoration ran — a SYSTEM act with
        // the snapshot as its evidence; deliberately NOT a catalog event (the
        // FR-22 twelve are closed; rule 16).
        'actor_types' => ['system'],
        'required_fields' => ['snapshot_id'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.blocked',
        'entity' => 'customer',
        // FR-18: reason mandatory; every channel goes quiet (the cascade +
        // the outbound guard are the enforcement, this is the paper trail).
        'actor_types' => ['staff'],
        'required_fields' => ['reason'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.unblocked',
        'entity' => 'customer',
        // §8: a DISTINCT permissioned action with its own reason (rides
        // customers.block per A-39).
        'actor_types' => ['staff'],
        'required_fields' => ['reason'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.rg_flag.set',
        'entity' => 'customer',
        // staff sets manually; 'system' is the AI-detection path (FR-14,
        // [D09] — actor_id null by the history table's own CHECK).
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['reason', 'source'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.rg_flag.cleared',
        'entity' => 'customer',
        // Clearing is always a HUMAN act (FR-15/EC-06 — a false-positive
        // review), never automated.
        'actor_types' => ['staff'],
        'required_fields' => ['reason'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.updated',
        'entity' => 'customer',
        // FR-01/EC-11: every profile-attribute edit, old/new pairs in the
        // entry (the event carries NAMES only, Q7). Both EC-11 writers land
        // here — the LWW trail holds the full story.
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.fields_changed',
        'entity' => 'customer',
        // FR-16 (addition 9, condition 1): the audit trail IS the edit
        // history — old/new per field, no second history table.
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.note.added',
        'entity' => 'customer',
        // FR-17: author + timestamp are the row's own columns; the entry
        // records the act (note bodies never ride the audit stream).
        'actor_types' => ['staff'],
        'required_fields' => ['note_id'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.export_requested',
        'entity' => 'customer',
        // SEC-04: the synchronous half — the door records WHO asked before
        // the job runs; the completed artifact audits as customer.exported.
        'actor_types' => ['staff'],
        'required_fields' => ['export_id'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.exported',
        'entity' => 'customer',
        // SEC-04: written BY THE JOB at completion, after the SEC-06 re-check
        // and the atomic put — an export with no artifact never audits here.
        'actor_types' => ['staff'],
        'required_fields' => ['export_id'],
        'owning_doc' => 'Doc08',
    ],
    [
        'action_key' => 'customer.erasure.initiated',
        'entity' => 'customer',
        // A-38/[D17]: initiation only, reason mandatory — this entry IS the
        // durable record Doc 17's approval workflow consumes.
        'actor_types' => ['staff'],
        'required_fields' => ['reason'],
        'owning_doc' => 'Doc08',
    ],
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
        // `system` joined in S22 step 6: Doc 06's operations write system notes
        // (FR-21's split origin note, EC-05's fallback) through the same sanctioned
        // writer — NotesWriter::addSystemNote, author_type `system`, no author id.
        'action_key' => 'conversation.note_added',
        'entity' => 'message',
        'actor_types' => ['staff', 'system'],
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
    [
        // FR-03: blocking changes what every pipeline does with this thread —
        // the reason is required, the actor may be staff or an automation.
        'action_key' => 'conversation.blocked',
        'entity' => 'conversation',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['conversation_id', 'reason'],
        'owning_doc' => 'Doc04',
    ],
    [
        'action_key' => 'conversation.unblocked',
        'entity' => 'conversation',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['conversation_id', 'reason'],
        'owning_doc' => 'Doc04',
    ],
    [
        // T-08/AC-09: what a retention run did, per tenant — the paper trail a
        // "where did that thread go" question resolves against.
        'action_key' => 'retention.completed',
        'entity' => 'partition',
        'actor_types' => ['system'],
        'required_fields' => ['partitions', 'messages_held', 'messages_removed'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FS-05: a tries-exhausted inbound parked in the dead letter with its
        // full payload. System actor — the queue parked it, nobody chose to.
        'action_key' => 'conversation.message_parked',
        'entity' => 'message_dead_letter',
        'actor_types' => ['system'],
        'required_fields' => ['dead_letter_id', 'channel', 'error_class'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FS-01 (A-4): a channel endpoint's health TRANSITIONED (down on the
        // consecutive-failure threshold, up on first recovery) — one entry per
        // transition, never per failure.
        'action_key' => 'channel.health_changed',
        'entity' => 'channel',
        'actor_types' => ['system'],
        'required_fields' => ['channel', 'channel_ref_id', 'status'],
        'owning_doc' => 'Doc04',
    ],
    [
        // FS-05: an operator re-drove a parked item back through the inbound
        // pipeline. Staff actor with id — re-drives are accountable decisions.
        'action_key' => 'conversation.message_redriven',
        'entity' => 'message_dead_letter',
        'actor_types' => ['staff'],
        'required_fields' => ['dead_letter_id', 'channel'],
        'owning_doc' => 'Doc04',
    ],
    [
        // Doc 06 FR-04 + SEC-03: EVERY transition is audited with actor and
        // old/new. SEC-03 names spam-unmark, staff reopen and escalation reasons
        // as always-audited; auditing every transition is the superset, and it is
        // also the only honest answer to "who moved this ticket, and why" — the
        // question a betting dispute actually asks six months later.
        //
        // `reason` is a required FIELD but may be null: FR-04 requires a reason
        // only on escalate, spam and staff reopen-of-closed (the transition
        // service enforces which), and the audit entry records its presence or
        // absence either way rather than hiding the distinction.
        'action_key' => 'ticket.status_changed',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'old_status', 'new_status', 'reason'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-12/13/14 + FR-11: one creation path, four doors, and the audit entry
        // records which door and the human-facing number that came out of it —
        // the two facts a dispute six months later actually asks for.
        'action_key' => 'ticket.created',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'conversation_id', 'source', 'number'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-03: old/new subject with the provenance. Kept out of the event stream
        // deliberately (Q7) — the audit table is where authored text belongs.
        'action_key' => 'ticket.subject_changed',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'old_subject', 'new_subject', 'subject_source'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-15: every hand-over, including to nobody. old/new both required so an
        // unassign is as legible in the record as an assignment.
        'action_key' => 'ticket.assigned',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'old_assignee_id', 'new_assignee_id'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-17. Automation and AI change priority too, hence the system actor.
        'action_key' => 'ticket.priority_changed',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'old_priority', 'new_priority'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-18. `system` covers FR-23's auto-follow of the escalating assignee.
        'action_key' => 'ticket.follower_added',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'user_id'],
        'owning_doc' => 'Doc06',
    ],
    [
        // `system` joined in step 6: AC-04's unmerge resets the target's follower set
        // to the snapshot, and those removals are the platform's restore, not a
        // person's unfollow — the same staff|system split follower_added already has.
        'action_key' => 'ticket.follower_removed',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'user_id'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-19: the tag TEXT lives here rather than on the event stream (Q7).
        'action_key' => 'ticket.tagged',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'tags'],
        'owning_doc' => 'Doc06',
    ],
    [
        // `system` joined in step 6: AC-04's unmerge resets the target's tag set to
        // the snapshot, and the shed tags are the platform's restore, not a person's
        // untag — the follower_removed reasoning exactly.
        'action_key' => 'ticket.untagged',
        'entity' => 'ticket',
        'actor_types' => ['staff', 'system'],
        'required_fields' => ['ticket_id', 'tags'],
        'owning_doc' => 'Doc06',
    ],
    [
        // T-07: "audited with counts" — the count is the point of the record, because
        // a historical rewrite is exactly the operation whose blast radius an operator
        // cannot see any other way.
        'action_key' => 'ticket.tags_rewritten',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['from_tag', 'to_tag', 'tickets_rewritten'],
        'owning_doc' => 'Doc06',
    ],
    [
        // EC-06: a system hand-back, one entry per ticket.
        'action_key' => 'ticket.assignee_deactivated',
        'entity' => 'ticket',
        'actor_types' => ['system'],
        'required_fields' => ['ticket_id', 'previous_assignee_id'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-20: one entry per merge link, on the source.
        'action_key' => 'ticket.merged',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['source_ticket_id', 'target_ticket_id'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-20/AC-04: the 24 h reversal, on the source.
        'action_key' => 'ticket.unmerged',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['source_ticket_id', 'target_ticket_id', 'restored_status'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-21: one entry per split, on the NEW ticket (the origin's side is its
        // system note; the ticket.created entry covers the row's birth).
        'action_key' => 'ticket.split',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['origin_ticket_id', 'new_ticket_id', 'message_count'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FS-04: "both attempts audited" — the refused unmerge is history too.
        'action_key' => 'ticket.unmerge_refused',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['source_ticket_id', 'target_ticket_id', 'reason'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-22: the department change with its retention outcome.
        'action_key' => 'ticket.transferred',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'old_department_id', 'new_department_id', 'assignee_retained'],
        'owning_doc' => 'Doc06',
    ],
    [
        // SEC-03: "escalation reasons always audited with actor + old/new."
        'action_key' => 'ticket.escalated',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'path', 'reason'],
        'owning_doc' => 'Doc06',
    ],
    [
        'action_key' => 'ticket.deescalated',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'note'],
        'owning_doc' => 'Doc06',
    ],
    [
        // SEC-03: "bulk summaries always audited" — one entry per batch, on the job.
        'action_key' => 'ticket.bulk_completed',
        'entity' => 'ticket_bulk_job',
        'actor_types' => ['staff'],
        'required_fields' => ['bulk_job_id', 'action', 'ok', 'failed_count'],
        'owning_doc' => 'Doc06',
    ],
    [
        // FR-09/SEC-03: the mark, with its reason and the separate-block flag.
        'action_key' => 'ticket.spam_marked',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'reason', 'conversation_blocked'],
        'owning_doc' => 'Doc06',
    ],
    [
        // SEC-03: "Spam-unmark … always audited with actor + old/new."
        'action_key' => 'ticket.spam_unmarked',
        'entity' => 'ticket',
        'actor_types' => ['staff'],
        'required_fields' => ['ticket_id', 'restored_status', 'reason'],
        'owning_doc' => 'Doc06',
    ],
    [
        // EC-05: the fallback is the platform's own act.
        'action_key' => 'ticket.escalation_rerouted',
        'entity' => 'ticket',
        'actor_types' => ['system'],
        'required_fields' => ['ticket_id', 'from_target_user_id'],
        'owning_doc' => 'Doc06',
    ],

    // ── Doc 05 (Telegram) — S23 step 4: bot registry lifecycle.
    [
        'action_key' => 'telegram.bot_registered',
        'entity' => 'telegram_bot',
        'actor_types' => ['staff'],
        'required_fields' => ['route'],
        'owning_doc' => 'Doc05',
    ],
    [
        'action_key' => 'telegram.bot_webhook_reregistered',
        'entity' => 'telegram_bot',
        'actor_types' => ['staff'],
        'required_fields' => ['route'],
        'owning_doc' => 'Doc05',
    ],
    [
        'action_key' => 'telegram.bot_archived',
        'entity' => 'telegram_bot',
        'actor_types' => ['staff'],
        'required_fields' => ['route'],
        'owning_doc' => 'Doc05',
    ],

    [
        'action_key' => 'telegram.connection_confirmed',
        'entity' => 'telegram_business_connection',
        'actor_types' => ['staff'],
        'required_fields' => ['route'],
        'owning_doc' => 'Doc05',
    ],
    [
        'action_key' => 'telegram.connection_rejected',
        'entity' => 'telegram_business_connection',
        'actor_types' => ['staff'],
        'required_fields' => ['route'],
        'owning_doc' => 'Doc05',
    ],

    // ── Doc 05 (Telegram) — S23 step 11: health rollup + FR-30 test tools.
    [
        // FR-29/A-22: the rollup transition (system actor; old/new statuses).
        'action_key' => 'telegram.health_rollup_changed',
        'entity' => 'telegram_bot',
        'actor_types' => ['system'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc05',
    ],
    [
        // SEC-03: viewer ACCESS is audited (the HistoryReader precedent —
        // the viewer shows customer payload shapes, masked).
        'action_key' => 'telegram.raw_updates_viewed',
        'entity' => 'telegram_bot',
        'actor_types' => ['staff'],
        'required_fields' => ['route'],
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-30: send-test to the sandbox chat.
        'action_key' => 'telegram.test_message_sent',
        'entity' => 'telegram_bot',
        'actor_types' => ['staff'],
        'required_fields' => ['chat_id'],
        'owning_doc' => 'Doc05',
    ],
    [
        // FR-30: simulate-window-expiry (staging only — the production
        // refusal never reaches this entry; only executed simulations audit).
        'action_key' => 'telegram.window_expiry_simulated',
        'entity' => 'conversation',
        'actor_types' => ['staff'],
        'required_fields' => ['environment'],
        'owning_doc' => 'Doc05',
    ],
    [
        // Doc 07 FR-06/SEC-03 (S07 step 4): sharing a saved view changes what
        // a whole team/department SEES — audited with actor + target.
        'action_key' => 'inbox.view_shared',
        'entity' => 'inbox_saved_view',
        'actor_types' => ['staff'],
        'required_fields' => ['to_type', 'to_id'],
        'owning_doc' => 'Doc07',
    ],
    [
        'action_key' => 'inbox.view_unshared',
        'entity' => 'inbox_saved_view',
        'actor_types' => ['staff'],
        'required_fields' => [],
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 07 T-05/SEC-03 (S07 step 7): a selection-token execution is a
        // WIDE mutation — audited with actor, action and the drift counts.
        'action_key' => 'inbox.selection_executed',
        'entity' => 'inbox_selection_token',
        'actor_types' => ['staff'],
        'required_fields' => ['action', 'resolved', 'skipped'],
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 07 FR-13/SEC-03 (S07 step 14, Unit D): an ID-BASED bulk submit
        // (the drawn bar's partial selections) — the same wide-mutation
        // posture as inbox.selection_executed; retry-the-failed records the
        // same key with retried_from in the payload.
        'action_key' => 'inbox.bulk_submitted',
        'entity' => 'ticket_bulk_job',
        'actor_types' => ['staff'],
        'required_fields' => ['action', 'count'],
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 07 §8 C15 (S07 step 14, Unit D): the drawn Stop on a running
        // batch — a state-mutating latch the running job observes live.
        'action_key' => 'inbox.bulk_stop_requested',
        'entity' => 'ticket_bulk_job',
        'actor_types' => ['staff'],
        'required_fields' => ['action'],
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 07 FR-28 (S07 step 12): a supervisor FORCED another agent's
        // availability — Doc 03's supervision.agent_status.force key, with
        // attribution ("set by …") stored on the agent_statuses row.
        'action_key' => 'agent.status_forced',
        'entity' => 'user',
        'actor_types' => ['staff'],
        'required_fields' => ['status'],
        'owning_doc' => 'Doc07',
    ],
    [
        // Doc 09 SEC-05: config publishes are the high-visibility class.
        'action_key' => 'ai.config.published',
        'entity' => 'ai_config_version',
        'actor_types' => ['staff'],
        'required_fields' => ['kind', 'key', 'version'],
        'owning_doc' => 'Doc09',
    ],
    [
        // FR-04's rollback = a republish, audited under its own name so the
        // trail reads "rolled back", not "published again" (SEC-05).
        'action_key' => 'ai.config.rolled_back',
        'entity' => 'ai_config_version',
        'actor_types' => ['staff'],
        'required_fields' => ['kind', 'key', 'version', 'republished_version_of'],
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 FR-18/SEC-05: kill-switch flips are the high-visibility class —
        // actor + old/new always.
        'action_key' => 'ai.killswitch.changed',
        'entity' => 'ai_kill_switch',
        'actor_types' => ['staff'],
        'required_fields' => ['surface', 'old', 'new'],
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 09 FR-02/SEC-05 (S10 step 0.4, R-D10-1 as amended): a provider/model
        // switch is the high-visibility class — actor + the OLD and NEW chains per
        // tier. The ConfigVersions publish also writes ai.config.published; this
        // row names the switch by what it is.
        'action_key' => 'ai.providers.switched',
        'entity' => 'ai_config_version',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc09',
    ],
    [
        // Doc 10 FR-01 (S10 step 3).
        'action_key' => 'kb.category.created',
        'entity' => 'kb_category',
        'actor_types' => ['staff'],
        'required_fields' => ['slug', 'depth', 'ai_usable'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-01 — ai_usable old/new (FLAG 3 re-derivation is a visible act) (S10 step 3).
        'action_key' => 'kb.category.updated',
        'entity' => 'kb_category',
        'actor_types' => ['staff'],
        'required_fields' => ['fields', 'old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-01/EC-03 (S10 step 3).
        'action_key' => 'kb.category.reassigned',
        'entity' => 'kb_category',
        'actor_types' => ['staff'],
        'required_fields' => ['to', 'articles', 'retrieval_impact'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-01/A-43 soft delete (S10 step 3).
        'action_key' => 'kb.category.deleted',
        'entity' => 'kb_category',
        'actor_types' => ['staff'],
        'required_fields' => ['soft'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-02/FR-04 (S10 step 3).
        'action_key' => 'kb.article.created',
        'entity' => 'kb_article',
        'actor_types' => ['staff'],
        'required_fields' => ['audience', 'source_language', 'seeded'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-02/FR-03 (S10 step 3).
        'action_key' => 'kb.variant.created',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['article_id', 'language', 'translation_source'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-02 metadata (S10 step 3).
        'action_key' => 'kb.variant.updated',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['fields'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-01/EC-02 (S10 step 3).
        'action_key' => 'kb.article.moved',
        'entity' => 'kb_article',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new', 'ai_usable_changed'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-05/T-07 — BOTH parties: two rows, party=taker|holder (S10 step 3).
        'action_key' => 'kb.lock.taken_over',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['variant_id', 'previous_holder_id', 'new_holder_id', 'party'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07 submit (and EC-11\'s forced review) (S10 step 4).
        'action_key' => 'kb.variant.submitted',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07 request changes (S10 step 4).
        'action_key' => 'kb.variant.changes_requested',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07/FR-13 approve → publish (S10 step 4).
        'action_key' => 'kb.variant.published',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['version_id', 'version', 'old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07 the collapsed review, audited by its own name (S10 step 4).
        'action_key' => 'kb.variant.self_published',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['version_id', 'version', 'old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-13/EC-06 unpublish (chunks retire in-transaction) (S10 step 4).
        'action_key' => 'kb.variant.unpublished',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-09 archive (S10 step 4).
        'action_key' => 'kb.variant.archived',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-09 un-archive → draft (S10 step 4).
        'action_key' => 'kb.variant.unarchived',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-08 restore-to-draft (S10 step 4).
        'action_key' => 'kb.variant.restored',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['version_id', 'version'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 EC-11/SEC-04 HIGH-VISIBILITY — the leak surface changed; old/new + actor always (S10 step 4).
        'action_key' => 'kb.article.visibility_changed',
        'entity' => 'kb_article',
        'actor_types' => ['staff'],
        'required_fields' => ['old', 'new', 'variants_to_review'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 BR-06/SEC-04 the override recorded on the version (S10 step 4).
        'action_key' => 'kb.sanitizer.overridden',
        'entity' => 'kb_article_version',
        'actor_types' => ['staff'],
        'required_fields' => ['flags', 'reason'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07 review thread (S10 step 4).
        'action_key' => 'kb.review.commented',
        'entity' => 'kb_review_comment',
        'actor_types' => ['staff'],
        'required_fields' => ['variant_id', 'block_anchor'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-07 review thread (S10 step 4).
        'action_key' => 'kb.review.resolved',
        'entity' => 'kb_review_comment',
        'actor_types' => ['staff'],
        'required_fields' => [],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 A-43 (S10 step 8): article delete is SOFT — the row survives, chunks retire, tombstones resolve.
        'action_key' => 'kb.article.deleted',
        'entity' => 'kb_article',
        'actor_types' => ['staff'],
        'required_fields' => ['soft'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 §14 / FR-06 (S10 step 8): one-click AI-translate-all — the request, with the languages it queued.
        'action_key' => 'kb.article.translate_all_requested',
        'entity' => 'kb_article',
        'actor_types' => ['staff'],
        'required_fields' => ['source_variant_id', 'languages', 'variant_ids'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-06 Translate variant (S10 step 8): the queued job wrote an AI draft's working copy (never a status).
        'action_key' => 'kb.variant.ai_translated',
        'entity' => 'kb_article_variant',
        'actor_types' => ['system'],
        'required_fields' => ['article_id', 'source_variant_id', 'language', 'decision_id'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-06 (S10 step 9): a staff member asked an AI tool for a draft/rewrite/FAQ (identifiers + counts only).
        'action_key' => 'kb.variant.ai_requested',
        'entity' => 'kb_article_variant',
        'actor_types' => ['staff'],
        'required_fields' => ['task'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-06 (S10 step 9): the ai-queue job wrote an accented draft's working copy (never a status).
        'action_key' => 'kb.variant.ai_drafted',
        'entity' => 'kb_article_variant',
        'actor_types' => ['system'],
        'required_fields' => ['article_id', 'task', 'decision_id'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 FR-11 (S10 step 10): a staff member left an internal feedback note on a variant (the note text stays in the row, not the audit).
        'action_key' => 'kb.feedback.noted',
        'entity' => 'kb_feedback',
        'actor_types' => ['staff'],
        'required_fields' => ['variant_id'],
        'owning_doc' => 'Doc10',
    ],
    [
        // Doc 10 §7 stats header (S10 step 10): a feedback item was marked actioned by a kb.feedback.view holder.
        'action_key' => 'kb.feedback.actioned',
        'entity' => 'kb_feedback',
        'actor_types' => ['staff'],
        'required_fields' => ['variant_id'],
        'owning_doc' => 'Doc10',
    ],
];
