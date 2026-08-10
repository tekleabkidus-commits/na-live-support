<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Views;

use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-06: saved views are DEFINITIONS — filter set + sort + column config —
 * owned by their creator. Sharing to team/department requires `views.share`
 * (the addition of record); a SHARED view is READ-ONLY to consumers, whose
 * one affordance is "duplicate to edit" (AC-02's round-trip). Deletion is
 * the deleted_at tombstone (BR-02 posture; DELETE is grant-revoked).
 *
 * Materialization (T-01): definitions materialize into `saved:{id}` ZSETs on
 * create/update (full recompute — §12's ≤5 s/50k budget is step 15's
 * measured structure) and are INCREMENTALLY PATCHED by the projector from
 * the saved-view index; consumers with narrower scope read their lazy A-30
 * variant (EC-04).
 */
final class SavedViews
{
    private const CONNECTION = 'volatile';

    private const TTL_SECONDS = 604_800;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly TenantContext $context,
        private readonly ViewZsets $zsets,
    ) {}

    /** The per-tenant index of MATERIALIZED saved views the projector patches. */
    public function indexKey(): string
    {
        return sprintf('t:%s:view:saved_index', $this->context->tenantId());
    }

    /** @return list<string> materialized saved-view ids */
    public function materializedIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->redis->connection(self::CONNECTION)->command('smembers', [$this->indexKey()]);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $sort
     * @param  array<string, mixed>  $columns
     */
    public function create(User $owner, string $name, array $filters, array $sort = [], array $columns = []): string
    {
        if (! SavedViewFilters::validate($filters)) {
            throw new DomainException('saved-view filters contain a key outside FR-06\'s closed set');
        }

        $id = (string) Str::uuid7();
        DB::table('inbox_saved_views')->insert([
            'id' => $id,
            'tenant_id' => $this->context->tenantId(),
            'owner_user_id' => $owner->identifier(),
            'name' => $name,
            'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
            'sort' => json_encode($sort, JSON_THROW_ON_ERROR),
            'columns' => json_encode($columns, JSON_THROW_ON_ERROR),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);

        $this->materialize($id, $filters);

        return $id;
    }

    /**
     * Owner-only edit; recompute on definition change (T-01).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $sort
     * @param  array<string, mixed>  $columns
     */
    public function update(User $actor, string $viewId, string $name, array $filters, array $sort = [], array $columns = []): void
    {
        $view = $this->ownedView($actor, $viewId);

        if (! SavedViewFilters::validate($filters)) {
            throw new DomainException('saved-view filters contain a key outside FR-06\'s closed set');
        }

        DB::table('inbox_saved_views')->where('id', $view->id)->update([
            'name' => $name,
            'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
            'sort' => json_encode($sort, JSON_THROW_ON_ERROR),
            'columns' => json_encode($columns, JSON_THROW_ON_ERROR),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);

        $this->materialize($viewId, $filters);
        // Definition changed → every scoped variant is stale; drop them (they
        // lazily re-materialize on next read — A-30's one code path).
        foreach (app(ViewVariants::class)->signatures('saved:'.$viewId) as $sig) {
            $this->zsets->flush('saved:'.$viewId.':scope:'.$sig);
        }
        $this->redis->connection(self::CONNECTION)->command('del', [app(ViewVariants::class)->indexKey('saved:'.$viewId)]);
    }

    /** Sharing: owner + views.share (route-gated); audited (route middleware). */
    public function share(User $actor, string $viewId, string $toType, string $toId): void
    {
        $view = $this->ownedView($actor, $viewId);

        if (! in_array($toType, ['team', 'department'], true)) {
            throw new DomainException('share target must be team or department (FR-06)');
        }

        DB::table('inbox_saved_views')->where('id', $view->id)->update([
            'shared_to_type' => $toType,
            'shared_to_id' => $toId,
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    public function unshare(User $actor, string $viewId): void
    {
        $view = $this->ownedView($actor, $viewId);

        DB::table('inbox_saved_views')->where('id', $view->id)->update([
            'shared_to_type' => null,
            'shared_to_id' => null,
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    /**
     * AC-02's round-trip: a CONSUMER of a shared view cannot edit it — their
     * affordance is a duplicate they own. The copy starts UNSHARED.
     */
    public function duplicate(User $actor, string $viewId): string
    {
        /** @var object{id: string, name: string, filters: string, sort: string, columns: string}|null $view */
        $view = DB::table('inbox_saved_views')->where('id', $viewId)->whereNull('deleted_at')->first();
        if ($view === null) {
            throw new DomainException('view not found');
        }

        /** @var array<string, mixed> $filters */
        $filters = json_decode($view->filters, true, 16, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $sort */
        $sort = json_decode($view->sort, true, 16, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $columns */
        $columns = json_decode($view->columns, true, 16, JSON_THROW_ON_ERROR);

        return $this->create($actor, $view->name.' (copy)', $filters, $sort, $columns);
    }

    /** The tombstone delete (BR-02 posture) + de-materialization. */
    public function softDelete(User $actor, string $viewId): void
    {
        $view = $this->ownedView($actor, $viewId);

        DB::table('inbox_saved_views')->where('id', $view->id)->update([
            'deleted_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);

        $this->zsets->flush('saved:'.$viewId);
        $this->redis->connection(self::CONNECTION)->command('srem', [$this->indexKey(), $viewId]);
    }

    /**
     * Full recompute from the definition over ticket_list_items (T-01) +
     * (re-)registration in the projector's index.
     *
     * @param  array<string, mixed>  $filters
     */
    public function materialize(string $viewId, array $filters): void
    {
        $key = 'saved:'.$viewId;
        $this->zsets->flush($key);

        $query = DB::table('ticket_list_items as li')
            ->selectRaw('li.ticket_id AS id, li.last_activity_at AS sort_at');
        SavedViewFilters::sql($query, $filters);

        /** @var list<object{id: string, sort_at: string}> $rows */
        $rows = $query->get()->all();
        foreach ($rows as $row) {
            $this->zsets->add($key, $row->id, ViewZsets::toMicros($row->sort_at));
        }

        $connection = $this->redis->connection(self::CONNECTION);
        $connection->command('sadd', [$this->indexKey(), $viewId]);
        $connection->command('expire', [$this->indexKey(), self::TTL_SECONDS]);
    }

    /** @return object{id: string, owner_user_id: string, filters: string} */
    private function ownedView(User $actor, string $viewId): object
    {
        /** @var object{id: string, owner_user_id: string, filters: string}|null $view */
        $view = DB::table('inbox_saved_views')->where('id', $viewId)->whereNull('deleted_at')->first();

        if ($view === null) {
            throw new DomainException('view not found');
        }
        if ($view->owner_user_id !== $actor->identifier()) {
            // AC-02: shared views are READ-ONLY to consumers — the same
            // refusal for "not yours" and "not shared with you" (C-10 spirit).
            throw new DomainException('read_only_view');
        }

        return $view;
    }
}
