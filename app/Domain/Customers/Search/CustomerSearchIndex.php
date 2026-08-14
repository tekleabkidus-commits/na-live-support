<?php

declare(strict_types=1);

namespace App\Domain\Customers\Search;

use App\Support\Tenancy\TenantContext;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

/**
 * T-04's customers_index — Meilisearch's SECOND index family (Doc 07's
 * tickets/messages were the first). The same two-layer tenancy shape, and
 * both layers are CODE this module proves (RLS does not reach a search
 * index; Doc 07 SearchIndex's own law, applied to the second index):
 *   1. PHYSICAL per-tenant index — t{tenant}_customers; the NAME derives
 *      from server-controlled TenantContext, never from input.
 *   2. Server-injected Doc 03 scope FILTERS (CustomerScope) over the
 *      filterable attributes below — the client never sends scope (SEC-02;
 *      sabotage #9's target).
 *
 * Searchable fields are T-04's own list: names, usernames INCLUDING history
 * (FR-06), phone, email, player_id. RG and blocked state are NEVER indexed —
 * BR-03's absence discipline extends to the search surface, and the blocked
 * directory filter is a DB concern, not an index attribute.
 */
final class CustomerSearchIndex
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

    public function customersIndexName(): string
    {
        return 't'.str_replace('-', '', $this->context->tenantId()).'_customers';
    }

    /** Create-if-missing with the T-04 settings; idempotent (settings re-apply is a no-op update). */
    public function ensureCustomers(): Indexes
    {
        $client = $this->client();
        $name = $this->customersIndexName();

        $task = $client->createIndex($name, ['primaryKey' => 'id']);
        $client->waitForTask($task['taskUid']);

        $index = $client->index($name);
        $settings = $index->updateSettings([
            'filterableAttributes' => ['assignee_ids', 'department_ids'],
            'searchableAttributes' => ['display_name', 'usernames', 'phones', 'emails', 'player_id'],
        ]);
        $client->waitForTask($settings['taskUid']);

        return $index;
    }
}
