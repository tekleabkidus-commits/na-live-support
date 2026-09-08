<?php

declare(strict_types=1);

namespace App\Domain\Sms\Catalog;

/**
 * Doc 14 FR-01 — the SENDABLE event catalog, FIXED for v1 (ten events; the
 * enumeration grows only by a filed amendment — rule 16). Each is toggleable
 * per tenant (`sms.events.<event>.enabled`, a SETTING) and mapped to exactly
 * one template per language (the `sms_templates` unique index).
 *
 * The backing values are the column values, the settings-key segments, the
 * `template_ref` vocabulary Doc 12's `automation.sms.requested` carries, and
 * the event identifiers on `sms.*` payloads — renaming one is a frozen-public-
 * contract change.
 *
 * `optout_confirmation` (FR-15) is a TEMPLATE event and NOT a case here: it
 * is never sendable by a trigger source, never toggleable, never broadcast-
 * capable (STOP-D's condition; TemplateEvents carries it).
 */
enum SendableEvent: string
{
    case TicketCreated = 'ticket_created';
    case TicketAssigned = 'ticket_assigned';
    case TicketUpdated = 'ticket_updated';
    case TicketResolved = 'ticket_resolved';
    case TicketClosed = 'ticket_closed';
    case SecurityNotification = 'security_notification';
    case VerificationCode = 'verification_code';
    case ServiceAnnouncement = 'service_announcement';
    case WindowReengagement = 'window_reengagement';
    case OfflineFormConfirmation = 'offline_form_confirmation';

    /** §21: ship four on, everything else off. */
    public function enabledByDefault(): bool
    {
        return match ($this) {
            self::TicketCreated, self::TicketResolved, self::WindowReengagement, self::OfflineFormConfirmation => true,
            default => false,
        };
    }

    /** FR-03: "customer blocked (Doc 08 FR-18) → skip except security_notification". */
    public function exemptFromBlocked(): bool
    {
        return $this === self::SecurityNotification;
    }

    /**
     * FR-03: "RG-flagged → skip all except window_reengagement and ticket_resolved
     * (the Doc 08 FR-15 suppression contract — essential service continuity only)".
     * The same essential set answers the marketing-suppression read (A-67).
     */
    public function essentialServiceContinuity(): bool
    {
        return $this === self::TicketResolved || $this === self::WindowReengagement;
    }

    /** FR-04: quiet_hours_exempt default true ONLY for verification_code and security_notification. */
    public function quietHoursExemptByDefault(): bool
    {
        return $this === self::VerificationCode || $this === self::SecurityNotification;
    }

    /** BR-02: the service_announcement family is the ONLY broadcast-capable event. */
    public function broadcastCapable(): bool
    {
        return $this === self::ServiceAnnouncement;
    }

    /** T-04 / NFR-02: transactional > re-engagement > broadcast. */
    public function lane(): Lane
    {
        return match ($this) {
            self::WindowReengagement => Lane::Reengagement,
            self::ServiceAnnouncement => Lane::Broadcast,
            default => Lane::Transactional,
        };
    }

    /**
     * FR-04: the allowed variable set PER EVENT. A template referencing a
     * variable its event does not provide cannot publish (FR-05).
     *
     * @return list<string>
     */
    public function allowedVariables(): array
    {
        $brand = ['brand.name'];
        $ticket = ['ticket.number', 'customer.name', 'brand.name', 'link'];

        return match ($this) {
            self::TicketCreated, self::TicketAssigned, self::TicketUpdated, self::TicketResolved, self::TicketClosed => $ticket,
            self::OfflineFormConfirmation => [...$ticket, 'queue.wait_band'],
            self::WindowReengagement => [...$ticket],
            self::VerificationCode => [...$brand, 'code'],
            self::SecurityNotification => [...$brand, 'link'],
            self::ServiceAnnouncement => [...$brand, 'customer.name', 'link'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $e): string => $e->value, self::cases());
    }
}
