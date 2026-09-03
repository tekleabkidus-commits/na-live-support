# SESSION-F1 close — the full correctness battery (advisor ruling, owner-relayed at 5e8a953)

- `make test` = the whole Pest suite EXCEPT the `soak` group (the Makefile's own law: Doc 04 AC-03's 10k-message ordering soak runs alone via `make soak`, ~6–8 min, commits to the DB, flaky in isolation — not claimed as proven).
- Run NOW on battery power per the ruling; only timing legs wait for wall power.
- **Timing not taken:** `make soak` (the AC-03 ordering soak) and the Performance-group measurements that the Makefile keeps out of `make test` were NOT run in this session — they wait for wall power. Stated plainly; nothing is claimed for them.
- The capture: `docs/evidence/F1-CLOSE/battery-01.txt` (capture-first, `make capture LABEL=battery SET=F1-CLOSE CMD='make test'`); its verdict is the last lines of that file.

## Verdict

- `battery-01.txt`: **1 failed · 13 skipped · 1834 passed (46,980 assertions), 1069 s** — the one red was mine: `AiI18nParityTest` demands every machine-authored am `ai.*` key be listed in the R-14 queue, and the packet's new `ai.ui.banner_details` was not yet queued.
- Fix-first: the key was appended to `fixtures/i18n/r14-queue-doc09.json` (count 613 → 614); the fix's run `battery-fix-01.txt`: **2 passed (812 assertions) · EXIT 0** — the verdict of record for that file. No other red in the battery.
- The 13 skips are the suite's own (environment-gated tests), unchanged by this session.
