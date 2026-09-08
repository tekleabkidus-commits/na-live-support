<?php

declare(strict_types=1);

namespace App\Domain\Sms\Templates;

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Catalog\TemplateEvents;
use App\Domain\Sms\Guards\QuietHoursPolicy;
use App\Models\SmsTemplate;
use App\Models\SmsTemplateVersion;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-04 / §8 / EC-06 — the ONE write door for templates: the platform
 * draft→publish pattern with instant rollback.
 *
 *  - create   = the head + version 1 (published) in ONE transaction;
 *  - publish  = validate (TemplatePublisher) → INSERT version N+1 → ONE UPDATE
 *               of the head's live pointer → audit `sms.template.published`;
 *               the previous row is untouched (INSERT-only by grant);
 *  - rollback = a NEW version whose body is copied from the chosen old row
 *               (`status_at_birth = rolled_back`) → the pointer flip → audit;
 *  - update   = the head-only fields (name, active, quiet-hours policy,
 *               on_missing, fallback_text) — never the body;
 *  - delete   = soft (`deleted_at`); the versions stay.
 *
 * Every audit row carries ids and the version number — never the body.
 */
final class TemplateWriter
{
    public function __construct(
        private readonly TemplatePublisher $publisher,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{quiet_hours_policy?: string, on_missing?: string, fallback_text?: ?string, active?: bool, note?: ?string}  $options
     */
    public function create(string $event, string $language, string $name, string $body, string $actorId, array $options = []): SmsTemplate
    {
        $analysis = $this->publisher->validate($event, $body);
        $policy = $options['quiet_hours_policy'] ?? $this->defaultQuietHoursPolicy($event)->value;

        return DB::transaction(function () use ($event, $language, $name, $body, $actorId, $options, $analysis, $policy): SmsTemplate {
            if (SmsTemplate::query()->where('event', $event)->where('language', $language)->whereNull('deleted_at')->exists()) {
                throw new TemplateRefused('duplicate_template', 'one template per event per language (FR-01)', ['event' => $event, 'language' => $language]);
            }
            $template = SmsTemplate::query()->create([
                'event' => $event,
                'language' => $language,
                'name' => $name,
                'active' => $options['active'] ?? true,
                'quiet_hours_policy' => $policy,
                'on_missing' => $options['on_missing'] ?? 'skip_send',
                'fallback_text' => $options['fallback_text'] ?? null,
            ]);
            $version = $this->insertVersion($template, 1, $body, $analysis, $actorId, 'live', $options['note'] ?? null);
            $template->forceFill(['live_version_id' => $version->getKey()])->save();

            $this->audit->record('sms.template.created', 'sms_template', $template->identifier(), AuditActor::Staff, [
                'template_id' => $template->identifier(), 'event' => $event, 'language' => $language,
            ], $actorId);

            return $template->refresh();
        });
    }

    /**
     * @param  array{name?: string, active?: bool, quiet_hours_policy?: string, on_missing?: string, fallback_text?: ?string}  $attributes
     */
    public function update(SmsTemplate $template, array $attributes, string $actorId): SmsTemplate
    {
        $allowed = array_intersect_key($attributes, array_flip(['name', 'active', 'quiet_hours_policy', 'on_missing', 'fallback_text']));

        return DB::transaction(function () use ($template, $allowed, $actorId): SmsTemplate {
            $template->forceFill($allowed)->save();
            $this->audit->record('sms.template.updated', 'sms_template', $template->identifier(), AuditActor::Staff, [
                'template_id' => $template->identifier(), 'fields' => array_keys($allowed),
            ], $actorId);

            return $template->refresh();
        });
    }

    public function publish(SmsTemplate $template, string $body, string $actorId, ?string $note = null): SmsTemplateVersion
    {
        $analysis = $this->publisher->validate($template->event, $body);

        return DB::transaction(function () use ($template, $body, $actorId, $note, $analysis): SmsTemplateVersion {
            $next = $this->nextVersion($template);
            $version = $this->insertVersion($template, $next, $body, $analysis, $actorId, 'live', $note);
            $template->forceFill(['live_version_id' => $version->getKey()])->save();
            $this->audit->record('sms.template.published', 'sms_template', $template->identifier(), AuditActor::Staff, [
                'template_id' => $template->identifier(), 'version' => $next, 'encoding' => $analysis['encoding'], 'segments_estimate' => $analysis['segments_estimate'],
            ], $actorId);

            return $version;
        });
    }

    /** FR-04 "instant rollback": a NEW version copying the chosen one (history is append-only). */
    public function rollback(SmsTemplate $template, int $toVersion, string $actorId): SmsTemplateVersion
    {
        return DB::transaction(function () use ($template, $toVersion, $actorId): SmsTemplateVersion {
            /** @var SmsTemplateVersion|null $source */
            $source = SmsTemplateVersion::query()->where('template_id', $template->getKey())->where('version', $toVersion)->first();
            if ($source === null) {
                throw new TemplateRefused('version_not_found', 'no such version to roll back to', ['version' => $toVersion]);
            }
            $analysis = $this->publisher->validate($template->event, $source->body);
            $next = $this->nextVersion($template);
            $version = $this->insertVersion($template, $next, $source->body, $analysis, $actorId, 'rolled_back', 'rollback to v'.$toVersion);
            $template->forceFill(['live_version_id' => $version->getKey()])->save();
            $this->audit->record('sms.template.rolled_back', 'sms_template', $template->identifier(), AuditActor::Staff, [
                'template_id' => $template->identifier(), 'version' => $next, 'rolled_back_to' => $toVersion,
            ], $actorId);

            return $version;
        });
    }

    public function delete(SmsTemplate $template, string $actorId): void
    {
        DB::transaction(function () use ($template, $actorId): void {
            // the query builder: the stamp is the DB clock's (an Expression the datetime cast cannot carry)
            DB::table('sms_templates')->where('id', $template->getKey())->update(['deleted_at' => DB::raw('clock_timestamp()'), 'active' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->audit->record('sms.template.deleted', 'sms_template', $template->identifier(), AuditActor::Staff, [
                'template_id' => $template->identifier(),
            ], $actorId);
        });
    }

    private function defaultQuietHoursPolicy(string $event): QuietHoursPolicy
    {
        if ($event === TemplateEvents::OPTOUT_CONFIRMATION) {
            return QuietHoursPolicy::Exempt; // the confirmation answers the STOP the customer just sent
        }

        return SendableEvent::from($event)->quietHoursExemptByDefault() ? QuietHoursPolicy::Exempt : QuietHoursPolicy::Hold;
    }

    private function nextVersion(SmsTemplate $template): int
    {
        $max = SmsTemplateVersion::query()->where('template_id', $template->getKey())->max('version');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    /**
     * @param  array{variables_used: list<string>, encoding: string, segments_estimate: int}  $analysis
     */
    private function insertVersion(SmsTemplate $template, int $number, string $body, array $analysis, string $actorId, string $statusAtBirth, ?string $note): SmsTemplateVersion
    {
        return SmsTemplateVersion::query()->create([
            'template_id' => $template->getKey(),
            'version' => $number,
            'body' => $body,
            'variables_used' => $analysis['variables_used'],
            'encoding' => $analysis['encoding'],
            'segments_estimate' => $analysis['segments_estimate'],
            'published_by' => $actorId,
            'status_at_birth' => $statusAtBirth,
            'note' => $note,
        ]);
    }
}
