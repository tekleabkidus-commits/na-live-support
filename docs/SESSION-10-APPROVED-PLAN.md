# SESSION-10 PLAN — Doc 10: Knowledge Base

**Status: APPROVED by the advisor on the owner-grounded step-0 record (45a58cc), 2026-08-27 —
family count 89 reconciled exact; STOP-A/B/C/D and A-43…A-46 ruled (§0a). Committed at approval
per standing rule 12. THIS FILE is the plan of record; the plan-time STOPS in §0 are kept as
the proposals the rulings answered.**

Read in full before this plan (prompt §6, every item): CLAUDE.md; Doc10-Knowledge-Base.md;
BUILD-STATUS Doc 09 close section + risks 0h–0l + incidents #14–#16 + platform notes +
DOC 09 SUITES; ANSWERS Q1–Q9, R-1…R-26, A-1…A-42, SCHEMA AMENDMENTS 2 + 3;
ADVISOR-RULINGS-D10/D09; S09-ACCEPTANCE; ADRs 0011/0015/0016/0017/0018/0019; the substrate
as code (KbIndexer, HybridRetriever, InstructionStripper, FixturesOnlyEgress, ConsumerRegistry
+ OutboxRelay, EventCatalog, WeeklyOutputs, TenantProvisioner/ProvisioningStep, MediaStore/
ClamdClient, SearchIndex/SearchProxy, CopilotPanel/CopilotTab, MenuDesigner + the
telegram_flow_targets CHECK, VisitorTokenStore/WidgetGuard, AgentStatus heartbeat, the
kb_chunks migration, AiGrantPhysicsTest, RetrievalTest, the golden fixtures);
SESSION-09 prompt + approved plan; DESIGN-FIDELITY-MANDATE v9 §5.

---

## §0 — PLAN-TIME STOPS (rulings needed before step 1; each with a proposal)

### STOP-A (mandatory, prompt §7) — T-01 "delete old async" vs the built REVOKE DELETE on kb_chunks
Doc 10 T-01: "index-swap via a retrieval_generation column (query always filters current
generation; swap = update pointer, delete old async)". Built physics (SCHEMA AMENDMENT 3,
countersigned; `2026_08_20_000500_create_kb_chunks_table.php`): `REVOKE DELETE` on `kb_chunks`
for `app`, retirement flips `retrievable`. Also found while reading the substrate: the built
upsert arbiter is `UNIQUE (tenant_id, article_ref, chunk_index)` — it has **no language and no
generation component**, so (a) an `en` and an `am` variant of one article would collide, and
(b) two generations cannot coexist for the swap at all. The document and the physics
genuinely differ; I do not pick.

**Proposal (recommended):** generation swap by FLAG FLIPS, no app DELETE ever.
1. SCHEMA AMENDMENT 4 (filed step 2a, countersigned before any migration): `kb_chunks` gains
   `variant_id uuid NOT NULL`, `version_id uuid NOT NULL`, `retrieval_generation int NOT NULL`,
   `heading_path text NOT NULL default ''`, `sanitizer_flags jsonb NOT NULL default '[]'`;
   the arbiter becomes `UNIQUE (tenant_id, variant_id, retrieval_generation, chunk_index)`
   (`article_ref` stays, indexed, no FK — the seam law); the partial retrieval index adds
   `retrieval_generation`. Expand-contract: add nullable → backfill (no production data;
   fixture rows only) → enforce, three migrations in one release is refused — so the three
   phases ship as three migrations in dependency order, all inside step 2b (C-12 satisfied
   by ordering; the advisor may require release separation — I ask).
2. Publish writes generation N+1 rows with `retrievable=false`; the SWAP is ONE statement
   `UPDATE kb_chunks SET retrievable = (retrieval_generation = N+1) WHERE variant_id = ?`
   inside the publish transaction — atomic; no reader ever sees a mix (NFR-06/AC-03).
3. "Delete old async" is read as a PLATFORM-ROLE retention job (Doc 01 SEC-02's retention
   class — the S09 payload-reduction precedent, its third sanctioned use, scoped by filing):
   `kb:chunks-prune` deletes `retrievable=false` rows whose generation is older than the
   current-2 AND whose version is not the article's current version, daily. App role never
   deletes. Forensics (SS-04) resolve through `kb_article_versions`, never through chunks.
   Alternative if refused: rows are never deleted; the prune job is dropped and the growth
   bound is stated as a §12 [D19] observation.
4. KbIndexer's signature grows to carry variant/version/generation/heading_path/flags —
   a Doc 09 core-edit packet (escalate-first, diff → countersign → land, step 2b/6).

### STOP-B (mandatory, prompt §7) — FR-15/SEC-01 "index-level separation, structural" vs the built single-table `audience` column
Built: one table, `audience` CHECK, the retriever adds `where audience='public'` unless
`includeInternal`. That is exactly the "query filter" FR-15 forbids as the sole mechanism.

**Proposal (recommended, option 2):** LIST-partition `kb_chunks` by `audience`
(`kb_chunks_public`, `kb_chunks_internal`) in SCHEMA AMENDMENT 4 (the table is recreated —
no production data, fixtures re-seed; each partition carries its OWN HNSW + GIN indexes =
index-level separation in the literal sense). The chatbot's retrieval path is bound to the
`kb_chunks_public` partition BY TABLE NAME through a `PublicChunkSource` that has no
`includeInternal` parameter and no way to name the parent; Copilot keeps the parent. The
T-08 structural test: (i) a Postgres-level proof — an internal-audience row is unreachable
from the public partition by physics (`SELECT` on the partition returns none regardless of
any WHERE); (ii) a static proof — `ChatbotTurn`'s retriever type cannot express
`includeInternal`; (iii) the sabotage: a query-filter bug planted in the public path still
cannot return an internal chunk. Option 1 (a second table `kb_chunks_internal` with a second
indexer door) is REJECTED by me on the one-door law; option 3 (keep the column, call the
retriever "structural") does not meet the document's words. HybridRetriever packet = core
edit (step 7).

