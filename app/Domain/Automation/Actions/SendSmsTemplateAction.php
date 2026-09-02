<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Suppression\MarketingSuppression;
use App\Models\Ticket;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03 send SMS template (Doc 14, template-based ONLY) / BR-02 "SMS
 * via templates" / BR-04 "no free-text SMS from rules — structurally
 * impossible". STRUCTURE, in three layers: (1) the card grammar admits
 * `template_ref` and nothing else (a `body` cannot be SAVED — ActionCards);
 * (2) this arm reads only that reference; (3) the catalog payload of
 * `automation.sms.requested` has NO text key, so a smuggled body dies on the
 * closed-payload law. The PRODUCT is the request event: Doc 14 resolves the
 * template, applies ITS guards (opt-out, quiet hours, phone) and sends
 * ([D14]). Doc 08 FR-15's word — "Doc 12 suppresses promotional
 * automations" — is kept HERE, on the one promotional-capable automation
 * Doc 12 emits: an RG-flagged or marketing-suppressed customer refuses.
 */
final class SendSmsTemplateAction implements AutomationAction
{
    use ArmSupport;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly MarketingSuppression $suppression,
    ) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $ticket = $this->ticketOf($executionId);
        $templateRef = is_string($action['template_ref'] ?? null) ? trim($action['template_ref']) : '';
        if (! $ticket instanceof Ticket) {
            return ['action' => 'send_sms_template', 'outcome' => 'failed', 'reason' => 'no_ticket'];
        }
        if ($templateRef === '') {
            return ['action' => 'send_sms_template', 'outcome' => 'failed', 'reason' => 'no_template_ref'];
        }
        $customerId = $ticket->getAttribute('customer_id');
        if (! is_string($customerId) || $customerId === '') {
            return ['action' => 'send_sms_template', 'outcome' => 'failed', 'reason' => 'no_customer'];
        }
        // Doc 08 FR-15's suppressions, RG first (the SurveyArmer's order).
        if ((bool) DB::table('customers')->where('id', $customerId)->value('rg_flag')) {
            return ['action' => 'send_sms_template', 'outcome' => 'failed', 'reason' => 'rg_suppressed'];
        }
        if ($this->suppression->isSuppressed($customerId)) {
            return ['action' => 'send_sms_template', 'outcome' => 'failed', 'reason' => 'marketing_suppressed'];
        }
        /** @var string|null $ruleId */
        $ruleId = DB::table('automation_rule_executions')->where('id', $executionId)->value('rule_id');
        $eventId = DB::transaction(fn (): string => $this->events->publish('automation.sms.requested', [
            'tenant_id' => $this->context->tenantId(),
            'rule_id' => is_string($ruleId) ? $ruleId : null,
            'execution_id' => $executionId,
            'ticket_id' => $ticket->identifier(),
            'customer_id' => $customerId,
            'template_ref' => $templateRef,
        ]));

        return ['action' => 'send_sms_template', 'outcome' => 'ok', 'requested_event_id' => $eventId];
    }
}
