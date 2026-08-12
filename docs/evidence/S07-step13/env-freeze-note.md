# S07 step 13 — the local browser-environment freeze (recorded intermittent)

**Symptom.** During step-13 client verification (2026-08-12, ~11:45 onward), the
local headless Chromium's renderer main thread intermittently freezes on
`/inbox` roughly 1–3 s after load: `page.evaluate` hangs, clicks hang after
"element is stable", `Debugger.pause` never interrupts (a NATIVE block, not a
JS loop), while the CDP accessibility snapshot still renders. `/login` never
freezes. Waves come and go: some runs are fully green minutes after a frozen
one.

**What was ruled out, each by executed probe (captures in this directory's
`shortcuts-run-*.txt` and the session log):**

- The step-13 client code — a **pure-HEAD bundle reproduces the freeze**
  (bisect probes A–E: coach disabled, offline machine disabled, shortcut
  dispatcher disabled, listbox components reverted to HEAD, finally the whole
  `resources/js` tree at HEAD).
- Chunk accumulation in the container's `public/build` anonymous volume
  (263 stale chunks found and wholesale-replaced — freeze persisted; the
  accumulation itself is now cleaned by `rm -rf` before each `compose cp`).
- Server/Octane state (full app-tier restart — freeze persisted).
- Data accumulation (23 tickets at probe time), `/dev/shm`, disk, memory
  pressure (avg10 = 0.00), kernel messages (clean), stray browser processes
  (none), CPU (all renderer threads sleeping at ~0% while frozen).
- Old vs NEW headless engine: both probed alive during a calm wave.

**What correlates.** The same machine ran the FULL 55-test browser suite green
at 09:5x (fresh WSL boot after yesterday's WSL-layer crash — the same layer).
The freezes began mid-session and recur in waves. Everything points at the
WSL2/graphics/IPC layer degrading with session age — the identical failure
layer that crashed yesterday's session.

**Impact on step-13 proofs.** All step-13 specs pass between waves EXCEPT the
coach-marks test (`inbox-shortcuts.spec.ts` FIRST-RUN), which idles with a
modal open long enough that every attempt so far has been hit
(`shortcuts-run-02…08`). Its step-walk WIRING is captured working in the
probe log (click → step 'list', dispatch → step 'modes'). The independent
verdict comes from CI's clean runners on this push.

**Disposition.** Filed as an environmental intermittent. Unblocker: the owner
reboots WSL (`wsl --shutdown` from Windows — privileged, owner-only per the
recorded sudo constraint), then the coach test re-runs. If CI greens the suite
while local waves continue, the environmental attribution is confirmed; if CI
reds the coach test specifically, it is a real defect and comes back to the
bench next session.
