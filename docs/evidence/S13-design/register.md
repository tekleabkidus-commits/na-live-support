# Doc 13 (S13) — FRAME-MAPPING REGISTER, recounted FROM ROWS at the step-13 close (2026-09-06)

One row per frame of B3-S01…S10 as enumerated in `docs/SESSION-13-APPROVED-PLAN.md` §10 (from the frozen files), plus B3-A01 and B3-S11's six. Status words: **PROVEN** (an executed test asserts the frame's visibility/behaviour — the step section and capture are named), **BUILT** (implemented, the drawn behaviour not asserted by an executed test — stated, never counted as proven), **PARTIAL** (a half proven, the other half in a named bucket or refused packet), **NOT BUILT** (the frame's control does not ship — bucketed). Every status is read from the step sections' own frame-mapping statements (BUILD-STATUS S13 steps 7, 8a, 8b, 9a, 9b, 10, 11, 12, 13), never from a summary.

| # | Frame | Status | Where proven / why not |
|---|---|---|---|
| 1 | S01 · B01 launcher closed 375 light | PROVEN | step 7 geometry (hostile-host bounding box + footprint audit) · step 8a render · step 13 the loader-drawn launcher paints the same 56 px bubble before the core (`widget-loader.spec` NFR-01 legs) |
| 2 | S01 · B02 unread + nudge | PROVEN | `widget-panel.spec` B01/B02 (label names the state; the count a NUMBER) · nudge once per session, none under reduced motion (8a) |
| 3 | S01 · B03 dark closed + mid-morph | PROVEN | axe both themes (`widget-theme.spec`, 8b); the 150 ms morph is the core launcher's — the loader-drawn static launcher swaps glyphs without transition until the core takes over (stated, step 13) |
| 4 | S01 · B06 sheet 375 persona header + tab bar | PROVEN | `widget-panel.spec` (dialog, focus trapped, Esc → launcher; full sheet < 480 on widget-375) |
| 5 | S01 · B07 dark agent header + overflow | PROVEN | `widget-panel.spec` B07 (sound · both languages · transcript · privacy = six items since step 12; pinned at the Batch C checkpoint) |
| 6 | S01 · B09 Messages-only | PROVEN | step 10 `propagation-live-01` — a publish takes the tab bar away in place and the next brings it back |
| 7 | S01 · 1440 light floating | PROVEN | `widget-panel.spec` B05 (380×640; minimize present on widget, absent on widget-375) |
| 8 | S01 · 1440 dark auto-theme + am | PROVEN | `widget-theme.spec` L03 both ways + B07's Amharic switch re-renders in real Ethiopic |
| 9 | S02 · C01–C05 returning in-hours | PARTIAL | C01/C05 PROVEN (`widget-home.spec` greeting, band, shortcuts, "opens the site"); the RESUME CARD (C02/C04) is BUILT (renders only with history) — its visibility is not asserted by an executed test |
| 10 | S02 · C03 dark out-of-hours am | PROVEN | `widget-forms-hours.spec` F09/F10 ("opens at" band) · step 12 F11 (`off_hours` band) · axe dark + am (Designer 375 leg) |
| 11 | S02 · C06 loading | PROVEN | `widget-home.spec` — greeting from cached config, never a skeleton |
| 12 | S02 · 1440 first visit | PROVEN | `widget-home.spec` — "Quick answers", no resume card |
| 13 | S02 · 1440 dark busy slower band | BUILT | the amber band + clock glyph for the hour band is built (`Home.tsx`); the harness never produced a `long` band — not asserted |
| 14 | S03 · D02–D07 AI mid-stream | PARTIAL | the COMPLETED AI bubble PROVEN (violet accent + the "AI assistant" text, `widget-thread.spec`, 8b live AI path); the mid-STREAM caret NOT BUILT — P-STREAM's countersign REFUSED by its own safety condition (step 5b); Doc 09 T-04 stays PARTIAL naming P-STREAM |
| 15 | S03 · D10–D13 dark handoff + queue | PROVEN | `widget-queue.spec` (pushed position; EC-06 as drawn; agent joining clears) — and its fixture-shape fix at f72da6d |
| 16 | S03 · D12–D13 two queue states | PROVEN | `widget-queue.spec` (band-only when unknown; the number when known) |
| 17 | S03 · D14/D15/D08/D09 agent, media, tombstone | PARTIAL | D14 PROVEN (first_name), D15 PROVEN (thumbnail → lightbox → Esc, 9b); D08 "Message deleted" tombstone NOT RENDERED — `GET messages` excludes deleted rows (Doc 04 physics), class-1 filed for the advisor (8a) |
| 18 | S03 · D16/D17 CSAT then continues | PROVEN | `widget-csat.spec` (star radiogroup after resolve; the thread carries on, no wall) |
| 19 | S03 · D18 empty | PROVEN | `widget-thread.spec` (8a) |
| 20 | S03 · 1440 long thread folded | BUILT | virtualization > 200 built (`Thread.tsx`); no 200-message scene was driven — not asserted |
| 21 | S03 · D01 no list explainer | BUILT | the thin row only with ≥ 2 conversations is built; not asserted by an executed test |
| 22 | S04 · E01 browse | PROVEN | `widget-help.spec` E01 (9a) |
| 23 | S04 · E02 dark search am | PROVEN | `widget-help.spec` E02 |
| 24 | S04 · E03/E04 article + feedback | PROVEN | `widget-help.spec` E03/E04 after P-KB-VIEWED (both projects, `widget-help-after-landing-01`) |
| 25 | S04 · E06 index down | PROVEN | `widget-help.spec` E06/K05 |
| 26 | S04 · E07 language fallback | PROVEN | `widget-help.spec` E07 after P-KB-VIEWED (the "awaits E07" caption STRUCK) |
| 27 | S05 · F01/F02 anonymous, pre-chat off | PROVEN | `NoGhostProfileTest` + `widget-forms-hours.spec` |
| 28 | S05 · F03/F04 optional/required | PROVEN | `widget-forms-hours.spec` (skip vs block; no tab bar; inline refusal naming the field) |
| 29 | S05 · F05/F06 conflict silent, asserted | PROVEN | F05 server-side (`PreChatConsumerTest`, the visitor never told); F06 "Signed in as" — the word "verified" appears nowhere (`NoVerifiedAffordanceTest` + the spec) |
| 30 | S05 · F08 dark out-of-hours bot on | BUILT | the gate logic (thread stays, off-hours handoff copy) built (9a); the after-hours AI leg rides the chatbot fixture — [D19]/[OA] |
| 31 | S05 · F09/F10 offline form + receipt | PROVEN | `widget-forms-hours.spec` (real offline-tagged ticket, masked phone) — and its seeder fix at 0835a7b |
| 32 | S05 · F11 17:59 → 18:00 | PROVEN | step 12 `drills-browser-01` (the calendar closes under a live thread: nothing changes for the in-flight conversation) |
| 33 | S05 · F12 third page | PROVEN | `browser-continuity-01` (home → /deposit → /payments, one conversation, the same visitor) |
| 34 | S05 · F13 site logged in as someone else | PARTIAL | server half PROVEN (`SignedIdentityTest` EC-04: nothing re-linked, the staff note); the DOM half ("nothing visible") is not asserted by an executed test |
| 35 | S06 · G01 five-line ceiling | PROVEN | `widget-composer.spec` G01 |
| 36 | S06 · G03/G04 offline + queued | PROVEN | `widget-degrade.spec` K02 (banner; attach disabled; typing still works) + transport chips |
| 37 | S06 · G02 messaging unavailable | PROVEN | `widget-composer.spec` G02 (paused copy through the vocabulary gate) |
| 38 | S06 · G06 pasted screenshot card | PROVEN | `widget-composer.spec` G06 |
| 39 | S06 · G05 uploading + G07 over cap | PROVEN | `widget-composer.spec` G09 (progress + cancel) + EC-08 (over-cap refused, zero upload requests) |
| 40 | S06 · G09 long paste | PROVEN | `widget-composer.spec` EC-10 ("2 parts", two messages in order) |
| 41 | S06 · attach menu voice off | PROVEN | `widget-composer.spec` G05 (the voice row ABSENT, not greyed) |
| 42 | S06 · attach menu voice on | NOT BUILT | the recorder does not ship (the platform flag is off) — [OA-voice]: the row renders only under the flag; no recorder behind it |
| 43 | S07 · H01 proactive on deposit page | PROVEN | `widget-proactive.spec` H01 |
| 44 | S07 · H02 dismissed once | PROVEN | `widget-proactive.spec` H02 |
| 45 | S07 · H03 one per cooldown | PROVEN | `widget-acceptance.spec` cross-rule cooldown (9b) |
| 46 | S07 · H04 never over a keyboard (card) | PROVEN | `widget-proactive.spec` H04 |
| 47 | S07 · H04 dark deferred frame | PROVEN | the same leg (held until blur) |
| 48 | S07 · I01 asked after first message | PROVEN | `widget-notify.spec` (BR-03) |
| 49 | S07 · I02 tab unfocused — all three | PROVEN | step 12 `drills-browser-01` (exactly one Notification, one tone, the title counter) |
| 50 | S07 · I03 visible+focused — silence | PROVEN | step 12 `drills-browser-01` |
| 51 | S07 · I05 six tabs one voice | PROVEN | `widget-acceptance.spec` two tabs one socket + hand-over (9b); the leader lock keyed on the thread channel |
| 52 | S07 · I05 inside the Android app | PARTIAL | the harness half PROVEN (`widget-notify.spec` EC-09: API absent → no-op); the device is [OA] |
| 53 | S07 · 1440 I04 sound switch in overflow | PROVEN | `widget-panel.spec` B07 |
| 54 | S08 · 1440 J01–J03 Appearance + preview + refusal | PROVEN | `designer-widgets.spec` (step 11 proof; D13-D01, D13-D07) |
| 55 | S08 · 1440 J04/J05 Content + Behavior | PROVEN | `designer-widgets.spec` |
| 56 | S08 · 1440 J06–J10 Install, Health, publishing | PROVEN | `designer-widgets.spec` (the drawn snippet since RIDER 2) |
| 57 | S08 · 375 J09 admin list | PROVEN | `designer-widgets.spec` (list first, 44 px, dark + Amharic axe — its locale method fixed at fe15e93) |
| 58 | S09 · K01 socket lost — nothing changes | PROVEN | `widget-transport.spec` (the drill) |
| 59 | S09 · K02 dark visitor offline | PROVEN | `widget-degrade.spec` |
| 60 | S09 · K03 last-resort card | PROVEN | `widget-degrade.spec` |
| 61 | S09 · K04 bot down | PROVEN | step 12 `drill-bot-down-01` (`widget-bot-down.spec`) |
| 62 | S09 · K05 Help down | PROVEN | cited from S04 E06 (`widget-help.spec`) |
| 63 | S09 · K06 below matrix / blocked | PROVEN | `widget-loader.spec` NFR-05 (no mount, no trace) |
| 64 | S10 · L05 switcher + resolution list | PROVEN | `widget-panel.spec` B07 (the switch, real Ethiopic); the incomplete-language gate at the Designer (J04) |
| 65 | S10 · L06 RTL mirror | BUILT | logical CSS properties throughout (C-15); no Arabic placeholder leg was executed — not asserted |
| 66 | S10 · L03 auto theme two systems | PROVEN | `widget-theme.spec` |
| 67 | S10 · L07 focus trapped + returned | PROVEN | `widget-panel.spec` + `widget-acceptance.spec` (S11's six) |
| 68 | S10 · L08 thread polite log | PROVEN | `widget-thread.spec` + the six (the log announces the staff reply) |
| 69 | S10 · L09 queue on change | PARTIAL | the announcement PROVEN (`widget-queue.spec`: "You are number N in line"); the negative ("only when the number moves") is not asserted |
| 70 | S10 · L10 star row radiogroup | PROVEN | `widget-csat.spec` |
| 71 | S10 · L13 text zoom 200 % | PROVEN | `widget-acceptance.spec` (the six) |
| 72 | S10 · L12 four specimens | PROVEN | 8b (`widget-degrade`/`widget-thread`: number+label, glyph+word, queued in words) |
| 73 | S10 · M01–M07 table | PARTIAL | M03 (F06), M05 (D13), M06 (SEC-06 absence), M07 (footprint) PROVEN by their frames; M01/M02/M04 are not individually asserted — they ride AC-B3-01's prohibited-class sweep; flagged for the recount |
| 74 | A01 · AC-B3-01 | PROVEN | `widget-acceptance.spec` both assertions (375/1440, light/dark, the config matrix) |
| 75 | S11 · six drawn-not-proved | PROVEN | `widget-acceptance.spec` (focus trap · live regions · polling failover · leader election · proactive cooldown · text zoom) |

**Recount FROM ROWS (75 rows):** BUILT 5 · NOT BUILT 1 · PARTIAL 7 · PROVEN 62.
