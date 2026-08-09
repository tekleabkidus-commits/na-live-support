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
     */
    private const VALUE_PATTERNS = [
        '~https?://api\.telegram\.org/file/\S+~i',
        '/\b\d{6,12}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9_-])/',
    ];

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

    /** NFR-06: redact Telegram token/file-URL shapes wherever they appear. */
    private function scrubValue(string $value): string
    {
        return (string) preg_replace(self::VALUE_PATTERNS, self::REDACTED, $value);
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
