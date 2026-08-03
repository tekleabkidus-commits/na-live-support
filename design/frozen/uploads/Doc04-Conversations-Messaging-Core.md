Table of Contents

# Document 04 — Conversations & Messaging Core

**Product:** AI-First Support Platform for Betting Companies **Document series:** 04 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, QA, DevOps, product **Depends on:** Doc 01 (tenancy, outbox, events), Doc 02 (guards), Doc 03 (permissions: conversations.*, tickets.reply, tickets.note). Consumed by: Doc 05 (Telegram), Doc 06 (Ticketing), Doc 07 (Inbox), Doc 08 (Customers), Doc 09 (AI), Doc 13 (Widget). **Stack context:** Laravel, PostgreSQL (partitioned messages), Redis, Horizon queues, Reverb, S3-compatible storage, ClamAV

## 1. Executive Summary

This document specifies the channel-agnostic messaging engine at the heart of the platform: the Conversation and Message models, the **Channel Adapter contract** every channel (Telegram, web chat, and any future channel) must implement, the inbound ingestion pipeline (normalize → identity → persist → dispatch), the outbound delivery pipeline with its per-message state machine (queued → sending → sent → delivered → failed), media handling (upload, virus scan, storage, signed URLs, previews, voice transcription hooks, OCR hooks), internal notes, typing/presence signals, draft persistence, transcript export, message editing/deletion semantics, retention, and the realtime distribution of all of it over Reverb. A conversation is a *permanent, per-customer, per-channel-endpoint thread*; tickets (Doc 06) are bounded work items referencing message ranges within it. Nothing channel-specific lives here: Docs 05 and 13 supply adapters; this core guarantees identical behavior, storage, and events regardless of channel.

## 2. Purpose

- Define one message pipeline so every channel inherits delivery guarantees, media safety, audit, and realtime behavior identically.

- Freeze the Channel Adapter interface so new channels are additive work (Doc 05/13 now; WhatsApp later) with zero core changes.

- Specify message-level semantics (states, edits, deletes, notes, ordering, idempotency) precisely enough that inbox UI (Doc 07), AI (Doc 09), and reporting (Doc 15) can be built against them in parallel.

## 3. Scope

**In scope:** conversation lifecycle; message model & kinds; adapter contract; inbound pipeline; outbound pipeline & delivery state machine; retries/backoff; media pipeline (incl. transcription/OCR *hooks* — AI behavior itself is Doc 09); internal notes; typing & presence relay; drafts; transcripts; ordering & idempotency; realtime channels; retention; conversation blocking; events catalog. **Out of scope:** Telegram/Business specifics (Doc 05); widget specifics (Doc 13); ticket semantics, SLA (Doc 06/12); identity *merge* rules (Doc 08 — this doc only calls the identity resolver); AI content decisions (Doc 09); inbox presentation (Doc 07).

## 4. Functional Requirements

### 4.1 Conversation model

FR-01 A Conversation is unique per {tenant, channel endpoint, external chat id} — e.g., one Telegram user ↔ one bot/connection = one conversation forever; one widget visitor ↔ one widget = one conversation. FR-02 Fields (per Doc 01 schema conventions): channel (telegram|webchat), channel_ref_id (bot/widget), business_connection_id (nullable, Doc 05), customer_id, external_chat_id, state (active|blocked), last_customer_message_at, last_outbound_at, unread_count (per-viewer counts live in Redis, persisted rollup only). FR-03 Conversations are never deleted; blocked stops outbound sends and routes inbound to a quarantine flag (still stored; Doc 06 spam disposition consumes it). FR-04 A conversation may have 0..n tickets over its lifetime (Doc 06 owns ticket windows); the core exposes open_ticket_id (nullable, at most one open ticket per conversation — invariant enforced here, required by routing).

### 4.2 Message model & kinds

