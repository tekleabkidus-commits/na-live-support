# Annotation: `screens-render-01.txt` / `screens-render-02.txt` (advisor ruling 6, S23 step 11)

These two captures are **iteration, not sabotage**, and their RED endings are
part of the honest capture-first trail — not an unresolved failure:

- They record runs of `tests/Feature/Telegram/ScreensRenderTest.php`, a
  step-10 **transient scaffold** written before the Vue pages existed. Both
  captures end red on `Inertia page component file … does not exist` because,
  at capture time, the pages had not been created yet — and, once created,
  the full-middleware allow-path 403 (tenant resolution outside a real
  session) made the browser suite the correct home for the render proof.
- The scaffold file was **deleted before commit and never entered git**
  (`git log --all --follow` is empty for it). Its one passing assertion — the
  SEC-03 permission DENY — was re-homed properly at step 11 in
  `tests/Security/TelegramSecurityTest.php`.
- The committed proof for the step-10 screens is
  `tests/Browser/telegram-screens.spec.ts` (browser-05.txt: 13 passed).

The sabotage ledger reconciles against the plan's 20 enumerated items only;
these captures are not ledger entries and claim nothing.
