-- ADR 0015 (Doc 09 T-05) — pgvector, installed at PROVISION TIME as superuser.
--
-- CORRECTION OF RECORD vs ADR 0015's first text: the pgvector/pgvector:pg16
-- image ships the extension with trusted = false (verified:
-- pg_available_extension_versions), so the migrator CANNOT install it even
-- holding CREATE on the database. Init scripts run as the postgres superuser
-- on a fresh volume (dev `make fresh` and CI both), so the install lives
-- here; the migration 2026_08_20_000100_enable_pgvector keeps
-- CREATE EXTENSION IF NOT EXISTS as documentation + a LOUD failure on any
-- database that was never provisioned with it.
\connect na_live_support
CREATE EXTENSION IF NOT EXISTS vector;
\connect na_live_support_test
CREATE EXTENSION IF NOT EXISTS vector;
