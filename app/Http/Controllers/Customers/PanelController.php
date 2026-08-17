<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Notes\CustomerNotes;
use App\Domain\Customers\Panel\CustomerPayload;
use App\Domain\Customers\Panel\FilesGallery;
use App\Domain\Customers\Profile\CustomerProfile;
use App\Domain\Customers\Rollups\CustomerRollupStatus;
use App\Models\Customer;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * T-07: ONE aggregate endpoint answers the header + identities IMMEDIATELY
 * with section STUBS; every section then fetches independently (FR-21's lazy
 * contract; NFR-03's budget rides the header call). C-10 on every door
 * (404, RLS-shaped); payloads are the CURATED allowlists (CustomerPayload +
 * the identity/section shapes below) — nothing serializes a model.
 *
 * RG discipline: the header rides CustomerPayload's query-level gate; no
 * section carries RG data at all.
 */
final class PanelController
{
    private const SECTIONS = ['rollups', 'recent_tickets', 'betting_fields', 'notes', 'files'];

    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly CustomerPayload $payload,
        private readonly CustomerProfile $profile,
    ) {}

    public function panel(Request $request, string $customerId): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var User $viewer */
        $viewer = $request->user();
        $map = $this->verdicts->mapFor($viewer);

        $header = $this->payload->build($customerId, $map->allows('customers.rg_flag.view'));
        if ($header === null) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, __('customers.panel.not_found'));
        }

        /** @var object{updated_at: string} $stamp */
        $stamp = DB::selectOne('SELECT updated_at::text AS updated_at FROM customers WHERE id = ?', [$customerId]);

        /** @var list<object{id: string, type: ?string, value: ?string, source: ?string, first_seen_at: ?string, metadata: ?string}> $identityRows */
        $identityRows = DB::table('customer_identities')->where('customer_id', $customerId)
            ->orderBy('first_seen_at')
            ->get(['id', 'type', 'value', 'source', DB::raw('first_seen_at::text AS first_seen_at'), 'metadata'])->all();

        $identities = array_map(static function (object $row): array {
            /** @var array<string, mixed> $metadata */
            $metadata = $row->metadata !== null ? (array) json_decode($row->metadata, true) : [];

            // The curated identity shape: provenance ALWAYS rides (FR-05).
            return [
                'id' => $row->id,
                'type' => $row->type,
                'value' => $row->value,
                'source' => $row->source,
                'first_seen_at' => $row->first_seen_at,
                'display' => is_string($metadata['display'] ?? null) ? $metadata['display'] : null,
                'username' => is_string($metadata['username'] ?? null) ? $metadata['username'] : null,
                'username_history' => array_values(array_filter(array_map(
                    static fn ($entry): ?array => is_array($entry) && is_string($entry['old'] ?? null)
                        ? ['old' => $entry['old'], 'changed_at' => $entry['changed_at'] ?? null]
                        : null,
                    is_array($metadata['history'] ?? null) ? $metadata['history'] : [],
                ))),
            ];
        }, $identityRows);

        return new JsonResponse([
            'header' => $header + [
                'updated_at' => $stamp->updated_at, // EC-11's LWW expectation stamp
                'detected_language' => $this->profile->detectedLanguage($customerId), // EC-10's chip data
            ],
            'identities' => $identities,
            // T-07's stubs: names only — each section is its own fetch.
            'sections' => self::SECTIONS,
            // FS-03 / B4-S07 H07 (step 12): the rollup staleness answer —
            // stale only past threshold WITH pending rollup-family work
            // ("the handler is behind", never "nothing happened").
            'rollups_stale' => app(CustomerRollupStatus::class)->status(),
            // FR-21: "every field inline-editable PER PERMISSION" — the
            // render-gates, DERIVED from the existing catalog keys (NFR-03;
            // the T-04 page-capability manifest pattern, served by Doc 08's
            // own door so the workspace shell stays untouched).
            'can' => [
                'edit' => $map->allows('customers.edit'),
                'notes_view' => $map->allows('customers.notes.view'),
                'notes_add' => $map->allows('customers.notes.add'),
                'identity_link' => $map->allows('customers.identity.link'),
                'identity_unlink' => $map->allows('customers.identity.unlink'),
                'merge' => $map->allows('customers.merge'),
                'block' => $map->allows('customers.block'),
                'export' => $map->allows('customers.export'),
            ],
        ]);
    }

    public function recentTickets(string $customerId): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var list<object{id: string, number: int|string, subject: ?string, status: string, created_at: string}> $rows */
        $rows = DB::table('tickets')->where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)
            ->get(['id', 'number', 'subject', 'status', DB::raw('created_at::text AS created_at')])->all();

        return new JsonResponse(['tickets' => array_map(static fn (object $row): array => [
            'id' => $row->id,
            'number' => (string) $row->number,
            'subject' => $row->subject,
            'status' => $row->status,
            'created_at' => $row->created_at,
        ], $rows)]);
    }

    public function notes(Request $request, string $customerId, CustomerNotes $notes): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        return new JsonResponse(['notes' => $notes->listFor($customerId)]);
    }

    public function files(Request $request, string $customerId, FilesGallery $gallery): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{kind?: string, from?: string, to?: string, ticket_id?: string, cursor?: string, limit?: int} $input */
        $input = $request->validate([
            'kind' => ['sometimes', 'string', 'max:20'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'ticket_id' => ['sometimes', 'uuid'],
            'cursor' => ['sometimes', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return new JsonResponse($gallery->listFor(
            $customerId,
            array_intersect_key($input, array_flip(['kind', 'from', 'to', 'ticket_id'])),
            $input['cursor'] ?? null,
            $input['limit'] ?? 50,
        ));
    }

    public function fileUrl(Request $request, string $customerId, string $messageId, FilesGallery $gallery): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{index: int|string} $input */
        $input = $request->validate(['index' => ['required', 'integer', 'min:0', 'max:50']]);

        try {
            // a query-string integer validates but arrives as a string
            return new JsonResponse(['url' => $gallery->signedUrlFor($customerId, $messageId, (int) $input['index'])]);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'media_not_found') {
                return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, __('customers.files.not_found'));
            }

            // Doc 04 SEC-04's guard: non-clean has no download path, no role
            // exception — a stable refusal, never a URL.
            return ProblemDetails::response(ErrorCode::MediaNotServable, 409, __('customers.files.not_servable'));
        }
    }
}
