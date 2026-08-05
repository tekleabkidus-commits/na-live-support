<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MessageDeadLetter;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One parked dead letter for the FS-05 browser proof (Doc 04 step 9b, R-17):
 * dead-letters.spec.ts re-drives it through the real page. Runs against the
 * BrowserFixtureSeeder tenant; idempotent — re-drives flip rows to `redriven`
 * (DELETE is revoked), so a fresh parked row seeds only when none is waiting.
 * All content synthetic (CLAUDE.md §8).
 */
final class DeadLetterBrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('DeadLetterBrowserSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        // The same narrow platform lookup BrowserFixtureSeeder uses: finding the
        // fixture tenant requires reading without a bound context (SEC-02).
        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();

        if ($row === null || ! is_string($row->id)) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($row->id, ResolutionSource::Console);
        app(RlsSession::class)->set($row->id);

        // Keyed on the fixture's own error class: a re-driven fixture that
        // re-parks for a DIFFERENT reason (a genuine park) must not satisfy
        // this check — the spec needs the InvalidArgumentException row.
        $waiting = MessageDeadLetter::query()
            ->where('channel_ref_id', 'browser-fixture')
            ->where('error_class', \InvalidArgumentException::class)
            ->where('status', MessageDeadLetter::STATUS_PARKED)
            ->exists();

        if (! $waiting) {
            MessageDeadLetter::query()->create([
                'channel' => 'fakechan',
                'channel_ref_id' => 'browser-fixture',
                'inbound_family' => 'webchat-in',
                'payload' => ['poison' => true, 'note' => 'synthetic browser fixture', 'from' => '@fixture_deadletter'],
                'claimant' => null,
                'error_class' => \InvalidArgumentException::class,
                'error_message' => "fake webhook payload is missing 'update_id'.",
                'attempts' => 3,
                'status' => MessageDeadLetter::STATUS_PARKED,
            ]);
        }

        app(RlsSession::class)->clear();
        app(TenantContext::class)->clear();
    }
}
