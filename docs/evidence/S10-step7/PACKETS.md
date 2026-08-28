# S10 STEP 7 — CORE-EDIT PACKETS (escalate-first; diffs shown BEFORE landing)

## P1 — HybridRetriever: the table is a constructor fact (STOP-B, as ruled)
File: app/Domain/Ai/Retrieval/HybridRetriever.php (Doc 09)
Change: `__construct(AiOrchestrator $orchestrator, private readonly string $table = 'kb_chunks')`;
`DB::table('kb_chunks')` → `DB::table($this->table)`; docblock: "bound to kb_chunks_public for the
chatbot path — the partition cannot yield internal rows; includeInternal is meaningless there".
NO other change. Doc 10 side: KbServiceProvider contextual binding
`when(ChatbotTurn::class)->needs(HybridRetriever::class)->give(fn ($app) => new HybridRetriever($app->make(AiOrchestrator::class), 'kb_chunks_public'))`.
ChatbotTurn.php is NOT edited. Proof (KbIndexSeparationTest, written, parked):
 (a) physics — an internal chunk is invisible to SELECT on kb_chunks_public regardless of WHERE;
 (b) Copilot's retriever (parent) reaches both; (c) falsification — a deliberately mis-written
 filter (includeInternal: true) on the chatbot-bound instance STILL returns no internal row;
 (d) static — ChatbotTurn's source never passes includeInternal; the container resolves a
 retriever whose table is kb_chunks_public for ChatbotTurn (reflection).

## P2 — ChatbotTurn::renderAnswer: version-pinned citation URLs + kb.article.cited_by_ai
File: app/Domain/Ai/Chatbot/ChatbotTurn.php (Doc 09)
Change: `'📄 '.$ref` → `'📄 '.$this->citationUrl($ref)` where citationUrl resolves the ref's
current version through Doc 10's `CitationLinks::forArticle($articleRef)` (a Doc 10 service:
current published variant → CitationUrls::citation(articleId, currentVersionId); legacy
fixture refs (no article row) render as today). After compose: publish `kb.article.cited_by_ai`
{tenant_id, article_id, version_id, conversation_id, decision_id} per citation, inside the
existing decision transaction (identifiers only). The golden fixtures (article_ref 'kb-dep-001',
no article row) keep rendering the bare ref → golden suite unchanged.

## P3 — CopilotPanel kb items + CopilotTab insert-as-link + CopilotController inserted_by_agent
Files: app/Domain/Ai/Copilot/CopilotPanel.php:117-121 (Doc 09), resources/js/Components/Ai/CopilotTab.vue:97-100
(Doc 07 slot fill, S09 12d), app/Http/Controllers/Ai/CopilotController.php:117-129 (Doc 09), copilotApi.ts types.
Proposed lines:
  CopilotPanel: `$kb = array_map(fn (array $c): array => ['article_ref' => $c['article_ref'], 'excerpt' => mb_substr($c['content'], 0, 160)]
                 + ($this->citationLinks->forArticle($c['article_ref']) ?? []), $retrieval['chunks']);`
                (CitationLinks::forArticle → ['url' => '/kb/cite/{a}/{v}', 'version_id' => v, 'title' => current title] | null; fixture refs unchanged)
  copilotApi.ts: `kb: { article_ref: string; excerpt: string; url?: string; title?: string }[]`
  CopilotTab.vue insertKbLink: `props.insert('📄 ' + (item.title ?? ref_) + (item.url ? ' ' + item.url : ''), 'kb_link', '')`
  CopilotController::insert: when `$artifact['kind'] === 'kb_link'` publish `kb.article.inserted_by_agent`
                {tenant_id, article_id, version_id, conversation_id, agent_id} inside the existing artifacts transaction.
  Catalog: kb.article.inserted_by_agent payload = [tenant_id, article_id, version_id, conversation_id, agent_id].
FR-17's blended top-3 (semantic + Meilisearch keyword) is step 10's second line on the same seam.

## P4 — the three kb notification categories (registered at step 4; delivery = Doc 07's projector, the SOLE bell-row writer)
Read at step 7: `app/Domain/Inbox/ReadModels/ListProjector.php:368-410` — bell rows are minted ONLY
by the projector's event arms (`'ai.killswitch.changed' => array_map(fn ($userId) => [$userId,
'notify.ai.killswitch_changed', null, [...]], $this->holdersOf('ai.killswitch', [actor]))`), and
`inbox_notifications.payload` is identifiers only (Q7). A Doc 10 door writing bell rows directly
would be a SECOND writer of a Doc 07 table — refused by me on the one-writer law.
PROPOSAL (two parts, both needing the countersign):
 (i) A-44 AMENDMENT — the kb.* family grows 6 → 9 with identifiers-only events the doors already
     have the facts for: `kb.variant.submitted` {tenant_id, article_id, variant_id, actor_id},
     `kb.variant.changes_requested` {tenant_id, article_id, variant_id, actor_id, comment_id},
     `kb.lock.taken_over` {tenant_id, variant_id, previous_holder_id, new_holder_id}. Emitted inside
     the existing transactions (Transitions::submit/requestChanges; Locks::takeover → the door's
     transaction). KbEventConsumerContractsTest's family pin moves 6 → 9 in the same commit.
 (ii) Doc 07 packet — three projector arms in the S09-packet-2 shape:
     'kb.variant.submitted'         => holdersOf('kb.articles.publish', [actor])  → notify.kb.review_requested
     'kb.variant.changes_requested' => [the variant's created_by/author]           → notify.kb.changes_requested
     'kb.lock.taken_over'           => [previous_holder_id]                         → notify.kb.lock_taken_over
     + ConsumerRegistry lines (routine) + the projector's contract fixture (doc07.json) rows.
 FALLBACK if (i) is refused: the three categories stay registered-but-undelivered, filed [B9]/[D16]
 with the reason (no event exists to arm) — stated, never silent.

## P5 — TaskCatalog amendment: THREE kb tasks (FR-06 tools)
Files: app/Domain/Ai/Orchestrator/TaskCatalog.php (Doc 09, CLOSED at twelve) + a migration
widening ai_decisions' task CHECK (SCHEMA AMENDMENT 3 addition — countersign) + the
ai.decision.logged contract fixtures. Proposed: `kb_draft_from_source` (strong, 1200 tokens,
30 s, staff-only), `kb_faq_variants` (fast, 400), `kb_draft_from_conversations` (strong, 1200).
Translate → existing `translate`; Simplify → existing `improve_text`.
FALLBACK if refused: all five tools ride `improve_text`/`translate` with a Doc 10 prompt layer
(no catalog growth) — semantically looser decision-log rows, stated as such.
