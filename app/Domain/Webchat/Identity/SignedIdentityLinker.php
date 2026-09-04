<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Identity;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Conversations\Notes\NotesWriter;
use App\Domain\Fields\ValueWriter;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Models\Customer;
use App\Models\Widget;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * A-61 (ruled at the S13 plan approval) — signed identity ASSERTS, never
 * verifies, and never re-anchors:
 *
 *  - the asserted identity is a SECOND `web` identity, value
 *    `site:<widget id>:<user_ref>`, `source = 'api'`, worded "operator site
 *    asserted" (Doc 08 BR-01 — the word "verified" appears nowhere);
 *  - the customer holding it is found by exact match or CREATED with
 *    `created_source = 'api'` (FR-07 "links/creates");
 *  - the visitor's OWN identity (`web` / <visitor id>, the channel anchor) is
 *    minted UNDER that customer when it does not exist yet, so the first
 *    message lands there (the resolver's exact match finds it) — a mint the
 *    operator asserted, never one inferred from a session id;
 *  - EC-04: a visitor ALREADY anchored to another customer keeps its
 *    conversation there; a staff-visible internal note names both identities,
 *    an audit row records the switch, and the widget RE-SESSIONS (the next
 *    conversation opens under a NEW visitor id bound to the asserted
 *    identity); nothing is re-linked, ever (Doc 08 BR-02);
 *  - name → the asserted identity's `display` (the visitor sees their own
 *    asserted name for continuity, BR-05); phone/email → STAFF-COLLECTED field
 *    values through Doc 12's one write door when the tenant defines those
 *    fields, otherwise the identity's metadata — never identities (BR-02: a
 *    phone from a signed payload can never auto-link).
 */
final class SignedIdentityLinker
{
    public const SOURCE = 'api';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly NotesWriter $notes,
        private readonly ValueWriter $fields,
        private readonly VisitorConversation $conversations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{customer_id: string, display: ?string, switched: bool}
     */
    public function assert(Widget $widget, WidgetVisitor $visitor, array $payload): array
    {
        $userRef = is_string($payload['user_ref'] ?? null) ? $payload['user_ref'] : '';
        $display = is_string($payload['name'] ?? null) && trim($payload['name']) !== '' ? mb_substr(trim($payload['name']), 0, 120) : null;
        $assertedValue = sprintf('site:%s:%s', VisitorConversation::widgetId($widget), $userRef);
        $visitorValue = $visitor->visitorId;

        return DB::transaction(function () use ($widget, $visitor, $payload, $assertedValue, $visitorValue, $display): array {
            $asserted = $this->identity('web', $assertedValue);
            $customerId = $asserted?->customer_id;
            if (! is_string($customerId)) {
                $customerId = $this->createCustomer();
                $this->mintIdentity($customerId, 'web', $assertedValue, ['display' => $display, 'user_ref' => $payload['user_ref']]);
            } elseif ($display !== null) {
                $this->mergeMetadata($asserted->id, ['display' => $display]);
            }

            $this->staffCollectedFields($customerId, $assertedValue, $payload);

            $own = $this->identity('web', $visitorValue);
            $switched = false;
            if ($own === null) {
                // The anchor minted under the asserted customer — the operator asserted it (source=api).
                $this->mintIdentity($customerId, 'web', $visitorValue, ['display' => $display, 'channel' => 'webchat']);
            } elseif ($own->customer_id !== $customerId) {
                // EC-04: the switch — staff-visible, audited, never a re-link; the widget re-sessions.
                $switched = true;
                $conversation = $this->conversations->find($widget, $visitor);
                if ($conversation !== null) {
                    $this->notes->addSystemNote($conversation, (string) __('widget.identity.switched_note', [
                        'asserted' => $assertedValue,
                        'previous' => (string) $own->value,
                    ]));
                }
                $this->audit->record('widget.identity.switched', 'conversation', $conversation?->identifier(), AuditActor::System, [
                    'conversation_id' => $conversation?->identifier(),
                    'previous_identity_id' => (string) $own->id,
                    'asserted_identity_id' => $this->identity('web', $assertedValue)?->id,
                ]);
            }

            return ['customer_id' => $customerId, 'display' => $display, 'switched' => $switched];
        });
    }

    /** @return object{id: string, customer_id: string, value: string}|null */
    private function identity(string $type, string $value): ?object
    {
        /** @var object{id: string, customer_id: string, value: string}|null $row */
        $row = DB::table('customer_identities')->where('type', $type)->where('value', $value)->first(['id', 'customer_id', 'value']);

        return $row;
    }

    private function createCustomer(): string
    {
        $customer = Customer::query()->create(['is_shell' => true, 'created_source' => self::SOURCE]);
        $this->events->publish('customer.created', [
            'tenant_id' => $this->context->tenantId(),
            'customer_id' => $customer->identifier(),
            'created_source' => self::SOURCE,
        ]);

        return $customer->identifier();
    }

    /** @param array<string, mixed> $metadata */
    private function mintIdentity(string $customerId, string $type, string $value, array $metadata): void
    {
        $id = (string) new UuidV7;
        DB::table('customer_identities')->insert([
            'id' => $id,
            'tenant_id' => $this->context->tenantId(),
            'customer_id' => $customerId,
            'channel' => 'webchat',
            'external_user_id' => $value,
            'type' => $type,
            'value' => $value,
            'source' => self::SOURCE,
            'first_seen_at' => DB::raw('clock_timestamp()'),
            'metadata' => json_encode(array_filter($metadata, static fn ($v): bool => $v !== null), JSON_THROW_ON_ERROR),
            'created_at' => DB::raw('now()'),
            'updated_at' => DB::raw('now()'),
        ]);
        $this->events->publish('customer.identity.linked', [
            'tenant_id' => $this->context->tenantId(),
            'customer_id' => $customerId,
            'identity_id' => $id,
            'type' => $type,
            'source' => self::SOURCE,
        ]);
    }

    /**
     * phone/email are STAFF-COLLECTED values (Doc 12's door validates and
     * canonicalizes per the tenant's definition); without a definition they
     * ride the asserted identity's metadata — never a phone/email IDENTITY.
     *
     * @param  array<string, mixed>  $payload
     */
    /**
     * A bound jsonb merge — never string-built SQL.
     *
     * @param  array<string, mixed>  $patch
     */
    private function mergeMetadata(string $identityId, array $patch): void
    {
        DB::statement('UPDATE customer_identities SET metadata = metadata || ?::jsonb WHERE id = ?', [
            json_encode($patch, JSON_THROW_ON_ERROR), $identityId,
        ]);
    }

    /**
     * phone/email are STAFF-COLLECTED values (Doc 12's door validates and canonicalizes per the
     * tenant's definition); without a definition they ride the asserted identity's metadata —
     * never a phone/email IDENTITY (BR-02: a phone from a signed payload can never auto-link).
     *
     * @param  array<string, mixed>  $payload
     */
    private function staffCollectedFields(string $customerId, string $assertedValue, array $payload): void
    {
        foreach (['phone', 'email'] as $key) {
            $value = $payload[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            try {
                $this->fields->setCustomerField($customerId, $key, trim($value), self::SOURCE);
            } catch (\InvalidArgumentException) {
                // No such customer field defined (or the value fails its definition): the
                // assertion is recorded on the identity's metadata for staff, not dropped.
                $assertedId = $this->identity('web', $assertedValue)?->id;
                if ($assertedId !== null) {
                    $this->mergeMetadata($assertedId, ['asserted_'.$key => trim($value)]);
                }
            }
        }
    }
}
