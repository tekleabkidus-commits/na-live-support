# S10 STEP 11 — CHECKPOINT PROPOSALS (escalate-first; nothing below is landed)

## P7 — Doc 05 flow-target CHECK expansion + MenuDesigner acceptance (FR-19's binding half)
Files: a NEW migration widening the `telegram_flow_targets` kind CHECK with `kb_article` and
`kb_category` (Doc 05 table — R-19b analysis: constraint-only, no column, no grant change;
expand-contract N/A, a CHECK widening is additive), and `app/Domain/Telegram/Direct/MenuDesigner.php`
(Doc 05) accepting the two new kinds — target id validated against kb_articles / kb_categories
(existence only; PUBLISHED-ness is render-time truth, not bind-time: an article that later
unpublishes must degrade at render, not strand the menu). The RENDER call site (the flow engine
answering a tapped option) calls Doc 10's `KbFlowRenderer::forCategory/forArticle` (contract
proven at TelegramFlowContractTest, landed step 11) and emits the viewed event via
`viewedVariantId` + `UsageEvents::viewed(v, 'telegram', conversationId)` — the FR-12 surface
category exists since step 10. PROOF with the packet: one MenuDesigner leg (bind kb targets,
refuse an unknown id) + one flow leg (tap → numbered list / summary+link, tombstone on retired).
FALLBACK if refused: the contract stays consumer-less; FR-19 files PARTIAL [D05]/[D12] as the
plan's buckets already provide — no schema change, nothing stranded.

## P8 — the Backoffice nav slot for the step-12 KB screens (Doc 07/09 shell)
File: the Backoffice navigation shell (Doc 09's AI screens' host — exact file named at the
countersign; expected `resources/js/.../Backoffice*` nav registry). Change: ONE nav entry
"Knowledge Base" (i18n key `kb.nav.title`, en+am landing with step 12) gated on `tickets.view`
render + per-screen keys inside, pointing at the step-12 Inertia pages. The S09 slot-fill
precedent (P4's shape): registration data, no behavior change. FALLBACK if refused: the step-12
screens land route-addressable but unlinked ([B9] carries the nav affordance with the fidelity
pass).

## Noted (no ruling needed)
- routes/api.php gained the widget kb group INSIDE the existing authenticated widget stack —
  the mounting that group's own docblock anticipates for Docs 04/13; registration only,
  exemptions filed with reasons (permission + audit exempt lists).
- ArticleMedia writes `t/{tenant}/kb/{article}/{uuid}.{ext}` on Doc 04's disk with Doc 04's
  scan + serve halves; the WRITE half is Doc 10's because MediaStore::store's key shape is
  conversation-bound (the S17/row-4b precedent left non-conversation writes open). If the
  advisor prefers a generalized MediaStore::store, that is a Doc 04 packet for a later window;
  nothing here blocks it.
- Image blocks carry `media_key` from step 11 on; step-4 test fixtures' `storage_key` image
  blocks predate media and stay inert to the EC-05 gate (deliberately — they name no real file).
