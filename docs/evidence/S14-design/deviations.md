# SESSION-14 design deviations (mandate v13; R-D14-2 Option A)

Filed at the close-out (2026-10-20). D14-D01 was ruled at plan approval (STOP-N) to be
filed "at step 12"; the file was not created then — an honest lateness, recorded here.
The IMPLEMENTATION landed at step 12 exactly as ruled; only the filing is late.

## D14-D01 (class 2, ruled at STOP-N — the two glyph fills)

The frozen B1-DS-v2 glyph row draws ◷ queued · ◷ sending · ✓ sent · ! failed · ⊘ canceled.
Doc 14's state vocabulary needs two states the row does not draw; they are FILLED, not
invented:

- `delivered` → **✓✓** (the double check), rendered ONLY on a real DLR — never inferred
  from `sent` (NFR-04; the zero-inferred-delivered DOM gate
  `tests/Browser/sms-no-inferred-delivered.spec.ts` is the control).
- `held` → **☾** (moon — the quiet-hours hold).
- `expired` → no glyph fill; the DS gray wording "Sent · unconfirmed" (the honest
  terminal), per the ruling.

Ink: the DS tokens only (the chip renders through `TicketTimeline.vue`'s `smsGlyph`
map, `resources/js/Components/Inbox/TicketTimeline.vue:23`). Tooltip copy explains
"different channel, different truth" via the `sms_chip_*` keys (`ticket_panel` slice,
en + am).

## D14-D02 (the STOP-E copy alignment — P-EXPIRED-CARD)

The built copy before S14 was `inbox.expired_sms` = "Send SMS notification"; the frozen
B1-S02 card reads "Send SMS re-engagement · Reply from the phone · Wait · SMS is
notification-only — the customer cannot reply to it." P-EXPIRED-CARD (countersigned
2026-09-08, landed at step 12) aligned the card copy to the frozen text verbatim
(R-D14-2.4), en + am. Doc 07's design record carries no filed deviation for this copy
(searched: no `deviations.md` under any design evidence names the expired card), so per
STOP-E this files as **D14-D02** rather than citing one.

## The [B14-batch] screens (not a deviation — the standing PARTIAL)

The six step-13 screens (Templates, Gateways + routing, Queue & history, Opt-out
manager, Broadcasts, Settings + setup checklist) are functional/themed/i18n-keyed from
DS components against the NEAREST DRAWN IDIOMS (R-D14-2.2) — there are no frozen frames
for them yet. The frame-fidelity PARTIAL stands on each ([B14-batch]); the Docs 14+17
design track then one fidelity session (R-D14-2.5) discharges it. Hex extraction re-run
before the first screen: 148 token declarations, PASS (step-13 record).
