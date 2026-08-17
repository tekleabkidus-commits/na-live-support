<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Identity\PhoneNumber;
use App\Domain\Customers\Profile\DisplayName;
use App\Domain\Customers\Search\CustomerScope;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use libphonenumber\NumberParseException;

/**
 * FR-20's table door (S08 step 12; B4-S06). Begun by the terminated session,
 * re-read line-by-line and completed by this one (the fold precedent's
 * conditions). Scope rides CustomerScope — the ONE
 * brain search and the degrade already use. C-05 cursor over
 * (last_contact_at, id) descending, nulls last.
 *
 * G03's law, enforced QUERY-LEVEL: the RG filter exists ONLY for a viewer
 * holding customers.rg_flag.view — for anyone else the parameter is not even
 * validated (NO code path) and the rg column is NEVER NAMED IN SQL, so the
 * table, its filters and its COUNT are byte-identical for permitted and
 * unpermitted viewers looking at the same page ("a filter that exists but
 * returns nothing is a disclosure"). Rows carry rg only for the permitted
 * (absence, never null — BR-03).
 *
 * The header count is the SCOPE total (filter-independent): G01's
 * "4,182 in your scope" and G03's same-count-for-both-viewers proof are the
 * same number.
 */
final class DirectoryController
{
    /** The page shell (B4-S06): translations + the viewer's render-gates. */
    public function page(Request $request, CapabilityMapProvider $verdicts): \Inertia\Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $map = $verdicts->mapFor($viewer);

