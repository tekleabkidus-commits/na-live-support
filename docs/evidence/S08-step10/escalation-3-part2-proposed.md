# ESCALATION #3, PART 2 (S08 step 10) — the slot fill, the REAL badge, and the projector's `customer.merged`

**Status: PREPARED, NOT LANDED. Escalate-first. Doc 07 files. Browser specs held per the standing order.**
Closing row 2 (BUILD-STATUS S07 line: the ContextPanel Customer slot + `tabBadges.customer` seam) completes when this lands.

## The current mechanism, surveyed (what part 2 touches)

1. `resources/js/Components/Inbox/ContextPanel.vue` — the Customer tab's badge dot is a single
   boolean-driven span: `v-if="tab === 'customer' && badges.customer"`, colour hardwired
   `var(--ds-danger-strong)` (the B1-S01-v2 generic, implemented at Doc 07 step 8 before any
   real badge state existed), aria-label `t.tabs.customer_badge`. The `#customer` slot renders
   the honest-empty fallback.
2. `app/Http/Controllers/Inbox/WorkspaceController.php::tabBadges()` — production always
   `{customer: false, copilot: false}`; the only feed is the `seam_badges=1` query reading
   `fixtures/inbox/seams/doc08-customer-badge.json` (local/testing/ci only).
3. `resources/js/Pages/Inbox/Workspace.vue` — passes `tabBadges` at three render sites; the
   `#customer` template is never filled.
4. `app/Domain/Inbox/ReadModels/ListProjector.php` + `app/Jobs/Inbox/ListProjectorConsumer.php`
   — no `customer.merged` handling; list rows carry `customer_display_name`/`customer_id`
   copied at projection time, so a merge leaves STALE names/ids on open list rows (EC-01's
   live re-point is the missing piece; the catalog already names `Doc07/ListProjector` as a
   `customer.merged` consumer, unconsumed until now).

## The frame survey (the badge-dot state map the ruling ordered)

- **B4-S01** (the slot fill): "The frame is not mine: 380px, the tab order Ticket / Customer /
  Copilot, the tab treatment and the badge dot all come from B1-S01-v2 unchanged." The fill
  does not restyle the tab strip.
- **B4-S05 E01** (RG, permitted viewer): "Slate, not red. This is not an alarm… **The tab dot
  uses the same neutral**, so a glance at the tab strip does not shout it across an open-plan
  floor either." → the RG state's dot is drawn SLATE/NEUTRAL.
- **B4-S05 E03** (RG, unpermitted viewer): "Not redacted. Absent… no banner slot, no empty
  space where one was, **no dot on the tab**." → for the unpermitted viewer the dot is ABSENT
  — the dot itself would be the inference channel BR-03 forbids.
- **B4-S05 F01–F05** (blocking): the frames draw the red banner, the composer refusal, the
  profile state, the unblock, and EC-07 — **no frame draws a tab-strip dot for the blocked
  state.** Nothing anywhere in Batch 4 draws a second dot state.

## The proposal

**A. The badge becomes real, single-state, permission-computed, and SLATE (class-1 filed).**
`tabBadges.customer` stays boolean but its MEANING becomes: *the active thread's customer
carries an RG flag AND this viewer holds `customers.rg_flag.view`* — computed server-side per
viewer in `WorkspaceController::tabBadges()` (through Doc 08's no-cache RG repository — NFR-06
binds this read too), replacing the `doc08-customer-badge` seam fixture, which retires
(`doc09-copilot-badge` stays). The dot's colour moves `--ds-danger-strong` → the neutral token
(recommended: `--ds-text-muted`, the slate the RG chip family already uses — final token named
at the ruling). **Filed as class-1 with both citations**: B4-S05 E01's drawn slate versus the
B1-S01-v2 generic red the code implemented — the document/frame wins. With only ONE drawn
state this is a single-state recolour, not the state-dependent-colouring case the plan's
principle reserves for a colour serving several states.

**B. Blocked is NOT a dot state (recommended).** No frame draws it; the blocked signal is the
red banner inside the panel/thread (§8) and the list-row treatment — building a second dot
state would be invention. If the advisor rules blocked should light the dot, that is a class-2
fill needing an owner-named shape, and then precedence (blocked-red over rg-slate) must be
ruled with it; the recommended path avoids both.

**C. The slot fills** — `Workspace.vue` gains `<template #customer><CustomerPanel …/></template>`
at the three render sites (the panel component itself is Doc 08's new file, not a core edit);
`ContextPanel.vue` changes only the dot colour + aria-label copy if the ruling renames it. B14's
law rides the single shared fill (same panel, same controls, in the 375 sheet).

**D. The projector consumes `customer.merged`** (T-03/EC-01): `ConsumerRegistry` adds
`ListProjectorConsumer` to `customer.merged`; the consumer resolves the merged/survivor pair
from the event row and the projector recomputes every list row whose `customer_id` is the
merged id (values from truth — the survivor row — never the payload). **The routine line
applies in the landing commit**: the registry addition moves the Doc 07 contract fixture
(`fixtures/inbox/consumers/doc07-events.json` gains `customer.merged → Doc07/ListProjector`,
reads: tenant_id + survivor_id + merged_id); ReopenConsumerDrillTest's pin covers
message.received/ticket.created/tenant.provisioned only — unaffected, stated so the check is
on the record.

**E. Proofs at landing** (browser specs HELD until the runner decision — the non-browser
proofs land with the code; the specs join when released): the badge's per-viewer absence
(unpermitted viewer's payload carries `customer: false` — asserted at the controller, the
BR-03 clause), the RG-read freshness through the badge path (no cache), the projector
re-point (merge → open list rows carry the survivor's id/name; C-09 replay), and the retired
seam fixture's absence. AC-08/AC-B4-03's browser halves stay with the held specs.

## Decisions for the ruling

1. Approve A (real badge = RG-permitted-only, slate, seam retired) and the token choice.
2. Confirm B (blocked is not a dot state — build the drawn state only).
3. Approve D's registry/fixture shape.
4. Confirm E's split (non-browser proofs land now; browser specs wait for the runner decision).
