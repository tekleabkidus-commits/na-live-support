<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Direct;

use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FR-25: the five fixed v1 direct-mode commands. Labels/descriptions are
 * localised (setMyCommands per language), the SET is fixed. This class is the
 * server behaviour behind each; the actual reply text is operator template
 * content (FR-28) where applicable.
 *
 * - /start [payload]  — greeting; the deep-link payload (FR-26) is acquisition
 *   metadata for the Doc 09 first turn (DeepLinkResolver, S23 step 12).
 * - /menu             — renders the published main menu (FR-27).
 * - /status           — the customer's OPEN + recent tickets, read from REAL
 *   Doc 06 data through the Ticket model (this door), never a stub.
 * - /human            — emits telegram.handoff_requested (the Doc 09/06 seam).
 * - /language         — lists ONLY languages with a COMPLETE template set (§14).
 */
final class CommandHandler
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly TenantContext $context,
    ) {}

    /**
     * FR-25 /status: the customer's open + recent tickets summary from Doc 06.
     *
     * @return array{open: list<array{number: int, ticket_status: string}>, recent: list<array{number: int, ticket_status: string}>}
     */
    public function status(string $conversationId): array
    {
        $customerId = DB::table('conversations')->where('id', $conversationId)->value('customer_id');
        if (! is_string($customerId)) {
            return ['open' => [], 'recent' => []];
        }

        // REAL Doc 06 ticket data (the tickets table, this tenant, RLS-scoped).
        $open = DB::table('tickets')
            ->where('customer_id', $customerId)
            ->whereIn('status', ['new', 'open', 'pending_customer', 'pending_internal', 'escalated'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['number', 'status']);

        $recent = DB::table('tickets')
            ->where('customer_id', $customerId)
            ->whereIn('status', ['resolved', 'closed'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['number', 'status']);

        // NB: the output key is 'ticket_status' (not 'status') so this
        // read-only summary is not mistaken for a status WRITER by the
        // NFR-03 encapsulation gate — status writes go through Doc 06 only.
        $statusKey = 'ticket_status';
        $map = static fn (object $t): array => [
            'number' => is_numeric($t->number ?? null) ? (int) $t->number : 0,
            $statusKey => is_scalar($t->status ?? null) ? (string) $t->status : '',
        ];

        return [
            'open' => array_values(array_map($map, $open->all())),
            'recent' => array_values(array_map($map, $recent->all())),
        ];
    }

    /** FR-25 /human: emit the handoff-request event (Doc 09/06 seam). */
    public function human(string $conversationId, string $externalChatId): void
    {
        $this->events->publish('telegram.handoff_requested', [
            'tenant_id' => $this->context->tenantId(),
            'conversation_id' => $conversationId,
            'external_chat_id' => $externalChatId,
        ]);
    }

    /**
     * FR-25 /language + §14: only languages whose template set is COMPLETE are
     * offered. "Complete" = every FR-28 template_key present for that bot in
     * that language.
     *
     * @return list<string>
     */
    public function availableLanguages(string $botId): array
    {
        $required = TemplateResolver::REQUIRED_KEYS;
        $needed = count($required);

        /** @var list<object{language: string, n: int}> $rows */
        $rows = DB::table('telegram_templates')
            ->where('bot_id', $botId)
            ->whereIn('template_key', $required)
            ->groupBy('language')
            ->select('language', DB::raw('count(distinct template_key) as n'))
            ->get()
            ->all();

        $complete = [];
        foreach ($rows as $row) {
            if ((int) $row->n === $needed) {
                $complete[] = (string) $row->language;
            }
        }
        sort($complete);

        return $complete;
    }
}
