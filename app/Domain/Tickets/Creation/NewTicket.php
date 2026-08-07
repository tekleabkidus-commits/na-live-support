<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

use App\Models\Conversation;
use App\Models\User;
use InvalidArgumentException;

/**
 * The validated shape every creation path hands to CreateTicket (FR-13's "same
 * validation path" made literal — there is one type, so there is one set of rules).
 *
 * Validation lives in the constructor rather than in a request class because the four
 * doors are not all HTTP: automation (Doc 12) and the AI handoff (Doc 09) arrive as
 * calls, and a rule enforced only at an HTTP boundary is a rule those two skip.
 */
final readonly class NewTicket
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>|null  $formData
     */
    public function __construct(
        public Conversation $conversation,
        public TicketSource $source,
        public ?User $actor = null,
        public ?string $subject = null,
        public string $priority = 'normal',
        public ?string $departmentId = null,
        public array $tags = [],
        public ?array $formData = null,
        public ?string $idempotencyKey = null,
        public bool $linkedWork = false,
        public ?string $originTicketId = null,
        public ?string $previousTicketId = null,
    ) {
        if (! in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new InvalidArgumentException(sprintf('unknown priority "%s" (FR-17 fixes four).', $priority));
        }

        // FR-03: the subject is OPTIONAL, and an empty string is not a subject — it
        // would defeat Doc 09's "generate one if absent" branch by looking present.
        if ($subject !== null && trim($subject) === '') {
            throw new InvalidArgumentException('a subject that is present must have content; omit it entirely to let Doc 09 generate one (FR-03).');
        }

        // FR-21: linked work is the split target's flag and only a split may set it,
        // together with the origin it was split from. Guarding the pair here stops any
        // other path from minting the one sanctioned multi-open case by accident.
        if ($linkedWork && $originTicketId === null) {
            throw new InvalidArgumentException('linked_work without an origin ticket is not a split (FR-21) — it is a second open ticket.');
        }

        if ($source === TicketSource::OfflineForm && ! in_array('offline', $tags, true)) {
            // FR-14: "creates new, tagged offline". The tag is part of the path's
            // definition, not decoration a caller may forget.
            throw new InvalidArgumentException('an offline-form ticket carries the `offline` tag (FR-14).');
        }
    }

    /**
     * FR-14's door: the Doc 13 widget's offline form. The caller (the widget itself) is
     * the pre-agreed deferral; this path and its validation are built here so that when
     * Doc 13 arrives it wires a form to a rule that already exists.
     *
     * @param  array<string, mixed>  $formData
     * @param  list<string>  $tags
     */
    public static function fromOfflineForm(Conversation $conversation, array $formData, array $tags = [], ?string $subject = null): self
    {
        return new self(
            conversation: $conversation,
            source: TicketSource::OfflineForm,
            subject: $subject,
            tags: array_values(array_unique(['offline', ...$tags])),
            formData: $formData,
        );
    }
}