        return \Inertia\Inertia::render('Customers/Directory', [
            't' => array_merge((array) __('customers.directory'), (array) __('customers.panel')),
            'refOptions' => [
                'language' => (array) __('ref.language'),
                'country' => (array) __('ref.country'),
            ],
            'can' => [
                'edit' => $map->allows('customers.edit'),
                'export' => $map->allows('customers.export'),
            ],
        ]);
    }

    public function list(Request $request, CustomerScope $scope, CapabilityMapProvider $verdicts): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $seesRg = $verdicts->mapFor($viewer)->allows('customers.rg_flag.view');

        $rules = [
            'has_open' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'string', 'max:12'],
            'country' => ['sometimes', 'string', 'size:2'],
            'tag' => ['sometimes', 'string', 'max:60'],
            'blocked' => ['sometimes', 'boolean'],
            'cursor' => ['sometimes', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
        if ($seesRg) {
            // G03: without the verdict the rg parameter has NO code path —
            // not refused, not read, not validated. Ignored.
            $rules['rg'] = ['sometimes', 'boolean'];
        }

        /** @var array{has_open?: bool, language?: string, country?: string, tag?: string, blocked?: bool, rg?: bool, cursor?: string, limit?: int} $input */
        $input = $request->validate($rules);

        $signature = $scope->signature($viewer);
        $base = static fn (): \Illuminate\Database\Query\Builder => DB::table('customers')->whereNull('merged_into_id');

        // The scope total — same base, NO filters, so G01's header count and
        // G03's count-parity are one derivation.
        $total = $scope->constrainQuery($base(), $signature)->count();

        $columns = [
            'id', 'display_name', 'is_shell', 'language', 'country', 'tags', 'blocked',
            'open_tickets', 'tickets_count', 'satisfaction_avg', 'satisfaction_count',
            DB::raw('last_contact_at::text AS last_contact_at'),
        ];
        if ($seesRg) {
            $columns[] = 'rg_flag'; // named in SQL ONLY for the permitted (SEC-01)
        }

        $query = $scope->constrainQuery($base(), $signature);

        // The 'boolean' rule VALIDATES without casting — a query-string true
        // arrives as the string '1'. Cast once, here.
        $bool = static fn (bool|string|int $value): bool => filter_var($value, FILTER_VALIDATE_BOOL);

        if (isset($input['has_open']) && $bool($input['has_open'])) {
            $query->where('open_tickets', '>', 0);
        }
        if (isset($input['language'])) {
            $query->where('language', $input['language']);
        }
        if (isset($input['country'])) {
            $query->where('country', $input['country']);
        }
        if (isset($input['tag'])) {
            // pg text[] containment — the tag filter FR-20 names.
            $query->whereRaw('tags @> ARRAY[?]::text[]', [$input['tag']]);
        }
        if (isset($input['blocked'])) {
            $query->where('blocked', $bool($input['blocked']));
        }
        if ($seesRg && isset($input['rg'])) {
            $query->where('rg_flag', $bool($input['rg']));
        }

        if (isset($input['cursor']) && str_contains($input['cursor'], '|')) {
            [$cursorAt, $cursorId] = explode('|', $input['cursor'], 2);
            if ($cursorAt === 'null') {
                // Inside the null tail: only ids after the cursor remain.
                $query->whereNull('last_contact_at')->whereRaw('id < ?::uuid', [$cursorId]);
            } else {
                // Past a dated cursor: strictly-later rows in DESC order,
                // plus the whole null tail (NULLS LAST puts it after).
                $query->whereRaw(
                    '(last_contact_at IS NULL OR (last_contact_at, id) < (?::timestamptz, ?::uuid))',
                    [$cursorAt, $cursorId],
                );
            }
        }

        $limit = (int) ($input['limit'] ?? 50); // validated-but-string (the 0c72552 lesson: cast at the boundary)
        /** @var list<object{id: string, display_name: ?string, is_shell: bool, language: ?string, country: ?string, tags: ?string, blocked: bool, open_tickets: int, tickets_count: int, satisfaction_avg: ?string, satisfaction_count: int, last_contact_at: ?string, rg_flag?: bool}> $rows */
        $rows = $query->orderByRaw('last_contact_at DESC NULLS LAST')->orderByDesc('id')
            ->limit($limit)->get($columns)->all();

        // EC-08's disambiguators, one batched read: the identity glyph row
        // (type + value, phone shown national per §14/I08) — plus the
        // FR-01 derivation facts (DisplayName is the one brain).
        /** @var array<string, list<array{type: string, value: string, display: string}>> $identityMap */
        $identityMap = [];
        /** @var array<string, list<array{display: ?string, username: ?string}>> $nameFacts */
        $nameFacts = [];
        $ids = array_map(static fn (object $row): string => (string) $row->id, $rows);
        if ($ids !== []) {
            /** @var list<object{customer_id: string, type: ?string, value: ?string, metadata: ?string}> $identityRows */
            $identityRows = DB::table('customer_identities')->whereIn('customer_id', $ids)
                ->whereNotNull('type')->orderBy('first_seen_at')
                ->get(['customer_id', 'type', 'value', 'metadata'])->all();
            foreach ($identityRows as $identityRow) {
                if (! is_string($identityRow->type) || ! is_string($identityRow->value)) {
                    continue;
                }
                $identityMap[$identityRow->customer_id][] = [
                    'type' => $identityRow->type,
                    'value' => $identityRow->value,
                    'display' => $this->identityDisplay($identityRow->type, $identityRow->value, $identityRow->metadata),
                ];
                $nameFacts[$identityRow->customer_id][] = DisplayName::factsFromMetadata($identityRow->metadata);
            }
        }

        $items = array_map(function (object $row) use ($seesRg, $identityMap, $nameFacts): array {
            $item = [
                'id' => $row->id,
                // FR-01: staff name → channel display → username → the
                // client's "Customer #short" fallback on null.
                'display_name' => DisplayName::derive($row->display_name, $nameFacts[$row->id] ?? []),
                'short_id' => substr($row->id, -4), // EC-08's collision disambiguator
                'is_shell' => (bool) $row->is_shell,
                'identities' => $identityMap[$row->id] ?? [],
                'language' => $row->language,
                'country' => $row->country,
                'tags' => $this->pgTextArray($row->tags),
                'blocked' => (bool) $row->blocked,
                'open_tickets' => (int) $row->open_tickets,
                'tickets_count' => (int) $row->tickets_count,
                'satisfaction_avg' => $row->satisfaction_avg === null ? null : (float) $row->satisfaction_avg,
                'satisfaction_count' => (int) $row->satisfaction_count,
                'last_contact_at' => $row->last_contact_at,
            ];
            if ($seesRg) {
                $item['rg_flag'] = (bool) (get_object_vars($row)['rg_flag'] ?? false);
            }

            return $item;
        }, $rows);

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'next_cursor' => count($rows) === $limit && $last !== null
                ? (($last->{'last_contact_at'} ?? 'null').'|'.$last->{'id'})
                : null,
            'filters' => ['rg_available' => $seesRg],
        ]);
    }

    /** The glyph row's display value: @username for telegram, national form for phone, the raw value otherwise. */
    private function identityDisplay(string $type, string $value, ?string $metadata): string
    {
        if ($type === 'telegram') {
            /** @var array<string, mixed> $meta */
            $meta = $metadata !== null ? (array) json_decode($metadata, true) : [];
            $username = $meta['username'] ?? null;

            return is_string($username) && $username !== '' ? '@'.$username : $value;
        }
        if ($type === 'phone') {
            try {
                return PhoneNumber::national($value);
            } catch (NumberParseException) {
                return $value; // a stored value that predates ADR 0014 normalization renders as stored
            }
        }

        return $value;
    }

    /**
     * pg text[] literal → list<string> (handles quoted elements; tags are
     * plain words by Doc 06's tag machinery, this is belt-and-braces).
     *
     * @return list<string>
     */
    private function pgTextArray(?string $literal): array
    {
        if ($literal === null || $literal === '{}' || $literal === '') {
            return [];
        }

        $parsed = str_getcsv(trim($literal, '{}'), ',', '"', '\\');

        return array_values(array_filter(
            array_map(static fn ($tag): string => trim((string) $tag), $parsed),
            static fn (string $tag): bool => $tag !== '',
        ));
    }
}
