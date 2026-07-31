<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Support\Collection;

/**
 * One page of a cursor-paginated collection (Doc 01 T-08, C-05).
 *
 * `nextCursor` is null exactly when this page is the last one the query could see.
 * There is deliberately no total count and no page number: producing either would
 * require the COUNT(*) over the unbounded collection that cursor pagination exists
 * to avoid, and a "page 3 of 118" UI invites the offset arithmetic C-05 prohibits.
 *
 * @template TValue
 */
final class CursorPage
{
    /**
     * @param  Collection<int, TValue>  $items
     */
    public function __construct(
        public readonly Collection $items,
        public readonly ?string $nextCursor,
    ) {}

    /**
     * The response shape C-05 fixes: `data` + `next_cursor`.
     *
     * @return array{data: list<TValue>, next_cursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'data' => array_values($this->items->all()),
            'next_cursor' => $this->nextCursor,
        ];
    }
}
