Table of Contents

# Document 10 — Knowledge Base

**Product:** AI-First Support Platform for Betting Companies **Document series:** 10 of 19 · Standalone enterprise specification **Audience:** Backend engineers, frontend engineers, content designers, QA, product, operator admins **Depends on:** Doc 01 (tenancy, events, i18n), Doc 03 (permissions kb.*), Doc 04 (media pipeline for article images), Doc 09 (retrieval contract, sanitizer hook, article-from-conversations). Consumed by: Doc 07 (Copilot KB inserts), Doc 09 (RAG source of truth), Doc 12 (bot menu KB flows), Doc 13 (widget Help tab), Doc 15 (deflection metrics). **Stack context:** Laravel, PostgreSQL, pgvector (chunks), Meilisearch (human search), S3 media

## 1. Executive Summary

This document specifies the knowledge management system: categories, articles with per-language variants and independent translation states, the authoring editor with AI assistance (draft-from-source, translate, simplify, generate-from-conversations), the publishing workflow (Draft → In review → Published → Archived) with separated write and publish permissions, full version history with diff and restore, internal vs public visibility, article feedback and usage analytics (including how often the AI chatbot cited each article), the retrieval contract that makes published content the *only* grounding source for Doc 09’s chatbot (chunking, embedding maintenance, the injection-sanitizer hook, cache invalidation on publish), human-facing search and browse surfaces (staff manager, Copilot inserts, widget Help tab, Telegram KB flows), starter templates seeded at tenant provisioning, and the tombstone behavior for cited-then-changed articles. The KB is simultaneously a content product for humans and the grounding corpus for AI — every requirement serves both masters explicitly.

## 2. Purpose

- Give operators a no-code content system whose published output is trustworthy enough to be the chatbot’s only permitted knowledge.

- Specify the publish pipeline’s side effects (embeddings, caches, sanitizer) so Doc 09’s grounding guarantees actually hold.

- Close the quality loop: usage and feedback data drive what gets written next (the Doc 09 FR-20 unanswerable-questions integration lands here).

## 3. Scope

**In scope:** category tree; article model with language variants; editor & AI authoring tools; workflow states & permissions; versioning/diff/restore; visibility (public/internal); AI-usable scoping; feedback capture; usage analytics feeds; retrieval pipeline (chunking, embedding, sanitizing, invalidation); human search; consumption surfaces’ contracts (Copilot, widget Help, Telegram flows); starter templates; tombstones; media in articles; events. **Out of scope:** the retrieval *query* mechanics at answer time (Doc 09 T-05); widget Help tab layout (Doc 13 renders this doc’s contract); bot menu flow builder (Doc 05/12); a public help-center website (future, §19); translation-manager tooling for UI strings (Doc 16 — this doc covers article content only).

## 4. Functional Requirements

### 4.1 Structure & model

