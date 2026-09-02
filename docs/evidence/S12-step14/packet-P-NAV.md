# PACKET P-NAV (S12 step 14; escalate-first — PROPOSED, countersign before landing)

THE GAP: Doc 12's surfaces (rules, SLA policies, business hours, macros,
fields, forms, CSAT) ship at step 14 as their own Backoffice pages under
`/admin/workflow/*`, carrying the DRAWN Backoffice chrome the way Doc 11's
Organization page does. Two shell-side entries point AT them and both live in
other documents' files:
1. Doc 11's `Organization.vue` draws the Backoffice nav with "SLA policies",
   "Automation" and "Business hours" as siblings — "drawn, not linked (their
   docs own them)". Doc 12 now owns them.
2. Doc 07's command palette (Workspace.vue + CommandPalette.vue) carries the
   P8 precedent: ONE nav row per surface group ("Open the Knowledge Base",
   S10 countersigned).

THE EDIT (two files, one line each):
- Organization.vue: the three sibling spans become `<a href>` rows to
  `/admin/workflow/sla`, `/admin/workflow/rules`, `/admin/workflow/hours`
  (People/Channels stay drawn — Docs 03/05 own them). No new row; the drawn
  set is unchanged.
- CommandPalette.vue + Workspace.vue: one row `open-workflow` → `/admin/workflow`
  (label key `inbox.palette.action_open_workflow`, en + am), the P8 shape verbatim.
Until countersigned: Doc 12's pages link EACH OTHER through their own chrome
(the same drawn nav, with Organization as a real link back — a Doc 12 page
linking OUT is Doc 12's own file), and are reachable by URL; the reverse
links are this packet's. The ruling's "an unlinked screen is the worse
deviation" is answered by the pages' own nav; the shell entry follows the
countersign (the P8 precedent's own order).
CONTROLS: the Organization browser spec byte-untouched except the three hrefs
asserted; inbox-palette.spec gains the one row; Doc 07 + Doc 11 owning suites
green in the same commit.
