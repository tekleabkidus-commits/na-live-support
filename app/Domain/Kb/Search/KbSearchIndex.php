<?php

declare(strict_types=1);

namespace App\Domain\Kb\Search;

use App\Support\Tenancy\TenantContext;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

/**
 * Doc 10 FR-17 (S10 step 10): the per-tenant staff keyword index `t{tenant}_kb_articles` —
 * Meilisearch's THIRD index family (Doc 07 tickets/messages, Doc 08 customers). One document per
 * VARIANT (language is a filter, not an index split — §14's `simple` posture carried over: no
 * stemmer choice per language); filterable on language / visibility / status / category_id so
 * the manager's filters and the Copilot blend are index-side facts, never post-filters.
 */
final class KbSearchIndex
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

    public function indexName(): string
    {
        return 't'.str_replace('-', '', $this->context->tenantId()).'_kb_articles';
    }

    /** Create-if-missing with the FR-17 settings; idempotent. */
    public function ensure(): Indexes
    {
        $client = $this->client();
        $name = $this->indexName();
        $task = $client->createIndex($name, ['primaryKey' => 'id']);
        $client->waitForTask($task['taskUid']);
        $index = $client->index($name);
        $settings = $index->updateSettings([
            'filterableAttributes' => ['language', 'visibility', 'status', 'category_id', 'article_id'],
            'searchableAttributes' => ['title', 'summary', 'body', 'tags'],
            'displayedAttributes' => ['id', 'article_id', 'language', 'visibility', 'status', 'category_id', 'title', 'summary', 'slug', 'version_id'],
        ]);
        $client->waitForTask($settings['taskUid']);

        return $index;
    }
}
