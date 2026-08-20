<?php

declare(strict_types=1);

use App\Domain\Ai\Chatbot\ContextBuilder;
use App\Domain\Ai\Prompt\LayeredAssembly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEC-04 AS PHYSICS (BR-06; sabotage #4's subject), proven BY PLANTING —
 * landed BEFORE any prompt is built from customer context: RG flag contents
 * and staff notes are planted on a real customer and asserted absent from
 * the built context AND from the assembled prompt, while the allowlisted
 * facts flow.
 */
const RG_PLANT = 'FAKESENSITIVE-rg-reason-self-exclusion-request';
const NOTE_PLANT = 'FAKESENSITIVE-staff-note-customer-is-difficult';

function plantedCustomer(): array
{
    $tenant = createTenant();
    bindTenant($tenant);
    $tenantId = $tenant->identifier();

    $customerId = (string) Str::uuid7();
    DB::table('customers')->insert([
        'id' => $customerId, 'tenant_id' => $tenantId, 'created_source' => 'telegram',
        'rg_flag' => true, 'rg_flag_reason' => RG_PLANT,
    ]);
    DB::table('customer_notes')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'customer_id' => $customerId,
        'author_id' => (string) Str::uuid7(), 'body' => NOTE_PLANT,
    ]);

    return [$tenantId, $customerId];
}

it('SEC-04/BR-06: planted RG flag content and staff notes NEVER enter the built context', function (): void {
    [, $customerId] = plantedCustomer();

    $context = app(ContextBuilder::class)->build($customerId, 'en', [
        ['role' => 'user', 'content' => '[FIXTURE] where is my withdrawal?'],
    ]);

    $serialized = json_encode($context, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain(RG_PLANT)
        ->and($serialized)->not->toContain(NOTE_PLANT)
        ->and($serialized)->not->toContain('rg_flag');
});

it('SEC-04: the exclusion holds through the FULL assembled prompt, and a note in the window is dropped by the re-check', function (): void {
    [, $customerId] = plantedCustomer();

    $context = app(ContextBuilder::class)->build($customerId, 'en', [
        ['role' => 'user', 'content' => '[FIXTURE] where is my withdrawal?'],
        ['role' => 'user', 'content' => NOTE_PLANT, 'kind' => 'note'],
    ]);
    $assembled = app(LayeredAssembly::class)->assemble('chatbot_turn', 'Answer.', $context);

    $prompt = json_encode($assembled['messages'], JSON_THROW_ON_ERROR);
    expect($prompt)->not->toContain(RG_PLANT)
        ->and($prompt)->not->toContain(NOTE_PLANT);
});

it('the allowlist is honest in the OTHER direction: language, prior-ticket topics and acquisition DO flow', function (): void {
    [$tenantId, $customerId] = plantedCustomer();

    $conversation = anotherConversation($tenantId, 'ctx', $customerId);
    DB::table('tickets')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'conversation_id' => $conversation->identifier(),
        'customer_id' => $customerId,
        'number' => random_int(1, 2_000_000_000),
        'status' => 'open',
        'priority' => 'normal',
        'source' => 'agent',
        'subject' => '[FIXTURE] telebirr deposit question',
        'subject_source' => 'staff',
    ]);

    $context = app(ContextBuilder::class)->build($customerId, 'am', [
        ['role' => 'user', 'content' => '[FIXTURE] hi'],
    ], acquisition: 'src-app_ctx-deposit');

    expect($context['language'])->toBe('am')
        ->and($context['prior_topics'])->toContain('[FIXTURE] telebirr deposit question')
        ->and($context['acquisition'])->toBe('src-app_ctx-deposit');
});