FR-05 Kinds: text, photo, video, voice, audio, document, sticker (stored, rendered as image), system (join/leave/window-expired/etc.), note (internal, never delivered to customer). Location/contact kinds reserved. FR-06 Fields: direction (in|out), author_type (customer|agent|ai|system), author_id, body (text, ≤ 16 KB), media[] (jsonb: storage_key, mime, bytes, width/height/duration, thumb_key, transcript_ref, ocr_ref, scan_status), language (detected, nullable), external_message_id (channel-native id), reply_to_message_id (threading), delivery_state, delivery_error, is_internal, edited_at, deleted_at (tombstone semantics §4.7). FR-07 Ordering: display order = (created_at, id-uuidv7); external clock skew from channels is normalized to receipt time with the channel timestamp preserved in metadata; QA fixture asserts stable ordering under same-second bursts.

### 4.3 Channel Adapter contract (normative interface)

FR-08 Every adapter implements:

inbound:  handleWebhook(raw) → NormalizedInbound{external_chat_id, external_message_id,
          sender{external_user_id, display, username?, phone?}, kind, body?, media[]?,
          reply_to?, channel_meta{}, occurred_at, dedupe_key}
outbound: send(OutboundMessage) → SendResult{external_message_id? , state: sent|failed,
          error{code, retryable, retry_after?}}
capabilities() → {max_text, media_types[], supports_typing, supports_edit, supports_delete,
          supports_buttons, reply_window: none|duration}
health() → {status, detail}

FR-09 The core calls adapters only through this contract; adapters never touch core tables directly. Capability flags drive core behavior (e.g., composer limits, button rendering fallbacks) — no channel if-statements in core code (CI grep gate).

### 4.4 Inbound pipeline

FR-10 Steps (queued, per Doc 01 outbox/idempotency conventions): 1. Webhook controller: authenticate (Doc 02 FR-23/24 via adapter), enqueue raw payload to {channel}-in, ACK the channel ≤ 250 ms (never process inline). 2. Normalize via adapter; dedupe on dedupe_key (Redis SETNX, 48 h window) — duplicates ACKed and dropped with metric. 3. Identity resolution: call Doc 08 resolver with sender → customer_id (existing or shell). 4. Persist message (+ media placeholders) in the conversation (create conversation if first contact) inside one transaction with outbox event message.received. 5. Media fetch jobs (per item): download from channel → scan (ClamAV) → store → thumbnail → mark scan_status=clean|infected|failed (infected: media blocked, message shows a shield notice, event media.infected). 6. Post-persist fan-out (subscribers, not pipeline steps): AI turn decision (Doc 09), ticket routing (Doc 06), realtime push, unread counters. FR-11 Inbound during blocked state: stored with quarantined=true, no fan-out except audit; unblocking does not retro-fan-out (documented).

### 4.5 Outbound pipeline & delivery state machine

FR-12 Compose sources: agent (Doc 07), AI (Doc 09), automation (Doc 12), system. All create a message row in queued and an outbox job on {channel}-out. FR-13 State machine (normative):

queued → sending → sent → delivered
   │         │        └(no receipt support)→ [sent is terminal-success]
   │         └→ failed(retryable) → backoff retry ×5 (1s,5s,30s,2m,10m) → failed(final)
   └→ canceled (pre-send only, e.g., ticket closed by rule before send)
failed(final) → manual retry (permission tickets.reply) → re-enters queued

Delivered exists only where the channel provides receipts (capability-driven); UI renders the max truthful state (Doc 05 defines Telegram mapping). FR-14 Non-retryable errors (adapter-classified: blocked-by-user, reply-window expired, invalid content) → failed(final) immediately with a reason code the UI maps to specific guidance (e.g., Doc 05’s window-expired composer state). FR-15 Per-conversation outbound FIFO: a conversation’s messages send in order; a failed(retryable) head blocks the lane (prevents out-of-order delivery) with a 10-min cap before lane-skip + warning event. FR-16 Rate limiting: token buckets per channel endpoint (config from adapter capability + settings); over-limit messages wait in queued (never dropped).

### 4.6 Notes, typing, presence, drafts

