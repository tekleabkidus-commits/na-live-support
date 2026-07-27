# HOW TO USE THIS KIT

You are the project manager. Claude Code is the engineering team. This kit is how you keep control
of a team that writes faster than you can read.

## First 20 minutes

```bash
mkdir na-live-support && cd na-live-support
git init
# copy this kit in:
#   CLAUDE.md          → repo root
#   docs/              → repo root  (the 19 specs, now markdown)
#   prompts/           → repo root  (not shipped, just your working folder)
git add . && git commit -m "chore: specs and project constitution"
claude
```

Then paste **the entire contents of `prompts/SESSION-00-repo-bootstrap.md`** as your first message.

## The loop, every session

1. **Fresh session per document.** Never continue Doc 03 in the session that built Doc 02 — context
   rot is what makes Claude Code start inventing.
2. Paste the session prompt. It will reply with a **plan table**. Read it. Approve or correct it.
   This is your main control point — a wrong plan is cheap to fix, wrong code is not.
3. Let it build. Expect fix-loops; they are normal, not failure.
4. When it reports done, **do not read the code**. Do this instead:
   ```bash
   make gates
   make test
   ```
   If those are green and its self-audit table has no false `DONE` rows, the session succeeded.
5. Commit, update `docs/BUILD-STATUS.md`, start a fresh session for the next document.

## The three questions that catch over-claiming

Ask these whenever a report feels too clean:

- *"Show me the test file that proves «FR-07», and paste its output."*
- *"Which acceptance criteria did you NOT implement, and why?"*
- *"Delete the check that enforces «invariant» and show me the test failing."*

The third one is the strongest. A safety test that has never been seen to fail is not known to work.

## What only you can supply

Claude Code cannot get these. Line them up before the sessions that need them:

| Needed by | Item |
|---|---|
| Session 0 | Docker + Ubuntu host, a Git remote |
| Doc 05 (Telegram) | BotFather token, **and a Business account on a dedicated phone** for staging and another for production. Treat the support phone as production infrastructure |
| Doc 14 (SMS) | Ethiopian SMS gateway sandbox credentials, then production credentials |
| Doc 09 (AI) | AI provider API keys (staging keys first) |
| Doc 13 (Widget) | The support subdomain, and the Android app WebView build |
| Before real players | A freelance senior Laravel dev, a few paid hours, to review auth, tenancy, and money-adjacent code |

## Honest expectations

- **MVP = Docs 01–07 plus a conservative Doc 09, Telegram only.** Realistically **2–4 months** at a
  steady pace, not weeks.
- Sessions 0 and 1 are slow and feel like nothing is happening. They are the sessions that decide
  whether month four is possible.
- Claude Code will occasionally report work it did not finish. That is not malice and not unusual —
  it is why every prompt in this kit ends with a falsification step and a self-audit table.
- The first output of any session is roughly 70%. Iteration is the process, not a sign of failure.

## Where the design fits

Claude Design produces the screens; Claude Code consumes them. When you reach Doc 07 (Unified Inbox)
and Doc 13 (Widget), attach the approved design screenshots to that session's prompt and add one
line: *"Match the approved design exactly; deviations are rejections."* Do not let Claude Code
invent UI for a screen that has an approved design.
