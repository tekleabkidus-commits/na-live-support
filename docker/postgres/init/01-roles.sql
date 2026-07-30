-- Database roles (Doc 01 T-01 / SEC-01 / SEC-02; ADR 0003).
--
-- THREE roles, three jobs, and the separation is the point:
--
--   migrator       — owns the schema. Runs DDL and nothing else. NOBYPASSRLS.
--   app            — the role every HTTP request, queued job and socket
--                    connection uses. DML only: no CREATE, no ownership,
--                    therefore NO ability to ALTER TABLE ... DISABLE ROW
--                    LEVEL SECURITY. RLS APPLIES to it (no bypass).
--   platform_admin — the ONLY role that may bypass RLS. Used exclusively by
--                    the platform console service account and retention jobs.
--
-- WHY THE migrator/app SPLIT EXISTS (ADR 0003, owner ruling D-3):
-- In PostgreSQL the owner of a table is exempt from that table's RLS policies
-- unless FORCE ROW LEVEL SECURITY is set, and — the part that actually
-- matters — an owner can switch RLS off at will. Session 0 made `app` the
-- owner of the database, so migrations ran as `app` and `app` owned every
-- table: Layer 2 would have looked present and been inert, and even with
-- FORCE set, `app` could have disabled it with one statement. An isolation
-- layer the application role can switch off is not a layer. So the schema is
-- owned by a role the application never connects as, and `app` holds table
-- privileges only. tests/Isolation/RlsPhysicsTest.php asserts this by trying.
--
-- The docker-entrypoint POSTGRES_USER is a superuser used only by this init
-- script and healthchecks; the application does not use it.
--
-- LOCAL-CREDENTIAL CAVEAT (recorded in docs/BUILD-STATUS.md, Doc 18 follow-up):
-- on this single-machine dev stack all three passwords live in one .env,
-- because `make fresh` and `make test` have to work. That does not weaken what
-- the isolation suite proves — `app`, the role real traffic connects as,
-- provably cannot disable RLS or read across tenants — but issuing migrator
-- and platform_admin credentials to separate deployment identities, so no one
-- process holds all three, is Doc 18's environment/secrets work, not done here.

CREATE ROLE migrator LOGIN PASSWORD 'migrator_local_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE app LOGIN PASSWORD 'app_local_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE platform_admin LOGIN PASSWORD 'platform_admin_local_password' NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;

-- The schema owner owns the databases. `app` is deliberately NOT the owner.
CREATE DATABASE na_live_support OWNER migrator;

-- A separate database for the test runner (mirrors prod shape, disposable).
CREATE DATABASE na_live_support_test OWNER migrator;

-- Per-database grants. Repeated verbatim for the test database: parity is not
-- negotiable (SEC-01), and an isolation suite running against a
-- differently-privileged database would prove nothing about production.

\connect na_live_support

ALTER SCHEMA public OWNER TO migrator;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO app, platform_admin;
GRANT CONNECT ON DATABASE na_live_support TO app, platform_admin;

-- Tables migrator creates from here on: app gets DML, never ownership.
-- DELETE is granted here and then REVOKEd per-table on tenant business tables
-- by the RLS migration (BR-02: nothing is hard-deleted at tenant scope; N-13:
-- the audit log loses UPDATE too). Framework-infrastructure tables (sessions,
-- feature flags) keep DELETE — expiring a session is not a business deletion.
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT ALL ON TABLES TO platform_admin;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT ALL ON SEQUENCES TO platform_admin;

-- No implicit EXECUTE to PUBLIC: the tenant-key resolver is a SECURITY DEFINER
-- function (Doc 01 FR-03) and every grant on it must be deliberate.
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

\connect na_live_support_test

ALTER SCHEMA public OWNER TO migrator;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO app, platform_admin;
GRANT CONNECT ON DATABASE na_live_support_test TO app, platform_admin;

ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT ALL ON TABLES TO platform_admin;
ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    GRANT ALL ON SEQUENCES TO platform_admin;

ALTER DEFAULT PRIVILEGES FOR ROLE migrator IN SCHEMA public
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