FR-17 Internal notes (kind=note): visible only to staff with tickets.note/customers.notes.view as applicable; never enter any adapter; support @mentions (mention event → notification, Doc 07/18); rendered distinctly (Doc 07). FR-18 Typing: customer typing (where channel supports) relayed to viewing staff; staff composing triggers adapter typing signal at most every 4 s while active (capability-gated). FR-19 Presence: “who is viewing conversation X” via Reverb presence channel; powers Doc 07 collision UX. FR-20 Drafts: per user per conversation, autosaved locally every 2 s + server every 10 s (survives device switch); cleared on send; restored after re-login (Doc 02 §8).

### 4.7 Edit / delete semantics

FR-21 Customer-side edits/deletes (channel-originated): stored as new revisions/tombstones — original retained internally (edited_at, prior body in revision table; deletion shows “message deleted by customer” tombstone to staff, content retained per retention policy, permission-gated view conversations.history.full). FR-22 Staff-side: outbound edit/delete only where capability supports it AND within channel limits; platform records both the customer-visible outcome and the internal history; telegram.message.delete (Doc 03) gates deletes; every edit/delete audited. FR-23 AI messages are never edited post-send; corrections are new messages (honesty rule, Doc 09).

### 4.8 Transcripts, realtime, events

FR-24 Transcript export (conversations.transcript.export): rendered PDF/text of a conversation or ticket range, media as links or embedded thumbs (option), notes excluded by default (include-notes option double-confirmed), export audited. FR-25 Realtime channels: conversation.{id} (messages, states, typing), user.{id} (mentions, assignments), inbox list deltas via Doc 07’s view channels; every payload ≤ 32 KB with media referenced by signed URL, never inlined. FR-26 Event catalog published: conversation.created|blocked|unblocked, message.received|queued|sent|delivered|failed|canceled|edited|deleted, media.stored|infected, note.added, draft.saved (local only, not bus), transcript.exported.

## 5. Non-functional Requirements

NFR-01 Inbound webhook ACK ≤ 250 ms p99; end-to-end inbound (webhook → visible to agent via Reverb) ≤ 1.5 s p95 without media, ≤ 4 s p95 with one photo. NFR-02 Outbound enqueue→sent ≤ 2 s p95 nominal; burst absorption 50 msg/s per endpoint with lag alerting at 30 s. NFR-03 Message writes ≤ 5 ms p99 (partition-local); thread page (50 messages + media refs) ≤ 120 ms p95. NFR-04 At-least-once processing with idempotent consumers everywhere; zero message loss on any single worker/node failure (Doc 01 NFR-05). NFR-05 Media: ≤ 25 MB default cap (setting media.max_mb), scan ≤ 10 s p95, quarantine on scan failure after ×3. NFR-06 Storage growth budget documented: text ~2 KB/msg avg, media per policy; retention jobs keep partitions within plan.

## 6. User Stories

US-01 As an agent, every message I send shows me exactly what happened to it (sending, sent, failed-with-reason) without guessing. US-02 As an agent, when a customer deletes a message, I still see that something was deleted so context isn’t silently rewritten. US-03 As an agent, my half-written reply survives a crashed laptop and is waiting on my phone. US-04 As a supervisor, I can export a clean transcript for a payment dispute without internal notes leaking. US-05 As a developer adding WhatsApp later, I implement one adapter and the entire platform behavior comes for free. US-06 As a compliance officer, edited and deleted content is preserved internally per retention policy and access to full history is permission-gated. US-07 As a customer, my photos and voice notes just work, and I never receive replies out of order.

## 7. Business Rules

BR-01 The customer’s thread is sacred: nothing staff-internal (notes, AI suggestions, system chatter) can ever reach an adapter — enforced structurally (notes carry no deliverable payload path), tested per release. BR-02 No outbound to blocked conversations, no exceptions (even automations are refused with conversation_blocked). BR-03 Original content of edits/deletes is retained internally per retention policy — betting disputes require it — but exposed only via conversations.history.full. BR-04 Ordering truth is platform receipt order; channels’ own timestamps are metadata, never sort keys. BR-05 One open ticket per conversation (invariant); the core rejects a second open with open_ticket_exists — Doc 06 must transition, not duplicate. BR-06 Media of kind sticker/photo from customers is EXIF-stripped at store (privacy, Doc 01 posture).

