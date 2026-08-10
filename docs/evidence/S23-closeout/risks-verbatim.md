# S23 close-out — RISKS section, verbatim (for the advisor)

Extracted verbatim from the close-out report in docs/BUILD-STATUS.md (SESSION-23 step-12 section):

```
RISKS:        (verbatim, per the step-6 owner ruling) "staging bot token was
              exposed in an owner chat and remains unrotated by owner decision;
              the live-account connection that made it critical has been
              severed; rotation is MANDATORY before any Business account is
              reconnected to this bot and before any production traffic —
              whichever comes first." A-24 rider 1 stands: dev has no public
              ingress; real-webhook behaviour is proven by capture-replay, and
              Doc 18 owns the staging-ingress handoff row. The Stage-4
              first-attempt seeder failure turned out to be a REAL FK defect
              in TelegramBrowserSeeder, retry-masked for three CI rounds —
              diagnosed by this step's harness fix and FIXED (see the CI
              record above). The two Doc 01/03
              strict perf budgets stay MARGINAL on dev hardware (recorded at
              their own rows; Doc 19's L1 profile is the honest closure).
```
