<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The asTenant() abort-surfacing pin (S08 step-1 checkpoint ruling 3)
|--------------------------------------------------------------------------
|
| The ruled core edit's regression test (the A-35 condition-2 precedent): a
| statement that aborts the Postgres transaction inside an asTenant() callback
| must surface ITS OWN exception — not the finally's 25P02 from the RLS
| re-bind, which is what masked four real defects in S08 step 1b. Reverting
| the harness to the unguarded restore turns this red.
*/

it('surfaces the REAL error when the callback aborts the transaction — never the finally\'s 25P02', function (): void {
    $tenant = createTenant();

    try {
        asTenant($tenant, function (): void {
            // Abort the transaction with a KNOWN error class: an insert into a
            // nonexistent relation (42P01), deliberately NOT savepoint-wrapped.
            DB::statement('INSERT INTO s08_no_such_table_fixture VALUES (1)');
        });
        $this->fail('the aborting statement did not throw at all');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('42P01');
    }
});

it('still restores the previous binding on the normal path', function (): void {
    $outer = createTenant();
    $inner = createTenant();
    bindTenant($outer);

    asTenant($inner, function (): void {
        DB::select('SELECT 1');
    });

    /** @var list<object{value: ?string}> $rows */
    $rows = DB::select("SELECT current_setting('app.tenant_id', true) AS value");
    expect($rows[0]->value)->toBe((string) $outer->getKey());
});