## 8. UX Requirements

(Rendering lives in Doc 07; the core mandates these behaviors any renderer must honor.) - Delivery states as glyphs with tooltips: clock (queued/sending), single check (sent), double check (delivered where supported), red ! (failed) with reason and Retry where permitted. - Failed-final states map reason codes to actionable guidance strings (i18n messaging.fail.*): blocked_by_user → “Customer has blocked this channel — consider SMS”; window_expired → Doc 05 composer state; too_large → shows the configured cap. - Tombstones and edit markers: “edited” affordance opens revision history only for permitted users; deleted shows neutral tombstone. - Media: photo/video lightbox with pinch-zoom (mobile), voice player with scrubber + speed (1×/1.5×/2×) + “show transcript” toggle when transcript exists; document rows show type icon, name, size; infected media shows shield block with no download path. - Typing indicator shown ≤ 6 s after last signal then auto-clears; never stacks. - Draft indicator on conversation lists (“pencil” affordance) so agents spot unfinished replies.

## 9. UI Requirements

- Message bubble component set (customer/staff/AI/system/note variants) with density-aware spacing; virtualized thread list (stable scroll anchoring; “new messages ↓” chip on incoming while scrolled up; jump-to-first-unread).

- Loading: thread skeleton (6 bubbles), progressive media (thumb → full on demand), upload progress per attachment with cancel.

- Error: offline composer banner queues sends locally (visible “will send when online” chips), reconciles on reconnect (idempotency keys prevent dupes).

- Empty: brand-new conversation shows customer identity card + “no messages yet”.

- Animations: bubble-in 120 ms fade/slide; state glyph transitions crossfade; reduced-motion honored.

- Responsive: bubbles max-width 72% desktop / 86% mobile; media grid adapts 1–2 columns.

## 10. Technical Requirements

T-01 Tables per Doc 01 Phase-22 conventions: conversations, messages (monthly partitions), message_revisions, message_media folded into jsonb per FR-06 with storage keys; all writes via repository classes (no ad-hoc Eloquent on partitions — Doc 01 R-05). T-02 Queues: telegram-in, webchat-in, telegram-out, webchat-out, media, isolated Horizon supervisors; per-conversation FIFO via Redis lists keyed lane:{conversation} consumed by lane workers (FR-15). T-03 Idempotency: inbound dedupe_key (adapter-supplied, e.g., update_id); outbound client idempotency keys (Doc 01 T-08 convention) stored on the message row. T-04 Media: streamed download (no full-buffer), size enforced during stream, ClamAV daemon sidecar, S3 with tenant-prefixed keys + SSE, thumbnails via queued image job (max 3 sizes), signed URLs TTL 10 min re-issued on demand. T-05 Transcription/OCR hooks: after media.stored for voice/photo kinds, emit media.needs_transcript|media.needs_ocr — Doc 09 subscribes and writes back transcript_ref/ocr_ref; core is provider-agnostic. T-06 Reverb: channel auth callbacks enforce Doc 03 scope on conversation.{id} subscribe; payload schema versioned (v:1 field) for client compatibility. T-07 Drafts: local IndexedDB + server drafts table (user_id, conversation_id unique, body, updated_at), last-writer-wins with updated_at guard. T-08 Retention: policy keys retention.messages.months, retention.media.months, retention.revisions.months (settings, platform minimums); partition-drop jobs with legal-hold override flag per conversation. T-09 CI gates: adapter-contract conformance suite (a fake channel fixture runs the full matrix); “no channel conditionals in core” grep gate; ordering fuzz test.

**Pipelines (text diagram):**

IN : webhook → auth → enqueue raw → normalize+dedupe → identity → persist(+outbox)
     → [media jobs: fetch→scan→store→thumb] → fanout: AI | routing | realtime | counters
OUT: compose → persist(queued)+outbox → lane FIFO → rate gate → adapter.send
     → sent/delivered/failed(retry backoff ×5) → realtime state updates → events

## 11. Security Requirements

