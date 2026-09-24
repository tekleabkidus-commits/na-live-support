<?php

declare(strict_types=1);

namespace App\Domain\Sms\Broadcasts;

use App\Domain\Sms\Templates\TemplateRefused;
use App\Jobs\Sms\BroadcastChunkJob;
use App\Models\SmsBroadcast;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * FR-17 — the broadcast lifecycle: draft → (scheduled) → sending →
 * done | canceled, the machine being the state CHECK + the DB triggers
 * (launched_at / canceled_at / completed_at / monthly_bucket are the
 * clock's, app-DENIED). The VERSION IS PINNED at draft (EC-06: a template
 * republished after the pin is a NOTICE — pinned ≠ live — never a silent
 * swap; only a deliberate re-pin updates it); only a `service_announcement`
 * template is broadcast-capable (BR-02 — the writer refuses first, the DB
 * trigger is the physics behind it). The preview count comes from THE SAME
 * AudienceQuery the chunker executes (AC-06's honesty). LAUNCH is A-64's
 * high door (`sms.send` AND `sms.gateways.manage`, enforced at the route):
 * the typed confirm is required at ≥ 1,000 recipients; the tenant monthly
 * cap and the platform ceiling count DB-stamped `monthly_bucket` rows in
 * the tenant's own month (C-11).
 */
final class BroadcastWriter
{
    public const TYPED_CONFIRM_THRESHOLD = 1000;

    public function __construct(
        private readonly AudienceQuery $audience,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly SettingsRepository $settings,
    ) {}

    /** @param array<string, mixed> $filters */
    public function create(string $name, string $templateId, array $filters, User $creator): SmsBroadcast
    {
        /** @var SmsTemplate|null $template */
        $template = SmsTemplate::query()->whereNull('deleted_at')->find($templateId);
        if ($template === null || $template->event !== 'service_announcement') {
            throw new TemplateRefused('broadcast_template_refused', 'only a service_announcement template is broadcast-capable (BR-02)');
        }
        if ($template->live_version_id === null) {
            throw new TemplateRefused('broadcast_template_unpublished', 'a broadcast pins a PUBLISHED version');
        }

        $broadcast = new SmsBroadcast;

        return DB::transaction(function () use ($broadcast, $name, $template, $filters, $creator): SmsBroadcast {
            $broadcast->fill([
                'name' => $name,
                'template_id' => $template->identifier(),
                'template_version_id' => $template->live_version_id,   // THE PIN (EC-06)
                'audience_filter' => $filters,
                'audience_count_preview' => $this->audience->count($filters, $creator),
                'state' => 'draft',
            ]);
            $broadcast->save();
            $this->audit->record('sms.broadcast.created', 'sms_broadcast', $broadcast->identifier(), AuditActor::Staff, ['broadcast_id' => $broadcast->identifier(), 'template_id' => $template->identifier()], $creator->identifier());

            return $broadcast;
        });
    }

    /** @param array<string, mixed> $attributes name|audience_filter|scheduled_at|repin (bool: re-pin to the template's CURRENT live version, deliberately) */
    public function update(SmsBroadcast $broadcast, array $attributes, User $actor): SmsBroadcast
    {
        if (! in_array($broadcast->state, ['draft', 'scheduled'], true)) {
            throw new TemplateRefused('broadcast_not_editable', 'only a draft or scheduled broadcast edits');
        }
        $fill = [];
        if (is_string($attributes['name'] ?? null)) {
            $fill['name'] = $attributes['name'];
        }
        if (is_array($attributes['audience_filter'] ?? null)) {
            /** @var array<string, mixed> $newFilters */
            $newFilters = $attributes['audience_filter'];
            $fill['audience_filter'] = $newFilters;
            $fill['audience_count_preview'] = $this->audience->count($newFilters, $actor);
        }
        if (array_key_exists('scheduled_at', $attributes)) {
            $fill['scheduled_at'] = is_string($attributes['scheduled_at']) ? $attributes['scheduled_at'] : null;
            $fill['state'] = $fill['scheduled_at'] === null ? 'draft' : 'scheduled';
        }
        if (($attributes['repin'] ?? null) === true) {
            /** @var SmsTemplate $template */
            $template = SmsTemplate::query()->findOrFail($broadcast->template_id);
            $fill['template_version_id'] = $template->live_version_id; // EC-06: the DELIBERATE re-pin
        }

        return DB::transaction(function () use ($broadcast, $fill, $actor): SmsBroadcast {
            $broadcast->fill($fill)->save();
            $this->audit->record('sms.broadcast.updated', 'sms_broadcast', $broadcast->identifier(), AuditActor::Staff, ['broadcast_id' => $broadcast->identifier(), 'fields' => array_keys($fill)], $actor->identifier());

            return $broadcast->refresh();
        });
    }

    /** EC-06's notice data: the pin vs the template's CURRENT live version. */
    public function pinNotice(SmsBroadcast $broadcast): ?string
    {
        $live = SmsTemplate::query()->whereKey($broadcast->template_id)->value('live_version_id');

        return is_string($live) && $live !== $broadcast->template_version_id ? $live : null;
    }

    public function launch(SmsBroadcast $broadcast, User $launcher, ?string $typedConfirmation): SmsBroadcast
    {
        if (! in_array($broadcast->state, ['draft', 'scheduled'], true)) {
            throw new TemplateRefused('broadcast_not_launchable', 'already launched, done or canceled');
        }

        $total = $this->audience->count($this->filters($broadcast), $launcher);
        if ($total >= self::TYPED_CONFIRM_THRESHOLD && $typedConfirmation !== $broadcast->name) {
            throw new TemplateRefused('broadcast_confirmation_required', sprintf('an audience of %d needs the broadcast name typed back exactly', $total), ['audience_count' => (string) $total]);
        }

        $cap = $this->effectiveMonthlyCap();
        $launchedThisMonth = $this->launchedThisTenantMonth();
        if ($launchedThisMonth >= $cap) {
            throw new TemplateRefused('broadcast_monthly_cap', sprintf('the monthly cap is %d and %d have launched this month', $cap, $launchedThisMonth), ['cap' => (string) $cap]);
        }

        return DB::transaction(function () use ($broadcast, $launcher, $total, $typedConfirmation): SmsBroadcast {
            $broadcast->fill(['state' => 'sending', 'total' => $total, 'launched_by' => $launcher->identifier()]);
            $broadcast->save();
            $this->audit->record('sms.broadcast.launched', 'sms_broadcast', $broadcast->identifier(), AuditActor::Staff, [
                'broadcast_id' => $broadcast->identifier(),
                'audience_count' => $total,
                'typed_confirmation_given' => $typedConfirmation !== null,
            ], $launcher->identifier());
            $this->events->publish('sms.broadcast.started', $this->eventPayload($broadcast->refresh(), $launcher->identifier()));
            BroadcastChunkJob::dispatch($broadcast->identifier(), null);

            return $broadcast;
        });
    }

    public function cancel(SmsBroadcast $broadcast, User $actor): SmsBroadcast
    {
        if (! in_array($broadcast->state, ['scheduled', 'sending', 'paused_quota'], true)) {
            throw new TemplateRefused('broadcast_not_cancelable', 'only a scheduled, sending or paused broadcast cancels');
        }

        return DB::transaction(function () use ($broadcast, $actor): SmsBroadcast {
            $remaining = max(0, $broadcast->total - $broadcast->sent - $broadcast->skipped - $broadcast->failed);
            // the raw DB-time stamp rides the query builder (Eloquent casts choke on an Expression); the
            // trigger stamps canceled_at from the state change in the same UPDATE.
            DB::table('sms_broadcasts')->where('id', $broadcast->getKey())->update(['state' => 'canceled', 'cancel_requested_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()')]);
            $broadcast->refresh();
            $this->audit->record('sms.broadcast.canceled', 'sms_broadcast', $broadcast->identifier(), AuditActor::Staff, ['broadcast_id' => $broadcast->identifier(), 'remaining' => $remaining], $actor->identifier());
            $this->events->publish('sms.broadcast.canceled', $this->eventPayload($broadcast, $actor->identifier()));

            return $broadcast;
        });
    }

    /** @return array<string, mixed> */
    public function filters(SmsBroadcast $broadcast): array
    {
        /** @var array<string, mixed> $filters the model casts this jsonb column to array */
        $filters = $broadcast->audience_filter;

        return $filters;
    }

    /** @return array<string, mixed> the CLOSED sms.broadcast.* payload */
    public function eventPayload(SmsBroadcast $broadcast, ?string $actorId): array
    {
        return [
            'tenant_id' => $broadcast->tenant_id,
            'broadcast_id' => $broadcast->identifier(),
            'total' => (int) $broadcast->total,
            'sent' => (int) $broadcast->sent,
            'skipped' => (int) $broadcast->skipped,
            'failed' => (int) $broadcast->failed,
            'cost_accrued' => (string) $broadcast->cost_accrued,
            'actor_id' => $actorId,
        ];
    }

    private function effectiveMonthlyCap(): int
    {
        $cap = $this->settings->get('sms.broadcast.monthly_cap');
        $ceiling = $this->settings->get('sms.broadcast.platform_ceiling');

        return min(is_int($cap) ? $cap : 4, is_int($ceiling) ? $ceiling : 30);
    }

    /** DB-stamped buckets in the tenant's OWN month, from DB time (C-11). */
    private function launchedThisTenantMonth(): int
    {
        /** @var object{n: int} $row */
        $row = DB::selectOne(
            "SELECT count(*) AS n FROM sms_broadcasts b
              WHERE b.monthly_bucket = to_char(clock_timestamp() AT TIME ZONE (SELECT timezone_name FROM tenants WHERE id = b.tenant_id), 'YYYY-MM')",
        );

        return (int) $row->n;
    }
}
