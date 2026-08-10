<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Views;

use Illuminate\Database\Query\Builder;

/**
 * FR-06's filter set, defined ONCE in the two executable forms the read-model
 * architecture demands (the SystemViews discipline applied to saved views):
 *
 *   - sql():     the materializer/checker/rebuild form over ticket_list_items
 *   - matches(): the projector's per-row form (incremental patching)
 *
 * The CLOSED key set (FR-06's own list): status[], priority[], department_id,
 * queue_id, assignee_id ('unassigned' sentinel for ∅), tags[] (any overlap),
 * language, channel, has_draft, created_from/created_to,
 * updated_from/updated_to. `has_draft` is VIEWER-RELATIVE (Doc 04 drafts are
 * per user) — it is validated and stored here but evaluated at HYDRATION
 * overlay (step 9's drafts wiring), never in membership; stated in the plan's
 * step-4 report, not silently.
 */
final class SavedViewFilters
{
    public const KEYS = [
        'status', 'priority', 'department_id', 'queue_id', 'assignee_id',
        'tags', 'language', 'channel', 'has_draft',
        'created_from', 'created_to', 'updated_from', 'updated_to',
    ];

    /** @param array<string, mixed> $filters */
    public static function validate(array $filters): bool
    {
        if (array_diff(array_keys($filters), self::KEYS) !== []) {
            return false;
        }

        // Types are part of the closed contract: list-of-strings for the
        // array keys, scalar strings elsewhere, bool for has_draft.
        foreach ($filters as $key => $value) {
            $ok = match ($key) {
                'status', 'priority', 'tags' => is_array($value) && $value === array_values(array_filter($value, is_string(...))),
                'has_draft' => is_bool($value),
                default => is_string($value) && $value !== '',
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** A validated string value (validate() enforces; this makes the contract explicit). */
    private static function str(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('saved-view filter value escaped validation — not a string');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException('saved-view filter value escaped validation — not a list');
        }

        return array_values(array_map(static fn (mixed $v): string => self::str($v), $value));
    }

    /**
     * The SQL form over ticket_list_items (aliased li).
     *
     * @param  array<string, mixed>  $filters
     */
    public static function sql(Builder $query, array $filters): Builder
    {
        if (isset($filters['status']) && is_array($filters['status'])) {
            $query->whereIn('li.status', self::strings($filters['status']));
        }
        if (isset($filters['priority']) && is_array($filters['priority'])) {
            $query->whereIn('li.priority', self::strings($filters['priority']));
        }
        if (isset($filters['department_id'])) {
            $query->where('li.department_id', self::str($filters['department_id']));
        }
        if (isset($filters['queue_id'])) {
            $query->where('li.queue_id', self::str($filters['queue_id']));
        }
        if (isset($filters['assignee_id'])) {
            $filters['assignee_id'] === 'unassigned'
                ? $query->whereNull('li.assignee_id')
                : $query->where('li.assignee_id', self::str($filters['assignee_id']));
        }
        if (isset($filters['tags']) && is_array($filters['tags']) && $filters['tags'] !== []) {
            // jsonb ?| — any overlap with the filter's tag list.
            $query->whereRaw('li.tags ?| ?::text[]', ['{'.implode(',', self::strings($filters['tags'])).'}']);
        }
        if (isset($filters['language'])) {
            $query->where('li.language', self::str($filters['language']));
        }
        if (isset($filters['channel'])) {
            $query->where('li.channel', self::str($filters['channel']));
        }
        if (isset($filters['created_from'])) {
            $query->where('li.ticket_created_at', '>=', self::str($filters['created_from']));
        }
        if (isset($filters['created_to'])) {
            $query->where('li.ticket_created_at', '<=', self::str($filters['created_to']));
        }
        if (isset($filters['updated_from'])) {
            $query->where('li.last_activity_at', '>=', self::str($filters['updated_from']));
        }
        if (isset($filters['updated_to'])) {
            $query->where('li.last_activity_at', '<=', self::str($filters['updated_to']));
        }

        return $query;
    }

    /**
     * The per-row form — MUST agree with sql(); ReplayCounting/NFR-07-style
     * equality between the two is asserted in SavedViewsTest.
     *
     * @param  array<string, mixed>  $filters
     * @param  object{status: string, priority: string, department_id: string|null, queue_id: string|null, assignee_id: string|null, language: string|null, channel: string, tags: string, ticket_created_at: string, last_activity_at: string}  $row
     */
    public static function matches(array $filters, object $row): bool
    {
        if (isset($filters['status']) && ! in_array($row->status, self::strings($filters['status']), true)) {
            return false;
        }
        if (isset($filters['priority']) && ! in_array($row->priority, self::strings($filters['priority']), true)) {
            return false;
        }
        if (isset($filters['department_id']) && $row->department_id !== self::str($filters['department_id'])) {
            return false;
        }
        if (isset($filters['queue_id']) && $row->queue_id !== self::str($filters['queue_id'])) {
            return false;
        }
        if (isset($filters['assignee_id'])) {
            if ($filters['assignee_id'] === 'unassigned' ? $row->assignee_id !== null : $row->assignee_id !== self::str($filters['assignee_id'])) {
                return false;
            }
        }
        if (isset($filters['tags']) && is_array($filters['tags']) && $filters['tags'] !== []) {
            /** @var list<string> $rowTags */
            $rowTags = json_decode($row->tags, true, 8, JSON_THROW_ON_ERROR);
            if (array_intersect(self::strings($filters['tags']), $rowTags) === []) {
                return false;
            }
        }
        if (isset($filters['language']) && $row->language !== self::str($filters['language'])) {
            return false;
        }
        if (isset($filters['channel']) && $row->channel !== self::str($filters['channel'])) {
            return false;
        }
        if (isset($filters['created_from']) && strcmp($row->ticket_created_at, self::str($filters['created_from'])) < 0) {
            return false;
        }
        if (isset($filters['created_to']) && strcmp($row->ticket_created_at, self::str($filters['created_to'])) > 0) {
            return false;
        }
        if (isset($filters['updated_from']) && strcmp($row->last_activity_at, self::str($filters['updated_from'])) < 0) {
            return false;
        }
        if (isset($filters['updated_to']) && strcmp($row->last_activity_at, self::str($filters['updated_to'])) > 0) {
            return false;
        }

        return true;
    }
}
