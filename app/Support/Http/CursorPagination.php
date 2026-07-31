<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\Http\Exceptions\InvalidPaginationRequest;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use stdClass;

/**
 * The one pagination convention (Doc 01 T-08, C-05, C-11).
 *
 * C-05: "cursor pagination only (`cursor`, `limit ≤ 100`, `next_cursor`). Offset pagination is
 * prohibited on unbounded collections." This class is where that sentence becomes mechanism:
 *
 *   - `offset` and `page` are REJECTED BY NAME, not merely unsupported. Silently ignoring them
 *     would let a client believe it is paging while it re-reads page one forever — the bug
 *     would live in the client where no gate can see it. A loud 400 puts it here.
 *   - The cursor is OPAQUE (base64url over a versioned payload). Clients that can read a cursor
 *     start doing arithmetic on it, and then the encoding is API surface forever.
 *   - Ordering is by UUIDv7 primary key unless the caller names another monotonic column —
 *     which is C-11's "UUIDv7 monotonicity, never app-server wall clocks" applied to paging. A
 *     `created_at` cursor would skip or duplicate rows written in the same second.
 *
 * Doc 01 ships no unbounded collection itself — the first real consumer is Doc 04's messages.
 * The helper exists NOW because T-08 is a frozen-interface requirement (§21): every later
 * module pages this way, none invents its own.
 */
final class CursorPagination
{
    public const MAX_LIMIT = 100;

    public const DEFAULT_LIMIT = 25;

    private const CURSOR_VERSION = 'v1';

    private function __construct(
        public readonly int $limit,
        public readonly ?string $afterId,
    ) {}

    /**
     * Parse and validate the paging grammar of a request. Everything wrong with it is an
     * InvalidPaginationRequest, which renders as a 400 `invalid_pagination` problem.
     */
    public static function fromRequest(Request $request): self
    {
        foreach (['offset', 'page', 'per_page'] as $prohibited) {
            if ($request->query->has($prohibited)) {
                throw new InvalidPaginationRequest(sprintf(
                    'Offset pagination is prohibited (C-05): drop "%s" and page with the '
                    .'"cursor" value returned as "next_cursor".',
                    $prohibited,
                ));
            }
        }

        $rawLimit = $request->query->get('limit');
        $limit = self::DEFAULT_LIMIT;

        if ($rawLimit !== null) {
            if (! is_numeric($rawLimit) || (string) (int) $rawLimit !== (string) $rawLimit) {
                throw new InvalidPaginationRequest('"limit" must be an integer between 1 and 100.');
            }

            $limit = (int) $rawLimit;

            if ($limit < 1 || $limit > self::MAX_LIMIT) {
                throw new InvalidPaginationRequest(
                    sprintf('"limit" must be between 1 and %d; got %d.', self::MAX_LIMIT, $limit),
                );
            }
        }

        $rawCursor = $request->query->get('cursor');
        $afterId = null;

        if ($rawCursor !== null) {
            if ($rawCursor === '') {
                throw new InvalidPaginationRequest('"cursor" must be a value previously returned as "next_cursor".');
            }

            $afterId = self::decode($rawCursor);
        }

        return new self($limit, $afterId);
    }

    /**
     * Apply the page window to a query and materialise the page.
     *
     * Fetches limit+1 rows: the extra row answers "is there a next page" without the
     * COUNT(*) this convention exists to avoid, and is discarded before the caller sees it.
     *
     * @param  string  $column  a UUIDv7 (or otherwise monotonic) column — see the class docblock
     * @return CursorPage<stdClass>
     */
    public function paginate(Builder $query, string $column = 'id'): CursorPage
    {
        $rows = $query
            ->when($this->afterId !== null, fn (Builder $q): Builder => $q->where($column, '>', $this->afterId))
            ->orderBy($column)
            ->limit($this->limit + 1)
            ->get();

        $hasMore = $rows->count() > $this->limit;
        $items = $rows->take($this->limit);

        $nextCursor = null;

        if ($hasMore) {
            $lastId = $items->last()->{$column} ?? null;

            if (! is_string($lastId)) {
                throw new InvalidPaginationRequest(sprintf(
                    'Cursor column "%s" is missing from the selected rows — select it, or name '
                    .'the ordering column explicitly.',
                    $column,
                ));
            }

            $nextCursor = self::encode($lastId);
        }

        return new CursorPage($items->collect(), $nextCursor);
    }

    private static function encode(string $id): string
    {
        return rtrim(strtr(base64_encode(self::CURSOR_VERSION.':'.$id), '+/', '-_'), '=');
    }

    private static function decode(string $cursor): string
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false || ! str_starts_with($decoded, self::CURSOR_VERSION.':')) {
            throw new InvalidPaginationRequest('"cursor" is not a value this API issued.');
        }

        $id = substr($decoded, strlen(self::CURSOR_VERSION) + 1);

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            throw new InvalidPaginationRequest('"cursor" is not a value this API issued.');
        }

        return $id;
    }
}
