<?php

declare(strict_types=1);

namespace App\Domain\Sms\OptOut;

use App\Domain\Sms\Support\PhoneMask;
use App\Jobs\Sms\SendSmsJob;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FR-14/FR-15 — THE opt-out registry (N-16: absolute; BR-03: phone-keyed,
 * append-only). `isOptedOut()` is what the guard reads; adds come from the
 * three sources the CHECK names (`inbound_stop` — the STOP parser, `staff` —
 * the manager door with a reason, `api` — the import); restore is the
 * high-visibility door (reason required) and UPDATES only the restore columns
 * the grant allows — history is never deleted, a re-add after a restore is a
 * NEW row. The `sms.optout.added|removed` events carry ids and the source,
 * NEVER the phone (SEC-02); audit rows likewise.
 *
 * STOP-Q 3 (FR-14 vs FR-15, the document's own single carve-out): where
 * `sms.optout.confirmation_required` is on, ONE `optout_confirmation` goes to
 * the phone that just opted out — rendered AT INSERT and sent through a
 * dedicated pipeline branch that bypasses the opted-out guard ONCE (it could
 * never pass it); `optout_confirmation` remains structurally unsendable
 * anywhere else (not a SendableEvent — STOP-D condition 1).
 */
final class OptOutRegistry
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
        private readonly SettingsRepository $settings,
    ) {}

    /** The guard's read (N-16): an un-restored row for this phone means NO. */
    public function isOptedOut(string $phoneE164): bool
    {
        return DB::table('sms_optouts')->where('phone', $phoneE164)->whereNull('restored_at')->exists();
    }

    public function addFromInbound(string $phoneE164, string $stopWordMatched): ?string
    {
        return $this->add($phoneE164, 'inbound_stop', null, null, $stopWordMatched);
    }

    public function addByStaff(string $phoneE164, string $reason, string $actorId): ?string
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('a staff opt-out carries a reason (FR-14)');
        }

        return $this->add($phoneE164, 'staff', $reason, $actorId, null);
    }

    public function addFromImport(string $phoneE164, string $actorId): ?string
    {
        return $this->add($phoneE164, 'api', null, $actorId, null);
    }

    /** @return string|null the new opt-out id; null = already opted out (idempotent — absoluteness needs no second row) */
    private function add(string $phoneE164, string $source, ?string $reason, ?string $actorId, ?string $stopWordMatched): ?string
    {
        if (preg_match('/^\+[1-9]\d{6,14}$/', $phoneE164) !== 1) {
            throw new \InvalidArgumentException('an opt-out is keyed on an E.164 phone');
        }
        if ($this->isOptedOut($phoneE164)) {
            return null;
        }

        $optout = new SmsOptOut;
        $id = DB::transaction(function () use ($optout, $phoneE164, $source, $reason, $actorId, $stopWordMatched): string {
            $optout->fill([
                'phone' => $phoneE164,
                'source' => $source,
                'reason' => $reason,
                'added_by' => $actorId,
                'stop_word_matched' => $stopWordMatched,
            ]);
            $optout->save();
            $id = $optout->identifier();
            $this->audit->record('sms.optout.added', 'sms_optout', $id, $actorId === null ? AuditActor::System : AuditActor::Staff, ['optout_id' => $id, 'source' => $source], $actorId);
            $this->events->publish('sms.optout.added', ['tenant_id' => $optout->tenant_id, 'optout_id' => $id, 'source' => $source, 'actor_id' => $actorId]);

            return $id;
        });
        Log::info('sms optout added', ['optout_id' => $id, 'source' => $source, 'to_masked' => PhoneMask::forLog($phoneE164)]);

        $this->maybeSendConfirmation($phoneE164);

        return $id;
    }

    /** The high-visibility restore (FR-14: opt back in is deliberate, reasoned and audited). */
    public function restore(string $optoutId, string $reason, string $actorId): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('a restore carries a reason (FR-14, high-visibility)');
        }
        DB::transaction(function () use ($optoutId, $reason, $actorId): void {
            /** @var SmsOptOut|null $optout */
            $optout = SmsOptOut::query()->whereNull('restored_at')->find($optoutId);
            if ($optout === null) {
                throw new \InvalidArgumentException('no active opt-out with that id');
            }
            DB::table('sms_optouts')->where('id', $optoutId)->update([
                'restored_at' => DB::raw('clock_timestamp()'),
                'restored_by' => $actorId,
                'restore_reason' => $reason,
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            $this->audit->record('sms.optout.restored', 'sms_optout', $optoutId, AuditActor::Staff, ['optout_id' => $optoutId, 'reason' => $reason], $actorId);
            $this->events->publish('sms.optout.removed', ['tenant_id' => $optout->tenant_id, 'optout_id' => $optoutId, 'source' => $optout->source, 'actor_id' => $actorId]);
        });
    }

    /**
     * STOP-Q 3: the ONE confirmation, only where the market knob is on. Rendered
     * AT INSERT (the OTP shape); the row's event is the template-only
     * `optout_confirmation`; SendPipeline's dedicated branch carries it past the
     * opted-out guard exactly once. No template published → nothing, logged.
     */
    private function maybeSendConfirmation(string $phoneE164): void
    {
        if ($this->settings->get('sms.optout.confirmation_required') !== true) {
            return;
        }
        $language = DB::table('customer_identities')
            ->join('customers', 'customers.id', '=', 'customer_identities.customer_id')
            ->where('customer_identities.type', 'phone')->where('customer_identities.value', $phoneE164)
            ->value('customers.language');
        $language = is_string($language) && $language !== '' ? $language : 'en';

        $resolved = app(\App\Domain\Sms\Templates\TemplateReader::class)->resolveForSend('optout_confirmation', $language);
        if ($resolved === null) {
            Log::info('sms optout confirmation skipped: no published template', ['to_masked' => PhoneMask::forLog($phoneE164)]);

            return;
        }
        $rendered = app(\App\Domain\Sms\Rendering\TemplateRenderer::class)->render($resolved['version'], $resolved['template'], new \App\Domain\Sms\Rendering\RenderContext(language: $resolved['language']));
        if (! $rendered->sends() || $rendered->text === null) {
            return;
        }
        $segmentation = app(\App\Domain\Sms\Encoding\Segmenter::class)->segment($rendered->text);

        $row = new SmsMessage;
        DB::transaction(function () use ($row, $phoneE164, $resolved, $rendered, $segmentation, $language): void {
            $row->fill([
                'event' => 'optout_confirmation',
                'language' => $language,
                'phone' => $phoneE164,
                'template_id' => $resolved['template']->identifier(),
                'template_version_id' => $resolved['version']->identifier(),
                'rendered_body' => $rendered->text,
                'encoding' => $segmentation->encoding->value,
                'segments' => $segmentation->segments,
                'state' => 'queued',
                'guard_log' => [['check' => 'optout_confirmation_carveout', 'result' => 'sent_once (FR-15\'s single exception)']],
                'lane' => 'sms-transactional',
            ]);
            $row->save();
            app(\App\Domain\Sms\Pipeline\SmsLifecycle::class)->publish('sms.queued', $row);
        });
        SendSmsJob::dispatch($row->identifier(), 'sms-transactional');
    }
}
