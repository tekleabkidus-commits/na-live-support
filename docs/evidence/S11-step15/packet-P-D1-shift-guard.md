# PACKET P-D1 (S11 batch D, filed at the close — escalate-first; countersign BEFORE landing): the Doc 07 `inbox-keyboard-shift` walk budget vs Doc 11's waiting fixtures

## The red (two CI looks, both on the record)
- Run [33365113733](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/33365113733) (`b1a23cb`): Stage 4 — `inbox-keyboard-shift.spec.ts:191` worked **50/60**.
- Run [33368373952](https://github.com/tekleabkidus-commits/na-live-support/actions/runs/33368373952) (`e01f2ad`): worked **53/60** after two of the five strangers left Unassigned — the removal moved the count by exactly the arithmetic's prediction, confirming the mechanism. (That commit's agent-assigned shape also broke seven My-Open specs — corrected to an org-temp assignee in the follow-up; those seven are not this packet's subject.)

## Diagnosis (at cause)
The spec's J-walk works Unassigned to 60 shift tickets under `guard < 1500` total presses, restarting at the top after each worked ticket. Unassigned sorts **created ASC (first-come, FR-10/BR-04)**; on CI's fresh DB the S11 scenes seed BEFORE the shift seeder, so Doc 11's waiting fixtures are OLDER and sit at the TOP — every restart re-pays them. The spec tolerates strangers BY DESIGN (its own comment: "the spec works whatever ELSE unassigned holds"); the 1500 budget was simply sized for the pre-Doc-11 stranger census. Doc 11 legitimately adds waiting tickets to the shared tenant: the queue-view scene's trio (d1101–03, S11 step 11 — `inbox-views.spec.ts:150` renders exactly them as the REAL queue view's waiting list; they MUST be unassigned + waiting) cannot leave Unassigned, cannot be re-sorted (`created_at` is R-19b grant-protected), and cannot move tenants without reworking Doc 07's own spec.

## The ask (Doc 07's file, ONE line)
`tests/Browser/inbox-keyboard-shift.spec.ts:117`:
`while (worked < SHIFT_SIZE && guard < 1_500 && consecutiveSkips <= 60) {`
→ `while (worked < SHIFT_SIZE && guard < 3_000 && consecutiveSkips <= 60) {`
(or, if preferred, a census-proportional form `guard < SHIFT_SIZE * 25`). No assertion changes: `worked === 60`, the leftovers check, and the consecutiveSkips escape all stand byte-identical — the guard is a runaway brake, not a proof, and 3000 still bounds the walk at ~2× today's worst census.

## Proof on landing
The spec green on CI's fresh DB with the S11 trio present (the next push's Stage 4); locally green ×1 (the local DB's larger pileup makes the local run indicative only — stated).

## Until the countersign
The tree stands at ONE known Stage-4 red with this packet filed; nothing in Doc 07's file is touched.
