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

        // B4-S07 H06 (step 12): the erasure status SURFACE — this page
        // reports, it does not decide ([D17] owns the workflow). The audit
        // entry IS the durable record, so the status reads it: the latest
        // initiation, with no reason echoed (the reason lives in the audit
        // trail, behind audit.view).
        /** @var object{id: string, actor_label: ?string, occurred_at: string}|null $erasure */
        $erasure = DB::table('audit_log')
            ->leftJoin('users', 'users.id', '=', 'audit_log.actor_id')
            ->where('audit_log.action_key', 'customer.erasure.initiated')
            ->where('audit_log.entity_id', $customerId)
            ->orderByDesc('audit_log.occurred_at')
            ->first([
                'audit_log.id',
                DB::raw('COALESCE(audit_log.actor_label, users.name) AS actor_label'),
                DB::raw('audit_log.occurred_at::text AS occurred_at'),
            ]);

        return Inertia::render('Customers/Profile', [
            't' => array_merge((array) __('customers.panel'), (array) __('customers.fields'), (array) __('customers.profile'), (array) __('customers.honesty')),
            'customerId' => $customerId,
            'erasure' => $erasure === null ? null : [
                'requested_by' => $erasure->actor_label,
                'requested_at' => $erasure->occurred_at,
                // The drawn reference is a stable handle for "ask compliance
                // about this one" — the audit entry id IS that handle.
                'reference' => 'ERA-'.substr($erasure->id, -8),
            ],
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
