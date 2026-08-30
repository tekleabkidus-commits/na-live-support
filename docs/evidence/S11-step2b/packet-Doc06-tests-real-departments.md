# S11 step 2b — FINDING + PACKET PROPOSAL: `tickets_department_fk` vs Doc 06's opaque department ids (mandatory STOP #3/#6)

## The finding (captures `S11-step2b/doc11-suites-01.txt`, `make-test-01.txt`)
SCHEMA AMENDMENT 5 item 12 / FLAG G (countersigned) adds `tickets_department_fk … NOT VALID`.
Postgres semantics: NOT VALID skips the scan of EXISTING rows only — every NEW insert/update
that names a `department_id` is checked immediately. Doc 06's own suites were written under the
S06 docblock "DEPARTMENTS ARE OPAQUE IDS until Doc 11's structures land" and transfer/create
tickets with `Str::uuid7()` department ids by design → `SQLSTATE[23503] … violates foreign key
constraint "tickets_department_fk"`. The physics is doing exactly what the amendment asked; the
fixtures predate the physics. The red list from the full battery is appended below.

## Option A (recommended) — keep the FK; a Doc 06 TEST-FIXTURE packet (escalate-first)
A shared Pest helper `departmentFixture(?string $name = null): string` (in `tests/Support/`,
loaded by `tests/Pest.php` like the other fixture helpers) that inserts a real `departments` row +
its default `queues` row for the bound tenant (the seeder's own shape, one transaction) and returns
the department id. Every Doc 06/07 test that currently mints an opaque department id calls it
instead: `$dept = departmentFixture('Payments')`. **Assertions byte-untouched**; only the id's
SOURCE changes. The one deliberate exception — `TicketOperationDoorsTest:134`'s "transfer to an
unknown department" — is now a REAL refusal by FK, and its assertion (a refusal) is read against
the FK's own error code (a Doc 06 behaviour that FR-22 always meant: "existence validation is Doc
11's arrival gift", TransferTicket.php:41 — the arrival is here). Files: listed below from the
battery. Countersign before landing; Doc 06's own suites green as the owning-document proof.

## Option B — defer `tickets_department_fk` to step 3 (amendment rider)
Drop only the department FK from migration 000400 now (keep `tickets_queue_fk`: queue_id is null
everywhere today, nothing trips); re-add it in step 3's migration once `OrganizationSeeder` and
the fixture helper exist. Same Doc 06 test edits are needed THEN — Option B only moves the day.
Rejected on that basis unless the advisor wants the batch-A checkpoint green without a Doc 06
packet inside it.

## Red list from the full battery (filled after `make-test-01.txt`)

## The helper (Option A's one shared addition to `tests/Pest.php`, beside `ticketFixture()`)
```php
/**
 * Doc 11 (S11 step 2b): a REAL department + its default queue for the bound tenant, in the
 * OrganizationSeeder's own shape (one transaction, default_queue_id set before commit — FLAG A).
 * Replaces the opaque `Str::uuid7()` department ids Doc 06's suites minted before departments
 * existed (TransferTicket.php:41 "existence validation is Doc 11's arrival gift" — this is it).
 *
 * @return string the department id
 */
function departmentFixture(string $name = 'Fixture Department', bool $isRg = false): string
{
    $departmentId = (string) Str::uuid7();
    $queueId = (string) Str::uuid7();
    DB::transaction(function () use ($departmentId, $queueId, $name, $isRg): void {
        DB::table('departments')->insert([
            'id' => $departmentId, 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
            'name' => json_encode(['en' => $name]), 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'default_queue_id' => $queueId,
        ]);
        DB::table('queues')->insert([
            'id' => $queueId, 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
            'department_id' => $departmentId, 'name' => $name, 'is_rg' => $isRg,
        ]);
    });

    return $departmentId;
}
```
Call-site diffs (assertions untouched): `TransferEscalateTest.php:55,80,98` `(string) Str::uuid7()` →
`departmentFixture()`; `TicketOperationDoorsTest.php:118` the same; `:134`'s unknown-department
transfer keeps its opaque id AND its `assertNotFound()` (the Team-scoped actor is refused at scope
BEFORE any write — unchanged behaviour); any further sites the battery names below.
`make-test-01.txt`: **1548 passed / 26,379 assertions / 13 skipped / 5 failed, exit 2.**
1. `tests/Feature/Tickets/TransferEscalateTest.php` FR-22 (L55) — `tickets_department_fk` violation (opaque id).
2. `tests/Feature/Tickets/TransferEscalateTest.php` FR-22 Dept-scoped assignee (L80) — same.
3. `tests/Feature/Inbox/TicketOperationDoorsTest.php` transfer door (L118) — same.
4. `tests/Feature/Tickets/TicketSchemaTest.php` FR-01/A-7 — the tickets COLUMN-LIST PIN ("schema changes to
   this table go through the record, not a quiet migration"): the three amendment-5 columns
   (`queue_id`, `queued_at`, `routed_at`) moved it. NOT an FK matter — the pin moving WITH the filed
   amendment is exactly what the test exists to force; it joins this packet as a Doc 06 pin move
   citing SCHEMA AMENDMENT 5 item 12.
5. `tests/Security/EnumerationTest.php:274` AC-01 constant-time tolerance — a TIMING leg, unrelated to
   Doc 11's files; dispositioned below.
