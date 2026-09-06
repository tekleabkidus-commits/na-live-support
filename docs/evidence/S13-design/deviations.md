# Doc 13 (S13) — DESIGN DEVIATIONS (every one filed; classes per the mandate §3)

| Id | Class | Frame | What | Disposition |
|---|---|---|---|---|
| D13-D01 | 1 (RULED R-D13-2.5) | B3-S08 J03 | the "Use #…" apply button: 36 px at desktop density, 44 px under 640 px | built so (step 11) |
| D13-D02 | 1 | B3-S08 J06 | the frozen fixture hostnames (`cdn.naplatform.et`, `widget.naplatform.et`) render as the PLATFORM's configured origins (`widget.cdn_origin` / `widget.frame_origin`, app URL fallback) | built so (`InstallSnippet`) |
| D13-D03 | 1 | Doc 13 T-03 | `/csat/{survey}` is consumed as Doc 12's `POST csat` | cited at step 8b |
| D13-D04 | 3 | every browser fixture | the HARNESS origins (`*.harness.localhost:8443`) stand in for the drawn hosts (technical necessity, R-D13-1) | standing |
| D13-D05 | 2 (owner-ruled at a stop if reached) | Backoffice shell | any Designer state the shell requires that B3-S08 does not draw — the nav row was filled from the B9 pattern ("an unlinked screen is a worse deviation") | filled (step 11); no other state reached |
| D13-D06 | 1 + 3 | B3-S08 J06 | the Install tab draws the COMPLETE snippet at any time; Doc 02's issuer keeps only the key's hash, so after creation nothing can render it — INTERIM: the snippet rides the create response, the tab shows the shown-once line | **STRUCK — SCHEMA AMENDMENT 7 RIDER 2 landed (S13 Batch C): `widgets.key_public` renders the drawn snippet at any time** |
| D13-D07 | 3 (design defect, owner-confirmed, RULED 2026-09-05) | B3-S08 J03 | the frame draws "#F2C94C on white is 1.9:1 … the nearest passing shade … #8A6A05 at 4.6:1"; the WCAG 2.x formula (A-62's kernel) answers **1.59:1** and the same-hue lightness walk lands on **#906f0a at 4.70:1** | the built Designer shows the formula's figures; the frozen numbers are recorded as illustrative; the frozen file is not touched |
| D13-D08 | 1 (RULED class 1 — advisor recount addendum 2026-09-06) | B3-S03 D08 | the frame draws a "Message deleted" tombstone in the visitor's thread; `GET /api/widget/v1/messages` excludes deleted rows (`whereNull('deleted_at')` — Doc 04's read contract for a customer surface), so the tombstone cannot render: a deleted message simply leaves the thread | filed 8a as a class-1 note, RULED class 1 at the recount: the exclusion stands (Doc 04's read contract; BR-04's surface stays clean); the drawn tombstone is recorded as not rendered; register row 17 reads PARTIAL "class 1 D13-D08" |

Stale references STRUCK (R-D13-2): B3-S04's "awaits E07" caption; the mandate §5's A01/S06 lines.
