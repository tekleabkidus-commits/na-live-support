# ADR 0004 — The traceability gate enforces per document, with a ratchet

- **Status:** Accepted (owner ruling D-1, Session 1)
- **Date:** 2026-07-30
- **Spec:** Doc 19 FR-02, CLAUDE.md §2 (traceability registry)
- **Supersedes:** the all-or-nothing bootstrap flip in Session 0's `ci/traceability.php`

## Context

CLAUDE.md §2 requires every Acceptance Criterion in Docs 01–19 to map to at least one
automated test or a justified manual entry, enforced by CI. Session 0 implemented that
with a single global flip:

- `registries/traceability.yaml` empty → warn, exit 0.
- registry non-empty → **every** AC in **every** document must be mapped, or fail.

There are **180 ACs across the 19 documents**. Doc 01 owns 8. So the first honest entry
— mapping Doc 01 AC-01 to the isolation suite that proves it — would have made 172 ACs
of eighteen unwritten modules hard-fail the build.

That left three moves, and two of them were unacceptable:

1. **Map the 172 to placeholder `manual` entries.** `registry:check` would accept them
   (it does not validate `justification`), so this was the path of least resistance. It
   is also a lie: it asserts manual coverage that does not exist, in the one file whose
   entire job is to tell the owner what is proven. This is the false-PASS that
   CLAUDE.md §0.3 and §9 exist to prevent, and the owner confirmed it is never
   acceptable in this repo.
2. **Sign a skip file for the gate.** Turns the gate off. CLAUDE.md §8 forbids it
   outside the change-control process, and a gate skipped on the day it would first
   have bitten is a gate that never bites.
3. **Fix the gate.** Chosen.

The deeper problem with the Session 0 rule is that it was never satisfiable *and*
enforcing. It was either off (registry empty) or impossible (registry non-empty), so in
practice the registry would have stayed empty and the gate would have warned forever.

## Decision

A document's ACs become **blocking** when either condition holds:

1. **Its `docs/BUILD-STATUS.md` row is neither `NOT STARTED` nor `CONTINUOUS`** — i.e.
   the module is being built or is built.
2. **Any AC of that document is already mapped** — the *ratchet*.

`BUILD-STATUS.md` is the source of truth rather than a separate config file, because it
is the document the owner actually reads and updates. Enforcement then follows the
project's own account of itself, and there is no second list to drift out of step.

### Why `CONTINUOUS` is excluded from (1), and why the ratchet is required

Docs 18 (Infrastructure) and 19 (QA) are marked `CONTINUOUS`: Doc 18 accrues a CI gate
per session, Doc 19 accrues traceability per session. There is no single session at
which all their ACs are in scope — Doc 18 AC-06 concerns SSRF/egress controls that no
module has introduced yet — so enforcing them wholesale today would demand rows for
infrastructure that does not exist.

Without the ratchet, `CONTINUOUS` would be a permanent exemption. With it, the moment
anyone maps a single Doc 18 AC, all ten become blocking. **A document is exempt only
while nobody has claimed any coverage in it; claiming any is a commitment to account
for all of it.**

### This is a strengthening, not a relaxation

Under the old rule the gate would have been permanently off. Under this one, every
document being worked on is fully enforced, and enforcement arrives module by module as
the build order in CLAUDE.md §7 progresses. Doc 01's eight ACs are blocking today.

### Honest partial coverage

This project adds `status` and `gap`/`justification` fields to registry entries so a row
can say *"the test exists, runs, and covers this much"*. Doc 01 AC-01 is recorded as
`partial`: its direct-SQL half is complete and schema-generated, while its "via every
public endpoint" half is only as complete as the endpoints that exist. Five of Doc 01's
eight ACs are recorded as `not-implemented` with the reason.

Recording an AC as not-implemented is not a way to satisfy the gate cheaply — the gate
only requires a *row*, and the row's content is what the owner reads. The alternative is
silence, which is worse: an unmapped AC is invisible, whereas a `not-implemented` row is
a standing accusation.

## Consequences

Falsified in the commit that introduced it — the gate must be seen to fail:

| Sabotage | Result |
|---|---|
| Remove Doc 01 AC-08's entry | `FAIL unmapped AC: Doc01 AC-08`, exit 1 |
| Mark Doc 02 `IN PROGRESS` with no Doc 02 entries | `FAIL (9 unmapped AC(s) in 2 in-progress document(s))`, exit 1 |
| Add one Doc 18 entry (ratchet) | `FAIL … Doc18 AC-02 … AC-10`, exit 1 |
| Restored | `PASS (8 AC(s) mapped across 1 enforced document(s); 172 pending)`, exit 0 |

Costs:

- **Marking a document `IN PROGRESS` in `BUILD-STATUS.md` now has teeth.** Flipping a row
  without mapping that document's ACs fails the build. This is intended: it makes the
  status file a commitment rather than a note.
- The gate now parses a Markdown table. If `BUILD-STATUS.md`'s table shape changes, the
  parse silently finds no row and treats the document as not started. Mitigated by the
  gate failing loudly if the file is missing entirely, and by the pending count being
  printed on every run — a sudden jump in "pending" is the signal that a row stopped
  matching.