FR-01 Categories: single-tree hierarchy, max depth 3; fields: name (per language), slug, description, position, icon, ai_usable (bool — Doc 09 FR-10 scope), visibility default for children; deleting a category requires reassigning its articles first (Doc 07-era rule retained). FR-02 Article: one logical article = N language variants sharing an article id; per-variant fields: title, slug, body (rich content: headings, lists, tables, images via Doc 04 media pipeline, callout blocks, anchors), status (per-variant workflow — an article can be Published in am and Draft in en), visibility (public | internal) shared at article level (not per variant — one audience decision), tags, related-articles links, SEO-ish summary line (used as search snippet + retrieval chunk header). FR-03 Translation states per variant relative to the source variant (operator marks one language as source per article): current | outdated (source changed after this variant's last publish) | missing; outdated is computed automatically on source publish and surfaced everywhere (badges, dashboards). FR-04 Starter templates: tenant provisioning (Doc 01 FR-06) seeds Draft articles — Deposit FAQ, Withdrawal FAQ, Verification guide, Bonus rules skeleton, Responsible play — in the tenant default language, marked seeded (editable, deletable; deleting is allowed since they’re content, not config).

### 4.2 Authoring & AI assistance

FR-05 Editor: block-based rich editor with the FR-02 block set; autosave drafts every 5 s (versioned as working copy, not history entries); concurrent-edit protection: per-variant soft lock (“Hanna is editing”) with takeover (lock steal notifies the holder, both audited); image uploads go through Doc 04’s scan/store path. FR-06 AI tools (permission kb.articles.edit + Doc 09 tasks): **Draft from source** (paste T&Cs/policy text → structured article draft), **Translate variant** (source → target, producing a Draft in the target language, translation_source=ai marked until human publish), **Simplify** (reading-level pass), **Generate FAQ variants** (Q-phrasing alternatives appended as an “Also asked” block), **Draft from conversations** (Doc 09 FR-20’s one-click: takes a cluster of unanswered conversations → draft with the questions as headings). All AI drafts are visually AI-accented in the editor until edited/published; AI never publishes (BR-02). FR-07 Review workflow: Draft → (submit) → In review → (approve: kb.articles.publish) → Published; reviewers can request changes (comment thread per variant, resolved-state tracked); direct publish allowed for users holding both edit+publish (small-team reality) with the review step auto-collapsed but still audited as self-published. FR-08 Versioning: every publish creates an immutable version (full content snapshot + author + note); diff view (block-aware: changed/added/removed blocks) between any two versions; restore = new draft from an old version (never in-place rewrite of history). FR-09 Archive: Published → Archived removes from all consumption surfaces (search, retrieval, flows) but keeps URLs resolving to tombstones (FR-16); un-archive returns to Draft (must re-publish — content may be stale).

### 4.3 Visibility, AI scope, feedback

FR-10 public: retrievable by chatbot (if category ai_usable), listed in widget Help, linkable in Telegram flows, insertable by Copilot as customer-facing links. internal: Copilot-retrievable and staff-searchable only; never cited to customers; rendered with an INTERNAL banner in all staff views (leak-prevention salience). FR-11 Feedback: customer-facing renders (widget article view, and a feedback prompt after Telegram KB-flow answers) capture 👍/👎 + optional comment (rate-limited per visitor); staff can leave internal feedback notes on any article; aggregated per variant. FR-12 Usage analytics feeds (events → Doc 15): kb.article.viewed {surface}, kb.article.cited_by_ai {conversation}, kb.article.inserted_by_agent, kb.feedback.received {score}, plus deflection attribution: a view/citation followed by no ticket in that conversation within 24 h counts toward the article’s deflection metric (formula owned by Doc 15’s dictionary; the events defined here).

### 4.4 Retrieval pipeline (the Doc 09 contract, producer side)

FR-13 On publish of a public variant in an ai_usable category: chunking (block-aware: ~500-token chunks with heading-path prefixes and the FR-02 summary line as chunk 0), **injection sanitizing** (Doc 09 T-03’s hook: strips imperative-to-assistant patterns; flagged phrases surfaced to the publisher as warnings pre-publish — “this sentence may be misread as an instruction to the AI”), embedding per chunk (task via Doc 09 orchestrator), atomic index swap for that article (no partial-article states in retrieval), retrieval-cache invalidation, and kb.retrieval.updated event. Unpublish/archive removes chunks atomically. FR-14 The retrieval store answers Doc 09 FR-10 queries with: chunk text, article id, variant language, heading path, version id — version id lets Doc 09’s citations pin what was actually shown (dispute forensics). FR-15 Internal articles embed into a separate Copilot-only index (Doc 09 respects the split; enforced by index-level separation, not query filters — structural, per the platform’s isolation style).

### 4.5 Search, consumption, tombstones

FR-16 Tombstones: an unpublished/archived/superseded-version article URL (from old citations, Doc 09 EC-06) renders “This article was updated/removed” with a link to the current version when one exists; never a 404 for a once-cited URL. FR-17 Staff search (manager + Copilot): Meilisearch over title/body/tags/summary with language + visibility + status filters; Copilot’s top-3 (Doc 09 FR-14) uses retrieval (semantic) blended with this keyword index. FR-18 Widget Help tab contract (Doc 13 renders): category browse (public, published, in visitor language with fallback chain), search-as-you-type, article view with feedback, “still need help → start chat” always present. FR-19 Telegram KB flows (Doc 05 FR-27 menu targets): a category or article bound to a menu button renders as: article summary + “read more” link (direct mode) / summary + numbered related options (business mode); full articles are never dumped into chat (summary + link discipline).

## 5. Non-functional Requirements

NFR-01 Publish pipeline (chunk+sanitize+embed+swap) ≤ 30 s p95 for a 3,000-word article; the article is live to humans instantly, to retrieval on swap completion (states visible to the publisher). NFR-02 Editor autosave ≤ 300 ms p95; lock acquisition instant (Redis). NFR-03 Staff search ≤ 120 ms p95 at 5k articles; widget search ≤ 200 ms p95 (public CDN-cached category tree, search via API). NFR-04 Version storage: full snapshots (content is small relative to messages); 1k versions/article supported without listing degradation (paginated history). NFR-05 Translation-state computation is event-driven (source publish) — never a scan job; badge freshness ≤ 5 s. NFR-06 Zero retrieval states where an article is half-updated (FR-13 atomic swap; asserted by a consistency probe in staging).

## 6. User Stories

US-01 As an operator admin, I paste our new bonus T&Cs, get a structured draft, fix two sentences, publish in English, and see Amharic flagged “outdated” until my colleague updates it. US-02 As a content reviewer, I see exactly which blocks changed since the last version before I approve. US-03 As an agent, Copilot offers the right article and I insert it as a link without leaving the composer. US-04 As a player in the widget, I search “withdrawal time”, read the answer, tap 👎 because my case differs, and start a chat — my feedback reaches the content team. US-05 As the platform’s AI, I only ever ground on published, sanitized, versioned chunks — and my citations can be traced to the exact version the customer saw. US-06 As an operator admin, the weekly “top unanswerable questions” gives me a one-click draft that closes the gap (Doc 09 SS-02 realized here). US-07 As a compliance reviewer, the internal payout-escalation playbook is findable by staff and provably never citable to customers.

## 7. Business Rules

BR-01 Published means accountable: only published content reaches customers or grounds the chatbot; drafts and review states are invisible outside authors/reviewers. BR-02 AI assists, humans publish: no AI path sets status=Published (FR-06); the publish permission is the human accountability point. BR-03 One audience per article (FR-02 visibility at article level): mixed public/internal variants of one article are prohibited — split into two articles; prevents variant-level leak mistakes. BR-04 Summary-plus-link in chat surfaces (FR-19): chat is for answers, articles are for depth; dumping walls of text into Telegram is prohibited by rendering contract, not guideline. BR-05 Outdated translations still serve (with their badge staff-side) until updated — stale-but-published beats silently falling back to a language the customer didn’t choose; the fallback chain applies only to missing. BR-06 Sanitizer warnings (FR-13) can be overridden by the publisher (they may be false positives) but the override is recorded on the version — accountability without paternalism. BR-07 Seeded starter articles are suggestions, not fixtures — full operator ownership including deletion (contrast with Doc 03’s seeded roles).

## 8. UX Requirements

- **Manager layout:** three-pane (category tree with drag-reorder + counts / article list with status+translation badges, filters, search / editor). List badges: status pill per workflow state, language completeness dots (● current ◐ outdated ○ missing per active language), visibility tag (INTERNAL in amber), AI-usable category inheritance shown on hover.

- **Editor:** block toolbar; language tabs across the top with the state dots; the AI tools in a right-side drawer (results stream in AI-accented blocks); sanitizer warnings render inline on the offending sentences at publish time with keep/edit choices; publish dialog shows: audience (public/internal), AI-usability (inherited, with link to category), languages being published, sanitizer summary, and — for source-language publishes — the list of variants that will flip to “outdated”.

- **Review:** request-changes comments anchor to blocks; a review checklist (accuracy, tone, links) configurable per tenant (Doc 16 content); approve/publish button reflects the caller’s permissions.

- **History:** version list with author/note/badges; block-diff view with prev/next changed-block navigation; restore-to-draft with confirmation explaining it creates a new draft.

- **Feedback ****&**** analytics per article:** a stats header (views by surface sparkline, AI citations count, helpful %, deflection contribution) with a feedback comments list (paginated, mark-actioned).

- **Empty states:** fresh tenant shows the seeded drafts with a “publish your first article” checklist; empty category (“no articles yet — create or move one here”); no search results with query echo + “create article from this query” shortcut for staff.

- **Loading:** tree instant (cached), list skeletons, editor content-first then toolbars; publish shows the pipeline stages (saving → sanitizing → indexing) with per-stage ticks (NFR-01 states).

- **Error states:** publish pipeline stage failure → article stays live-to-humans as published, retrieval marked “index pending” with retry (the split is explained honestly); lock takeover notice; media upload failures per Doc 04 patterns.

## 9. UI Requirements

- Language dots (●◐○) always paired with tooltips text (AA, never color/shape alone… shape+fill+tooltip satisfies).

- INTERNAL banner: amber, full-width in editor and article views, repeated above the fold on long articles.

- Sanitizer inline warnings: dotted amber underline + popover with the flagged pattern and rationale.

- Diff view: added=green tint blocks, removed=red tint collapsed stubs (expandable), changed=paired old/new; color-blind safe with +/− glyphs.

- Widget-facing article rendering tokens come from the theme (Doc 16); staff manager uses the platform design system; both light/dark.

- Responsive: manager usable on tablet; editor read-only on phones with an edit-requires-larger-screen notice (Doc 03/05 policy consistency); widget Help fully mobile-first (Doc 13).

## 10. Technical Requirements

T-01 Tables: kb_categories, kb_articles (audience, ai-usable via category), kb_article_variants (language, status, working_copy jsonb, current_version_id), kb_article_versions (immutable snapshots), kb_review_comments, kb_feedback, kb_chunks (variant_id, version_id, position, heading_path, text, embedding vector, sanitizer_flags jsonb) with index-swap via a retrieval_generation column (query always filters current generation; swap = update pointer, delete old async). T-02 Chunking: block-aware splitter targeting ~500 tokens, heading-path prefixes, summary-line chunk 0; deterministic (same content → same chunks) for diff-friendly re-embedding (only changed chunks re-embed on minor edits — cost control). T-03 Sanitizer: rule set shared with Doc 09 T-03 (single package); runs at publish producing flags; the override decision stored on the version (BR-06). T-04 Embeddings via the Doc 09 orchestrator (embed task added to its catalog change log) on the ai queue, batched; failure → “index pending” state + retry (see §8 error state). T-05 Caches: public category tree + article renders CDN/edge-cached with purge on publish (widget consumption); Copilot/staff search index updated from events; Telegram flow bindings resolve articles by id at serve time (no content duplication into flow configs). T-06 Tombstone resolution: article/variant URLs keyed by stable ids + slugs with slug-history redirects; citation URLs embed version id → tombstone logic per FR-16. T-07 Locks: Redis per-variant with 90 s heartbeat TTL; takeover writes both audit entries. T-08 Contract tests: publish-pipeline atomicity (kill between stages → humans see published, retrieval sees old generation until swap — never a mix); sanitizer fixture set (shared with Doc 09 AC-07); translation-state matrix; tombstone/redirect suite; index-separation proof (internal chunks unreachable from the chatbot index, structural test).

**Publish pipeline (text diagram):**

publish(variant) → version snapshot → [humans: live immediately]
   → chunk (deterministic) → sanitize (flags→version) → embed (changed chunks)
   → swap retrieval_generation → purge caches → events(kb.retrieval.updated, kb.article.published)
unpublish/archive → remove generation atomically → tombstone active

## 11. Security Requirements

SEC-01 Internal/public index separation is structural (FR-15/T-08) — a query-filter bug cannot leak internal content to customers. SEC-02 Article media follow Doc 04’s media security (scanned, signed URLs, separate origin); public article images get long-lived CDN URLs only after publish of a public article. SEC-03 Editor content is sanitized on render everywhere (staff and customer surfaces) — articles are less hostile than messages but the same inert-render rule applies (script/style stripped at save). SEC-04 Version history and sanitizer overrides are immutable and audited (Doc 17); publish/unpublish/archive are standard-visibility audit events; visibility changes (public↔internal) are high-visibility (they change the leak surface). SEC-05 Feedback endpoints (customer-facing) are rate-limited per visitor token and content-length-capped; comments are hostile input (inert render, no links auto-linked in staff views without unwrap). SEC-06 The “create article from query” shortcut (FR §8 empty state) never stores customer search queries longer than the session unless the draft is actually created.

## 12. Performance Requirements

- Budgets per NFR-01–03 under L2; publish pipeline parallelizes sanitize+embed per chunk.

- Category tree (widget) served ≤ 50 ms p95 from edge cache.

- Diff computation for 200-block articles ≤ 500 ms.

- Re-embed cost bound: minor edit to one section re-embeds only affected chunks (T-02 determinism verified with a cost fixture).

- Retrieval store: 50k chunks/tenant target (Doc 09 §12 aligned) with swap operations ≤ 1 s.

## 13. Accessibility Requirements

Editor fully keyboard operable (block navigation, toolbar via menus, drag-reorder with move-up/down equivalents); language tabs are a proper tablist; status/translation badges have text equivalents; diff navigable by changed-block landmarks with additions/removals announced; sanitizer popovers keyboard-triggerable; widget article view meets AA (heading structure preserved from blocks, images require alt text — publish warns on missing alt, blocks publish for public articles per setting kb.require_alt default on); feedback controls labeled; INTERNAL banner announced as a note region.

## 14. Localization Requirements

Article content is per-language by design (FR-02/03); manager UI strings under kb.* (en + am); the source-language concept is per-article (not tenant-global — an Amharic-first article is normal); fallback chain (Doc 01) applies to missing only (BR-05); slugs are per-language with transliteration helper for Amharic; RTL-ready article rendering; date/number formatting in articles is author-controlled content (not auto-localized — accuracy over convenience, noted in authoring guidance); the seeded starter set ships in the tenant default language with one-click “AI-translate all” to other active languages (drafts, per BR-02).

## 15. Edge Cases

EC-01 Source-language publish while a translator has the variant’s editor open → their working copy continues; the outdated badge appears on their tab live; publishing their variant recomputes state to current. EC-02 Article moved to a non-ai_usable category → retrieval chunks removed on move (same atomic path as unpublish); Copilot keyword search still finds it; Doc 09 citations to old versions tombstone-resolve. EC-03 Category deleted with AI-usable children articles → blocked until reassigned (FR-01); the dialog lists retrieval impact (“14 articles will leave the chatbot’s knowledge if moved to X”). EC-04 Two variants published simultaneously (en + am one click) → pipeline runs per variant; partial failure leaves the succeeded variant live and the failed one “index pending” (independent, honest states). EC-05 Restore of a version whose media was retention-deleted → draft renders media placeholders with a “missing media” list; publish blocked until resolved or removed. EC-06 Slug collision on publish (another article took it) → auto-suffix with a warning; slug-history redirects keep both old URLs working. EC-07 An article cited by AI seconds before unpublish → citation link tombstones (FR-16); Doc 09’s version-pinned citation still identifies exactly what the customer read (forensics intact). EC-08 A 20,000-word mega-article → soft warning at 5k words (chat-surface unfriendliness), chunker handles it; the FR-19 summary-plus-link discipline keeps chat sane regardless. EC-09 Sanitizer false-positive storm on legitimately imperative content (step-by-step guides say “click…”) → the rule set distinguishes user-directed imperatives from assistant-directed patterns; the fixture suite includes guide-style articles asserting near-zero false positives on them; overrides (BR-06) remain the escape hatch. EC-10 Feedback bombing (one visitor spamming 👎) → per-visitor rate limit + dedupe (one score per visitor per article, latest wins); anomalies flagged to Doc 15. EC-11 Internal article accidentally set public → visibility change is high-visibility audited (SEC-04) and takes the full publish pipeline (review step applies) — no silent flips. EC-12 Working copy vs autosave crash → working_copy jsonb is the recovery point; “restored unsaved changes from {time}” banner on reopen.

## 16. Failure Scenarios

FS-01 Embedding provider down (Doc 09 FS-01 upstream) → publishes complete for humans, retrieval marked pending, retry drains on recovery; the chatbot serves the previous generation meanwhile (never a knowledge hole for already-published content). FS-02 Meilisearch down → staff/widget keyword search degrade per Doc 07 FS-03 pattern; retrieval (pgvector) unaffected — the two systems fail independently by design. FS-03 CDN purge failure → stale public article ≤ TTL (5 min cap for article renders); purge retried; publish reports the degraded stage. FS-04 pgvector index corruption → rebuild from kb_chunks rows (embeddings stored, not only indexed); during rebuild Doc 09 runs no_grounding-handoff mode for affected tenants (safe posture); alert + runbook. FS-05 Lock service (Redis) down → editor falls back to optimistic concurrency (version check on save with merge-conflict UI); noisier but safe; heals on recovery.

## 17. Success Scenarios

SS-01 A regulation change lands: the operator updates 3 articles, publishes, and within a minute the chatbot cites the new versions while old citations tombstone gracefully — zero engineering involvement, full audit trail. SS-02 The deflection report (Doc 15) shows the Withdrawal FAQ deflecting a measurable share of would-be tickets, with the feedback comments explaining the residual gaps — and the next article sprint is planned from that page. SS-03 The Doc 09 loop closes end-to-end in production: unanswerable cluster → one-click draft → review → publish → that intent’s handoff rate visibly drops week-over-week. SS-04 An auditor asks “what exactly did the bot show this customer on May 3?” — the version-pinned citation answers in one lookup.

## 18. Acceptance Criteria

AC-01 Workflow matrix: every state transition (incl. self-publish collapse, request-changes loop, archive/un-archive) with correct permissions, events, and badges. AC-02 Translation-state matrix: source publish flips others to outdated (≤ 5 s), missing-vs-outdated fallback behavior per BR-05, per-variant independence (EC-04). AC-03 Publish atomicity (T-08): stage-kill chaos test proves the human/retrieval generation split with no mixed states (NFR-06 probe green for a week in staging). AC-04 Retrieval contract: chunks carry article id, language, heading path, version id; only public+ai_usable+published content reachable from the chatbot index; internal-index structural separation proven by the T-08 test. AC-05 Sanitizer: shared fixture suite (Doc 09 AC-07) passes; guide-style false-positive fixtures (EC-09) pass; overrides recorded on versions. AC-06 Tombstones: unpublish/archive/supersede/slug-change fixtures all resolve per FR-16/EC-06 with zero 404s on once-cited URLs. AC-07 Versioning: publish snapshots immutable (DB-level), diff correctness on a block-edit fixture set, restore-creates-draft semantics. AC-08 Feedback & analytics: events fire per FR-12 with dedupe (EC-10); the per-article stats header reconciles against Doc 15 fixtures. AC-09 AI authoring: each FR-06 tool produces AI-accented drafts, never publishes (static + runtime assertion), and draft-from-conversations consumes a Doc 09 cluster fixture end-to-end. AC-10 Alt-text gate (kb.require_alt on) blocks public publish with missing alts and lists them.

## 19. Future Expansion

- Public help-center website (SEO’d, per-tenant domain) rendering the same public corpus — the visibility/publish model already supports it; only a renderer is new.

- Article scheduling (publish_at) atop Doc 06’s timer infrastructure pattern.

- Content-health dashboards (staleness age, orphaned articles, broken internal links — link checker job reserved).

- Per-article A/B of phrasing measured by deflection (governed experiment framework needed first).

- Import/export packs (operator-to-operator article bundles, platform-reviewed) mirroring Doc 03 §19’s role packages.

- Glossary/term-consistency tooling for regulated wording across articles.

## 20. Risks

R-01 Content rot poisoning AI answers (High/High — the KB *is* the chatbot’s brain) → outdated badges, staleness surfaced in Doc 15, tombstones, version-pinned citations, and the weekly loop; recommend a standing operator content-review cadence (onboarding material). R-02 Sanitizer arms race (Medium/Medium) → shared package with Doc 09, fixture-driven evolution, override accountability; injection findings feed the Sev-2 runbook (Doc 09 SEC-03). R-03 Translation debt (High likelihood) → the outdated/missing states make debt visible rather than hidden; AI-translate drafts lower the cost floor; completeness gates on customer-facing enablement (Doc 09 §14) contain the harm. R-04 Editor scope creep toward a CMS (Medium/Medium) → the block set is fixed v1 (FR-02); new blocks require series review; the help-center renderer (§19) must not drive block sprawl backward into support content. R-05 Retrieval/search split confusing operators (“I see it in search, why won’t the bot cite it?”) (Medium/Low) → the article stats header shows AI-usability + index status explicitly; the publish dialog states audience and AI scope every time.

## 21. Recommendations

- Build the publish pipeline with its atomic-generation swap first; every guarantee Doc 09 makes to customers rests on FR-13 behaving exactly as specified.

- Seed real content before the chatbot pilot: the starter templates plus the operator’s actual top-20 questions; an empty-KB chatbot launch produces only handoffs and erodes trust in the whole AI program.

- Make the weekly unanswerable-review a named ritual with an owner on the operator side from week one — the Doc 09→10 loop is organizational, not just technical.

- Keep the sanitizer package co-owned by the AI and KB teams with a single fixture suite; split ownership is how injection defenses drift.

- Resist the public help-center until the support corpus is mature (≥ 50 published, feedback-positive articles); shipping the renderer early inverts the content-quality incentive.

*End of Document 10. Next in series: Document 11 — Departments, Teams, Queues **&** Routing.*