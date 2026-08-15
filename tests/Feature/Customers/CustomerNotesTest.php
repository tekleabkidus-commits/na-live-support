<?php

declare(strict_types=1);

use App\Domain\Customers\Notes\CustomerNotes;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-17 / BR-07 / N-04 (S08 step 9) — notes: one pinned, never
| deliverable, forever on the profile. Sabotage #15 routes a note body
| toward a deliverable path and the architecture sweep goes red.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

it('FR-17: adds notes through the door with author + audit + event; ONE pinned per customer — pinning the new one unpins the incumbent', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $author = ticketActor(['customers.view', 'customers.notes.add', 'customers.notes.view'], 'S08 Notes Author');

    $first = test()->actingAs($author, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/notes", ['body' => 'first note', 'pinned' => true]);
    $first->assertCreated();

    $second = test()->actingAs($author, 'staff')
        ->postJson("https://localhost/api/staff/customers/{$customerId}/notes", ['body' => 'second note, takes the pin', 'pinned' => true]);
    $second->assertCreated();

    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    expect(DB::table('customer_notes')->where('customer_id', $customerId)->count())->toBe(2)
        ->and(DB::table('customer_notes')->where('customer_id', $customerId)->where('pinned', true)->count())->toBe(1)
        ->and((string) DB::table('customer_notes')->where('customer_id', $customerId)->where('pinned', true)->value('id'))->toBe((string) $second->json('id'))
        ->and(DB::table('outbox')->where('name', 'customer.note.added')->count())->toBe(2)
        ->and(DB::table('audit_log')->where('action_key', 'customer.note.added')->count())->toBe(2);

    // The notes section renders through its own permissioned door.
    $list = test()->actingAs($author, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel/notes");
    $list->assertOk();
    expect($list->json('notes.0.pinned'))->toBeTrue()
        ->and($list->json('notes.0.body'))->toBe('second note, takes the pin');
});

it('N-04 STRUCTURALLY (sabotage #15\'s subject): NOTHING in the outbound/adapter world references customer_notes — a note has no path to a customer, by sweep', function (): void {
    // The undeliverability proof is an ABSENCE over the deliverable side of
    // the codebase: the Doc 04 outbound pipeline, the channel adapters, and
    // the lane/delivery jobs must not know the customer_notes table or the
    // CustomerNotes class exist. Sabotage #15 adds such a reference and this
    // sweep names the file.
    $deliverableRoots = [
        app_path('Domain/Conversations/Outbound'),
        app_path('Domain/Conversations/Adapters'),
        app_path('Domain/Telegram'),
        app_path('Jobs/Conversations'),
        app_path('Jobs/Telegram'),
    ];

    $offenders = [];
    foreach ($deliverableRoots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'customer_notes') || str_contains($source, 'CustomerNotes')) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([], 'a deliverable-side file references customer notes — N-04\'s structural undeliverability is breached: '.implode(', ', $offenders));

    // And the inverse: the notes namespace itself imports nothing deliverable.
    $notesSource = (string) file_get_contents(app_path('Domain/Customers/Notes/CustomerNotes.php'));
    expect(str_contains($notesSource, 'DeliverablePayload') || str_contains($notesSource, 'ComposeOutbound') || str_contains($notesSource, 'Adapter'))
        ->toBeFalse('the notes service references the deliverable world — the wall runs both ways');
});

it('BR-07: the notes-stay-on-profile onboarding copy ships as i18n STRINGS only (en + am), no onboarding page or route', function (): void {
    expect(trans('customers.notes.onboarding_title', [], 'en'))->not->toBe('customers.notes.onboarding_title')
        ->and(trans('customers.notes.onboarding_body', [], 'en'))->not->toBe('customers.notes.onboarding_body')
        ->and(trans('customers.notes.onboarding_title', [], 'am'))->not->toBe('customers.notes.onboarding_title')
        ->and(trans('customers.notes.onboarding_body', [], 'am'))->not->toBe('customers.notes.onboarding_body');

    // No onboarding surface exists — the copy is a string, not a page.
    $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
    expect($routes->filter(fn (string $uri): bool => str_contains($uri, 'onboarding'))->values()->all())->toBe([]);
});

it('FR-17: an empty body is refused at the service boundary', function (): void {
    createTenant();
    $customerId = enforcedCustomer(app(App\Support\Tenancy\TenantContext::class)->tenantId(), '2026-06-01 10:00:00+00');

    expect(fn () => app(CustomerNotes::class)->add($customerId, '   ', (string) Illuminate\Support\Str::uuid7()))
        ->toThrow(InvalidArgumentException::class);
});