### STOP-C — the ADR 0018 embeddings sidecar does not exist
Step-0 probe (pasted in the step-0 report): no `embeddings` service in `docker/compose*.yaml`,
`getent hosts embeddings` → no host, HTTP 000; no `sidecars/embeddings/`; `mock-ai` answers
404 on `/v1/embeddings`. `config/ai.php` points at `http://embeddings:8081/v1`; S09 recorded
"embeddings NOT SERVED" and proved retrieval over fixture vectors (ADR 0018's determinism
split). KbIndexer tolerates a null vector (keyword half serves).
**Proposal (recommended):** Doc 10's correctness proofs run over fixture vectors and the
FakeDriver/replay driver under the egress guard, exactly as S09 did; the sidecar container
is filed as an UNBUILT ADR-0018 deliverable in BUILD-STATUS (honest trail — the register
implied it existed) and rides `[D18]` (stand-up in the staging/infra window) with the
nightly live leg as its proof. Alternative: the owner orders the sidecar built as an S10
infra side-row (Node + ONNX bge-m3 image, ~2 GB model, no runtime internet) — a new
container and a stack change; I would need the ruling and an ADR 0018 amendment.

### STOP-D — step 0.4's "provider page's route mapping": no provider page exists
R-D10-1 orders ONE commit: registry entry + CatalogSnapshotTest pins + "the S09-built
Backoffice AI provider screen's routes bind to this key". Found: `AiBackofficeController`
says "no page here yet"; no route, no Vue page; BUILD-STATUS:147 expects "the provider
switch page and its route land in one commit"; a FROZEN Batch 8 frame exists
(`design/frozen/B8-S08-provider-config-status-v1.html`, cfe305b) — fallback chains, the
degradation ladder as live status, the model map with `ai.providers.manage`-gated Change.
Building that screen is Doc 09 FR-02/FR-18 work at full mandate fidelity — the `[B8]`
pass the prompt says is NOT this session's. Two further facts the commit needs: the key's
RISK (Doc 09 FR-02 calls it "ai.thresholds.edit-adjacent" and the frame calls the switch
"a high-visibility audit event" → I propose `risk: high`, moving the HIGH pin 38→39, and
seeding into Super Admin + Admin only), and the door's audit action.
**Proposal (recommended, (b)):** the one commit = registry entry (`module ai`, unscoped,
`risk high`, `owning_doc Doc03` per the registry's own rule) + `perm`/`perm_desc` en+am +
CatalogSnapshotTest pins (keys 114, high 39) + RoleSeederTest pin (SA 114) + RoleTemplates
(Admin gains it) + the SWITCH DOOR: `POST admin/ai/providers/routing` (draft + publish a
`provider_routing` ConfigVersion through the existing `ConfigVersions` doors, gated
`permission:ai.providers.manage`, audited via a new `ai.providers.switched` audit-coverage
row with old/new + actor) and `GET admin/ai/providers` returning the model map as JSON —
no Vue page. The B8-S08 screen lands in the `[B8]` pass session at full fidelity on top of
this door. Alternative (a): also ship a functional-first page — refused by me because a
frozen frame exists and the mandate then demands full fidelity. Count flips 113→114 at this
commit and nowhere else; pasted before/after.

### Filed-interpretation candidates raised now (A-4 mechanism; each filed BEFORE its step, never silent)
- **A-43 (proposed)** — article/category DELETE = soft delete (`deleted_at`) under REVOKE
  DELETE (Doc 01 BR-02; the R-11 role-deletion precedent), riding `kb.articles.delete` /
  `kb.categories.manage`; chunks retire; URLs tombstone. FR-04's "deletable" and EC-03's
  block-until-reassigned are served exactly.
- **A-44 (proposed)** — unnamed events the document requires: `kb.article.archived`,
  `kb.article.visibility_changed` (EC-11 high-visibility), `kb.category.changed` (tree
  cache purge). Named events (§3) are the enumeration; these three enter only by filed
  amendment at their steps if a consumer needs them (index removal on archive is
  in-transaction and needs no event).
- **A-45 (proposed)** — FR-04 seeding rides Doc 01's provisioning HOOK
  (`ProvisioningStep::KnowledgeBase`, the RoleSeeder pattern) — in-process, recorded in
  `tenant_provisioning_steps`; the EventCatalog's descriptive `Doc10/KnowledgeBaseSeeder`
  consumer note on `tenant.provisioned` is corrected to name the hook (no consumer; the
  ReopenConsumerDrillTest empty pin stays).
- **A-46 (proposed)** — T-04's "embed task added to [the orchestrator's] catalog change
  log": the built orchestrator serves embeddings through the `embeddings` ROUTING TIER, not
  a TaskCatalog task (the catalog is CLOSED at twelve and mirrors a CHECK). Reading: the
  tier IS the change-log entry; no `embed` task, no migration. Q9 shape.

---

## §0a — ADVISOR RULINGS FOLDED (paste of 2026-08-27, grounded at 45a58cc; family count 89 reconciled exact)

