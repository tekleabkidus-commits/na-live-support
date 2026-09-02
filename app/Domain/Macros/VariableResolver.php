<?php

declare(strict_types=1);

namespace App\Domain\Macros;

use App\Domain\Routing\Estimator\QueueWaitVariable;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 FR-15 / T-06 / §14 — THE ONE variable resolver.
 *
 * Palette preview and every send path call THIS class (parity is shared code,
 * never drift — AC-05's law). Vocabulary: {{customer.name}}, {{ticket.number}},
 * {{agent.first_name}}, {{queue.wait_band}} (Doc 11's own provider, already
 * tenant-timezone/locale-aware), and {{custom.<key>}} (ticket custom fields —
 * A-59's store). Values are LOCALE-FORMATTED per the customer's language
 * (§14); an unresolvable variable comes back in `unresolved` — the B12-S03
 * warning treatment's data, and the T-06 guard's input.
 */
final class VariableResolver
{
    /** The named variables the resolver answers (custom.<key> fields ride the field_definitions rows) — the editor's palette (S12 step 14). */
    public const VOCABULARY = ['customer.name', 'ticket.number', 'agent.first_name', 'queue.wait_band'];

    public function __construct(private readonly QueueWaitVariable $queueWait) {}

    /**
     * @return array{text: string, variables: array<string, ?string>, unresolved: list<string>}
     */
    public function resolve(string $body, string $ticketId, ?string $agentId = null): array
    {
        /** @var object{id: string, number: int|string, conversation_id: string, customer_id: ?string, form_data: ?string}|null $ticket */
        $ticket = DB::table('tickets')->where('id', $ticketId)->first(['id', 'number', 'conversation_id', 'customer_id', 'form_data']);
        preg_match_all('/\{\{\s*([a-z_.]+[a-z0-9_.]*)\s*\}\}/i', $body, $matches);
        /** @var list<string> $names */
        $names = array_values(array_unique($matches[1]));

        /** @var object{display_name: ?string, language: ?string}|null $customer */
        $customer = $ticket !== null && is_string($ticket->customer_id)
            ? DB::table('customers')->where('id', $ticket->customer_id)->first(['display_name', 'language'])
            : null;
        $language = $customer !== null && is_string($customer->language) ? $customer->language : 'en';
        $custom = [];
        if ($ticket !== null && is_string($ticket->form_data)) {
            $decoded = json_decode($ticket->form_data, true, 8);
            $stored = is_array($decoded) && is_array($decoded['custom'] ?? null) ? $decoded['custom'] : [];
            foreach ($stored as $key => $value) {
                $custom[(string) $key] = $value;
            }
        }

        $variables = [];
        $text = $body;
        foreach ($names as $name) {
            $value = $this->valueFor($name, $ticket, $customer, $custom, $agentId, $language);
            $variables[$name] = $value;
            if ($value !== null) {
                $text = (string) preg_replace('/\{\{\s*'.preg_quote($name, '/').'\s*\}\}/i', $value, $text);
            }
        }
        // (array keys: a purely-numeric name would come back int — restring them)
        $unresolved = array_keys(array_filter($variables, static fn (?string $v): bool => $v === null));

        return ['text' => $text, 'variables' => $variables, 'unresolved' => array_map(strval(...), $unresolved)];
    }

    /**
     * @param  object{id: string, number: int|string, conversation_id: string, customer_id: ?string, form_data: ?string}|null  $ticket
     * @param  object{display_name: ?string, language: ?string}|null  $customer
     * @param  array<string, mixed>  $custom
     */
    private function valueFor(string $name, ?object $ticket, ?object $customer, array $custom, ?string $agentId, string $language): ?string
    {
        if (str_starts_with($name, 'custom.')) {
            $value = $custom[substr($name, 7)] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        }

        return match ($name) {
            'customer.name' => $customer !== null && is_string($customer->display_name) && $customer->display_name !== '' ? $customer->display_name : null,
            'ticket.number' => $ticket === null ? null : '#'.$ticket->number,
            'agent.first_name' => $this->agentFirstName($agentId),
            // Doc 11's provider is already §14-lawful (locale + tenant timezone).
            'queue.wait_band' => $ticket === null ? null : $this->queueWait->forConversation($ticket->conversation_id, $language),
            default => null,
        };
    }

    private function agentFirstName(?string $agentId): ?string
    {
        if ($agentId === null) {
            return null;
        }
        $name = DB::table('users')->where('id', $agentId)->value('name');

        return is_string($name) && $name !== '' ? explode(' ', trim($name))[0] : null;
    }
}
