# SESSION-08 / Doc 08 — APPROVED PLAN (committed at approval per standing rule 12)

**Approved by the owner on the advisor's audit, 2026-08-14: 91/91 family-exact**
(FR 22 / NFR 6 / US 7 / BR 7 / T 8 / SEC 6 / EC 12 / FS 5 / SS 4 / AC 9 / R 5).
The advisor's independent enumeration reconciled family-by-family; the twelve FR-22
events matched; the closing set matched with the 4a/4b split applied; the sabotage
list, frame map, and escalate-first forecast accepted as written. **Two blocking
corrections were folded in before this commit** (the step-1 split and the legacy
shared-phone backfill policy — §4 and §10 below). Rulings A-38/A-39 and the
ADR 0014 stack-growth approval are FILED in `docs/ANSWERS-FROM-DOC-AUTHOR.md`
(S08 section, same commit). Precedent correction applied: Doc 07's plan-approval
commit is `c9510c9` (creation), not `b727322` (a later amendment touching the file).

Design fidelity: `DESIGN-FIDELITY-MANDATE.md` v7; frozen record files
`B4-S00-checklist-v2` (78 rows: A16 B6 C7 D13 E7 F5 G9 H7 I8), `B4-S01-v1`,
`B4-S02-v1`, `B4-S03-v2`, `B4-S04-v2`, `B4-S05-v1`, `B4-S06-v1`, `B4-S07-v2`,
`B4-S08-v1`, `B4-A01-v1` (three build-acceptance rows), `B4-S09-v1`, plus
`B1-DS-design-system-v2` §2 and addenda B5 / B6(v3) / B7(v4). **No pixel baselines
exist for Batch 4** — every traceability row claims frame-mapped functional-state +
a11y coverage only. Tokens first: the committed hex-extraction script re-runs before
the first screen (step 10).

---

## 1. Id families (enumerated from the document): 91 total

FR-01…22 · NFR-01…06 · US-01…07 · BR-01…07 · T-01…08 · SEC-01…06 · EC-01…12 ·
FS-01…05 · SS-01…04 · AC-01…09 · R-01…05.

**FR-22 event family (the twelve; catalog 84→96; grows only by filed amendment,
rule 16):** `customer.created`, `customer.updated`, `customer.identity.linked`,
`customer.identity.unlinked`, `customer.merged`, `customer.unmerged`,
`customer.blocked`, `customer.unblocked`, `customer.rg_flag.set`,
`customer.rg_flag.cleared`, `customer.note.added`, `customer.fields_changed`.

## 2. Id → file → proving test

Namespaces: domain `app/Domain/Customers/` (Identity, Merge, Rg, Blocking, Search,
Panel, Notes, Export, Rollups), controllers `app/Http/Controllers/Customers/`, jobs
`app/Jobs/Customers/`, client `resources/js/Components/Customers/` +
`resources/js/Pages/Customers/`; tests `tests/Feature/Customers/`,
`tests/Contract/`, `tests/Isolation/`, `tests/Security/`,
`tests/Browser/customers-*.spec.ts`; evidence `docs/evidence/S08-step*/`.

