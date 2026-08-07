<?php

declare(strict_types=1);

namespace App\Domain\Tickets\Creation;

use InvalidArgumentException;

/**
 * FR-11's NORMATIVE packet contract, as a type.
 *
 * The document states it as a shape, so this states it as a shape:
 *   {summary (2–4 sentences), intent+confidence, language, sentiment, urgency,
 *    suggested {department, priority} + reasons[], form_data?, kb_shown[] (article ids),
 *    transcript_anchor (message id range)}
 *
 * WHY VALIDATION LIVES HERE AND IS STRICT. The packet is pinned to the ticket as an
 * IMMUTABLE snapshot (T-05, SEC-02) — there is no UPDATE grant, so a malformed packet
 * cannot be corrected later, only orphaned by a new ticket. Everything that can be
 * checked is therefore checked before the insert, once. Doc 09 is the producer and does
 * not exist yet, which is exactly why the contract is enforced from this side: the
 * fixture in fixtures/tickets/handoff/ is what Doc 09 will have to satisfy.
 *
 * `schemaVersion` is NFR-06's versioning. A future packet shape bumps it rather than
 * reinterpreting old rows, because old rows cannot be migrated — they are immutable.
 */
final readonly class HandoffPacket
{
    /**
     * @param  list<string>  $suggestedReasons
     * @param  list<string>  $kbShown  knowledge-base article ids the customer already saw
     * @param  array{from_message_id: string, to_message_id: string}  $transcriptAnchor
     * @param  array<string, mixed>|null  $formData
     */
    public function __construct(
        public string $summary,
        public string $intent,
        public float $confidence,
        public string $language,
        public string $sentiment,
        public string $urgency,
        public ?string $suggestedDepartment,
        public string $suggestedPriority,
        public array $suggestedReasons,
        public array $kbShown,
        public array $transcriptAnchor,
        public ?array $formData = null,
        public int $schemaVersion = 1,
    ) {
        // "summary (2–4 sentences)" — a bound the document states in as many words, so
        // it is enforced rather than trusted. A one-sentence summary is a headline and
        // a six-sentence one is a transcript; the agent picking this ticket up needs
        // neither.
        $sentences = preg_match_all('/[.!?](\s|$)/u', trim($summary));
        if ($sentences < 2 || $sentences > 4) {
            throw new InvalidArgumentException(sprintf(
                'the handoff summary must be 2-4 sentences, found %d (FR-11).', $sentences,
            ));
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('intent confidence is a probability in [0, 1] (FR-11).');
        }

        if (! in_array($suggestedPriority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new InvalidArgumentException(sprintf('unknown suggested priority "%s" (FR-17).', $suggestedPriority));
        }

        foreach ($kbShown as $articleId) {
            if ($articleId === '') {
                throw new InvalidArgumentException('kb_shown carries article IDS — "customer already saw these" is a link list, not prose (FR-11, §8).');
            }
        }
    }

    /**
     * THE UNTRUSTED DOOR. Doc 09 will hand this class decoded JSON, so every value
     * arrives as `mixed` and is narrowed HERE — not in the constructor, whose declared
     * shapes would make the same checks provably dead code (PHPStan said so, and it was
     * right: a check that cannot fail is a check that proves nothing).
     *
     * The division of labour is therefore: `fromArray` establishes that the SHAPE is
     * what FR-11 describes, and the constructor enforces the VALUE rules FR-11 states
     * on top of that shape (2–4 sentences, a confidence in [0,1], one of the four
     * priorities). Callers holding real types can construct directly and skip only the
     * shape checks they have already satisfied.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['summary', 'intent', 'confidence', 'language', 'sentiment', 'urgency', 'suggested', 'kb_shown', 'transcript_anchor'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("the handoff packet is missing `{$key}` (FR-11's normative contract).");
            }
        }

        $suggested = $data['suggested'];
        if (! is_array($suggested)) {
            throw new InvalidArgumentException('`suggested` is an object of {department, priority, reasons[]} (FR-11).');
        }

        $anchor = $data['transcript_anchor'];
        if (! is_array($anchor)) {
            throw new InvalidArgumentException('`transcript_anchor` is a message id range (FR-11).');
        }
        foreach (['from_message_id', 'to_message_id'] as $key) {
            if (! isset($anchor[$key]) || ! is_string($anchor[$key])) {
                throw new InvalidArgumentException("transcript_anchor needs a {$key} — the packet's range is what §8 renders and what BR-02 keeps as a REFERENCE rather than a copy (FR-11).");
            }
        }

        $kbShown = $data['kb_shown'];
        if (! is_array($kbShown)) {
            throw new InvalidArgumentException('`kb_shown` is a list of article ids (FR-11).');
        }
        $articleIds = [];
        foreach ($kbShown as $articleId) {
            if (! is_string($articleId)) {
                throw new InvalidArgumentException('kb_shown carries article IDS — "customer already saw these" is a link list, not prose (FR-11, §8).');
            }
            $articleIds[] = $articleId;
        }

        $reasons = [];
        if (isset($suggested['reasons']) && is_array($suggested['reasons'])) {
            foreach ($suggested['reasons'] as $reason) {
                $reasons[] = self::text($reason, 'suggested.reasons[]');
            }
        }

        // FR-14's offline form rides the packet as an OBJECT — string keys, so it can
        // be rendered as a field list (§8's "source form_data attached as an artifact
        // card" is the merge case of the same shape). A JSON array would decode to
        // integer keys and quietly render as nothing.
        $formData = null;
        if (isset($data['form_data'])) {
            if (! is_array($data['form_data'])) {
                throw new InvalidArgumentException('`form_data` is an object when present (FR-11).');
            }
            $formData = [];
            foreach ($data['form_data'] as $field => $value) {
                if (! is_string($field)) {
                    throw new InvalidArgumentException('`form_data` keys are field names (FR-11/FR-14).');
                }
                $formData[$field] = $value;
            }
        }

        return new self(
            summary: self::text($data['summary'], 'summary'),
            intent: self::text($data['intent'], 'intent'),
            confidence: self::number($data['confidence'], 'confidence'),
            language: self::text($data['language'], 'language'),
            sentiment: self::text($data['sentiment'], 'sentiment'),
            urgency: self::text($data['urgency'], 'urgency'),
            suggestedDepartment: isset($suggested['department']) ? self::text($suggested['department'], 'suggested.department') : null,
            suggestedPriority: self::text($suggested['priority'] ?? null, 'suggested.priority'),
            suggestedReasons: $reasons,
            kbShown: $articleIds,
            transcriptAnchor: ['from_message_id' => $anchor['from_message_id'], 'to_message_id' => $anchor['to_message_id']],
            formData: $formData,
            schemaVersion: (int) self::number($data['schema_version'] ?? 1, 'schema_version'),
        );
    }

    private static function text(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("`{$field}` must be a string (FR-11's normative contract).");
        }

        return $value;
    }

    private static function number(mixed $value, string $field): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("`{$field}` must be a number (FR-11's normative contract).");
        }

        return (float) $value;
    }

    /** @return array<string, mixed> the stored jsonb payload */
    public function toPayload(): array
    {
        return [
            'summary' => $this->summary,
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'language' => $this->language,
            'sentiment' => $this->sentiment,
            'urgency' => $this->urgency,
            'suggested' => [
                'department' => $this->suggestedDepartment,
                'priority' => $this->suggestedPriority,
                'reasons' => $this->suggestedReasons,
            ],
            'kb_shown' => $this->kbShown,
            'transcript_anchor' => $this->transcriptAnchor,
            'form_data' => $this->formData,
        ];
    }
}
