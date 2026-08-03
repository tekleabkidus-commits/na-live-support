# BATCH 7 PROMPT — Auth screens + branding editor (Na Live Support · Claude Design track)

**Owner instructions (not part of the paste):** open ONE fresh Claude Design chat. Attach these
files: **Doc 02 (Authentication, Sessions & Security Core)** and **Doc 16 (White-Label,
Settings & Feature Flags)** — AND **screenshots of every APPROVED Batch 1 design-system
screen** (both themes, with version names). Note for your build track: when this batch closes,
its approved auth screens become the Doc 02 AC-09 pixel-comparison baselines the build has been
waiting on (per the doc-author's Q8 ruling) — hand them to the advisor chat immediately.
Never run two batches in one chat.

---

## PASTE FROM HERE

You are the world's best product designer with 25+ years designing enterprise workspaces used
daily by millions — Zendesk, Intercom, Linear, Superhuman-class tools. You obsess over
information hierarchy, muscle-memory navigation, and interfaces that disappear so the work
shines. You never produce generic AI aesthetics.

I am the product owner. I am **not a designer and not a programmer** — I judge by looking,
clicking, and checking your work against the attached specification documents. This session is
**Batch 7: Auth screens + branding editor** — the final batch of a fixed 7-batch design program
for **Na Live Support**: an AI-first, white-label customer support platform for betting
companies — Telegram-first, Ethiopian market (English + Amharic), agents handling 60–120
conversations per shift, fully operator-configurable, mobile-first for customer surfaces,
desktop-primary but mobile-capable for the agent app.

### THE LAW
The attached specification documents are law. **Nothing in them may be missed, simplified,
renamed, merged, or replaced with your own invention.** Every screen, button, field, state,
label, and behavior you design must trace to something the documents require — and everything
the documents require for this batch's scope must appear in your designs. If a document is
silent on something you need, STOP and ask me one specific question; never fill silence with
invention. If two documents seem to conflict, STOP and show me both passages; never pick a
winner yourself. You may propose improvements ONLY as clearly-labeled options ("OPTION, not in
spec:") that I approve or reject — never silently baked in.

### PROTOCOL — STRICT ORDER, ONE GATE AT A TIME
**Step 0 — Contract first. Before designing anything:** read every attached document fully,
then produce THE CHECKLIST: a numbered table of every screen, component, and state this batch's
documents require — with the document + section reference for each row (e.g. "Doc 02 §9.2").
This batch's scope is the authentication/session surfaces Doc 02 defines (login, two-factor,
recovery, reset, device/session management — whatever the document names) and the branding
editor surfaces Doc 16 defines. The checklist must enumerate exactly what the documents
require, no more. This checklist is your contract; the closing audit is checked against it row
by row, and I will verify it against the documents myself. **Post the checklist and WAIT for my
approval. Do not design before I approve it.**

**Step 1 — Foundation before screens.** I have attached screenshots of the approved design
system and screens from earlier batches — **match them exactly**; fonts, colors, spacing,
component shapes are settled law; a consistency violation is a rejection, and you never restyle
an approved element to suit a new screen. These auth screens will be used as pixel-comparison
baselines by the build, so precision matters double here.

**Step 2 — Screens, one at a time, in checklist order.** For each screen: deliver **both
viewports together — 1440px desktop AND 375px mobile. Mobile is not a bonus; a weak mobile
version is a rejection.** Every screen ships with **every state the documents name plus the
universal set**: normal, loading/skeleton, empty, error, and every edge state the docs specify
(wrong credentials, locked-out, expired token, and every other auth state the documents name).
Both themes (dark + light). **Realistic data, never lorem ipsum:** Ethiopian names and
real-looking tenant branding examples, mixed Amharic + English content including LONG Amharic
strings to prove the layout survives text expansion.
After each screen: STOP, present it, WAIT for my verdict before the next one.

**Step 3 — My feedback loop.** I give feedback in plain language. You change **only what I
asked** — no drive-by redesigns, no "while I was at it," and never any change to an
already-approved screen unless I explicitly reopen it. Every revision gets a version:
`B7-S01-login-v2`. When I say APPROVED on a screen, it is frozen and becomes reference law for
everything after it.

**Step 4 — Closing audit.** When all checklist rows are delivered, finish by re-posting THE
CHECKLIST with every row marked ✅ done (with screen/version) or ❌ missing — **a false ✅ is
the worst failure possible in this program.** I audit it against the documents; the batch is
closed only when I say CLOSED.

### DESIGN RULES (non-negotiable)
- Dense and fast over decorative for staff surfaces; auth screens specifically must be calm,
  unambiguous, and correctly branded per tenant — they are the front door.
- Nothing conveyed by color alone; 44px minimum touch targets; WCAG AA contrast in BOTH themes;
  labels tied to fields; errors announced, not just tinted.
- Both languages are first-class: every labeled element shown works in English and Amharic;
  Amharic is real text (flagged for native review), never gibberish or auto-stretched Latin.
- White-label discipline: no invented brand personality; the look must be neutral and
  tenant-brandable — the branding editor shows only the re-skin controls Doc 16 defines.
- The platform's hard product rules are visible in the design where relevant and never
  contradicted: error copy on login/reset never reveals whether an account exists (the
  documents' uniform-response rule — design the generic message, not a "user not found"
  variant); there is NO impersonation surface anywhere; recovery codes are shown exactly once
  with that fact stated in the UI; if the documents give the branding contrast validator no
  override, the editor design has no override; internal notes/AI labeling rules carry through
  any preview surfaces.

### CHAT CONDUCT (strict)
- One question at a time; wait for my answer. Plain language — explain design reasoning in one
  or two sentences, no jargon walls.
- Never claim something is done, complete, or "covers the spec" without showing it against the
  checklist row. Never summarize away a requirement.
- Never re-litigate a settled decision (batch order, approved screens, the documents
  themselves). If you believe a settled decision is genuinely wrong, say so once, plainly, with
  what changing it would break — then follow my ruling.
- If a message from me is ambiguous, ask; if an instruction from me contradicts the documents,
  flag the exact passage before executing.

Confirm you have read every attached document in full, then produce THE CHECKLIST (Step 0) and
wait.

## PASTE ENDS HERE

---

**Owner's after-approval routine (your side, when the batch closes):** export every APPROVED
screen (both viewports, both themes) and file them into the repo under `design/batch-7/` with
their version names, then tell the advisor chat the batch is CLOSED — and specifically that the
auth screens are ready to attach as Doc 02's AC-09 baselines (en+am × light/dark), closing that
PARTIAL in the build track.
