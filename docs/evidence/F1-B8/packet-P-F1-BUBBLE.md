# PACKET P-F1-BUBBLE (SESSION-F1 [B8]; escalate-first — COUNTERSIGNED by the advisor, owner-relayed at 5e8a953 — LANDED)

THE FILE: Doc 07's `resources/js/Components/Inbox/Thread/MessageBubble.vue` (a CORE document's file for this session).

THE GAP (two clauses, both named by frozen Batch 8 frames):

1. **B8-S03 §G C02 — the AI bubble anatomy.** The frame: "the accent + '✦ AI assistant' text mark every AI turn … exactly the B1-DS drawn anatomy". The drawn anatomy (B1-DS v2 AI family, also the S01 card): background `--ds-ai-bg`, a 1 px `--ds-ai-border` border, a 3 px `--ds-ai-edge` inline-start edge, body text in `--ds-text-primary`, the ✦ label in `--ds-ai-fg`. The build (S09 packet, countersigned) draws `--ds-ai-bg` with a 1 px `--ds-ai-fg` border and the WHOLE bubble text in `--ds-ai-fg`. The two tokens the anatomy needs (`--ds-ai-border`, `--ds-ai-edge`) were added at the F1 opener hex-for-hex from the frozen design system (extraction 148/148 PASS). The `data-role="ai-marker"` label, its text, and the "translated" marker stay byte-identical (`ai-honesty.spec` asserts them).

2. **B8-S11 §E K08 — FS-03 transcription down (Doc 04 EC-07 semantics).** The frame: a voice message whose transcript is absent shows the player and a labelled line "Transcript unavailable — transcription is down; retrying automatically. The audio plays as normal." The bubble today renders the player (Doc 04's media) but no labelled degrade when `transcript` is absent on a voice message.

THE EDIT (one file):
- Clause 1: in the `isAi` style branch, `border: '1px solid var(--ds-ai-border)'`, `borderInlineStart: '3px solid var(--ds-ai-edge)'`, `color: 'var(--ds-text-primary)'` (the ✦ marker line keeps `color: var(--ds-ai-fg)` via its own style); no markup change.
- Clause 2: after the media block, when `str('kind') === 'voice'` (or the media type is audio) and no transcript text is present: one `<p data-role="transcript-unavailable">` carrying an i18n key (`inbox.thread.transcript_unavailable`, en + am — Doc 07's lang file, the same packet).

CONTROLS: `ai-honesty.spec.ts` byte-untouched (the marker text and the ai-bg ≠ customer-bg assertions still hold — the background is unchanged); `inbox-thread.spec.ts` untouched; the F1 proof adds one visibility assertion for the edge on an AI bubble and one for the transcript line on a transcript-less voice fixture if the InboxBrowserSeeder carries one (else recorded as unproven-live).

UNTIL COUNTERSIGNED: the thread bubble stays as built; the fidelity register carries B8-S03 C02 and B8-S11 K08 as NOT-DONE (packet pending), not as ✅.

**LANDED: both clauses as filed; the seeder gained a dedicated fixed-id transcript-less voice row under the 0l law (AiBrowserSeeder, `00000000-0000-7000-8000-0000000a0f03`) so the degrade line is proven visible (f1-b8 packets proof), never recorded unproven-live.**
