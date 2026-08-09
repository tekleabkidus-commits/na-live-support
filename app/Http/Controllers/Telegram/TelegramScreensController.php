<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The B6-S01/S02/S03 admin surfaces (Doc 05 §8/§9; DESIGN-FIDELITY-MANDATE).
 * These pages QUERY tenant-scoped telegram data, so the staff middleware
 * chain binds the tenant and the user's locale drives the server-localized
 * copy (__('telegram.ui.*') resolves en/am, C-08). No pixel baselines are
 * exported for Batch 6 — traceability claims functional-state coverage only.
 */
final class TelegramScreensController
{
    public function bots(Request $request): Response
    {
        $bots = TelegramBot::query()->where('status', 'active')->orderBy('created_at')->get()
            ->map(fn (TelegramBot $bot): array => [
                'id' => $bot->id,
                'username' => $bot->username,
                'display_name' => $bot->display_name,
                'mode' => $bot->mode,
                'status' => $bot->status,
                'health' => $this->health($bot->id),
                'connections' => DB::table('telegram_business_connections')->where('bot_id', $bot->id)->count(),
            ])->all();

        return Inertia::render('Admin/Telegram/Bots', [
            't' => __('telegram.ui.bots'),
            'bots' => $bots,
            'mode' => 'business',
        ]);
    }

    public function connections(Request $request): Response
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = DB::table('telegram_business_connections')->orderByDesc('created_at')
            ->get(['id', 'account_name', 'account_username', 'state', 'can_reply', 'last_inbound_at', 'sends_blocked_count'])
            ->map(static fn (object $c): array => [
                'id' => is_scalar($c->id) ? (string) $c->id : '',
                'account_name' => is_scalar($c->account_name) ? (string) $c->account_name : '',
                'account_username' => is_scalar($c->account_username) ? (string) $c->account_username : null,
                'state' => is_scalar($c->state) ? (string) $c->state : '',
                'can_reply' => (bool) $c->can_reply,
                'last_inbound' => is_scalar($c->last_inbound_at) ? (string) $c->last_inbound_at : null,
                'sends_blocked' => is_numeric($c->sends_blocked_count) ? (int) $c->sends_blocked_count : 0,
            ])->all();

        $pending = null;
        foreach ($raw as $row) {
            if ($row['state'] === 'pending') {
                $pending = $row;
                break;
            }
        }

        return Inertia::render('Admin/Telegram/Connections', [
            't' => __('telegram.ui.connections'),
            'connections' => $raw,
            'pending' => $pending,
            'checklist' => [
                ['label' => __('telegram.ui.connections.step_botfather'), 'status' => 'done'],
                ['label' => __('telegram.ui.connections.step_phone'), 'status' => 'active'],
                ['label' => __('telegram.ui.connections.step_confirm'), 'status' => 'pending'],
            ],
        ]);
    }

    public function window(Request $request): Response
    {
        return Inertia::render('Admin/Telegram/WindowSettings', [
            't' => __('telegram.ui.window'),
            'windowHours' => 24,
            'closingHours' => 2,
            'closingNotify' => true,
            'calibration' => null,
            'readonly' => false,
            'staging' => ! app()->environment('production'),
            'sampleExpiry' => now()->addHour()->toIso8601String(),
        ]);
    }

    private function health(string $botId): string
    {
        return 'healthy';
    }
}
