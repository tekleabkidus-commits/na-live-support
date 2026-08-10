<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-user inbox preferences (S07 step 4 lands the FR-08 view-order half;
 * FR-01 region sizes / §9 density / FR-27 sounds ride the same blob at their
 * steps). One jsonb document per user, last-writer-wins, merged per key —
 * BR-05: these are the user's own choices; nothing here is a tenant setting.
 */
final class PrefsController extends Controller
{
    private const KEYS = ['view_order', 'regions', 'density', 'sounds', 'mute_until', 'coach_marks', 'sort_overrides', 'recent_searches'];

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var string|null $prefs */
        $prefs = DB::table('inbox_user_prefs')->where('user_id', $user->identifier())->value('prefs');

        return new JsonResponse(['prefs' => $prefs !== null ? json_decode($prefs, true, 16, JSON_THROW_ON_ERROR) : (object) []]);
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'prefs' => ['required', 'array'],
        ]);

        /** @var array<string, mixed> $incoming */
        $incoming = $input['prefs'];
        if (array_diff(array_keys($incoming), self::KEYS) !== []) {
            return new JsonResponse(['ok' => false, 'unknown_keys' => array_values(array_diff(array_keys($incoming), self::KEYS))], 422);
        }

        /** @var User $user */
        $user = $request->user();

        /** @var string|null $current */
        $current = DB::table('inbox_user_prefs')->where('user_id', $user->identifier())->value('prefs');
        /** @var array<string, mixed> $merged */
        $merged = array_merge(
            $current !== null ? (array) json_decode($current, true, 16, JSON_THROW_ON_ERROR) : [],
            $incoming,
        );

        DB::table('inbox_user_prefs')->upsert(
            [[
                'id' => (string) Str::uuid7(),
                'tenant_id' => $context->tenantId(),
                'user_id' => $user->identifier(),
                'prefs' => json_encode($merged, JSON_THROW_ON_ERROR),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]],
            ['tenant_id', 'user_id'],
            ['prefs', 'updated_at'],
        );

        return new JsonResponse(['ok' => true]);
    }
}
