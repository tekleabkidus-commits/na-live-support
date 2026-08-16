<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FR-20's row click lands here: the full profile page (B4-S02 — the same
 * panel content, wider, plus the action surfaces) — and BR-05's law:
 * a MERGED profile's URL keeps working FOREVER. It renders the tombstone
 * page (§9's real surface: merged-into link + the undo button when
 * in-window and permitted), never a 404 — old ticket links in emails and
 * notes must keep resolving. Sabotage #13 teaches this door to 404 a
 * tombstone and TombstoneTest goes red.
 */
final class ProfilePageController
{
    public function show(Request $request, string $customerId): Response
    {
        /** @var Customer $customer */
        $customer = Customer::query()->findOrFail($customerId); // C-10: missing/cross-tenant = 404

        /** @var User $viewer */
        $viewer = $request->user();
        $map = app(CapabilityMapProvider::class)->mapFor($viewer);

        $mergedInto = $customer->getAttribute('merged_into_id');
        if (is_string($mergedInto)) {
            // BR-05: the tombstone RESOLVES. The undo affordance renders only
            // in-window and permitted (§9) — the countdown facts are
            // SERVER-computed stamps, never client arithmetic.
            /** @var object{id: string, expires_at: string, undone_at: ?string}|null $snapshot */
            $snapshot = DB::selectOne(
                'SELECT id, expires_at::text AS expires_at, undone_at::text AS undone_at
                   FROM customer_merge_snapshots
                  WHERE merged_id = ? AND undone_at IS NULL AND expires_at > clock_timestamp()
                  ORDER BY created_at DESC LIMIT 1',
                [$customerId],
            );
            /** @var ?string $survivorName */
            $survivorName = DB::table('customers')->where('id', $mergedInto)->value('display_name');

            return Inertia::render('Customers/Tombstone', [
                't' => __('customers.tombstone'),
                'customerId' => $customerId,
                'survivorId' => $mergedInto,
                'survivorName' => $survivorName,
                'snapshotId' => $snapshot->id ?? null,
                'expiresAt' => $snapshot->expires_at ?? null,
                'canUndo' => $snapshot !== null && $map->allows('customers.merge'),
            ]);
        }

        return Inertia::render('Customers/Profile', [
            't' => array_merge((array) __('customers.panel'), (array) __('customers.fields'), (array) __('customers.profile')),
            'customerId' => $customerId,
            'can' => [
                'edit' => $map->allows('customers.edit'),
                'notes_view' => $map->allows('customers.notes.view'),
                'notes_add' => $map->allows('customers.notes.add'),
                'identity_link' => $map->allows('customers.identity.link'),
                'identity_unlink' => $map->allows('customers.identity.unlink'),
                'merge' => $map->allows('customers.merge'),
                'block' => $map->allows('customers.block'),
                'rg_set' => $map->allows('customers.rg_flag.set'),
                'rg_view' => $map->allows('customers.rg_flag.view'),
                'export' => $map->allows('customers.export'),
                'delete' => $map->allows('customers.delete'),
                // [D17]: the Audit tab renders its seam for audit.view holders.
                'audit_view' => $map->allows('audit.view'),
            ],
        ]);
    }
}