SEC-01 All customer content is hostile input: rendered inert (no HTML execution), links unwrapped with target-safe handling, file downloads always attachment-disposition from a sandboxed media domain. SEC-02 Media domain is cookie-less and separate-origin; signed URLs single-tenant scoped; direct object access without signature impossible (bucket private). SEC-03 SSRF-safe media fetching: adapter-provided URLs fetched via an allowlisted egress proxy (channel API hosts only). SEC-04 Virus-infected media: no download path exists for any role; deletion only via retention; event alerts security monitoring at threshold rates. SEC-05 Transcript exports watermark the exporting user + timestamp; export events audited (Doc 17). SEC-06 Reverb payloads never include another tenant’s ids (schema-validated at emit in dev/staging). SEC-07 Note content is excluded from any adapter-bound serialization path by type-level construction (a DeliverablePayload type notes cannot produce).

## 12. Performance Requirements

- Budgets of §5 verified under load profile L2 (Doc 19): 200 msg/s inbound platform-wide, 3k concurrent open threads, 500 sockets/node.

- Thread pagination: cursor by (created_at,id), 50/page, p95 ≤ 120 ms warm.

- Media thumb generation ≤ 5 s p95; lightbox full-image fetch ≤ 300 ms p95 via CDN.

- Draft autosave server call ≤ 40 ms p95 (fire-and-forget with retry).

- Lane worker fairness: no conversation starves under a hot conversation (round-robin across lanes; test with 1 lane at 10 msg/s + 100 idle lanes).

## 13. Accessibility Requirements

Thread is a log live region (polite) announcing new messages with author + kind (“Voice message from customer, 12 seconds”); delivery glyphs have text equivalents; media alternatives: voice exposes transcript when available, images require stored alt slot editable by staff for KB reuse; lightbox and players fully keyboard operable; tombstones/edit markers announced; color-independent state signaling; focus retained on composer across incoming messages.

## 14. Localization Requirements

All system-message and state strings under messaging.* i18n keys (en + am); detected message language stored per message and exposed to Doc 09 translation features; timestamps localized (Doc 01 rules); voice player speed labels and duration formats localized; RTL-ready bubble layout (logical properties, mirrored glyph placement).

## 15. Edge Cases

EC-01 Same-second burst from one customer (10 msgs) → ordering stable per FR-07 fixture; single AI turn decision on the batch tail (Doc 09 consumes batch_tail=true hint set by a 2 s debounce). EC-02 Media exceeds cap mid-stream → aborted, message stored with media_rejected system note to staff; customer receives capability-appropriate notice (adapter-templated). EC-03 Duplicate webhook delivery (channel retry) → dedupe drops silently (metric only). EC-04 Outbound composed for a conversation blocked between compose and send → transition to canceled with reason; UI updates chip. EC-05 Adapter reports sent but channel later invalidates (rare) → adapter maps follow-up error to a post_send_failed system annotation; no state rollback (sent stays truthful to what we know when we knew it). EC-06 Draft conflict (two devices) → last-writer-wins with a “draft updated on another device” toast on the loser. EC-07 Voice message with failed transcription → player works; transcript toggle shows “unavailable”; retry job ×2 then gives up quietly. EC-08 Reply-to references a message outside the loaded page → thread fetches the anchor context window on demand. EC-09 Retention drops a partition containing a legally-held conversation → hold flag excludes those rows via pre-drop copy-out to hold storage (job spec in Doc 18 runbooks). EC-10 Clock: channel timestamp earlier than a prior message’s receipt → display uses receipt order; a subtle “sent earlier” metadata line preserves truth. EC-11 ClamAV down → media held in scanning up to 15 min, then quarantined-pending with staff notice; never delivered unscanned. EC-12 Reverb node loss mid-typing → typing auto-expiry (≤ 6 s) prevents stuck indicators; clients reconcile via sync endpoint.

## 16. Failure Scenarios