| Id | File(s) | Proving test |
|---|---|---|
| FR-01 | `add_profile_columns_to_customers` migration; `CustomerProfile` (display_name derivation; language/country `*_source` sticky) | `ProfileFieldsTest` |
| FR-02 | `Customer` lifecycle (shell → enriched → tombstone; nothing auto-deletes — grant physics) | `ProfileFieldsTest` |
| FR-03 | rollup columns; `Rollups/CustomerRollups`; `Jobs/Customers/RollupConsumer` | `RollupConsumerTest` (recompute == direct query) |
| FR-04 | `evolve_customer_identities` migration (type/value/source/first_seen_at/metadata; `(tenant,type,value)` unique); E.164 normalize-on-save (ADR 0014) | `IdentityInvariantsTest` (AC-02 direct-SQL duplicate fails) |
| FR-05 | identity `source` + `first_seen_at`; `IdentityRow.vue` provenance | `IdentityInvariantsTest` + `customers-panel.spec` |
| FR-06 | resolver display refresh + metadata username-history append | `ResolverMatrixTest`; search half at step 8 |
| FR-07 | `CustomerResolver` rewritten in place (exact `(type,value)` match → else `INSERT…ON CONFLICT` shell+identity) — **core edit, escalated** | `ResolverMatrixTest` (AC-01 matrix: match / create / race→1 / history / NO cross-type link) |
| FR-08 | resolver structurally match-or-create only | `ResolverMatrixTest` |
| FR-09 | `Identity/LinkIdentity` + door; duplicate-phone block + merge shortcut + C02-v2 out-of-scope variant | `LinkUnlinkTest` |
| FR-10 | `Identity/UnlinkIdentity` — move-set query is the SINGLE source of the typed confirmation text | `LinkUnlinkTest` (AC-04) |
| FR-11 | `Merge/CustomerMerge` (pick → per-field diff → consequences+confirm; unions; consequences server-computed at confirm from live queries) | `MergeFlowTest` |
| FR-12 | `Merge/MergeEffects` (re-point all; tombstone; open-family collision → newer ticket `linked_work=true` via Doc 06's sanctioned path + system note + checklist row) | `MergeFlowTest` + `MergeCollisionTest` |
| FR-13 | `merge_snapshots` (jsonb, expires_at 30 d); `Merge/UnmergeCustomer`; undo preview lists post-merge objects | `MergeUndoTest` (AC-03 byte-identical) |
| FR-14 | `Rg/RgFlagService` + `Rg/RgFlagRepository` (no-cache); banner/badge; `source=ai` storage + triggering-excerpt ref **[D09]** | `RgVisibilityTest` (AC-05) + `RgStorageTest` |
| FR-15 | routing/suppression contracts via event payloads **[D11]/[D12]/Doc 14 seam**; clear = perm + reason; `rg_flag_history` retained forever | `RgStorageTest` |
| FR-16 | betting fields (seeded set, defs fixture-fed **[D12]**); staff-collected label; duplicate-`player_id` hint (counts + scope-checked links) | `BettingFieldsTest` + `DuplicateHintScopeTest` |
| FR-17 | `customer_notes` + `Notes/CustomerNotes` (one pinned; N-04 structural undeliverability) | `CustomerNotesTest` |
| FR-18 | `Blocking/BlockCustomer` chunked cascade via Doc 04's conversation-block service + outbound customer-level guard (**Doc 04 core edit, escalated**); unblock rides `customers.block` (A-39) | `BlockingCascadeTest` (AC-06) |
| FR-19 | `Panel/FilesGallery` over Doc 04 message media; `MediaStore::signedUrl` (serve half — **closing row 4a**); infected blocked; Doc 04 lightbox reuse; export = `customers.export` | `FilesGalleryTest` |
| FR-20 | `Pages/Customers/Directory.vue` + `DirectoryController` (C-05 cursor; filters; RG filter ABSENT unpermitted) | `DirectoryTest` + `customers-directory.spec` |
| FR-21 | `CustomerPanel.vue` filling the Doc 07 slot (**slot pass-through + badge = Doc 07 core edit, escalated**); fixed section order; inline edit + autosave + provenance | `PanelEndpointTest` + `customers-panel.spec` (AC-08) |
| FR-22 | `EventCatalog.php` +12 closed payloads; producers in each service | `CustomerEventsTest` + catalog gate |
| NFR-01 | resolver ≤5 ms p99 index-only — dev figure printed; L2 **[D19]** | `Performance/CustomerBudgetsTest` |
| NFR-02 | chunked saga + progress + completion notification; formal scale **[D19]** | `MergeSagaTest` + budget row |
| NFR-03 | T-07 endpoint; ≤300 ms p75 dev-scale; formal **[D19]** | `PanelEndpointTest` + budget row |
| NFR-04 | `customers_index`; 1 M **[D19]**; dev figure printed | `CustomerSearchScopeTest` + budget row |
| NFR-05 | `customers:consistency-check` (weekly); drift ≤0, alerts | `RollupDriftTest` |
| NFR-06 | no-cache RG reads (+ AC-B4-01 warm-cache proof) | `RgVisibilityTest` |
| US-01…US-07 | panel header/rollups; on-behalf note (H01); duplicate-phone warn; guided merge+undo; RG invisibility+export; block-once; player_id hint | owning specs as mapped in the session report |
| BR-01 | no verified variant/field anywhere, repo-wide | `Security/NoVerifiedAffordanceTest` (AC-09) |
| BR-02 | no cross-type auto-link (C07 frame) | `ResolverMatrixTest` fixture |
| BR-03 | absence-not-redaction, query-level | `RgVisibilityTest` |
| BR-04 | one identity one customer, DB-enforced | `IdentityInvariantsTest` + sabotage #3 |
| BR-05 | tombstones redirect forever | `TombstoneTest` + sabotage #13 |
| BR-06 | no consent/marketing fields | schema assertion in `ProfileFieldsTest` |
| BR-07 | notes stay on profile; onboarding copy ships as **i18n strings only — no onboarding page** (advisor correction 3; §21 recommendations are not requirements) | `CustomerNotesTest` + i18n keys |
| T-01 | the five schema deliverables under **SCHEMA AMENDMENT 2** (countersigned BEFORE migrations — step 1a/1b split) | `CustomerGrantPhysicsTest` |
| T-02 | single-upsert resolver; internal service + `POST /v1/customers/{id}/identities` | `ResolverMatrixTest` race proof |
| T-03 | `Merge/MergeSaga` (conversations → tickets → files/notes → identities; compensation; progress on `user.{id}`; survivor rollups recomputed; **Doc 07 projector consumes `customer.merged`** — escalated) | `MergeSagaTest` chaos |
| T-04 | `customers_index` (names, username+history, phone, email, player_id) via the Doc 07 T-04 proxy (**escalated deepen**); fed from FR-22 events | `CustomerSearchScopeTest` (AC-07) |
| T-05 | flag columns + insert-only `rg_flag_history` + no-cache repo; Doc 03 high-sensitivity bypass list | `RgVisibilityTest` + grant physics |
| T-06 | `customer.blocked` → chunked Doc 04 conversation updates + outbound guard (belt and braces — both built, neither "simplified" away) | `BlockingCascadeTest` + sabotage #8 |
| T-07 | `GET /v1/customers/{id}/panel` (header+identities instant, section stubs; C-10 404 + curated payload allowlist on every read door) | `PanelEndpointTest` |
| T-08 | resolver matrix / saga chaos / RG per-role fixtures | the three named suites |
| SEC-01 | RG gating at query level — columns never loaded, keys omitted | `RgVisibilityTest` + sabotage #1 |
| SEC-02 | reasons + high-visibility audit on merge/unlink/high-risk; Doc 03 T-06 four-eyes where enabled | audit-coverage registry + `AuditCoverageTest` |
| SEC-03 | phone/email masked in logs (Doc 02 SEC-08 scrubber extended, plant→detect→remove) | scrubber control |
| SEC-04 | `Export/CustomerExport` (async, watermarked, signed URL, audited); erasure **initiation only**, key = `customers.delete` (A-38) **[D17]** | `CustomerExportTest` |
| SEC-05 | duplicate-hint: counts + scope-checked links, never names cross-scope | `DuplicateHintScopeTest` + sabotage #11 |
| SEC-06 | directory export re-checks scope in the job | `CustomerExportTest` |
| EC-01 | live-object merge; open thread re-points via `customer.merged` | `MergeFlowTest` + merge spec |
| EC-02 | shared family phone = documented reality (FR-09 block + note pattern + onboarding STRINGS) | `LinkUnlinkTest` |
| EC-03 | inactive hint from repeated hard failures — heuristic chip, threshold a cited constant (no invented setting) | `IdentityInvariantsTest` |
| EC-04 | chain undo outermost-first; UI explains order (D10) | `MergeUndoTest` |
| EC-05 | unlink only identity → zero-identity profile + unreachable chip; shell carries conversations | `LinkUnlinkTest` |
| EC-06 | clear reasons consumable by Doc 09 **[D09]** | `RgStorageTest` |
| EC-07 | evasion shell NOT blocked (BR-02); merge-then-inherit-block or block shell; documented limitation | `BlockingCascadeTest` |
| EC-08 | name collision: glyph values + short-id hover | `DirectoryTest` |
| EC-09 | retired-field read-only render **[D12]** | `BettingFieldsTest` |
| EC-10 | staff-set sticky + "detected — apply?" once per change | `ProfileFieldsTest` |
| EC-11 | LWW + conflict toast + both audited (A16 frame) | `ProfileFieldsTest` |
| EC-12 | merge blocked at confirm when combined open linked_work > 3, exact tickets listed (D13) | `MergeCollisionTest` |
| FS-01 | Meilisearch down → labeled DB prefix search; panel unaffected | `DirectoryTest` dead-port drill |
| FS-02 | saga crash → compensation; never half-merged | `MergeSagaTest` + sabotage #5 |
| FS-03 | rollup lag → staleness means "projector down", never "nothing happened" (H07) | `RollupDriftTest` |
| FS-04 | cascade crash → outbound guard holds; repair completes | `BlockingCascadeTest` |
| FS-05 | export restarts idempotently; partials never signed | `CustomerExportTest` |
| SS-01…SS-04 | returning player; lost-phone merge; RG scenario; resolver race (L2 **[D19]**) | the owning specs |
| AC-01…AC-09 | as mapped above; traceability rows same-commit | — |
| R-01/R-02/R-04/R-05 | mitigations as built (R-05 10× whale **[D19]**) | self-audit citations |
| R-03 | absence-not-redaction + AC-05; **the periodic red-team fixture is Doc 19's — bucketed [D19]** (advisor correction 2) | `RgVisibilityTest` + [D19] row |

**B4-A01 rows:** AC-B4-01 → `RgVisibilityTest` warm-cache clause (NFR-06 agrees —
proven twice). AC-B4-02 → `RgExportTest` (whole history, authors/reasons/AI excerpt
refs; edit+delete refused every path; unpermitted refused). AC-B4-03 →
`customers-panel.spec` spoken announce cycle (five moments, in order, both viewports).

## 3. Registries and permissions (nothing invented; catalog CLOSED at 113)

**The 13 `customers.*` keys, enumerated** (verified at `registries/permissions.php:306-449`):
`customers.view`, `customers.create`, `customers.edit`, `customers.notes.add`,
`customers.notes.view`, `customers.identity.link`, `customers.identity.unlink` (high),
`customers.merge` (high), `customers.block`, `customers.rg_flag.view` (high),
`customers.rg_flag.set` (high), `customers.export` (high), `customers.delete` (high).
Plus `audit.view` (profile Audit tab, scoped to the customer). Render-gates are
derivations, never new keys.

- **A-38 (RULED):** erasure initiation rides `customers.delete` — initiation only;
  Doc 17 owns approval/execution; Super-Admin-only by default (R-10 excludes it from
  Admin); operators may grant it (templates editable). Filed in ANSWERS.
- **A-39 (FILED, Q9):** unblock rides `customers.block`; §8's "distinct permissioned
  action" = separate door + mandatory reason + separate audit action. Filed in ANSWERS.
- **Settings: none.** 30-day window / 3 s cascade / weekly checker are document-fixed;
  EC-03's threshold is a cited constant.
- **Notifications:** `notify.customers.merge_completed` (NFR-02),
  `notify.customers.export_ready` (SEC-04).
- **Feature flags: none.**
- **Audit coverage:** merge, unmerge, identity.link, identity.unlink, block, unblock,
  rg_flag.set, rg_flag.cleared, export, erasure.initiate, field edits (both EC-11
  writers), note.added — actor + old/new + reasons where required. **A-37 causal noop
  declaration at design time:** the inline-edit autosave door declares `audit.noop` at
  the exact unchanged-value branch, tied to the profile service's own no-change return.
- **EVENT-CATALOG** regenerated (+12, closed payloads, identifiers only per Q7).
  **Durable consumers registered (ADR 0011 + contract fixtures):** `RollupConsumer`,
  `CustomerSearchIndexer`, `BlockingCascadeConsumer`, and Doc 07's
  `ListProjectorConsumer` gaining `customer.merged` (registry entry + escalated
  projector handling).

## 4. SCHEMA AMENDMENT 2 (filed at step 1a; countersigned BEFORE any migration ships)

Tables/columns, every column R-19b-analyzed with ADR-0003 grant physics and the
`APP_WRITABLE_COLUMNS` split-comment discipline, DB time only (C-11), pinned-provenance
copies for anything ordering-relevant (A-33/R-19b — `last_contact_at` is a byte-copy of
the source message's DB stamp, never a fresh clock):

1. `customers` additions: language+`language_source`, country+`country_source`,
   `tags text[]`, `rg_flag`+set_by/at/reason, `blocked`+reason, `merged_into_id`,
   `created_source`, rollup columns.
2. `customer_identities` **expand-contract evolution** (C-12): add
   type/value/source/first_seen_at/metadata nullable → backfill → enforce
   `(tenant,type,value)` unique; legacy channel/external_user_id columns deprecated,
   dropped two releases later. Channel→type mapping is DECLARED on the channel-adapter
   contract (`identityType()` — the N-20-compliant shape; part of escalation #1);
   telegram→telegram, fake→web; an unmapped channel fails the migration loudly.
3. `customer_notes`. 4. `merge_snapshots` (payload jsonb, expires_at). 5.
   `rg_flag_history` — **insert-only by grant**.

**Legacy shared-phone backfill policy (advisor blocking correction 2 — stated here,
proven by test):** when N customers share one `customers.phone` value, the phone
identity is created for exactly one keeper, chosen deterministically — **oldest
customer by `created_at`, tiebreak lowest UUIDv7**. Every loser's value is PRESERVED:
it remains in the deprecated `customers.phone` column through the C-12 contract
window, and the migration emits a **shared-phone report artifact**
(`docs/evidence/S08-step1/backfill-shared-phones-01.txt` + a durable report table row
set) listing every collision group so the operator can consolidate by hand later —
FR-09's "humans consolidate" applied to legacy data. **Test:** seed two customers on
one phone → migration completes deterministically → both values still retrievable →
report lists the pair (`IdentityBackfillTest`).

## 5. Stack growth (owner-approved with conditions)

**`giggsey/libphonenumber-for-php` — ADR 0014**, written before the code. Conditions
bound at approval: (a) pinned version stated in the ADR; (b) stored values normalize
ONCE at write and are never silently re-normalized by a metadata upgrade; (c) the ADR
states the upgrade policy when new metadata would change an already-stored value.
Normalization correctness is a SAFETY property: BR-04's unique index assumes one phone
normalizes one way forever. Nothing else grows.

## 6. Escalate-first forecast (planned batch-stops)

1. **Doc 04:** `CustomerResolver` rewrite + `NormalizeInbound` wiring + adapter
   contract `identityType()` (step 2).
2. **Doc 04:** outbound customer-level guard in the outbound pipeline (step 7).
3. **Doc 07:** slot fill pass-through + real `tabBadges.customer` + `SearchProxy`
   deepen + `ListProjectorConsumer`/`ListProjector` `customer.merged` (steps 8/10).
   **Badge-dot colour rides this escalation with the advisor's stated principle**: the
   escalation carries the dot's current state-map (today: a single
   `--ds-danger-strong` dot driven by `badges.customer`) and what each Batch-4 frame
   draws per state (B4-S05 E01: RG dot = slate neutral; blocked state's dot treatment
   per frames); if one generic colour serves several states it is a class-1 conflict
   requiring state-dependent colouring, not a recolour. Advisor rules at the stop.
4. **Doc 06:** FR-12's `linked_work` auto-flag — consume the sanctioned Doc 06 path
   if one exists; any Doc 06 file edit escalates first.

## 7. Closing set (each row updates its named BUILD-STATUS line at close)

| # | Row | Built at | Updates |
|---|---|---|---|
| 1 | Doc 05 EC-04 identity-resolution half | step 2 | `BUILD-STATUS:2597` + restatements (2042/2674) |
| 2 | Doc 07 Customer slot + real badge (doc08 seam fixture retired; doc09 untouched) | step 10 | `:1523` |
| 3 | Search customer section deepened onto `customers_index` | step 8 | `:1634` |
| 4a | Avatar-media seam **serve half**: FR-19 gallery via `MediaStore::signedUrl` | step 9 | `:3287`, stating exactly the serve half closed |
| 4b | **Store half (staff avatar): NOT BUILT — owner-reassignment flag** (Doc 02/16 territory, advisor ruling) | close-out | flagged in `:3287` row text |

## 8. Inherited rows 0–11

0 discharged at STEP 0 (Doc 09 key reminder given once). 1 [OA] rows owner-action,
untouched. 2 risks 0a/0d/0f/0g + rbac tri-state carried (0d red → one re-run recorded;
0f → rung 1 recorded; 0g-family → recurrence rule). 3 R-14 discharge-at-close
(destructive tier: MERGE/UNLINK/UNDO typed-phrase copy — the token itself stays Latin
per B4-S04's drawn law — block/unblock reasons, RG set/clear, erasure request;
"staff-collected" flagged with §14's not-"untrusted" note). 4 `shouldRenderJsonWhen`
per-door interim on every Doc 08 door, evidence filed. 5 granter-naming bounded
deferral untouched (C02-v2 names no granter — verified, no conflict). 6 dry-run note
carried; consequence checklists are server-computed at confirm, never a second engine.
7 Doc 18 notes: add if infra facts surface. 8 [D19] buckets per §9. 9 Doc 11/12 seams
honest (satisfaction/custom-field data fixture-fed). 10 Doc 16 seam (RG script link
content fixture-fed). 11 hygiene: battery legs self-gate on `BAT1: Charging`.

## 9. Pre-agreed PARTIAL buckets (the only sanctioned deferrals)

`[D19]`: NFR-01 L2, NFR-02 formal scale, NFR-04 1M, §12 formal budgets, R-05 10×
whale, SS-04 L2 race, **R-03's periodic red-team fixture** (advisor correction 2).
`[D09]`: FR-14 `source=ai` producer + EC-06 calibration consumption. `[D11]`: FR-15
RG-queue routing. `[D12]`: custom-field definitions, EC-09 rule ownership,
satisfaction inputs. `[D13]`: `web` visitor_id producer. `[D17]`: erasure
approval/execution + Audit-tab depth. Dev-scale figures print with any miss flagged
plainly (the TTI precedent).

## 10. Build order — steps 1a/1b then 2–13 (safety before the features they constrain)

| Step | Contents |
|---|---|
| **1a** | SCHEMA AMENDMENT 2 filed (with the backfill policy) + registries (permission map, notifications, audit-coverage, traceability) + the 12 events + AC-09 repo-wide test + ADR 0014 install groundwork → **MANDATORY STOP for countersign. Nothing touches the database before it returns.** |
| **1b** | On countersign: migrations (expand → backfill → enforce), grant physics, `IdentityBackfillTest` incl. the shared-phone pair proof, sabotages #3/#14 (migration-level) |
| 2 | Resolver (T-02 upsert) + AC-01 matrix incl. race + no-cross-type fixture → THEN escalation #1 → land ⇒ closing row 1 (§21: resolver first, wired to Doc 04 immediately) |
| 3 | Rollups + durable consumers + contract fixtures + drift checker + C-09 replay under the LIVE relay (sabotage #12) |
| 4 | RG storage + column-absence + no-cache + history physics (AC-05, AC-B4-01/02 server halves; sabotages #1/#2) — BEFORE any RG surface |
| 5 | Link/unlink services + doors (AC-02/AC-04, EC-05; sabotage #7) |
| 6 | Merge saga + compensation chaos + snapshots + undo + EC-04/EC-12 + collision (sabotages #5/#6) — merge NOT reachable by UI yet (§21: same milestone as the resolver) |
| 7 | Blocking: outbound guard FIRST (escalation #2), then cascade + unblock (AC-06; sabotage #8) |
| 8 | Search: `customers_index` + scope-injection proof BEFORE real data + indexer + Amharic corpus + FS-01 degrade + SearchProxy deepen (escalation #3 part; sabotage #9) ⇒ closing row 3 |
| 9 | Panel/profile backend: T-07 + sections + notes + files gallery (⇒ closing row 4a) + export/erasure doors (SEC-04/06, FS-05) — C-10 + curated allowlist on every read door |
| 10 | Hex-extraction re-run → panel UI (slot-fill escalation #3 + badge ⇒ closing row 2; badge-dot ruling taken at the stop) + AC-08 + AC-B4-03 |
| 11 | Profile page + tombstone (sabotage #13) + identity-work UI + merge UI (FIRST human reach — every guard already falsified) + RG/blocking UI |
| 12 | Directory UI + data-honesty surfaces + §13/§14 sweep + i18n en+am + R-14 flags |
| 13 | Close-out: 91-id self-audit; ledger 15/15 item-by-item; closing-set outcomes; B4-A01 dispositions; DESIGN-DEVIATIONS; inherited 0–11; core-edit enumeration; honest trail; R-14 totals; §12 budget table; final battery; CI once; §9 report — ends with its SHA, owner grounds before the recount |

Cadence: checkpoints after steps 2, 6, 9, 12, close (plus the 1a countersign stop);
mandatory stops per the session prompt. `make gates` on EVERY commit. CI one look per
checkpoint, verdicts owed forward.

## 11. Sabotage list — 15 items (floor 13 + 2; ledger reconciles item-by-item)

1. RG gating moved to serializer level → AC-05 column-absence red (SEC-01/BR-03).
2. An RG read routed through a cache → NFR-06 freshness red.
3. `(tenant,type,value)` unique index dropped (migration-level) → AC-01 race red (BR-04).
4. Resolver taught to auto-link across types → AC-01 no-cross-type red (BR-02/FR-07).
5. Merge compensation disabled → FS-02 chaos red.
6. `merge_snapshot` map incomplete/pruned early → AC-03 byte-identical undo red.
7. Unlink move-set computed differently from typed confirmation → AC-04 red.
8. Outbound customer-level guard removed → AC-06 mid-cascade send succeeds → red.
9. `customers_index` fed without tenancy/scope filter → AC-07 red.
10. "Verified" variant added → AC-09 repo-wide red (BR-01).
11. Duplicate-hint returns names cross-scope → SEC-05 red.
12. Rollup handler made non-idempotent → C-09 replay red under the LIVE relay.
13. Tombstone made to 404 → BR-05 red.
14. R-19b/ADR-0003 grants dropped on the new tables (migration-level) → `CustomerGrantPhysicsTest` red.
15. Customer-note payload routed toward a deliverable path → FR-17's N-04 structural proof red.

Backup before sabotage; fail AND restored-pass captured; tree proven clean before
commit; unfiltered captures, verdicts from capture summary + exit code; no pipes on a
gate (rule 11).

## 12. Frame map (every designed surface; assertions cite frames by id)

- **B4-S01** (12 frames): 1440 enriched light · 1440 dark inline-edit · A03 popover ·
  A04 copy · A06 hint · A12 loading · A13 shell · A14 empty · A15 section-error ·
  A16 conflict · 375 light sheet · 375 dark Amharic → `CustomerPanel.vue` +
  section components → `customers-panel.spec` (+ reduced-motion static placeholders).
- **B4-S02** (7): B01 1440 · B03 audit · B05 files · B06 tombstone ×2 · B02 375 ×2 →
  `Profile.vue`/`AuditTab.vue`/`FilesGallery.vue`/`Tombstone.vue` →
  `customers-profile.spec`.
- **B4-S03-v2** (10): C01 · C02 · C02-v2 out-of-scope · C07 · C03 · C04 · C05 · C06 ·
  375 ×2 → `AddIdentityModal.vue`/`UnlinkModal.vue`/`UnlinkResult.vue` →
  `customers-identity.spec`.
- **B4-S04-v2** (11): D01 · D02-03 · D04-06 · D13 · D07 · D08 · D11 · D09 · D10 ·
  375 ×2 (MERGE token beside the input in both layouts) → `MergeWizard.vue` +
  drawer/undo → `customers-merge.spec`.
- **B4-S05** (12): E01-02 · E03 absence · E04-07 · F01-05 · 375 ×2 →
  `RgBanner.vue`/`RgFlagModal.vue`/`RgHistory.vue`/`BlockModal.vue`/`BlockedBanner.vue`
  → `customers-rg-blocking.spec` (side-by-side no-seam proof; RG slate, blocked red,
  never harmonized).
- **B4-S06** (8): G01/03/07/08 · G04 · G03-unpermitted (five filters, no gap) · G05 ·
  G06 · G09 · G02 375 · 375 phone search → `Directory.vue`/`DirectoryCard.vue` →
  `customers-directory.spec`.
- **B4-S07-v2** (11): H02 + prohibition callout (documentation — nothing built from
  the struck frames) · H01 · H03 · H04 · H07 · H05 ×2 · H06 ×2 · 375 ×2 →
  honesty components + `ExportModal.vue`/`ErasureRequestModal.vue` →
  `customers-honesty.spec`.
- **B4-S08** (5): I08 ×2 · I07 · I05 · I01-04/06 pointer rows → asserted inside the
  owning specs.

## 13. Canonical suites command (standing rule 14 — entered in BUILD-STATUS this commit)

```
./vendor/bin/pest tests/Feature/Customers \
                  tests/Contract/CustomerEventConsumerContractsTest.php \
                  tests/Isolation/CustomerGrantPhysicsTest.php \
                  tests/Security/CustomerSearchScopeTest.php \
                  tests/Security/RgVisibilityTest.php \
                  tests/Security/NoVerifiedAffordanceTest.php \
                  tests/Security/DuplicateHintScopeTest.php
```

Performance under `make perf`; browser under `make browser`; new Doc 08 files at other
tiers join this command in the commit that creates them (figures cited before a named
file exists come from extant paths, stated as such — the S23/S07 record note).
Relay-live law: tests never share mutated rows; seeders self-heal.
