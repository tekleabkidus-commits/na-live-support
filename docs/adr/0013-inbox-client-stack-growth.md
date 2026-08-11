# ADR 0013 — Inbox client stack growth: pinia, @tanstack/vue-virtual, @lhci/cli

Status: accepted (owner-approved at S07 plan approval, R-15; recorded per CLAUDE.md §0.5)
Date: 2026-08-10 (filed with the step-8 commit that first installs them)

## Context

Doc 07 is the agent workspace: a normalized client store with command-object
mutations (T-06), 60 fps scroll over 10k-row virtualized lists (NFR-03), and a
CI Lighthouse budget on the inbox route (T-08 / NFR-04). The plan's R-15
stack-growth request named exactly three additions for these three
requirements; the owner approved them at plan approval ("stack growth
approved"). Nothing else was requested or approved.

## Decision

- **pinia** (runtime): the T-06 normalized store (tickets, conversations,
  messages, views) and the command-object layer that pairs optimistic-apply
  with rollback handlers (NFR-06's enforcement point). The Vue-native store
  library; no home-grown reactive singleton graph.
- **@tanstack/vue-virtual** (runtime): NFR-03 virtualization for the list and
  thread. Headless — rendering stays ours, tokens stay verbatim.
- **@lhci/cli** (dev/CI only): T-08's Lighthouse budget gate on the inbox
  route. Never shipped to the client bundle.

## Declined at the same ruling (and stated in the plan)

In-house fuzzy scorer for ⌘K (FR-24's scoring is small and testable in-house);
native BroadcastChannel (FR-04 tab-leader) and IndexedDB (FR-29 offline)
instead of wrapper libraries; existing Playwright + scout/meilisearch-php
cover browser driving and search.

## Consequences

Three `package.json` entries; NFR-04's 350 KB gz budget polices what they may
cost (pinia ~2 KB gz, vue-virtual ~3 KB gz; @lhci/cli is devDependency-only).
The bundle budget gate is the tripwire if either runtime addition grows.
