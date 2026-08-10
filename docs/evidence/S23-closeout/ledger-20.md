# S23 close-out — sabotage ledger, item-by-item against the plan's enumeration (for the advisor's recount)

The enumeration of record: BUILD-STATUS "Sabotage list (the ledger reconciles against THIS enumeration)"
(20 items after the A-26 and A-27 amendments, 19→20, each filed before its sabotage ran).

### Sabotage ledger — reconciled ITEM BY ITEM against the plan's 20 (COMPLETE)

| # | Sabotage | Step run | Evidence |
|---|---|---|---|
| 1 | capability lie → conformance red | 3 | S23-step3 captures |
| 2 | dedupe dropped → duplicate fixture red | 5 | S23-step5 captures |
| 3 | inline ACK → latency red | 9 | `S23-step9/sabotage-3-inline-ack-*` |
| 4 | media chain bypass → SEC-03/EICAR red | 5 | S23-step5 captures |
| 5 | ordering on telegram_date → R-19 controls red | 9 | `S23-step9/sabotage-5-telegram-date-*` |
| 6 | webhook secret removed → webhook tests red | 4 | S23-step4 captures |
| 7 | nonce reusable → SEC-06 red | 5 | S23-step5 captures |
| 8 | unconfirmed routes → SEC-04 red | 6 | S23-step6 captures |
| 9 | seeded fake token in log path → NFR-06 gate red | 9 | `S23-step9/sabotage-9-token-log-*` |
| 10 | sweep expires while health=down → FS-01 red | 8 | S23-step8 captures |
| 11 | broken proxy (dead port) → must fail | 2 | S23-step2 captures |
| 12 | R-19b grant dropped → schema guard red at MIGRATION level | 1 | S23-step1 captures |
| 13 | FS-05 rebuild disabled → red | 8 | S23-step8 captures |
| 14 | redirect template without link → BR-06 red | 9 | `S23-step9/sabotage-14-redirect-no-link-*` |
| 15 | split resumes from part 1 → EC-09 red | 7 | S23-step7 captures |
| 16 | re-register without rotation → SEC-02 red | 4 | S23-step4 captures |
| 17 | planted channel conditional → gate red | 9 | `S23-step9/sabotage-17-channel-conditional-*` |
| 18 | unreachable-writer emits transition → orthogonality red | 6 | S23-step6 captures |
| 19 | SourceUrlResolver deregistered → media fixture red (A-26 amendment) | 5 | S23-step5 captures |
| 20 | owner-phone author-override gate removed → spoof control red (A-27 amendment) | 6 | S23-step6 captures |

All 20 executed with fail+restored evidence; the enumeration was amended
twice in-flight (19→20 at A-26/A-27), each amendment filed before its
sabotage ran. No item was reinterpreted at reconciliation time.