- **STOP-A APPROVED IN DIRECTION**, five conditions: (1) amendment 4 filed + countersigned BEFORE any migration; (2) any KbIndexer signature change = Doc 09 core-edit packet with the diff; (3) the OLD arbiter is DROPPED with the amendment-3 FLAG-2 proof — a static sweep that no `ON CONFLICT` targets it, plus a test; (4) AC-03/T-08 stage-kill proof (humans published, retrieval on the old generation until the swap, never a mix); (5) a proving test that the app role's DELETE on `kb_chunks` is REFUSED, and the prune proven under the platform role. → steps 2a/2b/6.
- **STOP-B APPROVED IN DIRECTION**: LIST-partition by audience, own indexes per partition; the chatbot path binds to the PUBLIC partition BY TABLE NAME with NO widening parameter anywhere on that path (static assertion; Copilot may see both). Same amendment 4. Three-way T-08 proof: (a) internal chunk structurally unreachable from the chatbot index; (b) Copilot reaches both; (c) a deliberately mis-written filter on the chatbot path STILL cannot return internal rows. HybridRetriever change = core-edit packet. → step 7 (test), step 2b (schema).
- **STOP-C RULED OPTION 2 (owner-confirmed)**: stand the ADR 0018 sidecar UP as an early S10 infra side-row — compose service, pinned bge-m3-class model, healthcheck + the liveness probe, no external egress. CI stays on the deterministic dims-compatible fake driver; ONE recorded local run of the retrieval suites against the REAL sidecar per checkpoint, captures kept. [D18] carries only the production/ops half. → **new step 0.7 (infra side-row), before step 1.**
- **STOP-D RULED AS PROPOSED; R-D10-1 AMENDED on the record** (its "provider page" premise was false — honest trail). The one commit = registry entry risk HIGH (38→39) + en/am labels + CatalogSnapshotTest/RoleSeederTest pin moves + SA/Admin template grants + the SWITCH DOOR only (POST `admin/ai/providers/routing` via ConfigVersions, audited high-visibility `ai.providers.switched` old/new; GET model map JSON); 113→114 exactly there, pasted before/after; NO Vue page. → step 0.4 executes.
- **A-43 APPROVED** (soft delete; never-404 survives deletion; audited; `kb.articles.delete`).
- **A-44 APPROVED** (rule 16 restated: unnamed events enter only by filed amendment with payload + consumers).
- **A-45 REJECTED — RULED THE OPPOSITE**: the catalog is normative and DECLARES `Doc10/KnowledgeBaseSeeder` as a `tenant.provisioned` consumer; FR-04 implements that DECLARED DURABLE consumer (ADR 0011), moving the ReopenConsumerDrillTest pin + the NFR-06 consumer fixture in the SAME commit; this also discharges the Doc 08 forward-note on declared-but-unimplemented consumers. **Standing instruction:** if provisioning ordering makes the consumer semantics impossible, STOP with the evidence — never fall back to the hook silently. (Known tension to verify at step 8: `TenantProvisioner::finish()` requires every `ProvisioningStep`, including `KnowledgeBase`, to be `completed` before the tenant goes Active, while a consumer of `tenant.provisioned` can only run AFTER that event — the step-8 packet states how `ProvisioningStep::KnowledgeBase` is satisfied, escalate-first.)
- **A-46 APPROVED**: no new TaskCatalog task — the orchestrator's embeddings routing tier is the mechanism; a filed CATALOG CHANGE LOG entry records it (T-04's change-log clause satisfied literally; the entry rides step 6's commit in this file's §11); TaskCatalog stays closed at twelve; T-04's substance (orchestrator-mediated batching, retry, index-pending on failure) proven.
- **ALSO ORDERED**: (1) risk 0k gets its lettered BUILD-STATUS block (text from handoff v9 §8) — routine line in the plan-approval commit; (2) this file carries one row per id (89) and the sabotage list names the two prompt-mandated falsifications (§7 items 1 and 2); (3) the plan-approval commit pushes the two unpushed commits with it; (4) the A-45 provisioning-ordering tension is a named check — step-8 packet, escalate-first.
- **THE PLAN IS APPROVED on these rulings.**

## §1 — Id count by family (from Doc 10's own sections; cross-document citations excluded —
Doc 10 cites Doc 09 FR-10/FR-14/FR-20/T-03/T-05/EC-06/AC-07, Doc 01 FR-06, Doc 05 FR-27,
Doc 07 FS-03, Doc 03/17 — each checked against the section text, none counted)

| FR | NFR | US | BR | T | SEC | EC | FS | SS | AC | R | Total |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 19 | 6 | 7 | 7 | 8 | 6 | 12 | 5 | 4 | 10 | 5 | **89** |

**Event family (FR-12 names four, FR-13 names two; closed at SIX):** `kb.article.viewed`
{surface}, `kb.article.cited_by_ai` {conversation}, `kb.article.inserted_by_agent`,
`kb.feedback.received` {score}, `kb.retrieval.updated`, `kb.article.published`. Payloads:
identifiers, language, version id, surface/score categories — never article text on the bus
(Q7). Foreseen additions: A-44 above.

