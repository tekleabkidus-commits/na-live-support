-- Database roles (Doc 01 T-01 / SEC-01 / SEC-02; ADR 0003).
--
-- FOUR roles, four jobs, and the separation is the point:
--
--   migrator       — owns the schema. Runs DDL and nothing else. NOBYPASSRLS.
--   app            — the role every HTTP request, queued job and socket
--                    connection uses. DML only: no CREATE, no ownership,
--                    therefore NO ability to ALTER TABLE ... DISABLE ROW
--                    LEVEL SECURITY. RLS APPLIES to it (no bypass).
--   platform_admin — the only LOGIN role that may bypass RLS. Used exclusively by
--                    the platform console service account and retention jobs.
--   key_resolver   — NOLOGIN. Owns one SECURITY DEFINER function and nothing
--                    else; see the long note below it.
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

-- A FOURTH ROLE THAT NOBODY CAN LOG IN AS (owner ruling, S1).
--
-- key_resolver exists to own exactly one function: resolve_tenant_key(), the
-- SECURITY DEFINER lookup that turns a presented credential into a tenant id.
--
-- WHY IT HAS TO EXIST. Resolution happens BEFORE a tenant is known — that is what
-- resolution is — so the lookup cannot be filtered by `app.tenant_id`. A
-- SECURITY DEFINER function executes as its OWNER, and the owner was `migrator`;
-- but FORCE ROW LEVEL SECURITY applies policies to owners too, so the function's
-- own query was filtered by the value it existed to discover, and failed with
-- `invalid input syntax for type uuid: ""`. Five of the six FR-03 resolution paths
-- could not resolve at all.
--
-- WHY NOLOGIN IS THE WHOLE POINT. The alternative fixes were to drop FORCE (which
-- relaxes isolation on `tenants` and `tenant_keys` for any future data migration)
-- or to give `migrator` BYPASSRLS (which hands a login role with credentials in the
-- environment the ability to read every tenant's data). This role has no password,
-- no LOGIN, and appears in no environment variable — it cannot be connected as by
-- anyone, ever. Its BYPASSRLS is reachable only by invoking one function whose body
-- reads a single row matched on an exact key hash, and the app role holds nothing on
-- `tenant_keys` except EXECUTE on that function.
--
-- So the bypass is scoped to a function BODY rather than to a principal. Note that
-- tests/Integration/StackSmokeTest.php asserts which roles may bypass RLS, and it
-- names this one explicitly with this reasoning — a new BYPASSRLS role appearing
-- without that justification is a Sev-1.
CREATE ROLE key_resolver NOLOGIN BYPASSRLS;

-- So that migrations (run as migrator) can assign the function's ownership. A role
-- may only transfer ownership to a role it is a member of.
GRANT key_resolver TO migrator;

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
GRANT USAGE ON SCHEMA public TO app, platform_admin, key_resolver;

-- key_resolver needs CREATE on the schema purely to be able to OWN an object in
-- it: PostgreSQL requires a prospective owner to hold CREATE on the containing
-- schema. It is not there so this role can build things — it cannot log in, so the
-- only code that could ever run as it is the one SECURITY DEFINER function it owns,
-- and that function's body is a single SELECT.
GRANT CREATE ON SCHEMA public TO key_resolver;
GRANT CONNECT ON DATABASE na_live_support TO app, platform_admin;
-- ADR 0015 (Doc 09): pgvector is a TRUSTED extension; CREATE on the database is
-- what lets the MIGRATOR install it inside the migration path (no superuser step).
GRANT CREATE ON DATABASE na_live_support TO migrator;

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
GRANT USAGE ON SCHEMA public TO app, platform_admin, key_resolver;

-- key_resolver needs CREATE on the schema purely to be able to OWN an object in
-- it: PostgreSQL requires a prospective owner to hold CREATE on the containing
-- schema. It is not there so this role can build things — it cannot log in, so the
-- only code that could ever run as it is the one SECURITY DEFINER function it owns,
-- and that function's body is a single SELECT.
GRANT CREATE ON SCHEMA public TO key_resolver;
GRANT CONNECT ON DATABASE na_live_support_test TO app, platform_admin;
GRANT CREATE ON DATABASE na_live_support_test TO migrator; -- ADR 0015, same as above

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
