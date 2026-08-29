# S10 STEP 10 — CHECKPOINT PROPOSALS (escalate-first; nothing below is landed)

## P6 — CopilotPanel: the FR-17 blend at the kb-items call site (Doc 09 file)
File: app/Domain/Ai/Copilot/CopilotPanel.php:118-121 (Doc 09; P3 already gave kb items title/url/version_id).
Today: `$kb = array_map(fn (array $c): array => ['article_ref' => …, 'excerpt' => …] + ($this->citationLinks->forArticle(...) ?? []), $retrieval['chunks']);`
Proposed (≤ 6 lines): inject `App\Domain\Kb\Search\CopilotKbBlend $blend`; replace the map's INPUT with
`$blend->top3($query, $retrieval['chunks'], $language)` (retrieval chunks first, deduped by article; keyword hits fill to three;
published-only; Meilisearch down → retrieval-only) and keep the existing `+ citationLinks->forArticle(...)` decoration per item.
Nothing else changes; the Copilot's internal-allowed posture (FR-10) is unchanged. Proof: CopilotKbBlend is proven standalone
(StaffSearchTest, 3 legs); the wiring proof = one KbCitationRenderTest/CopilotPanel test leg asserting a keyword-only article
appears third when retrieval returns one article. FALLBACK if refused: Copilot stays retrieval-only; FR-17's second sentence
files PARTIAL under [B9]/[D09-reopen] with the reason stated.

## A-48 — event-family amendment (the kb.* pin is CLOSED at nine): two names
1. `kb.feedback.anomaly` {tenant_id, article_id, variant_id, visitor_bucket, refused_count} — EC-10 "anomalies flagged to Doc 15".
   Today the refused attempts are COUNTED (VisitorLimiter, header `anomalies_today`) and logged; no bus event exists to arm Doc 15.
2. `kb.article.deflection_attributed` {tenant_id, article_id, version_id, conversation_id, source} — FR-12's "deflection attribution"
   as an EVENT. Today the header computes `deflection_candidates` from viewed/cited rows + ticket absence (the inputs); the
   FORMULA stays Doc 15's. If granted, a Doc 10 consumer on kb.article.viewed/cited_by_ai emits it after the 24 h window (a
   scheduled sweep — NFR-05's "never a scan" is about translation state, not analytics; stated so the advisor can rule).
Both: identifiers/categories only (Q7); KB_EVENT_FAMILY 9 → 11 + KbEventConsumerContractsTest pin move + catalog rows SAME commit.
FALLBACK if refused: both stay as header facts ([D15] carries the report half); no PARTIAL outside the bucket.

## Noted for the batch-C checkpoint (no ruling needed)
- Customer-facing feedback ENDPOINTS (widget/Telegram) land at step 11 under WidgetGuard with SEC-05's endpoint legs; the writer,
  limiter and hostile-input proofs are step 10's.
- The [D10] closing set: rows 1–7 ALL flipped (1+3 step 6; 4+6+7 step 7; 2+5 step 9).
- NFR-03's 5k-article leg and the widget ≤200 ms leg: [D19]/[D18] as pre-agreed.
