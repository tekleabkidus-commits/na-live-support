# ADR 0003 — Database role separation: the app role must not be able to switch RLS off

- **Status:** Accepted (owner ruling D-3, Session 1)
- **Date:** 2026-07-30
- **Spec:** Doc 01 T-01, NFR-01, SEC-01, SEC-02
- **Supersedes:** the two-role design in Session 0's `docker/postgres/init/01-roles.sql`

## Context

Doc 01 specifies two independent isolation layers, and NFR-01 states the contract
precisely: *"a defect that omits an application-level tenant filter SHALL still be
contained by PostgreSQL row-level security"*. The second layer's whole purpose is to
survive the first layer's bugs.

Session 0 created two login roles: `app` (NOBYPASSRLS) and `platform_admin`
(BYPASSRLS), and made `app` the owner of the database. Migrations therefore ran as
`app`, and `app` owned every table.

Two PostgreSQL facts make that fatal:

1. **A table's owner is exempt from that table's RLS policies** unless
   `FORCE ROW LEVEL SECURITY` is set.
2. **A table's owner can disable RLS entirely**, with one statement.

So Layer 2 would have been *present in the schema and inert in practice*. This was
verified rather than assumed, by reproducing Session 0's design in the test database
with RLS enabled and a correct policy:

| Action, connected as `app` (the table's owner) | Result |
|---|---|
| `SELECT * FROM s0_style` with `app.tenant_id` = tenant A | **2 of 2 rows** — both tenants |
| `ALTER TABLE s0_style DISABLE ROW LEVEL SECURITY` | **succeeded, no error** |

## Decision

Four roles, with the schema owned by a role the application never connects as.

| Role | Login | BYPASSRLS | Job |
|---|---|---|---|
| `migrator` | yes | no | Owns the schema. DDL only — `php artisan migrate`, partition maintenance. Never serves a request. |
| `app` | yes | no | Every HTTP request, queued job and socket connection. **DML only**: no CREATE, no ownership, therefore no `ALTER TABLE`. |
| `platform_admin` | yes | **yes** | The only *login* role that may bypass RLS. Platform console cross-tenant reads and retention jobs, gated by `PlatformConnection` (SEC-02). |
| `key_resolver` | **no** | **yes** | Owns exactly one `SECURITY DEFINER` function. See ADR note below. |

Plus `FORCE ROW LEVEL SECURITY` on every tenant table, so even the schema owner is
policed against an accidental cross-tenant read in a future data migration.

`app` additionally loses privileges that encode business invariants rather than
tenancy:

- `DELETE` revoked on every business table (BR-02 — nothing is hard-deleted at tenant
  scope). `tenant_settings` keeps it, because "reset to default" genuinely is deleting
  the override row.
- `UPDATE` **and** `DELETE` revoked on `audit_log` (invariant N-13 — append-only by
  grant, not by convention).
- No privilege at all on `tenant_keys.hash` (SEC-03 — column-level `SELECT` excludes
  it, so no code path can read a stored key hash).

## Consequences, verified

Connected as `app`:

| Attempt | Result |
|---|---|
| Bound to A, select B's rows by explicit id | `0 rows` |
| Bound to A, insert stamped as B | `ERROR: new row violates row-level security policy` |
| Bound to A, update B's row | `UPDATE 0` |
| No context bound | `ERROR: unrecognized configuration parameter "app.tenant_id"` |
| `ALTER TABLE … DISABLE ROW LEVEL SECURITY` | `ERROR: must be owner of table` |
| `ALTER TABLE … NO FORCE ROW LEVEL SECURITY` | `ERROR: must be owner of table` |
| `DROP POLICY tenant_isolation ON …` | `ERROR: must be owner of relation` |
| `CREATE POLICY sneaky ON … USING (true)` | `ERROR: must be owner of table` |
| `ALTER TABLE … OWNER TO app` | `ERROR: must be owner of table` |
| `SELECT hash FROM tenant_keys` | `ERROR: permission denied for table tenant_keys` |
| `DELETE FROM audit_log` | `ERROR: permission denied for table audit_log` |
| `UPDATE audit_log SET …` | `ERROR: permission denied for table audit_log` |

Costs accepted:

- **`make fresh` is required** to pick up the role change, because the init script only
  runs on an empty data volume. Local data is destroyed; the owner accepted this.
- **Migrations run on a second connection** (`pgsql_migrator`), so `make fresh` and the
  test harness pass `--database=pgsql_migrator`. Laravel's `RefreshDatabase` cannot be
  used, because it migrates on the default connection — which is `app`, which by design
  cannot create a table. `tests/TestCase.php` migrates once per process as `migrator`
  and wraps each test in a rolled-back transaction on `app`.
- **A fourth role exists** (below), and the stack smoke test's "platform_admin is the
  only bypasser" assertion had to be widened, with justification, rather than deleted.

## The `key_resolver` addendum (same session, owner ruling)

`FORCE ROW LEVEL SECURITY` broke tenant resolution, and the conflict is structural, not
incidental. Five of the six FR-03 resolution paths present a credential that must be
looked up **before any tenant is known** — that is what resolution *is*. The lookup
therefore cannot be filtered by `app.tenant_id`, so it runs through a `SECURITY DEFINER`
function. A `SECURITY DEFINER` function executes as its *owner*; the owner was
`migrator`; and `FORCE` applies policies to owners. The function's own query was
filtered by the value it existed to discover:

```
SELECT * FROM resolve_tenant_key('api','diagprefix',<hash>);
ERROR:  invalid input syntax for type uuid: ""
CONTEXT:  SQL function "resolve_tenant_key" during startup
```

Three ways out were considered:

1. **Drop `FORCE` on `tenants` and `tenant_keys`.** Simple, and `app` stays fully
   policed since it is not the owner. But it relaxes something explicitly required, and
   a future data migration could read across tenants unnoticed.
2. **Give `migrator` BYPASSRLS.** Rejected: hands a login role, whose password is in the
   environment, the ability to read every tenant's data.
3. **A NOLOGIN role owning just that function.** Chosen.

`key_resolver` has no password, no `LOGIN`, and appears in no environment variable — it
cannot be connected as by anyone. Its `BYPASSRLS` is reachable only by invoking one
function whose body is a single `SELECT` matching an exact key hash, and `app` holds
nothing on `tenant_keys` beyond `EXECUTE` on it. **The bypass is scoped to a function
body rather than granted to a principal**, and `FORCE` stays on every tenant table.

It needs `CREATE` on the schema purely to be allowed to *own* an object there
(PostgreSQL requires a prospective owner to hold `CREATE` on the containing schema); it
is not there so the role can build anything.

`tests/Integration/StackSmokeTest.php` asserts all three properties that make this
acceptable: the bypasser set is exactly `[key_resolver, platform_admin]`,
`key_resolver` cannot log in, and it does own `resolve_tenant_key`.

## Open follow-up (Doc 18)

On this single-machine dev stack all three passwords live in one `.env`, because
`make fresh` and `make test` have to work. That does not weaken what the isolation
suite proves — `app`, the role real traffic connects as, provably cannot disable RLS or
read across tenants — but **issuing `migrator` and `platform_admin` credentials to
separate deployment identities, so that no single process holds all three, is Doc 18's
environment and secrets work and is not done.** Recorded in `docs/BUILD-STATUS.md`.
