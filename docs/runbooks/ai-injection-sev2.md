# Runbook — AI prompt-injection class found in the wild (Doc 09 SEC-03; Sev-2)

A working injection class discovered after ship is a **Sev-2**: customer-visible
risk, no data breach by construction (the closed action enum bounds the blast
radius — no tools, no writes beyond the five actions).

1. **KILL SWITCH FIRST** (FR-18, `ai.killswitch`, high-visibility audited): flip the
   chatbot OFF for the affected scope (surface × channel × endpoint). ≤10 s, no
   deploy. Copilot/Supervisor stay up unless the class touches them.
2. **Capture** the offending conversation ids + decision ids (`ai_decisions` holds
   payloads ≤30 days — SEC-06's reduction clock is running; export what forensics
   needs NOW via the platform tier).
3. **Patch the sanitizer** at the layer that owns the class: index-time stripper
   (KB-borne), delimiting/assembly (message-borne), ActionSchema (output-shaped),
   OutputGuard (leak-shaped). The patch ships with a NEW red-team fixture
   reproducing the class — the fixture is the regression proof, forever.
4. **Replay the red-team suite** (`tests/Security/AiInjectionRedTeamTest.php` +
   `tests/Golden`) against the replay mock, then against live staging
   (AI_GOLDEN_LIVE=1). Green both, then re-enable the switch.
5. **File**: the incident (BUILD-STATUS honest trail), the fixture, and — if the
   class rode a provider behavior change — the vendor register note (ADR 0019 /
   Doc 18).
