<?php

declare(strict_types=1);

namespace App\Domain\Sms\Templates;

use App\Domain\Sms\Catalog\Lane;
use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Catalog\TemplateEvents;
use App\Domain\Sms\Encoding\Segmenter;
use App\Domain\Sms\Rendering\RenderContext;
use App\Domain\Sms\Rendering\TemplateRenderer;
use App\Models\SmsMessage;
use App\Models\SmsTemplateVersion;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-05 — "test-send to an admin-verified number (sandbox-tagged,
 * excluded from reports per the platform test-mode rule)". STOP-M (ruled):
 * the number is TYPED per send and never stored on any customer — the row's
 * `customer_id` is NULL and `is_test = true`, the P-N14 shape on Doc 14's own
 * carrier: every Doc 14 figure and the Doc 15 feed exclude `is_test` rows at
 * the source. In every NON-production origin the target must be a
 * `+251-90000xxxx` fixture number (the 0l / §14 law) — anything else is
 * refused before a row exists. The message is rendered with the SAMPLE values
 * (what the editor estimated with) and queued on the transactional lane; the
 * pipeline (step 5) sends it like any other row — the tag rides with it.
 */
final class TestSend
{
    public const FIXTURE_PHONE = '/^\+25190000\d{4}$/';

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly Segmenter $segmenter,
        private readonly AuditLogger $audit,
    ) {}

    public function send(SmsTemplateVersion $version, string $phone, string $actorId): SmsMessage
    {
        if (! app()->environment('production') && preg_match(self::FIXTURE_PHONE, $phone) !== 1) {
            throw new TemplateRefused('fixture_phone_required', 'a non-production test-send targets a +251-90000xxxx fixture number only');
        }
        /** @var \App\Models\SmsTemplate $template */
        $template = $version->template()->firstOrFail();
        $rendered = $this->renderer->render($version, $template, RenderContext::samples($template->language));
        $text = $rendered->text ?? TemplatePublisher::withSamples($version->body);
        $segmentation = $this->segmenter->segment($text);
        $event = $template->event;
        $lane = $event === TemplateEvents::OPTOUT_CONFIRMATION ? Lane::Transactional : SendableEvent::from($event)->lane();

        return DB::transaction(function () use ($version, $template, $phone, $actorId, $text, $segmentation, $event, $lane): SmsMessage {
            $message = SmsMessage::query()->create([
                'customer_id' => null,
                'event' => $event,
                'template_id' => $template->getKey(),
                'template_version_id' => $version->getKey(),
                'language' => $template->language,
                'phone' => $phone,
                'rendered_body' => $text,
                'encoding' => $segmentation->encoding->value,
                'segments' => $segmentation->segments,
                'state' => 'queued',
                'guard_log' => [['check' => 'test_send', 'result' => 'pass']],
                'lane' => $lane->value,
                'is_test' => true,
            ]);
            $this->audit->record('sms.template.test_sent', 'sms_template', $template->identifier(), AuditActor::Staff, [
                'template_id' => $template->identifier(), 'is_test' => true, 'sms_message_id' => $message->identifier(),
            ], $actorId);

            return $message;
        });
    }
}