**Permission keys (all from the CLOSED catalog; render-gates are derivations):**
`kb.view_internal` (internal article visibility in every staff read), `kb.articles.create`,
`kb.articles.edit` (editor, autosave, locks, AI tools — FR-06's own words), `kb.articles.delete`
(A-43 soft delete), `kb.articles.publish` (approve/publish/unpublish/archive/restore-to-draft
publish; visibility change — EC-11), `kb.categories.manage`, `kb.feedback.view` (stats header +
feedback list + actioned). Read of published articles by staff: `tickets.view` (the AC-08
"every staff member" precedent from Doc 09's banner route). No seventh key.

**Settings (registry; A-5 naming):** `kb.require_alt` bool default true tenant_editable
(AC-10); `kb.lock.heartbeat_seconds` int 90 platform (T-07); `kb.feedback.rate_per_hour` int
20 platform (SEC-05); `kb.feedback.comment_max_chars` int 500 platform (SEC-05);
`kb.render_cache_ttl_seconds` int 300 (FS-03's 5-min cap, validation max:300) platform;
`kb.mega_article_warn_words` int 5000 platform (EC-08); `kb.chunk_target_tokens` int 500
platform (T-02). Nothing that disables the one door, the alt gate, or the sanitizer.

**Audit-coverage rows:** kb.category.created/updated/deleted/reassigned; kb.article.created;
kb.variant.submitted/changes_requested/approved/published/self_published/unpublished/archived/
unarchived/restored; kb.article.visibility_changed (HIGH-VISIBILITY: old/new + actor);
kb.sanitizer.overridden (on the version); kb.lock.taken_over (BOTH parties, T-07);
kb.article.deleted; kb.feedback.actioned; kb.starter.seeded. **Notification categories:**
`notify.kb.review_requested`, `notify.kb.changes_requested`, `notify.kb.lock_taken_over`,
`notify.kb.index_pending` (user layer). **Feature flags:** none needed by the document.

---

## §2 — Schema (SCHEMA AMENDMENT 4, filed at step 2a BEFORE any migration; countersigned; every column R-19b-analyzed; ADR-0003 grant physics; FORCED RLS + tenant_isolation + REVOKE DELETE on every table)

`kb_categories` (tree, depth ≤ 3 by CHECK on materialized depth, per-language name jsonb,
slug, position, icon, ai_usable, visibility_default, deleted_at) · `kb_articles` (audience
public|internal CHECK, category_id FK, source_language, tags, related ids, seeded bool +
seeded_fingerprint, deleted_at) · `kb_article_variants` (language, status CHECK
draft|in_review|published|archived, translation_state CHECK current|outdated|missing,
translation_source human|ai, working_copy jsonb, working_copy_saved_at, current_version_id,
slug, index_status CHECK current|pending|failed, retrieval_generation int, lock-free — Redis;
UNIQUE (tenant, article, language); UNIQUE (tenant, language, slug)) · `kb_article_versions`
(IMMUTABLE by grant: app INSERT only; content jsonb snapshot, summary, author, note,
sanitizer_flags, sanitizer_override {by, at, reason}, published_at DB-stamped) ·
`kb_slug_history` (T-06/EC-06: language, old slug → variant; app INSERT only) ·
`kb_review_comments` (variant, version, block anchor, body, resolved_at; UPDATE to resolved
only) · `kb_feedback` (variant, visitor_hash / staff_id, score CHECK ±1, comment ≤ cap,
actioned_at; UNIQUE (tenant, variant, visitor_hash) = EC-10's dedupe arbiter, latest wins
by UPDATE) · `kb_chunks` delta per STOP-A/STOP-B. DB time only (C-11); `created_at`
app-denied everywhere; `KbGrantPhysicsTest` pins every denied set; `AiGrantPhysicsTest`'s
`DOC09_DENIED_COLUMNS['kb_chunks']` moves in the same packet (core edit).

---

## §3 — Steps (14; one commit each; safety tests BEFORE the features they constrain)

| # | Step | Safety-ordering justification |
|---|---|---|
| 1 | **The safety floor, before any authoring code exists**: (i) `KbOneIndexerDoorTest` — static source scan: the ONLY writer of `kb_chunks` in `app/` is `KbIndexer` (the OrchestratorTest:90 shape) + runtime: every row's `provenance.stripped_patterns` is set; (ii) the no-second-route sweep (no `DB::table('kb_chunks')->insert/update/upsert`, no raw SQL naming the table outside the door, no Eloquent model over it); (iii) the publish/retire ROUND-TRIP contract over the EXISTING indexer with fixture vectors: index → retrieve → retire → not served → a citation to the retired ref resolves as a tombstone stub (the FR-16 resolver interface, fixture-fed until step 7) → golden + red-team green; (iv) DOC 10 SUITES defined in BUILD-STATUS; traceability rows for all ten Doc 10 ACs seeded (the gate flips blocking — S08 precedent); `lang/{en,am}/kb.php` skeleton + `kb.` string-lint namespace | Prompt §11: one-door proof + strip sweep BEFORE any authoring path; round-trip BEFORE live index maintenance |
| 2a | SCHEMA AMENDMENT 4 filed (§2 + STOP-A/B resolutions as ruled) → **MANDATORY STOP** for countersign | B1 split: nothing touches the DB before the countersign |
| 2b | Migrations + `KbGrantPhysicsTest` + the `AiGrantPhysicsTest` pin move + the KbIndexer signature packet (core edit, countersigned in 2a) + `A-43/A-45/A-46` filed | Tables before writers |
| 3 | Structure: categories (FR-01 tree, depth, reassign-before-delete EC-03 with the retrieval-impact count), articles + variants (FR-02 both axes: audience article-level, status variant-level; BR-03 enforced by CHECK + service), working copy autosave door (FR-05, NFR-02, EC-12 crash banner fact), locks (T-07 Redis `volatile` 90 s heartbeat, takeover audits both, FS-05 optimistic fallback with version check), per-language slugs + transliteration helper (§14) | Model and locks before workflow |
| 4 | Workflow + versioning: FR-07 transitions (submit/request-changes/approve/publish/self-publish collapse audited), FR-08 immutable versions + block-aware diff + restore-creates-draft, SEC-04, AC-01, AC-07 (grant-level immutability proof), review comments (§8), EC-11 visibility change = full pipeline + high-visibility audit | AC-07 physics before any publish exists |
| 5 | Sanitizer + chunker: T-02 deterministic block-aware chunker (heading-path prefix, summary chunk 0, ~500 tokens, EC-08 mega handling) with the changed-chunks-only cost fixture (§12); T-03 shared package — `InstructionStripper` gains a non-destructive `scan()` returning flagged spans (core-edit packet; same PATTERNS constant, one rule set) so flags surface pre-publish (BR-06 override recorded on the version); EC-09 guide-style fixture set (near-zero false positives, threshold stated); AC-05 shared fixture run | Sanitizer proven before the pipeline consumes it |
| 6 | **The publish pipeline** (FR-13, T-01 swap, T-04, NFR-01/NFR-06, EC-04, FS-01, AC-03 stage-kill chaos, AC-10 alt gate): `kb.article.published` event → `KbIndexMaintenanceConsumer` (DURABLE, ADR 0011: registry + drill pin + `fixtures/tickets/consumers/doc10.json` + `fixtures/kb/consumers/doc10-events.json` same commit) → chunk → sanitize → embed (changed chunks) via the one door → ONE-statement generation swap → cache purge → `kb.retrieval.updated`; humans-live-immediately / `index_status=pending` + retry; unpublish/archive retire in-transaction. **Closing rows 1 (BS:57) and 3 (BS:88) flip here.** | Round-trip (step 1) and physics (2b) already green |
| 7 | Retrieval contract + separation + tombstones: FR-14 (HybridRetriever packet: returns version_id, heading_path, language; filters current generation — core edit), FR-15/SEC-01/T-08 structural separation as ruled (STOP-B), FR-16/T-06/EC-06/EC-07 tombstone resolver + slug history + version-pinned citation URLs (ChatbotTurn `renderAnswer` packet: `📄 <url>` — core edit; CopilotTab insert link target), AC-04, AC-06, SS-04 one-lookup forensics; **`ai_usable` ruling** (category flag re-derives the chunk flag; EC-02 move = the unpublish path) with its proving test; FS-04 `kb:index-rebuild` from rows (command + `no_grounding` posture note). **Closing rows 4 (BS:104), 6 (FLAG 3), 7 (BS:112) flip here.** | Consumers of the contract exist only after it is proven |
| 8 | Translation states + archive + seeding: FR-03/NFR-05/BR-05/EC-01/AC-02 (event-driven `outdated` ≤ 5 s via the publish consumer; missing-only fallback), FR-09 archive/un-archive, A-43 delete, FR-04/BR-07 starter set through `ProvisioningStep::KnowledgeBase` (fingerprinted, editable, deletable) + §14 AI-translate-all producing DRAFTS | Depends on the pipeline and the workflow |
| 9 | AI authoring (FR-06 ×5 tools, BR-02, AC-09 static + runtime never-publishes, US-01/06, SS-03): every tool = a Doc 09 orchestrator task producing an AI-accented DRAFT (`translation_source=ai`); draft-from-conversations consumes `WeeklyOutputs::topUnanswerable()` ids-only end-to-end against a cluster fixture, reading message text server-side under the egress guard; the intake door on the Decisions surface (JSON; the Vue affordance in step 12). **Closing rows 2 (BS:67) and 5 (BS:117) flip here.** | The never-publishes proof lands BEFORE any tool exists (in-step, test first) |
| 10 | Feedback + analytics + staff search: FR-11/SEC-05/EC-10 (visitor-token limiter, length cap, inert render, dedupe arbiter, anomaly flag event), FR-12 events + deflection attribution EVENT (formula [D15]), AC-08 stats header reconciling against a Doc 15 fixture, SEC-06 (query kept only in session unless the draft is created), FR-17 Meilisearch `t{...}_kb_articles` index via a durable consumer (FS-02 degrade per SearchProxy), Copilot top-3 blended (CopilotPanel packet: kb items gain title + url — core edit), NFR-03 dev-indicative | Hostile-input surfaces get their tests first |
| 11 | Consumption contracts + media + caches: FR-18 widget Help API under `WidgetGuard` (category browse, search, article view, feedback, "start chat" flag) → rendering [D13]; FR-19/BR-04 renderer contract (summary + link, numbered options) + `kb_article`/`kb_category` target types (Doc 05 CHECK expansion — core-edit packet) → [D05]/[D12]; T-05/FS-03 category-tree + render TenantCache with purge-on-publish (CDN/edge half [D18]); FR-05/SEC-02/EC-05 article images via a staff upload door → `ClamdClient::scan` → `MediaStore::store`/`signedUrl` (public long-lived URLs only after public publish; missing-media list blocks publish); SEC-03 inert render both surfaces | Contracts after the producer side is proven |
| 12 | Staff UI tail (Option A, R-D10-3 → **[B9]**): manager three-pane, editor (fixed FR-02 block set, language tabs as tablist, AI drawer, inline sanitizer warnings, publish dialog with audience/AI-scope/outdated list), review, history + diff, stats header, empty/loading/error states, INTERNAL banner (`--ds-note-*`), tombstone page (Tombstone.vue shape); tokens only; both colourways; 1440 + 375; browser specs with a dedicated healed+reprojected seeder (0l), `octane:reload` first | UI last; [B9] is a bucket |
| 13 | §14/§13/i18n: en + am for every `kb.*` string; long-string (Amharic) layout proofs; a11y (axe) sweep; R-14 join with the destructive tier flagged (category-delete-with-reassign, archive confirm, sanitizer override, visibility flip, restore, lock takeover); §12 perf legs dev-indicative (publish ≤ 30 s, diff ≤ 500 ms, swap ≤ 1 s at dev scale; 50k/L2 [D19]) | Hard gates as code |
| 14 | Close-out (protocol step 6): 89-id itemized self-audit, ledger item-by-item, closing-set flips with BS lines, [B9] state, inherited rows, core-edit enumeration, honest trail, R-14 totals, final battery + CI once; SHA grounded BEFORE the recount | — |

**Checkpoint batches:** A = steps 1–2b · B = 3–6 · C = 7–10 · D = 11–14. Mandatory mid-batch
stops only (the seven of prompt §12): 2a countersign; every core-edit packet (KbIndexer,
InstructionStripper, HybridRetriever, ChatbotTurn render, CopilotPanel/CopilotTab,
AiGrantPhysicsTest pins, Doc 05 target CHECK, ReopenConsumerDrillTest pins, Doc 01
provisioner hook line); any enumeration amendment; a red battery; a design gap/conflict.

---

## §4 — Inherited rows (handoff v9 §4 / prompt §9), dispositions

| Row | Disposition |
|---|---|
| 0a `ai.providers.manage` | DISCHARGE at step 0.4 per R-D10-1 — shape per STOP-D ruling; 113→114 pasted before/after |
| 0b row 4b | DISCHARGED at `6cc524b` (verified by diff; annotation only) |
| 0c key rotation | OWNER-ACTION, restated at every checkpoint |
| 0d Addis AI DPA-or-switch | OWNER-ACTION [OA]; not this build (fixtures-only by mechanism) |
| 0e Batch 8 track + [B8] pass | OUT OF SCOPE — the advisor orders it separately; STOP-D's door is the seam it lands on |
| 1 no credential gate | Step 0 executed as written; the sidecar probe found the sidecar ABSENT (STOP-C) |
| 2 [OA] Doc 05 rows | CARRY, parked |
| 3 risks 0a–0l | CARRY; 0k escalate-first (NOTE: 0k's lettered row is absent from BUILD-STATUS — only line 144's pointer and handoff v9 §8 describe it; filed in the honest trail, row text to be added by ruling); 0l binds every KB browser fixture |
| 4 R-14 queue | CARRY + GROW at step 13 with the destructive tier flagged |
| 5 `shouldRenderJsonWhen` | Per-door interim on every KB JSON door (validate() caught locally); evidence filed |
| 6–11 | Unchanged |
| parked | Doc 01 QuotaTest order-dependence; partition-maintenance fold-in; Doc 07 channel-health ↔ AI-screen cross-link — untouched |

## §5 — The [D10] closing set (enumerated from the rows; matches R-D10-4 exactly: 5 tagged + 2 reopeners)

| # | Row | BUILD-STATUS line | Closed by |
|---|---|---|---|
| 1 | Doc 09 FR-10 | BS:57 | step 6 (live articles feed retrieval; publish-event maintenance) |
| 2 | Doc 09 FR-20 [D10] half | BS:67 | step 9 (draft-from-conversations consumes the ids-only seam) |
| 3 | Doc 09 T-05 | BS:88 | step 6 (index maintenance on publish events) |
| 4 | Doc 09 EC-06 [D10] half | BS:104 | step 7 (tombstone resolution + cache invalidation) |
| 5 | Doc 09 SS-02 | BS:117 | step 9 (the loop end-to-end against the cluster fixture) |
| 6 | kb_chunks `ai_usable` interim | SCHEMA AMENDMENT 3 FLAG 3 | step 7 ruling + proving test (category flag re-derives; EC-02/EC-03 edges) |
| 7 | pgvector rebuild note | BS:112 | step 7 `kb:index-rebuild` = FS-04 |

## §6 — Pre-agreed closure buckets (the ONLY lawful PARTIAL homes)

| Bucket | Holds | Unblocker |
|---|---|---|
| [B9] | pixel fidelity of every Doc 10 staff surface (step 12) | Batch 9 freeze (R-D10-3) |
| [D13] | widget Help tab RENDERING (FR-18 halves, §9 widget tokens, §13 widget AA) | Doc 13 |
| [D05]/[D12] | Telegram KB-flow RENDERING (FR-19 halves; menu binding UI) | those sessions |
| [D15] | deflection FORMULA + report surfaces (FR-12 half, SS-02 report, AC-08 dashboard half) | Doc 15 |
| [D16] | review-checklist content (§8); UI-string tooling (§3) | Doc 16 |
| [D18]/[D19] | CDN/edge cache + purge operational half (T-05, FS-03, §12 edge ≤ 50 ms); 50k chunks / L2 legs; the NFR-06 week-long staging probe (AC-03 half); the sidecar stand-up (STOP-C) | staging/perf windows |
| [OA] | native Amharic review (R-14) | owner |

## §7 — Sabotage enumeration (THIRTEEN; floor ten; grown only by filed amendment; captures first; fail AND restored-pass kept; positive control per assertion)

1. **Unstripped authoring route** planted (a second `DB::table('kb_chunks')->insert` in the publish service bypassing KbIndexer) → `KbOneIndexerDoorTest` static + runtime red (step 1/6).
2. **Retire that DELETES** (archive path issues DELETE instead of the flag flip) → grant physics red at the REVOKE line + round-trip red (step 1/8).
3. AI tool sets `status=published` → AC-09 static + runtime red (step 9).
4. Internal chunk reachable from the chatbot path (public source pointed at the parent / filter dropped) → T-08 structural red (step 7).
5. In-place version rewrite (UPDATE on `kb_article_versions.content`) → AC-07 grant red (step 4).
6. Stage kill between embed and swap leaves a mixed generation (swap split into two statements) → AC-03 chaos red (step 6).
7. Sanitizer stubbed (`scan()` returns none) → AC-05 shared fixture red (step 5).
8. Once-cited URL 404s (slug-history lookup removed) → AC-06 red (step 7).
9. Feedback dedupe bypassed (arbiter removed) → EC-10 red (step 10).
10. Missing-vs-outdated fallback inverted (outdated falls back) → BR-05/AC-02 red (step 8).
11. Alt-text gate disabled → AC-10 red (step 6).
12. Visibility flipped by a plain UPDATE without the pipeline → EC-11 audit + index red (step 4).
13. Publish consumer removed from `ConsumerRegistry` → drill pin + NFR-06 fixture red (step 6).

## §8 — "DOC 10 SUITES" (standing rule 14; defined now; the Doc 09 floor is INSIDE it)

```
./vendor/bin/pest tests/Feature/Kb \
                  tests/Contract/KbEventConsumerContractsTest.php \
                  tests/Isolation/KbGrantPhysicsTest.php \
                  tests/Security/KbOneIndexerDoorTest.php \
                  tests/Security/KbAiNeverPublishesTest.php \
                  tests/Security/KbIndexSeparationTest.php \
                  tests/Security/KbFeedbackHostileInputTest.php \
                  tests/Feature/Ai/RetrievalTest.php \
                  tests/Golden \
                  tests/Security/AiInjectionRedTeamTest.php \
                  tests/Security/AiEgressGuardTest.php
```
Files later steps create are named now (the record-note precedent). Every step-commit:
DOC 10 SUITES + full battery + `make gates` + `make lint` (no pipes, `&&` chains) regardless of
BAT1; §12 timing legs wait on wall power ("timing not taken" said plainly). CI once per
checkpoint (billing annotation first; 0i/0k triage). `octane:reload` before every browser run.

## §9 — Plan table of record (ONE row per id: id → file(s) → proving test; paths under `app/Domain/Kb/` unless noted; tests under `tests/`)

| Id | File(s) | Proving test |
|---|---|---|
| FR-01 | Categories/{CategoryTree,CategoryWriter}.php; kb_categories | Feature/Kb/CategoryTreeTest.php (depth 3, per-language name, ai_usable, reassign-before-delete) |
| FR-02 | Articles/{Article,Variant,ArticleWriter}.php; block set Content/BlockSet.php | Feature/Kb/ArticleModelTest.php (article-level audience, variant-level status, fixed block set, summary line) |
| FR-03 | Translation/TranslationState.php (+ publish consumer arm) | Feature/Kb/TranslationStateTest.php (matrix; ≤ 5 s event-driven) |
| FR-04 | Seeding/StarterSeeder.php + Doc 01 hook line (core edit) | Feature/Kb/StarterSeedingTest.php + Foundation provisioning test extension |
| FR-05 | Editor/{WorkingCopy,Locks}.php; Media/ArticleMediaUploader.php | Feature/Kb/WorkingCopyTest.php + LocksTest.php + ArticleMediaTest.php |
| FR-06 | Authoring/{DraftFromSource,TranslateVariant,Simplify,FaqVariants,DraftFromConversations}.php | Feature/Kb/AiAuthoringTest.php + Security/KbAiNeverPublishesTest.php |
| FR-07 | Workflow/{Transitions,ReviewComments}.php | Feature/Kb/WorkflowTest.php (AC-01 matrix incl. self-publish collapse) |
| FR-08 | Versioning/{Versions,BlockDiff,Restore}.php | Feature/Kb/VersioningTest.php + Isolation/KbGrantPhysicsTest.php |
| FR-09 | Workflow/Archive.php | Feature/Kb/ArchiveTest.php (removed from all surfaces; tombstone; un-archive → draft) |
| FR-10 | Visibility/Audience.php + retriever/search filters + INTERNAL banner payload | Feature/Kb/VisibilityTest.php + Security/KbIndexSeparationTest.php |
| FR-11 | Feedback/{FeedbackWriter,VisitorLimiter}.php | Feature/Kb/FeedbackTest.php + Security/KbFeedbackHostileInputTest.php |
| FR-12 | Events (EventCatalog +6, closed payloads); Analytics/UsageEvents.php; deflection attribution event | Contract/KbEventConsumerContractsTest.php + event-catalog gate [D15 formula] |
| FR-13 | Publish/{PublishPipeline,KbIndexMaintenanceConsumer}.php (app/Jobs/Kb/…) | Feature/Kb/PublishPipelineTest.php (stages, atomic swap, invalidation, events) |
| FR-14 | HybridRetriever packet (Doc 09 core edit) | Feature/Ai/RetrievalTest.php extension (version_id, heading_path, language) |
| FR-15 | STOP-B shape (partition + PublicChunkSource) | Security/KbIndexSeparationTest.php (structural, three proofs) |
| FR-16 | Tombstones/{TombstoneResolver,SlugHistory}.php; Http/Controllers/Kb/ArticleResolveController.php | Feature/Kb/TombstoneTest.php (AC-06 fixtures) |
| FR-17 | Search/{KbSearchIndex,KbSearchDocuments,KbSearchIndexer}.php; CopilotPanel packet | Feature/Kb/StaffSearchTest.php (filters; FS-02 degrade; Copilot blend) |
| FR-18 | Http/Controllers/Widget/HelpController.php (WidgetGuard) | Feature/Kb/WidgetHelpContractTest.php [D13 render] |
| FR-19 | Telegram/KbFlowRenderer.php + target CHECK (Doc 05 core edit) | Feature/Kb/TelegramFlowContractTest.php (summary + link; numbered) [D05/D12] |
| NFR-01 | pipeline timing leg | Performance/KbBudgetsTest.php (dev-indicative; states visible) |
| NFR-02 | autosave door + Redis lock | Performance/KbBudgetsTest.php autosave leg |
| NFR-03 | Meilisearch + widget API | Performance/KbBudgetsTest.php search leg [D18 CDN] |
| NFR-04 | versions cursor-paginated history | Feature/Kb/VersioningTest.php (1k versions listing) |
| NFR-05 | TranslationState via the publish consumer (no scan job exists — asserted) | Feature/Kb/TranslationStateTest.php |
| NFR-06 | one-statement swap | Feature/Kb/PublishAtomicityTest.php (AC-03 chaos) [D19 week probe] |
| US-01 | paste T&Cs → draft → publish en → am outdated | Feature/Kb/JourneysTest.php |
| US-02 | block diff | Feature/Kb/VersioningTest.php diff fixture set |
| US-03 | Copilot insert-as-link with URL | Feature/Ai/CopilotPanelTest.php extension |
| US-04 | widget search → 👎 → start chat → feedback lands | Feature/Kb/WidgetHelpContractTest.php |
| US-05 | grounding only on published sanitized versioned chunks | Security/KbIndexSeparationTest.php + PublishPipelineTest.php |
| US-06 | weekly top-10 → one-click draft | Feature/Kb/AiAuthoringTest.php (cluster fixture) |
| US-07 | internal playbook findable, never citable | Security/KbIndexSeparationTest.php |
| BR-01 | published-only consumption everywhere | Feature/Kb/VisibilityTest.php |
| BR-02 | no AI path sets published | Security/KbAiNeverPublishesTest.php (static + runtime) |
| BR-03 | one audience per article | Feature/Kb/ArticleModelTest.php + CHECK |
| BR-04 | summary-plus-link renderer contract | Feature/Kb/TelegramFlowContractTest.php |
| BR-05 | outdated serves; fallback on missing only | Feature/Kb/TranslationStateTest.php |
| BR-06 | override recorded on the version | Feature/Kb/SanitizerTest.php |
| BR-07 | seeded = deletable content | Feature/Kb/StarterSeedingTest.php |
| T-01 | migrations (amendment 4) + generation swap | Isolation/KbGrantPhysicsTest.php + PublishAtomicityTest.php |
| T-02 | Chunking/DeterministicChunker.php | Feature/Kb/ChunkerTest.php (determinism; changed-chunks-only cost fixture) |
| T-03 | InstructionStripper `scan()` packet + Sanitizer/PublishSanitizer.php | Feature/Kb/SanitizerTest.php + AiInjectionRedTeamTest (shared) |
| T-04 | embeddings via the orchestrator's embeddings tier (A-46), batched, ai-background queue | Feature/Kb/PublishPipelineTest.php (pending + retry) |
| T-05 | Cache/{CategoryTreeCache,RenderCache}.php purge-on-publish; Telegram bindings by id | Feature/Kb/CachesTest.php [D18 edge] |
| T-06 | TombstoneResolver + kb_slug_history + citation URL (version id) | Feature/Kb/TombstoneTest.php |
| T-07 | Editor/Locks.php (volatile Redis, 90 s, takeover audits both) | Feature/Kb/LocksTest.php |
| T-08 | the four contract suites | PublishAtomicityTest + SanitizerTest + TranslationStateTest + TombstoneTest + KbIndexSeparationTest |
| SEC-01 | STOP-B | Security/KbIndexSeparationTest.php |
| SEC-02 | ArticleMediaUploader → clamd → MediaStore; public URLs after public publish | Feature/Kb/ArticleMediaTest.php |
| SEC-03 | Content/InertRenderer.php (script/style stripped at save; inert render) | Security/KbInertRenderTest.php |
| SEC-04 | audit rows + version immutability + high-visibility visibility change | Isolation/KbGrantPhysicsTest.php + audit gate + WorkflowTest |
| SEC-05 | VisitorLimiter + length cap + inert comment render | Security/KbFeedbackHostileInputTest.php |
| SEC-06 | create-from-query shortcut stores nothing beyond session unless created | Feature/Kb/StaffSearchTest.php |
| EC-01 | translator's open editor survives source publish; badge live | Feature/Kb/TranslationStateTest.php |
| EC-02 | move to non-ai_usable = retire path | Feature/Kb/CategoryTreeTest.php + RetrievalTest extension |
| EC-03 | delete blocked until reassigned; impact count | Feature/Kb/CategoryTreeTest.php |
| EC-04 | two variants one click → independent states | Feature/Kb/PublishPipelineTest.php |
| EC-05 | restore with retention-deleted media → placeholders; publish blocked | Feature/Kb/ArticleMediaTest.php |
| EC-06 | slug collision → auto-suffix + warning; both URLs resolve | Feature/Kb/TombstoneTest.php |
| EC-07 | cited seconds before unpublish → tombstone; version pinned | Feature/Kb/TombstoneTest.php |
| EC-08 | 20k-word article → soft warning at 5k; chunker handles | Feature/Kb/ChunkerTest.php |
| EC-09 | user-directed imperatives not flagged | Feature/Kb/SanitizerTest.php guide fixtures |
| EC-10 | dedupe + rate limit + anomaly flag | Feature/Kb/FeedbackTest.php |
| EC-11 | internal→public = full pipeline + high-visibility audit | Feature/Kb/WorkflowTest.php |
| EC-12 | working_copy recovery banner fact | Feature/Kb/WorkingCopyTest.php |
| FS-01 | embeddings down → pending; retry drains; previous generation serves | Feature/Kb/PublishPipelineTest.php |
| FS-02 | Meilisearch down → degrade; retrieval unaffected | Feature/Kb/StaffSearchTest.php |
| FS-03 | purge failure → stale ≤ 5 min; retried; degraded stage reported | Feature/Kb/CachesTest.php [D18] |
| FS-04 | `kb:index-rebuild` from rows | Feature/Kb/IndexRebuildTest.php |
| FS-05 | Redis down → optimistic concurrency + conflict | Feature/Kb/LocksTest.php |
| SS-01 | 3 articles republished → new versions cited, old tombstone | Feature/Kb/JourneysTest.php |
| SS-02 | deflection events + feedback list | PARTIAL [D15] — events + fixture reconcile here |
| SS-03 | cluster → draft → review → publish | Feature/Kb/JourneysTest.php (handoff-rate drop [D15]) |
| SS-04 | one-lookup forensics by version id | Feature/Kb/TombstoneTest.php |
| AC-01 | workflow matrix | Feature/Kb/WorkflowTest.php |
| AC-02 | translation matrix | Feature/Kb/TranslationStateTest.php |
| AC-03 | stage-kill chaos | Feature/Kb/PublishAtomicityTest.php [D19 staging week] |
| AC-04 | retrieval contract + structural separation | RetrievalTest extension + KbIndexSeparationTest |
| AC-05 | shared sanitizer fixtures + guide fixtures + overrides | Feature/Kb/SanitizerTest.php |
| AC-06 | tombstone fixtures, zero 404 | Feature/Kb/TombstoneTest.php |
| AC-07 | immutable versions (DB), diff, restore | VersioningTest + KbGrantPhysicsTest |
| AC-08 | events + dedupe + stats header vs Doc 15 fixture | FeedbackTest + Analytics test [D15 render] |
| AC-09 | AI-accented drafts; never publishes; cluster e2e | AiAuthoringTest + KbAiNeverPublishesTest |
| AC-10 | alt-text gate blocks + lists | Feature/Kb/PublishPipelineTest.php |
| R-01 | content rot → badges, tombstones, pinned citations, loop | mitigations across steps 6–9 |
| R-02 | sanitizer arms race → shared package, fixtures, override accountability | SanitizerTest + runbook pointer |
| R-03 | translation debt visible → states + AI-translate drafts | TranslationStateTest |
| R-04 | CMS creep → fixed block set | ArticleModelTest (block set closed) |
| R-05 | split confusion → stats header shows AI-usability + index status | Analytics test + UI [B9] |

## §11 — Catalog change log (A-46; T-04's clause)
- `embed`: NOT a TaskCatalog task. Embeddings are served by the orchestrator's `embeddings` ROUTING TIER (`config/ai.php default_routing.embeddings` → ADR 0018 sidecar), batched from the ai-background queue by the KB publish consumer; failure → `index_status=pending` + retry. Recorded here as the change-log entry of record.

## §10 — STEP 0 record (evidence pasted in the step-0 report; captures at `docs/evidence/S10-step0/`)
Solo guard: two stale remote-transport trees killed; one session. Prompt integrity 6 / `Then STOP.`.
0.1 filings all present with commits. 0.2 count EXECUTED 113; six `kb.*` keys present (+`kb.articles.delete`).
0.3 + 0.5 already landed at `6cc524b`. 0.4 = STOP-D. 0.6 = §5 (7/7, R-D10-4 exact).
Sidecar probe: ABSENT (STOP-C). `design/frozen` ls-verified: 187 files (+ `design/batch-8`: 15);
no Doc 10 frame exists (program report names Doc 10 undesigned) → [B9].
