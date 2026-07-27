-- Session 0: database roles (Doc 01 T-01 / SEC-02).
--
--   app            — the application role. RLS APPLIES to it (no bypass).
--                    Every Laravel connection uses this role.
--   platform_admin — the ONLY role that may bypass RLS. Used exclusively by
--                    the platform console service account and retention jobs.
--                    The application never connects as this role; its
--                    credentials are not present in the app environment.
--
-- The docker-entrypoint POSTGRES_USER is a superuser used only by this init
-- script and healthchecks; the application does not use it.

CREATE ROLE app LOGIN PASSWORD 'app_local_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE platform_admin LOGIN PASSWORD 'platform_admin_local_password' NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS;

CREATE DATABASE na_live_support OWNER app;
GRANT ALL PRIVILEGES ON DATABASE na_live_support TO app;
GRANT CONNECT ON DATABASE na_live_support TO platform_admin;

-- A separate database for the test runner (mirrors prod shape, disposable).
CREATE DATABASE na_live_support_test OWNER app;
GRANT ALL PRIVILEGES ON DATABASE na_live_support_test TO app;
GRANT CONNECT ON DATABASE na_live_support_test TO platform_admin;