FS-01 Channel API total outage → outbound accumulates in queued (lanes intact), health events fire (Doc 05/13 surfaces), automatic drain on recovery, zero loss, order preserved. FS-02 Object storage outage → inbound text unaffected; media jobs retry with backoff; messages render with “attachment pending”; alert per Doc 18. FS-03 media queue backlog → autoscale signal (Doc 18); text pipeline unaffected (isolation by queue). FS-04 Partition creation missed → Doc 01 EC-10 pre-creation + monitor prevents; writes to a missing partition page the on-call (should never fire). FS-05 Poison message (normalizer crash loop) → ×3 then parked in a dead-letter queue with full payload; ops UI lists parked items with re-drive; the conversation continues around it.

## 17. Success Scenarios

SS-01 A match-day burst (50 msg/s for 10 min on one endpoint) is absorbed: ACKs within budget, lag alarms silent, ordering intact, agents see live flow. SS-02 WhatsApp prototype adapter passes the conformance suite and the whole product works on it with zero core edits (architecture proof). SS-03 A disputed-withdrawal transcript export is produced in one click, notes excluded, watermark applied, audit entry present. SS-04 Full node kill during a 1k-message replay drill: post-drain reconciliation shows zero lost, zero duplicated side effects.

## 18. Acceptance Criteria

AC-01 Adapter conformance suite green for every shipped adapter: all capability combinations, error classifications, dedupe, ordering. AC-02 State machine matrix: every transition in FR-13 (incl. illegal transitions rejected) proven by unit + integration tests. AC-03 FIFO: induced head failure holds the lane ≤ 10 min then lane-skip event; order violations = 0 across 10k-message soak. AC-04 Note isolation: attempt to route a note through every adapter path fails at type level (compile/static test) and at runtime guard. AC-05 Media: oversized, infected (EICAR), and scan-timeout fixtures each land in their specified terminal states with correct UI reason codes. AC-06 Tombstone/revision: customer edit + delete round-trip preserves originals, gates on conversations.history.full, audits access. AC-07 Realtime: message visible to a subscribed viewer ≤ 1.5 s p95 (automated browser measurement under L2). AC-08 Draft recovery: kill browser mid-compose → draft restored on new device ≤ 10 s stale. AC-09 Retention: policy run drops exactly the eligible partitions, respects legal holds, emits retention.completed with counts.

## 19. Future Expansion

- Additional adapters: WhatsApp Business, email-in, in-app SDK — contract already sufficient (reply_window and buttons capabilities cover the known variance).

- Message reactions (channel-supported) → reserved reactions jsonb.

- Scheduled sends (“send at 9:00”) → queued gains not_before (column reserved).

- Read-state sync for channels that add receipts later — capability flip, no schema change.

- Per-conversation encryption-at-rest keys for premium compliance tiers.

## 20. Risks

R-01 Partitioned-table operational complexity (Medium/High) → repositories only, pre-created partitions, drills in Doc 18. R-02 Lane FIFO becoming a throughput bottleneck on hot conversations (Medium/Medium) → measured cap (§12 fairness test); design allows per-lane concurrency=1 relaxation for system messages if ever needed. R-03 Media costs ballooning (High likelihood) → caps, retention, storage-class lifecycle (Doc 18), per-tenant quota (Doc 01 NFR-06). R-04 Adapter authors bypassing the contract under deadline (Medium/High) → CI conformance + grep gates make the shortcut harder than the right path. R-05 Transcript misuse (Low/High) → permission-gated, watermarked, audited; optional export approval flow reserved (§19 Doc 17 tie-in).

## 21. Recommendations

- Build this core with a **fake reference adapter first** and drive it to 100% conformance-suite coverage before Doc 05 begins — Telegram quirks then land in the adapter, not the core.

- Treat the delivery state machine and reason-code list as frozen public contract for Docs 05/07/13 — changes require a series-wide review.

- Invest early in the dead-letter ops view (FS-05); it converts your worst production mysteries into 5-minute re-drives.

- Run the node-kill replay drill (SS-04) monthly from the first staging environment; delivery guarantees rot without rehearsal.

- Keep transcription/OCR strictly hook-based (T-05) so AI provider changes (Doc 09) never touch messaging code.

*End of Document 04. Next in series: Document 05 — Telegram Channel (Business Account + Direct Bot).*