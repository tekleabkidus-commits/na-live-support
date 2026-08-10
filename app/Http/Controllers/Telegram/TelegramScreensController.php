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
                // FR-29: the real rollup column (step 11 replaced the stub).
                'health' => is_string($h = $bot->getAttribute('health_status')) ? $h : 'healthy',
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

    /** B6-S04 (E01–E06, E12–E13): the menu designer. */
    public function menu(Request $request): Response
    {
        $bot = TelegramBot::query()->where('status', 'active')->orderBy('created_at')->firstOrFail();

        $menu = DB::table('telegram_menus')->where('bot_id', $bot->id)->orderBy('language')->first();
        /** @var list<array{row: int, buttons: list<array{label: string, label_am: string|null, target_key: string}>}> $definition */
        $definition = [];
        if ($menu !== null && is_string($menu->draft ?? null)) {
            /** @var list<array{row: int, buttons: list<array{label: string, label_am: string|null, target_key: string}>}> $decoded */
            $decoded = json_decode($menu->draft, true) ?: [];
            $definition = $decoded;
        }

        $versions = $menu === null ? [] : DB::table('telegram_menu_versions')
            ->where('menu_id', $menu->id)->orderByDesc('version')
            ->get(['id', 'version', 'created_at'])
            ->map(static fn (object $v): array => [
                'version' => is_numeric($v->version) ? (int) $v->version : 0,
                'live' => is_string($menu->active_version_id ?? null) && $v->id === $menu->active_version_id,
                'at' => is_scalar($v->created_at) ? (string) $v->created_at : '',
            ])->all();

        $targets = DB::table('telegram_flow_targets')->where('bot_id', $bot->id)
            ->get(['key', 'target_type', 'target_ref'])
            ->map(static fn (object $t): array => [
                'key' => is_scalar($t->key) ? (string) $t->key : '',
                'type' => is_scalar($t->target_type) ? (string) $t->target_type : '',
                'ref' => is_scalar($t->target_ref) ? (string) $t->target_ref : null,
            ])->all();

        $total = 0;
        $missingAm = 0;
        foreach ($definition as $row) {
            foreach ($row['buttons'] as $button) {
                $total++;
                if (($button['label_am'] ?? null) === null) {
                    $missingAm++;
                }
            }
        }

        return Inertia::render('Admin/Telegram/MenuDesigner', [
            't' => __('telegram.ui.menu'),
            'bot' => ['username' => $bot->username],
            'definition' => $definition,
            'versions' => $versions,
            'targets' => $targets,
            'buttonsUsed' => $total,
            'amComplete' => $total - $missingAm,
            'amTotal' => $total,
            'readonly' => false, // E13: the CLIENT decides by viewport (≥1024 rule); server ships data either way
        ]);
    }

    /** B6-S05 (E07–E11): templates, variables, commands, the /language gate. */
    public function templates(Request $request): Response
    {
        $bot = TelegramBot::query()->where('status', 'active')->orderBy('created_at')->firstOrFail();

        /** @var array<string, array<string, array{body: string, edited: bool}>> $byLanguage */
        $byLanguage = [];
        foreach (DB::table('telegram_templates')->where('bot_id', $bot->id)->get(['language', 'template_key', 'body', 'updated_by']) as $t) {
            if (! is_scalar($t->language) || ! is_scalar($t->template_key)) {
                continue;
            }
            $byLanguage[(string) $t->language][(string) $t->template_key] = [
                'body' => is_scalar($t->body) ? (string) $t->body : '',
                'edited' => $t->updated_by !== null,
            ];
        }

        $required = \App\Domain\Telegram\Direct\TemplateResolver::REQUIRED_KEYS;
        $offered = app(\App\Domain\Telegram\Direct\CommandHandler::class)->availableLanguages($bot->id);

        return Inertia::render('Admin/Telegram/Templates', [
            't' => __('telegram.ui.templates'),
            'bot' => ['username' => $bot->username],
            'requiredKeys' => $required,
            'templates' => $byLanguage,
            'offeredLanguages' => $offered,
            'commands' => ['start', 'menu', 'status', 'human', 'language'],
            'variables' => ['first_name', 'queue_wait'],
        ]);
    }

    /** B6-S06 (F01–F12): the health panel for one bot (default: worst first). */
    public function health(Request $request, ?string $botId = null): Response
    {
        $bot = $botId !== null
            ? TelegramBot::query()->findOrFail($botId)
            : TelegramBot::query()->where('status', 'active')
                ->orderByRaw("CASE health_status WHEN 'down' THEN 0 WHEN 'degraded' THEN 1 ELSE 2 END")
                ->orderBy('created_at')->firstOrFail();
        /** @var \App\Domain\Telegram\Health\HealthRollup $rollup */
        $rollup = app(\App\Domain\Telegram\Health\HealthRollup::class);

        $connections = DB::table('telegram_business_connections')->where('bot_id', $bot->id)
            ->get(['account_name', 'account_username', 'state', 'can_reply', 'last_inbound_at', 'sends_blocked_count'])
            ->map(static fn (object $c): array => [
                'account_name' => is_scalar($c->account_name) ? (string) $c->account_name : '',
                'state' => is_scalar($c->state) ? (string) $c->state : '',
                'can_reply' => (bool) $c->can_reply,
                'last_inbound' => is_scalar($c->last_inbound_at) ? (string) $c->last_inbound_at : null,
                'sends_blocked' => is_numeric($c->sends_blocked_count) ? (int) $c->sends_blocked_count : 0,
            ])->all();

        /** @var \App\Support\Settings\SettingsRepository $settings */
        $settings = app(\App\Support\Settings\SettingsRepository::class);

        return Inertia::render('Admin/Telegram/Health', [
            't' => __('telegram.ui.health'),
            'bot' => [
                'id' => $bot->id,
                'username' => $bot->username,
                'health_status' => is_string($hs = $bot->getAttribute('health_status')) ? $hs : 'healthy',
                'health_changed_at' => $this->iso($bot->getAttribute('health_changed_at')),
                'pending_updates' => is_numeric($pu = $bot->getAttribute('webhook_pending_updates')) ? (int) $pu : 0,
                'last_error' => $bot->getAttribute('webhook_last_error'),
                'last_inbound_at' => $this->iso($bot->getAttribute('last_inbound_at')),
                'last_polled_at' => $this->iso($bot->getAttribute('last_polled_at')),
                'has_token' => is_string($bot->bot_token), // F11: never the token itself (SEC-01)
                'webhook_path' => is_string($bot->key_id) ? '/hooks/telegram/…' : null,
            ],
            'metrics' => $rollup->panelMetrics($bot->id),
            'hourly' => $this->hourlyBuckets($bot->id),
            'connections' => $connections,
            'rate' => [
                'bot_per_second' => $settings->get('telegram.rate.bot_per_second'),
                'chat_per_second' => $settings->get('telegram.rate.chat_per_second'),
                'bot_max' => 30,
                'chat_max' => 1,
            ],
            'thresholds' => [
                'degraded_pct' => $settings->get('telegram.health.degraded_failure_pct'),
                'down_pct' => $settings->get('telegram.health.down_failure_pct'),
                'silent_minutes' => $settings->get('telegram.health.silent_inbound_minutes'),
            ],
            'staging' => ! app()->environment('production'),
            'updates' => app(\App\Domain\Telegram\Health\RawUpdateLog::class)->read($bot->id),
        ]);
    }

    private function iso(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * F01's 12-hour in/out chart buckets, DB-time bucketing (C-03/R-19).
     *
     * @return list<array{hour: string, in: int, out: int}>
     */
    private function hourlyBuckets(string $botId): array
    {
        /** @var list<object{bucket: string, direction: string, n: int}> $rows */
        $rows = DB::select(
            "SELECT to_char(date_trunc('hour', m.created_at), 'HH24:00') AS bucket, m.direction, count(*) AS n
               FROM messages m
               JOIN conversations c ON c.id = m.conversation_id
              WHERE c.channel = 'telegram' AND c.channel_ref_id = ?
                AND m.created_at > clock_timestamp() - interval '12 hours'
              GROUP BY 1, 2",
            [$botId],
        );

        /** @var array<string, array<string, int>> $byHour */
        $byHour = [];
        foreach ($rows as $r) {
            $byHour[$r->bucket][$r->direction] = (int) $r->n;
        }

        $out = [];
        foreach ($byHour as $hour => $counts) {
            $out[] = ['hour' => $hour, 'in' => $counts['in'] ?? 0, 'out' => $counts['out'] ?? 0];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['hour'], $b['hour']));

        return $out;
    }
}
