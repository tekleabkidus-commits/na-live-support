<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 FR-19 (S08 step 9) — the files gallery over Doc 04's message media,
| served through MediaStore::signedUrl (closing row 4a: the serve half).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** A message carrying media items (the Doc 04 jsonb shape, storage keys included). */
function galleryMessage(string $tenantId, string $conversationId, array $items): string
{
    // kind rides the Doc 04 constraint set; created_at keeps its clock
    // default (the app grant excludes it — R-19b physics).
    $id = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $id, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'kind' => 'photo', 'body' => null,
        'is_internal' => false,
        'media' => json_encode(array_map(static fn (array $item): array => $item + [
            'source_url' => 'https://fixture.invalid/x', 'mime' => 'image/jpeg', 'bytes' => 1024,
            'width' => null, 'height' => null, 'duration_seconds' => null,
            'thumb_key' => null, 'transcript_ref' => null, 'ocr_ref' => null, 'alt_text' => null,
        ], $items), JSON_THROW_ON_ERROR),
    ]);

    return $id;
}

it('FR-19: lists ALL the customer\'s inbound media flattened per item, filterable by kind and ticket; the curated shape carries scan_status and never source_url', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $viewer = customersScopedActor('All', 'S08 Gallery Viewer');

    $convA = anchoredConversation($tenantId, $customerId, 'tg-gal-1', 'bot-business');
    $convB = anchoredConversation($tenantId, $customerId, 'tg-gal-2', 'bot-direct');
    $key = 't/'.$tenantId.'/gal/fixture-1.jpg';
    galleryMessage($tenantId, $convA, [
        ['kind' => 'photo', 'storage_key' => $key, 'scan_status' => 'clean'],
        ['kind' => 'file', 'storage_key' => 't/'.$tenantId.'/gal/fixture-2.pdf', 'scan_status' => 'clean'],
    ]);
    galleryMessage($tenantId, $convB, [
        ['kind' => 'photo', 'storage_key' => 't/'.$tenantId.'/gal/fixture-3.jpg', 'scan_status' => 'infected'],
    ]);
    // An OUTBOUND media message must NOT appear (FR-19: media the customer SENT).
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $convA,
        'direction' => 'out', 'author_type' => 'agent', 'kind' => 'photo', 'body' => null, 'is_internal' => false,
        'delivery_state' => 'queued',
        'media' => json_encode([['kind' => 'photo', 'storage_key' => 't/'.$tenantId.'/gal/out.jpg', 'scan_status' => 'clean',
            'source_url' => null, 'mime' => 'image/jpeg', 'bytes' => 1, 'width' => null, 'height' => null,
            'duration_seconds' => null, 'thumb_key' => null, 'transcript_ref' => null, 'ocr_ref' => null, 'alt_text' => null]], JSON_THROW_ON_ERROR),
    ]);

    // The ticket filter: a ticket on conversation A sees only A's items.
    [$ticket] = inboxTicketRow($tenantId, App\Models\Conversation::query()->findOrFail($convA), 'open');

    $all = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel/files");
    $all->assertOk();
    expect(count($all->json('items')))->toBe(3)
        ->and(json_encode($all->json('items')))->not->toContain('source_url')
        ->and(collect($all->json('items'))->pluck('scan_status')->countBy()->all())->toBe(['infected' => 1, 'clean' => 2]);

    $photos = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel/files?kind=photo");
    expect(count($photos->json('items')))->toBe(2);

    $byTicket = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$customerId}/panel/files?ticket_id=".$ticket->identifier());
    expect(count($byTicket->json('items')))->toBe(2)
        ->and(collect($byTicket->json('items'))->pluck('conversation_id')->unique()->values()->all())->toBe([$convA]);
});

it('FR-19 / closing row 4a: a CLEAN item signs through Doc 04\'s own MediaStore (one guard, no second implementation); an INFECTED item is REFUSED with the stable code; a foreign pairing is 404', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $customerId = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    $viewer = customersScopedActor('All', 'S08 Gallery Signer');

    $conv = anchoredConversation($tenantId, $customerId, 'tg-gal-sign');
    $cleanKey = 't/'.$tenantId.'/gal/clean-1.jpg';
    Illuminate\Support\Facades\Storage::disk((string) config('media.disk'))->put($cleanKey, 'fixture-bytes');
    $messageId = galleryMessage($tenantId, $conv, [
        ['kind' => 'photo', 'storage_key' => $cleanKey, 'scan_status' => 'clean'],
        ['kind' => 'photo', 'storage_key' => 't/'.$tenantId.'/gal/bad.jpg', 'scan_status' => 'infected'],
    ]);

    $clean = test()->actingAs($viewer, 'staff')
        ->getJson("https://localhost/api/staff/customers/{$customerId}/panel/files/{$messageId}/url?index=0");
    $clean->assertOk();
    expect((string) $clean->json('url'))->toContain($cleanKey);

    $infected = test()->actingAs($viewer, 'staff')
        ->getJson("https://localhost/api/staff/customers/{$customerId}/panel/files/{$messageId}/url?index=1");
    $infected->assertStatus(409);
    expect($infected->json('code'))->toBe('media_not_servable');

    // C-10: the same message asked through ANOTHER customer's id is missing,
    // not forbidden — no pairing oracle.
    bindTenant($tenant);    // the request lifecycle cleared the RLS binding on its way out
    $other = enforcedCustomer($tenantId, '2026-06-01 11:00:00+00');
    test()->actingAs($viewer, 'staff')
        ->getJson("https://localhost/api/staff/customers/{$other}/panel/files/{$messageId}/url?index=0")
        ->assertStatus(404);
});
