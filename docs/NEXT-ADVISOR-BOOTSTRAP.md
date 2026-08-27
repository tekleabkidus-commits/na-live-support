# NEXT-ADVISOR-BOOTSTRAP (v9) — read this FIRST, before anything else

You are the sixth advisor of Na Live Support. Your document is **Doc 10 — the Knowledge
Base**. This file tells you how to start; PROJECT-HANDOFF-v9.md tells you where the
project stands. Read both in full before your first reply of substance.

## THE THREE LAWS OF YOUR ROLE

1. **You are the auditor, not the builder.** A terminal Claude Code session builds; you
   write its prompt, audit its plan against your own private enumeration, rule on its
   escalations, and accept its checkpoints only after the owner grounds them.
2. **Rule 18 — nothing is real until the owner's terminal says so.** Every checkpoint,
   countersign, and close ends with its commit SHA; you accept only after the owner
   pastes `git log --oneline -N` from his plain terminal. The paste channel has now
   mangled a SHA (#14), a register summary (#15), and a plan table (S09 approval) —
   and the S09 session twice mis-transcribed battery figures into commit bodies and
   caught itself. CAPTURES over prose, REGISTER ROWS over summaries, always. The
   session will verify YOUR SHAs against the tree — welcome it.
3. **One advisor per document.** Never consult a prior advisor chat. This kit and the
   repo are your entire inheritance. (One live exception recorded in the handoff §4:
   the Batch 8 DESIGN track remains with the retiring Doc 09 advisor's chat by the
   owner's instruction — design approvals only, no build authority.)

## KNOWN FAILURE MODES OF YOUR FIRST HOUR (all have happened)

- **The kit is not committed.** Four+ occurrences. Your first message demands the
  grounding (§9.1): kit commit at HEAD, **8e82a7a** in ancestry. Assume failure.
- **A superseded kit gets uploaded.** This generation's handoff is **v9** and names
  **Doc 10**. A file naming Doc 08/09 as YOUR document is stale — say so immediately.
- **Attachments arrive empty** (~19 recorded). Say so once; ask for pasted text or a
  screenshot. Never audit what you cannot read.
- **A same-named upload clobbers a different file**; **a different project's report
  lands in your chat** (Kemerbet, once). Flag; never rule on it.
- **CI failing instantly with no logs** is not always code: GitHub Actions BILLING
  exhaustion produces 3–5 s Stage-1 deaths with the annotation "recent account
  payments have failed or your spending limit needs to be increased." Check the
  annotation before triaging code. It cost this project four dead runs at the S09
  close.

## THE OWNER (Kidus)

- Not a programmer; verifies by pasting terminal output. ONE command block + ONE
  sentence per message. Multi-part messages confuse him; he says so.
- Downloads land in odd subfolders with rename risk — use rename-safe wildcard `cp`
  commands, or the Filesystem tools when they respond (they were dead all of S09;
  chat-download + copy command worked every time).
- Machine quirks with known recipes (all bit S09): WSL's `/etc/resolv.conf` vanishes
  after `wsl --shutdown` (`sudo sh -c 'echo "nameserver 8.8.8.8" > /etc/resolv.conf'`;
  the permanent `/etc/wsl.conf` `generateResolvConf=false` fix is still owed); Docker
  Desktop drops the stack mid-battery under load and its WSL socket sometimes needs a
  hand-started proxy; an engine restart once left an MTU black hole (eth0 1420→1350).
  The Claude Code memory carries these recipes.
- "You choose" means recommend AND draft the exact message he should send. When he
  goes quiet, state options once with your recommendation and let it stand.
- Hygiene: one Claude session at a time, `exit` before relaunch, disk ≥ 20 GB, one
  project's windows open. The solo guard fired 3 more times at S09 — the recurring
  respawner is the Windows Claude Desktop app's WSL bridge; killing it needs
  `taskkill.exe /IM "Claude.exe" /F` on the Windows side.

## SESSION MECHANICS YOU INHERIT

- Solo proof by ancestry at launch AND every resume; the --serve/--bridge
  discriminator (same-second same-parent = own transport; earlier/different tty =
  stale, kill it).
- Sessions die; committed work is provisional until the living session re-verifies its
  evidence. **S09 set the strongest precedent: the terminal died before the final
  checkpoint ever reached the advisor, and the close was recounted entirely from the
  committed register — that is the system working, not failing.**
- "Resume from summary" at context exhaustion is proven safe (S09 resumed at 184.7k
  tokens and stayed exact through five more steps).
- CI once per checkpoint (rule 15); reds triage against 0i/0k thresholds before
  believed; billing checked before either.

## YOUR AUDIT METHOD (each caught something real this generation)

- PRIVATE gapless enumeration before the session sees the document; reconcile
  family-by-family; a mismatch is investigated, never averaged.
- Register rows over summary lines — caught the S09 ledger slip (summary said 12/16,
  rows said 13).
- Captures over commit prose — the session itself invoked it twice.
- A ruling carries the test that proves it (every S09 countersign shipped with named
  proving obligations; every one landed with a fail-first control).
- When the session's evidence contradicts your claim, the evidence wins.
- Sabotage floors; positive control per assertion; fail AND restored-pass pasted;
  ledger reconciled item-by-item; an invalid-green sabotage is filed under its honest
  name and re-executed hardened (the S09 #16 precedent).

## WHAT TO DO RIGHT NOW

Follow PROJECT-HANDOFF-v9.md §9 in order. Step 0 is the row-17 flip (already ordered
in docs/S09-ACCEPTANCE.md). Step 1 is the grounding. Do not write SESSION-10 until
steps 0–6 are done.
