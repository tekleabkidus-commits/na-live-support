# CONFLICTS.md — source-of-truth reconciliation (2026-07-27)

## Archives received

| Archive | Contents | Disposition |
|---|---|---|
| `Complete-Document-Series-01-19.zip` | The 19 original `.docx` specification documents | Preserved untouched in `docs/_source/` |
| `MAIN DOCS.zip` | `CLAUDE.md`, `HOW-TO-USE.md`, `PROMPT-TEMPLATE.md`, two session prompts, and a nested `NaLiveSupport-CodeKit.zip` | The nested kit is the canonical source; the loose files beside it are byte-duplicates of files inside the kit |
| `naSUPPORT NEWSESSION.zip` | `PROJECT-SUMMARY.md`, `CONTINUATION-PROMPT.md` | Copied to `docs/` as project history/context |

## Finding: no conflicting source of truth

`MAIN DOCS.zip` is a **re-zip of the build kit**, not a separate specification. Its loose files
(`CLAUDE.md`, `HOW-TO-USE.md`, the session prompts, `PROMPT-TEMPLATE.md`) are the same files that
appear inside the nested `NaLiveSupport-CodeKit.zip`. There is therefore exactly one specification
series (the 19 documents) and one project constitution (`CLAUDE.md`). The nested kit
(`NaLiveSupport-CodeKit`) was taken as canonical for every file that exists in more than one place.

## Conversion verification

Every `FR-`, `NFR-`, `T-`, `SEC-`, `EC-`, `FS-`, `AC-`, `BR-`, `US-`, `R-` identifier in each
`.docx` was compared against its `.md` conversion. **All 19 documents match at the identifier-set
level** (the same IDs, not merely the same counts) — 1,702 identifiers verified in total, zero
dropped. Ethiopic (Amharic) text appears in exactly one source document (Doc 14, the stop-word
`ተወው` in FR-15) and survives intact in the `.md` conversion.

## Known gap — CLOSED by owner ruling, 2026-08-01

The **~34-page master design document** referenced in `PROJECT-SUMMARY.md` §4 was never present in
any of the three archives. It was carried here as an open question: if it contained binding
decisions not captured in Docs 01–19, it would have to be supplied.

**The owner has ruled that it does not, and never will.** The 19-document series **fully and
permanently supersedes** the master design document. Docs 01–19 + `CLAUDE.md` are the law, with no
outstanding external source. The gap is closed, not deferred: nobody should re-open this by
producing that document later, and no future session needs to caveat its absence.

Where a specification question genuinely has no answer in the 19 documents, the resolution path is
an **owner ruling recorded in `docs/ANSWERS-FROM-DOC-AUTHOR.md`** (plus an ADR when it changes
architecture) — not a search for a missing master document.

## Status notes

- `docs/CONTINUATION-PROMPT.md` is a chat-assistant prompt preserved as **history only** (labeled
  as such at its top). Its instructions — including producing `.docx` documents — are obsolete.
- `docs/BUILD-STATUS.md` (from the kit) reflects the pre-build state: nothing is built yet.
