# ADR 0015 — pgvector for retrieval (Doc 09 T-05/FR-10)

**Status:** approved with the S09 plan (owner, 2026-08-19). Written before the code it
covers (R-15 discipline).

## Decision

Retrieval (RAG over KB chunks, similar-ticket search) uses the **pgvector** PostgreSQL
extension with **HNSW** indexes, per Doc 09 T-05's own words ("pgvector HNSW over
Doc 10 chunk embeddings"). Hybrid scoring combines vector distance with keyword rank
(a generated `tsvector` column + GIN) and a language filter — all inside the one
Postgres the platform already operates.

## Consequences and physics

- **Extension install is migrator-owned DDL** (ADR 0003 grant physics): the migration
  runs `CREATE EXTENSION IF NOT EXISTS vector` on the migrator connection; the app
  role gains nothing new.
- **The dev/CI postgres image must carry the extension.** Stock `postgres:16` does
  not; the compose stack moves to `pgvector/pgvector:pg16` — a drop-in build of the
  same Postgres 16 with the extension available. The image swap rides the same commit
  as the first vector migration (infra exception, R-15 shape). No data migration:
  the volume format is unchanged.
- Embedding dimensionality is pinned by ADR 0018's model (bge-m3 → **1024**); the
  column type is `vector(1024)`. A model change is an expand-contract re-embed, never
  an in-place cast.
- Formal scale (50k chunks, ≤120 ms p95 — Doc 09 §12) is measured at Doc 19 on staging
  hardware; dev-scale figures ship flagged as indicative.

## Alternatives rejected

- **Meilisearch vectors**: already in the stack, but T-05 names pgvector, and keeping
  retrieval in Postgres keeps RLS as the tenancy layer for chunk reads (Meilisearch
  tenancy is index-name convention, proven for search but weaker than RLS for
  AI-usable scope filtering).
- **External vector DB** (new infrastructure, new egress surface) — nothing in the
  document asks for it.

## Correction of record (S09 step 2b, executed against the real image)

The first text above claimed the migrator's CREATE right suffices because
pgvector is trusted. **The pgvector/pgvector:pg16 image ships `vector` with
`trusted = false`** (verified via `pg_available_extension_versions` after the
swap), so only a superuser can install it. The install therefore lives in
`docker/postgres/init/02-extensions.sql` (superuser at provision time — fresh
volumes and CI both); the migration keeps `CREATE EXTENSION IF NOT EXISTS
vector` as documentation and as a loud failure on any database never
provisioned with it. The migrator's `GRANT CREATE ON DATABASE` (added in the
same commit) stands — harmless and correct for trusted extensions. One more
operational fact recorded: swapping images across base distributions surfaced
a collation-version mismatch on the warm dev volume (glibc 2.41 → 2.36);
resolved by `REFRESH COLLATION VERSION` + full `REINDEX` of both databases —
fresh volumes (CI, `make fresh`) never see it.
