<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Models\Ticket;
use App\Support\Events\EventPublisher;
use App\Support\Notifications\NotificationCategories;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-03 notify (users/roles — Doc 16 delivery). The arm RESOLVES the
 * recipients from row truth (named users that exist in this tenant; every
 * member of each named role), REQUIRES a registered category (the registry's
 * runtime door — an unregistered category cannot ship a notification), and
 * publishes `automation.notify.requested` carrying IDENTIFIERS ONLY. Delivery
 * — the channels, the 3-layer opt model, the bell — is Doc 16's ([D16]);
 * fixtures/automation/doc16-notify-contract.json pins the slice it reads.
 */
final class NotifyAction implements AutomationAction
{
    use ArmSupport;

    public const DEFAULT_CATEGORY = 'notify.automation.rule';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
    ) {}

    public function run(array $action, string $executionId, int $automationDepth, array $basis = []): array
    {
        $category = is_string($action['category'] ?? null) && $action['category'] !== '' ? $action['category'] : self::DEFAULT_CATEGORY;
        if (! NotificationCategories::has($category)) {
            return ['action' => 'notify', 'outcome' => 'failed', 'reason' => 'unregistered_category'];
        }
        $userIds = self::ids($action['user_ids'] ?? []);
        $roleIds = self::ids($action['role_ids'] ?? []);

        $recipients = [];
        if ($userIds !== []) {
            $recipients = DB::table('users')->whereIn('id', $userIds)->pluck('id')->all();
        }
        if ($roleIds !== []) {
            $recipients = array_merge($recipients, DB::table('users')->whereIn('role_id', $roleIds)->pluck('id')->all());
        }
        $recipients = array_values(array_unique(array_filter(array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $recipients), static fn (string $id): bool => $id !== '')));
        sort($recipients);
        if ($recipients === []) {
            return ['action' => 'notify', 'outcome' => 'failed', 'reason' => 'no_recipients'];
        }

        $ticket = $this->ticketOf($executionId);
        /** @var string|null $ruleId */
        $ruleId = DB::table('automation_rule_executions')->where('id', $executionId)->value('rule_id');
        $eventId = DB::transaction(fn (): string => $this->events->publish('automation.notify.requested', [
            'tenant_id' => $this->context->tenantId(),
            'rule_id' => is_string($ruleId) ? $ruleId : null,
            'execution_id' => $executionId,
            'ticket_id' => $ticket instanceof Ticket ? $ticket->identifier() : null,
            'category' => $category,
            'user_ids' => $recipients,
        ]));

        return ['action' => 'notify', 'outcome' => 'ok', 'recipients' => count($recipients), 'requested_event_id' => $eventId];
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $value), static fn (string $v): bool => $v !== ''));
    }
}
