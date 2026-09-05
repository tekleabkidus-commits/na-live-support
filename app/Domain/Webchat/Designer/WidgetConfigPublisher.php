<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Designer;

use App\Domain\Webchat\Config\WidgetConfigReader;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Events\Webchat\WidgetConfigPush;
use App\Models\Widget;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Theme\ContrastValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Doc 13 FR-20 / NFR-04 / EC-11 (S13 step 10): versioned publishes with instant rollback.
 *
 *  - publish  = validate → the CONTRAST HARD GATE (A-62: refuses with the ratio and the
 *               suggested shade; NO override parameter exists on any method here) →
 *               INSERT a version row + ONE UPDATE of the widget's live pointer (the
 *               previous row becomes `superseded`) → audit `widget.config.published` →
 *               the push on the public per-widget config channel (open panels re-read;
 *               new loads see the shim's ≤ 60 s cache; the 5-min poll is the floor).
 *  - rollback = a NEW version whose config is copied from the chosen old row (history is
 *               append-only; the rolled-back row is marked) → audit `widget.config.rolled_back`.
 *
 * The published object is the ONE object that previews AND publishes (B3-S08): the
 * Designer previews the same array this class validates.
 */
final class WidgetConfigPublisher
{
    /** The surfaces a brand accent must read on: B1-DS-v2's light panel (text threshold) and dark panel (UI threshold). */
    public const SURFACES = [
        'light' => ['bg' => '#ffffff', 'min' => ContrastValidator::AA_TEXT],
        'dark' => ['bg' => '#1c1c19', 'min' => ContrastValidator::AA_LARGE],
    ];

    public const FONTS = ['inter_noto_ethiopic', 'noto_sans_ethiopic', 'system_ethiopic'];

    public const ICONS = ['chat', 'sparkle', 'question', 'upload'];

    public function __construct(
        private readonly ContrastValidator $contrast,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Validate a draft the way publish will — the Designer's inline refusal at edit time.
     *
     * @param  array<string, mixed>  $config
     * @return array{config: array<string, mixed>, warnings: list<string>}
     *
     * @throws ConfigRefused
     */
    public function validate(array $config): array
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(WidgetConfigReader::defaults(), $config);
        $validator = Validator::make($merged, [
            'appearance.position' => ['required', 'in:bottom_right,bottom_left'],
            'appearance.offset.side' => ['required', 'integer', 'between:0,200'],
            'appearance.offset.bottom' => ['required', 'integer', 'between:0,200'],
            'appearance.theme' => ['required', 'in:light,dark,auto'],
            'appearance.font' => ['required', 'in:'.implode(',', self::FONTS)],
            'appearance.launcher_icon' => ['required', 'string', 'max:120'],
            'appearance.brand' => ['nullable', 'array'],
            'appearance.brand.accent' => ['required_with:appearance.brand', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'content.welcome' => ['array'],
            'content.welcome.*' => ['nullable', 'string', 'max:300'],
            'content.shortcuts' => ['array', 'max:8'],
            'content.shortcuts.*.kind' => ['required', 'in:article,form,chat,url'],
            'content.shortcuts.*.label' => ['required', 'array'],
            'content.shortcuts.*.label.*' => ['nullable', 'string', 'max:80'],
            'content.shortcuts.*.target' => ['required', 'string', 'max:500'],
            'content.tabs.home' => ['required', 'boolean'],
            'content.tabs.help' => ['required', 'boolean'],
            'content.default_language' => ['nullable', 'in:en,am'],
            'content.pre_chat_form_id' => ['nullable', 'uuid'],
            'content.pre_chat_mode' => ['required', 'in:off,optional,required'],
            'content.offline_form_id' => ['nullable', 'uuid'],
            'behavior.hours_source' => ['required', 'string', 'regex:/^(tenant|department:[0-9a-f-]{36})$/'],
            'behavior.file_upload' => ['required', 'boolean'],
            'behavior.sound' => ['nullable', 'boolean'],
            'behavior.csat' => ['required', 'boolean'],
            'behavior.proactive' => ['array', 'max:10'],
            'behavior.proactive.*.id' => ['required', 'string', 'max:60'],
            'behavior.proactive.*.condition.kind' => ['required', 'in:url,time,exit,capture'],
            'behavior.proactive.*.action.kind' => ['required', 'in:message'],
            'behavior.proactive.*.action.message' => ['required', 'array'],
            'behavior.proactive.*.action.message.*' => ['nullable', 'string', 'max:300'],
            'behavior.context_allowlist' => ['array', 'max:20'],
            'behavior.context_allowlist.*' => ['string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
        ]);
        if ($validator->fails()) {
            throw new ConfigRefused('invalid_config', 'the configuration does not validate', ['errors' => $validator->errors()->toArray()]);
        }
        $iconRaw = data_get($merged, 'appearance.launcher_icon');
        $icon = is_string($iconRaw) ? $iconRaw : '';
        if (! in_array($icon, self::ICONS, true) && ! str_starts_with($icon, 'upload:')) {
            throw new ConfigRefused('invalid_config', 'the launcher icon is not a preset or an uploaded asset', ['errors' => ['appearance.launcher_icon' => ['invalid']]]);
        }
        $this->contrastGate($merged);

        return ['config' => $merged, 'warnings' => $this->warnings($merged)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{version_id: string, version: int, warnings: list<string>}
     */
    public function publish(Widget $widget, array $config, string $actorId, ?string $note = null): array
    {
        $validated = $this->validate($config);
        $widgetId = VisitorConversation::widgetId($widget);
        $row = DB::transaction(function () use ($widget, $widgetId, $validated, $actorId, $note): array {
            DB::table('widgets')->where('id', $widgetId)->lockForUpdate()->first(['id']); // serialize publishes per widget (Postgres: no FOR UPDATE on an aggregate)
            $max = DB::table('widget_config_versions')->where('widget_id', $widgetId)->max('version');
            $version = (is_numeric($max) ? (int) $max : 0) + 1;
            $id = (string) Str::uuid7();
            DB::table('widget_config_versions')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(), 'widget_id' => $widgetId, 'version' => $version,
                'config' => json_encode($validated['config'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'published_by' => $actorId, 'note' => $note !== null ? mb_substr($note, 0, 200) : null,
            ]);
            // History is APPEND-ONLY by grant (the app role holds INSERT + SELECT on widget_config_versions,
            // schema amendment 7): a row's status is never rewritten — `history()` derives superseded /
            // rolled_back from the pointer and the rollback rows' notes.
            $widget->forceFill(['live_version_id' => $id])->save(); // the ONE pointer update
            $this->audit->record('widget.config.published', 'widget', $widgetId, AuditActor::Staff, [
                'widget_id' => $widgetId, 'version_id' => $id, 'version' => $version, 'note' => $note,
            ], actorId: $actorId);

            return ['version_id' => $id, 'version' => $version];
        });
        WidgetConfigPush::dispatch($widgetId, $row['version'], $row['version_id']);

        return $row + ['warnings' => $validated['warnings']];
    }

    /**
     * @return array{version_id: string, version: int}
     */
    public function rollback(Widget $widget, string $toVersionId, string $actorId): array
    {
        $widgetId = VisitorConversation::widgetId($widget);
        /** @var object{id: string, version: int|string, config: string}|null $old */
        $old = DB::table('widget_config_versions')->where('widget_id', $widgetId)->where('id', $toVersionId)->first(['id', 'version', 'config']);
        if ($old === null) {
            throw new ConfigRefused('version_not_found', 'that version does not belong to this widget');
        }
        if ($widget->live_version_id === $old->id) {
            throw new ConfigRefused('already_live', 'that version is already live');
        }
        /** @var array<string, mixed> $config */
        $config = json_decode($old->config, true, 64, JSON_THROW_ON_ERROR);
        $fromRaw = is_string($widget->live_version_id) ? DB::table('widget_config_versions')->where('id', $widget->live_version_id)->value('version') : null;
        $fromVersion = is_numeric($fromRaw) ? (int) $fromRaw : 0;
        // A rollback is a NEW version (history is append-only); the rolled-back row is marked so the history reads true.
        $row = DB::transaction(function () use ($widget, $widgetId, $config, $old, $fromVersion, $actorId): array {
            DB::table('widgets')->where('id', $widgetId)->lockForUpdate()->first(['id']); // serialize publishes per widget (Postgres: no FOR UPDATE on an aggregate)
            $max = DB::table('widget_config_versions')->where('widget_id', $widgetId)->max('version');
            $version = (is_numeric($max) ? (int) $max : 0) + 1;
            $id = (string) Str::uuid7();
            DB::table('widget_config_versions')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(), 'widget_id' => $widgetId, 'version' => $version,
                'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'published_by' => $actorId, 'note' => 'rollback:'.$old->version,
            ]);
            $widget->forceFill(['live_version_id' => $id])->save(); // the row that was live is derived rolled_back by the note below
            $this->audit->record('widget.config.rolled_back', 'widget', $widgetId, AuditActor::Staff, [
                'widget_id' => $widgetId, 'from_version' => $fromVersion, 'to_version' => (int) $old->version, 'new_version_id' => $id,
            ], actorId: $actorId);

            return ['version_id' => $id, 'version' => $version];
        });
        WidgetConfigPush::dispatch($widgetId, $row['version'], $row['version_id']);

        return $row;
    }

    /**
     * @return list<array{id: string, version: int, status: string, note: ?string, published_by: string, published_at: string}>
     */
    public function history(Widget $widget, int $limit = 20): array
    {
        $rows = DB::table('widget_config_versions')->where('widget_id', VisitorConversation::widgetId($widget))
            ->orderByDesc('version')->limit(max(1, min(100, $limit)))->get(['id', 'version', 'status', 'note', 'published_by', 'published_at']);
        $out = [];
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        // Derived status (append-only rows): the pointer is live; a rollback row (note `rollback:<n>`) marks the
        // version just before it — the one that was live at that moment — rolled_back; everything else is superseded.
        $rolledBack = [];
        foreach ($rows as $r) {
            if (is_string($r->note) && str_starts_with($r->note, 'rollback:') && is_numeric($r->version)) {
                $rolledBack[(int) $r->version - 1] = true;
            }
        }
        foreach ($rows as $r) {
            $version = is_numeric($r->version) ? (int) $r->version : 0;
            $status = $str($r->id) === $widget->live_version_id ? 'live' : (isset($rolledBack[$version]) ? 'rolled_back' : 'superseded');
            $out[] = ['id' => $str($r->id), 'version' => $version, 'status' => $status, 'note' => is_string($r->note) ? $r->note : null, 'published_by' => $str($r->published_by), 'published_at' => $str($r->published_at)];
        }

        return $out;
    }

    /**
     * A-62 HARD GATE: the brand accent must read on both colourways. Refused with the
     * ratio and the nearest passing shade of the same hue — and there is no argument
     * on this method, on publish() or on validate() that skips it.
     *
     * @param  array<string, mixed>  $merged
     */
    private function contrastGate(array $merged): void
    {
        $brand = data_get($merged, 'appearance.brand');
        if (! is_array($brand)) {
            return; // the neutral default tokens — proven AA by the design system itself
        }
        $raw = $brand['accent'] ?? '';
        $accent = ContrastValidator::normalize(is_string($raw) ? $raw : '');
        foreach (self::SURFACES as $name => $surface) {
            $verdict = $this->contrast->check($accent, $surface['bg']);
            if ($verdict->ratio < $surface['min']) {
                throw new ConfigRefused('contrast', 'the brand colour cannot be published', [
                    'surface' => $name, 'accent' => $accent, 'on' => $surface['bg'],
                    'ratio' => round($verdict->ratio, 2), 'required' => $surface['min'],
                    'suggestion' => $this->contrast->suggest($accent, $surface['bg'], $surface['min']),
                    'suggestion_ratio' => round($this->contrast->check($this->contrast->suggest($accent, $surface['bg'], $surface['min']), $surface['bg'])->ratio, 2),
                ]);
            }
        }
    }

    /**
     * J04: Amharic is offered only when every string is filled — reported, never refused.
     *
     * @param  array<string, mixed>  $merged
     * @return list<string>
     */
    private function warnings(array $merged): array
    {
        $warnings = [];
        $welcome = data_get($merged, 'content.welcome');
        $shortcuts = data_get($merged, 'content.shortcuts');
        $needsAm = is_array($welcome) && array_key_exists('am', $welcome);
        $amMissing = is_array($welcome) && (($welcome['am'] ?? '') === '') && $needsAm;
        if (is_array($shortcuts)) {
            foreach ($shortcuts as $s) {
                $label = is_array($s) && is_array($s['label'] ?? null) ? $s['label'] : [];
                if (array_key_exists('en', $label) && (($label['am'] ?? '') === '')) {
                    $amMissing = true;
                }
            }
        }
        if ($amMissing) {
            $warnings[] = 'am_incomplete';
        }

        return $warnings;
    }
}
