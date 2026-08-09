# ADR 0012 — Telegram Bot API via a thin in-house client; Nutgram declined

**Status:** accepted (advisor ruling A-21, owner-approved with the S23 plan, 2026-08-09)
**Context docs:** Doc 05 T-02/SEC-07, ADR 0008 (the clamd precedent), CLAUDE.md §3

## Context

Doc 05's stack context names Nutgram (PHP) for the Telegram Bot API. By the
standing reading (S23 plan, owner-ratified), spec stack contexts are minimums —
what matters is T-02's actual requirement: the client binds a bot's token at
call time from the bot registry (no per-bot deploys), the webhook route
resolves the bot BEFORE secret verification, and every byte of Telegram
traffic rides the egress proxy (SEC-07, ADR 0008).

By the time Doc 05 arrives, the platform already owns everything a bot
framework provides: the webhook edge and its authentication are Doc 02 S15's;
routing, normalization, dedupe, persistence, and delivery state are Doc 04's;
update handling is the adapter's. What remains for a library is an HTTP client
and DTOs.

## Decision

A hand-rolled `TelegramBotApi` (~200 lines): Guzzle POST to
`{api_base}/bot{token}/{method}`, the egress proxy set from config on EVERY
request (no per-call opt-out parameter exists), typed errors
(`TelegramApiError` for ok:false answers, `TelegramTransportError` for
network/proxy failures), the token accepted per call and never stored on the
instance, never echoed into exception messages or logs (T-07; the NFR-06
scrubber is the backstop, not the mechanism).

The ADR 0008 clamd reasoning applies verbatim: for a protocol this small, an
auditable in-house client is a smaller supply-chain surface than a framework
dependency, and proxy pinning stays explicit — a Nutgram integration would
put the SEC-07 control inside a third-party client's configuration surface.

## Consequences

- The Bot API surface grows method by method as Doc 05's steps need it —
  never speculatively.
- CI and dev tests speak to `mock-telegram` through the same proxy and the
  same client; only step 0-style live proofs touch api.telegram.org.
- The broken-proxy test (S23 sabotage #11) is a permanent suite member: the
  client pointed at a dead proxy port MUST fail — a green there would mean a
  direct-egress fallback exists.
- If Telegram's API surface ever outgrows the thin client (Mini Apps, §19
  growth), adopting a library is a new ADR, not an amendment to this one.
