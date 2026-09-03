# PACKET P-F1-BANNER (SESSION-F1 [B8]; escalate-first — COUNTERSIGNED by the advisor, owner-relayed at 5e8a953 — LANDED)

THE FILE: Doc 07's `resources/js/Pages/Inbox/Workspace.vue` (a CORE document's file for this session).

THE GAP: B8-S07 §D G05 — "the inbox header mirror … one banner even when multiple switches are off; it names the count and links to the grid". The banner shipped at S09 (countersigned packet) renders ONLY when a switch is off and already names surface, scope, since {time}, by {actor} and the count (`ai.ui.banner_off`). It carries no link to the grid; the frame's right-hand text reads "Details in Settings → AI → Kill switches".

THE EDIT (one file, one line): inside the existing `data-testid="ai-killswitch-banner"` paragraph, append one `<a href="/admin/ai/kill-switches">` carrying the i18n key `ai.ui.banner_details` (en "Details in Settings → AI → Kill switches", am — the same Doc 09 lang file, already the banner's own namespace). No new testid, no logic change; the banner's visibility rule is untouched.

CONTROLS: `ai-backoffice.spec.ts` (banner absent while on, present with "chatbot" while off, gone after the flip back) byte-untouched and green; `ai-copilot.spec.ts`'s no-banner assertion untouched.

UNTIL COUNTERSIGNED: the banner stays as built; the fidelity register carries B8-S07 G05 as PARTIAL (count present, link pending the packet).

**LANDED as filed: one `<a href="/admin/ai/kill-switches">` inside the banner paragraph carrying `ai.ui.banner_details` (en + am); the visibility rule untouched; `ai-backoffice.spec` byte-untouched.**
