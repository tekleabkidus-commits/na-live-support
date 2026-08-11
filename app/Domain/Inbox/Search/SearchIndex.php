<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Search;

use App\Support\Tenancy\TenantContext;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

/**
 * T-04's index layer — Meilisearch's FIRST real consumer (running substrate
 * since S0; nothing ever indexed into it until this step).
 *
 * TENANCY IS TWO LAYERS, THE N-01 SHAPE, AND BOTH ARE CODE THIS MODULE
 * PROVES (RLS does not reach a search index):
 *   1. PHYSICAL per-tenant indexes — t{tenant}_tickets / t{tenant}_messages;
 *      the index NAME derives from server-controlled TenantContext, never
 *      from input. Sabotage #2 collapses this layer and the scoped-search
 *      test goes red.
 *   2. Server-injected Doc 03 scope FILTERS (SearchProxy) over the
 *      filterable attributes below — the client never sends scope (SEC-02).
 *
 * Filterable attributes (T-04's own list): department_id, assignee_id
 * (+ team ids when Doc 11 supplies membership — the signature grammar
 * already carries them).
 */
final class SearchIndex
{
    public function __construct(private readonly TenantContext $context) {}

    public function client(): Client
    {
        /** @var string $host */
        $host = config('services.meilisearch.host');
        /** @var string $key */
        $key = config('services.meilisearch.key');

        return new Client($host, $key);
    }

    public function ticketsIndexName(): string
    {
        return 't'.str_replace('-', '', $this->context->tenantId()).'_tickets';
    }

    public function messagesIndexName(): string
    {
        return 't'.str_replace('-', '', $this->context->tenantId()).'_messages';
    }

    /** Create-if-missing with the T-04 settings; idempotent (settings re-apply is a no-op update). */
    public function ensureTickets(): Indexes
    {
        $client = $this->client();
        $name = $this->ticketsIndexName();

        $task = $client->createIndex($name, ['primaryKey' => 'id']);
        $client->waitForTask($task['taskUid']);

        $index = $client->index($name);
        $settings = $index->updateSettings([
            'filterableAttributes' => ['department_id', 'assignee_id'],
            'searchableAttributes' => ['number', 'subject', 'tags', 'customer_display_name', 'customer_username', 'customer_phone'],
        ]);
        $client->waitForTask($settings['taskUid']);

        return $index;
    }

    public function ensureMessages(): Indexes
    {
        $client = $this->client();
        $name = $this->messagesIndexName();

        $task = $client->createIndex($name, ['primaryKey' => 'id']);
        $client->waitForTask($task['taskUid']);

        $index = $client->index($name);
        $settings = $index->updateSettings([
            'filterableAttributes' => ['department_id', 'assignee_id'],
            'searchableAttributes' => ['body'],
        ]);
        $client->waitForTask($settings['taskUid']);

        return $index;
    }
}
