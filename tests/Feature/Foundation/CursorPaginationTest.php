<?php

declare(strict_types=1);

use App\Support\Http\CursorPagination;
use App\Support\Http\Exceptions\InvalidPaginationRequest;
use App\Support\Ids\TenantSequence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * T-08 / C-05 — cursor pagination only: cursor, limit ≤ 100, next_cursor; offset prohibited.
 *
 * The fixture collection is tenant_sequences rows (any UUIDv7-keyed tenant table serves —
 * pagination does not care what it pages), created through the real allocator so the rows
 * carry real tenant scoping.
 */
function seedSequenceRows(int $count): void
{
    $allocator = app(TenantSequence::class);

    foreach (range(1, $count) as $i) {
        $allocator->next(sprintf('fixture_seq_%02d', $i));
    }
}

it('walks an entire collection in strictly ascending UUIDv7 order with no gap and no repeat', function () {
    createTenant();
    seedSequenceRows(7);

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $request = Request::create('/anything', parameters: array_filter([
            'limit' => '3',
            'cursor' => $cursor,
        ]));

        $page = CursorPagination::fromRequest($request)
            ->paginate(DB::table('tenant_sequences'));

        $pages++;
        foreach ($page->items as $row) {
            $seen[] = $row->id;
        }
        $cursor = $page->nextCursor;
    } while ($cursor !== null);

    // 7 rows at limit 3 → 3 + 3 + 1, each row exactly once, in index order.
    expect($pages)->toBe(3)
        ->and($seen)->toHaveCount(7)
        ->and(array_unique($seen))->toHaveCount(7)
        ->and($seen)->toBe(collect($seen)->sort()->values()->all());
});

it('shapes the page as data + next_cursor, with next_cursor null on the last page', function () {
    createTenant();
    seedSequenceRows(2);

    $page = CursorPagination::fromRequest(Request::create('/anything', parameters: ['limit' => '5']))
        ->paginate(DB::table('tenant_sequences'));

    expect($page->toArray())->toHaveKeys(['data', 'next_cursor'])
        ->and($page->toArray()['next_cursor'])->toBeNull()
        ->and($page->items)->toHaveCount(2);
});

it('rejects offset-family parameters BY NAME (C-05: offset pagination prohibited)', function (string $param) {
    expect(fn () => CursorPagination::fromRequest(Request::create('/anything', parameters: [$param => '10'])))
        ->toThrow(InvalidPaginationRequest::class, 'prohibited');
})->with(['offset', 'page', 'per_page']);

it('rejects a limit above 100, below 1, or non-integer', function (string $limit) {
    expect(fn () => CursorPagination::fromRequest(Request::create('/anything', parameters: ['limit' => $limit])))
        ->toThrow(InvalidPaginationRequest::class);
})->with(['101', '0', '-5', 'abc', '3.5']);

it('rejects a cursor this API never issued', function (string $cursor) {
    expect(fn () => CursorPagination::fromRequest(Request::create('/anything', parameters: ['cursor' => $cursor])))
        ->toThrow(InvalidPaginationRequest::class, 'not a value this API issued');
})->with([
    'not-base64!!!',
    'aGVsbG8',                    // valid base64url, wrong payload ("hello")
    'djE6bm90LWEtdXVpZA',         // "v1:not-a-uuid"
]);

it('renders pagination misuse as a 400 invalid_pagination problem (C-04)', function () {
    createTenant(); // trace_id comes from the bound context

    $response = (new InvalidPaginationRequest('drop "offset"'))->render();

    expect($response->getStatusCode())->toBe(400)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json');

    $body = $response->getData(true);
    expect($body['code'])->toBe('invalid_pagination')
        ->and($body['detail'])->toContain('offset')
        ->and($body)->toHaveKeys(['type', 'title', 'status', 'code', 'detail', 'errors', 'trace_id']);
});

it('keeps the cursor opaque: the row id never appears in the cursor text', function () {
    createTenant();
    seedSequenceRows(3);

    $page = CursorPagination::fromRequest(Request::create('/anything', parameters: ['limit' => '2']))
        ->paginate(DB::table('tenant_sequences'));

    $lastId = $page->items->last()->id;

    expect($page->nextCursor)->not->toBeNull()
        ->and($page->nextCursor)->not->toContain($lastId);
});
