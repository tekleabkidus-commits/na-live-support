<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts secret-shaped context off every log record (Doc 02 SEC-08; C-13).
 *
 * WHAT THIS IS AND IS NOT (the step-12 division of labour, owner-approved):
 * this processor is the defence-in-depth layer — it redacts by KEY SHAPE, so a
 * credential logged under an honest name (`password`, `two_factor_secret`,
 * `X-Telegram-Bot-Api-Secret-Token` in an access-log header map) can never
 * reach a sink whatever code path logged it. It cannot recognise a secret
 * VALUE hiding under an innocent key — no runtime scrubber can — which is why
 * the guarantee AC-08 actually counts is the seeded-credential sweep in
 * tests/Security/LogScrubberTest.php: real flows, real sentinel values,
 * scanned for in the rendered sink. Sabotage (a) in that suite leaks a seeded
 * password under an innocent key precisely to prove the sweep catches what
 * this processor structurally cannot.
 *
 * KEY MATCHING. Keys are normalised (lowercased, `-`/space → `_`) and then:
 *   - keys ending `_id` or `_count` always pass — identifiers and counters
 *     are what tracing needs, and `token_id`/`key_id` are not credentials;
 *   - exact matches on the short generic names (`code`, `codes`, `key`,
 *     `pin`) are redacted — a 2FA code logs as `code`, and over-scrubbing is
 *     the safe direction;
 *   - any key CONTAINING a needle (`password`, `secret`, `token`, …) is
 *     redacted, which covers the webhook header names in nested header-map
 *     shapes (`x_telegram_bot_api_secret_token` contains two needles) without
 *     a special case per header.
 *
 * Arrays recurse (access-log shapes are nested); anything deeper than the
 * depth cap is redacted wholesale — the safe direction again. The MESSAGE
 * string is deliberately untouched by KEY matching: free text has no keys to
 * match, so message-level leaks are the seeded sweep's to catch, and it does.
 *
 * NFR-06 AMENDMENT (Doc 05, ruling A-19): two VALUE-shape patterns join the
 * key-shape rule — the Telegram bot-token format and the api.telegram.org
 * file-URL format (T-07: file URLs CONTAIN the token). These are the only
 * value patterns because they are the only formats precise enough to carry
 * near-zero false-positive risk; they apply to string values at every depth
 * AND to the message string, since a token is a token wherever it lands.
 * tests/Security/TelegramScrubberPatternsTest.php proves both with planted
 * positive controls on the rendered sink.
 */
final class ScrubSecrets implements ProcessorInterface
{
    public const REDACTED = '[scrubbed]';

    private const MAX_DEPTH = 8;

    /** Substring matches, on the normalised key. */
    private const NEEDLES = [
        'password',
        'secret',
        'token',
        'signature',
        'hmac',
        'authorization',
        'cookie',
        'recovery',
        'otp',
        'credential',
        'api_key',
        'private_key',
    ];

    /** Exact matches only — too generic to be substrings. */
    private const EXACT = ['code', 'codes', 'key', 'pin'];

    /** Identifier/counter suffixes that always pass (they are not secrets). */
    private const EXEMPT_SUFFIXES = ['_id', '_count'];

    /**
     * NFR-06 value-shape patterns (Doc 05): the bot-token format BotFather
     * issues, and the file-download URL that embeds it (T-07). File-URL first:
     * scrubbing the whole URL beats leaving `api.telegram.org/file/[scrubbed]`
     * fragments behind.
     *
     * SEC-03 DISCHARGE (Doc 08 carry; ruling R-D09-1, S09 step 1): phone and
     * email VALUE patterns join the roster BEFORE the platform's first
     * external egress of conversation text (Doc 09) starts logging provider
     * request/response context — exactly where phone/email values begin to
     * flow. Key-shape scrubbing cannot catch a phone under `detail` or an
     * email inside the message string; these patterns must. Order matters:
     * email before phones, so an address whose local part is phone-shaped is
     * scrubbed WHOLE, never left as `[scrubbed]@domain`.
     *
     * BROAD PATTERN + EXPLICIT ALLOWLIST (the platform-notes law — never a
     * sweep narrowed to survive its own guard). Over-scrubbing is the safe
     * direction (this file's own header): the phone patterns tolerate
     * space/dash separators and will occasionally eat a phone-shaped digit
     * run that was not a phone; that trade is deliberate and documented.
     */
    private const VALUE_PATTERNS = [
        '~https?://api\.telegram\.org/file/\S+~i',
        '/\b\d{6,12}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9_-])/',
        // Email addresses — the standard shape, every depth + the message.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}/u',
        // E.164 phones, separator-tolerant: "+251912345678", "+251-900001234",
        // "+251 91 234 5678" — 7..15 total digits per the ITU bound.
        '/\+\d{1,3}(?:[\s-]?\d){6,12}(?!\d)/',
        // Local Ethiopian format: 09/07 + 8 digits, separator-tolerant
        // (R-D09-1 names both formats; 07 covers the 7-series mobiles).
        '/(?<!\d)0[79](?:[\s-]?\d){8}(?!\d)/',
    ];

    /**
     * Explicit allowlist consulted BEFORE value-pattern redaction: a match
     * survives only if one of these patterns accepts the WHOLE matched
     * string. Ships EMPTY — nothing benign is known to wear the shapes
     * above in a log record. Every future entry carries a citation to the
     * ruling or evidence that admitted it; narrowing a VALUE_PATTERN to
     * dodge a false positive is FORBIDDEN (the sweep-narrowed-to-survive-
     * its-own-guard law) — this allowlist is the only sanctioned relief,
     * and every addition re-plants the exact variant it exempts.
     *
     * A typed method rather than a const: the declared return type is the
     * extension point's contract, so the empty mechanism analyses as what
     * it is instead of as dead code.
     *
     * @return list<non-empty-string> regex patterns
     */
    private function valueAllowlist(): array
    {
        return [];
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        // `extra` is scrubbed too: AttachLogContext only adds `_id`-suffixed
        // keys, but a future processor must not become an unscrubbed side door.
        return $record->with(
            message: $this->scrubValue($record->message),
            context: $this->scrub($record->context, 0),
            extra: $this->scrub($record->extra, 0),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrub(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::REDACTED];
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSecretShaped($key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->scrub($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->scrubValue($value);
            }
        }

        return $values;
    }

    /** NFR-06 + SEC-03: redact secret/PII value shapes wherever they appear. */
    private function scrubValue(string $value): string
    {
        $allowlist = $this->valueAllowlist();

        foreach (self::VALUE_PATTERNS as $pattern) {
            $value = (string) preg_replace_callback(
                $pattern,
                static function (array $match) use ($allowlist): string {
                    foreach ($allowlist as $allowed) {
                        if (preg_match($allowed, $match[0]) === 1) {
                            return $match[0];
                        }
                    }

                    return self::REDACTED;
                },
                $value,
            );
        }

        return $value;
    }

    private function isSecretShaped(string $key): bool
    {
        $normalised = str_replace(['-', ' '], '_', strtolower($key));

        foreach (self::EXEMPT_SUFFIXES as $suffix) {
            if (str_ends_with($normalised, $suffix)) {
                return false;
            }
        }

        if (in_array($normalised, self::EXACT, true)) {
            return true;
        }

        foreach (self::NEEDLES as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }
}
