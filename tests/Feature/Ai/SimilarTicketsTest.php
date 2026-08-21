<?php

declare(strict_types=1);

use App\Domain\Ai\Copilot\SimilarTickets;
use App\Domain\Ai\Copilot\TicketSummaryEmbedder;
use App\Domain\Ai\Providers\ProviderResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 09 T-05's similar-tickets half + FR-14's "similar resolved tickets
 * (top 3, scope-checked)": the nightly embedder summarizes + embeds
 * resolved tickets (UNIQUE upsert), retrieval ranks by cosine and applies
 * Doc 03's scope filter AT QUERY TIME — an Own-scoped agent never sees a
 * ticket outside their scope however similar it is.
 */
function resolvedTicketWithSummary(string $tenantId, string $subject, string $summary, int $hot, ?string $assigneeId = null): string
{
    $conversation = anotherConversation($tenantId, 'sim');
    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'resolved', 'priority' => 'normal', 'source' => 'agent', 'subject' => $subject, 'assignee_id' => $assigneeId]);
    DB::statement('INSERT INTO ai_ticket_summaries (id, tenant_id, ticket_id, summary, language, embedding, embedded_at) VALUES (?, ?, ?, ?, ?, ?::vector, clock_timestamp())', [(string) Str::uuid7(), $tenantId, $ticketId, $summary, 'en', '['.implode(',', copilotVector($hot)).']']);
    runListProjector(inboxSeedEvent('ticket.created', ['tenant_id' => $tenantId, 'ticket_id' => $ticketId, 'conversation_id' => $conversation->identifier(), 'customer_id' => $conversation->customer_id, 'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null]));
    if ($assigneeId !== null) {
        DB::table('ticket_list_items')->where('ticket_id', $ticketId)->update(['assignee_id' => $assigneeId]);
    }

    return $ticketId;
}

it('T-05/FR-14: top-3 by cosine over resolved-ticket summaries, the current ticket excluded, with number + subject for the card', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $near = resolvedTicketWithSummary($tenantId, 'telebirr deposit delayed', 'Customer reported a delayed telebirr deposit; resolved after the payment provider retry.', 5);
    $nearer = resolvedTicketWithSummary($tenantId, 'deposit missing 2h', 'Deposit missing for two hours; credited manually after reference check.', 5);
    $far = resolvedTicketWithSummary($tenantId, 'password reset', 'Customer could not log in; reset link sent.', 900);
    copilotDriver(copilotEmbedding(5));

    $results = app(SimilarTickets::class)->forQuery($actor, '[FIXTURE] my deposit is delayed', $ticket->identifier());

    $ids = array_column($results, 'ticket_id');
    expect($ids)->toContain($near)->toContain($nearer)
        ->and($ids)->not->toContain($ticket->identifier())
        ->and($results[0]['score'])->toBeGreaterThan($results[count($results) - 1]['score'])
        ->and($results[0]['number'])->toBeInt()
        ->and($results[0]['subject'])->toBeString();
    expect(array_search($far, $ids, true))->toBeIn([false, 2]); // the far ticket is last or cut
});

it('T-05 scope AT QUERY TIME: an Own-scoped agent sees ONLY tickets assigned to them among the similar ones — the nearest foreign ticket is cut', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $role = App\Models\Role::query()->create(['name' => 'S09 Similar Own', 'color' => 'role-agent', 'permissions' => ['tickets.view', 'conversations.view', 'ai.copilot.use'], 'scopes' => ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own']]);
    $own = App\Models\User::factory()->create();
    $own->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($own);

    $mine = resolvedTicketWithSummary($tenantId, 'mine', 'Deposit delayed; resolved.', 5, $own->identifier());
    $theirs = resolvedTicketWithSummary($tenantId, 'theirs', 'Deposit delayed; resolved the same way.', 5);
    copilotDriver(copilotEmbedding(5), copilotEmbedding(5));

    $ownSees = array_column(app(SimilarTickets::class)->forQuery($own, '[FIXTURE] deposit delayed'), 'ticket_id');
    expect($ownSees)->toBe([$mine]);

    $allSees = array_column(app(SimilarTickets::class)->forQuery($actor, '[FIXTURE] deposit delayed'), 'ticket_id');
    expect($allSees)->toContain($mine)->toContain($theirs);
});

it('T-05 nightly: the embedder summarizes + embeds resolved tickets lacking a row (summarize_thread then embeddings, both through the orchestrator), upserts on (tenant, ticket), and skips a ticket whose provider call fails', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $first = anotherConversation($tenantId, 'emb1');
    $firstTicket = (string) Str::uuid7();
    DB::table('tickets')->insert(['id' => $firstTicket, 'tenant_id' => $tenantId, 'conversation_id' => $first->identifier(), 'customer_id' => $first->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'resolved', 'priority' => 'normal', 'source' => 'agent']);
    copilotCustomerMessage($tenantId, $first, '[FIXTURE] my 500 ETB deposit did not arrive');
    $other = anotherConversation($tenantId, 'emb');
    $otherTicket = (string) Str::uuid7();
    DB::table('tickets')->insert(['id' => $otherTicket, 'tenant_id' => $tenantId, 'conversation_id' => $other->identifier(), 'customer_id' => $other->customer_id, 'number' => random_int(1, 2_000_000_000), 'status' => 'closed', 'priority' => 'normal', 'source' => 'agent']);
    DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $other->identifier(), 'direction' => 'in', 'author_type' => 'customer', 'author_id' => $other->customer_id, 'kind' => 'text', 'body' => '[FIXTURE] cannot log in']);

    $driver = copilotDriver(
        new ProviderResponse('[FIXTURE] Customer reported a missing deposit; checked and credited.', 30, 20),
        copilotEmbedding(5),
        new RuntimeException('provider hiccup on the second ticket'), // → skipped, not retried this run
    );

    $counts = app(TicketSummaryEmbedder::class)->run();
    expect($counts)->toBe(['embedded' => 1, 'skipped' => 1]);

    $row = DB::table('ai_ticket_summaries')->where('ticket_id', $firstTicket)->first(['summary', 'language', 'embedded_at']);
    expect($row->summary)->toContain('missing deposit')->and($row->embedded_at)->not->toBeNull();
    expect(DB::table('ai_decisions')->where('ticket_id', $firstTicket)->where('task', 'summarize_thread')->where('action', 'ticket_summary')->count())->toBe(1);

    // Re-run: the embedded ticket is NOT redone (no new summary row, no provider call); the skipped one is retried.
    Illuminate\Support\Facades\Cache::flush();
    $driver2 = copilotDriver(new ProviderResponse('[FIXTURE] Login issue resolved.', 10, 10), copilotEmbedding(7));
    expect(app(TicketSummaryEmbedder::class)->run())->toBe(['embedded' => 1, 'skipped' => 0]);
    expect(DB::table('ai_ticket_summaries')->count())->toBe(2)->and(count($driver2->seen))->toBe(2);
});
