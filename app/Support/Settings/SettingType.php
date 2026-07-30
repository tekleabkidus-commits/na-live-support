<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * The five setting types (Doc 01 FR-07, T-04).
 *
 * FR-07 names them exactly: string, int, bool, json, encrypted-string. The enum is
 * closed; a sixth type is a specification change, not a convenience.
 *
 * Every value is stored as JSONB in one column, and this enum owns the round trip. That
 * keeps the storage decision in one place rather than spread across every caller, and it
 * is what makes `encrypted-string` possible at all — the ciphertext is just a JSON
 * string, so it needs no special column and no schema change.
 */
enum SettingType: string
{
    case String = 'string';
    case Int = 'int';
    case Bool = 'bool';
    case Json = 'json';
    case EncryptedString = 'encrypted-string';

    /**
     * Encode a value for storage.
     *
     * ENCRYPTION HAPPENS HERE, not in a model cast, and that is deliberate. Laravel's
     * `encrypted` cast is attached to a named attribute, but every setting shares one
     * `value` column — so a cast would encrypt EVERY setting or none. Doing it per type
     * means the plaintext of an `encrypted-string` never touches the column while an
     * `int` stays queryable.
     *
     * KEY ROTATION comes free from Laravel's Crypt: `APP_PREVIOUS_KEYS` lets decryption
     * try retired keys, so rotating the app key does not require re-encrypting every
     * row up front. Rows re-encrypt with the current key as they are written. T-04 asks
     * for "key rotation support" and this is it — nothing in this class pins a key.
     */
    public function encode(mixed $value): mixed
    {
        return match ($this) {
            self::String => $this->requireString($value),
            self::Int => $this->requireInt($value),
            self::Bool => $this->requireBool($value),
            self::Json => $value,
            self::EncryptedString => Crypt::encryptString($this->requireString($value)),
        };
    }

    /**
     * Decode a stored value.
     *
     * A decryption failure is NOT swallowed into a null. If an encrypted setting cannot
     * be decrypted, the app key has been rotated without the old key being retained, or
     * the row is corrupt — and silently returning null would apply a DEFAULT in place of
     * an operator's real configuration. For something like a gateway credential that is
     * the difference between "SMS is misconfigured" and "SMS is silently sending from
     * the wrong account".
     */
    public function decode(mixed $stored): mixed
    {
        return match ($this) {
            self::String => $this->requireString($stored),
            self::Int => $this->requireInt($stored),
            self::Bool => $this->requireBool($stored),
            self::Json => $stored,
            self::EncryptedString => Crypt::decryptString($this->requireString($stored)),
        };
    }

    /**
     * Is this type's value safe to expose in an API response or a log?
     *
     * The one place that answers this question, so that no future surface has to
     * remember (C-13: secrets never serialise into logs, jobs or events).
     */
    public function isSecret(): bool
    {
        return $this === self::EncryptedString;
    }

    private function requireString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Setting of type %s expects a string, got %s.',
                $this->value,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private function requireInt(mixed $value): int
    {
        // Accepts an integer-valued float or numeric string because JSON round trips
        // and HTTP form input both blur the distinction; rejects 1.5 and "abc".
        if (is_int($value)) {
            return $value;
        }

        if ((is_string($value) || is_float($value)) && (string) (int) $value === (string) $value) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf(
            'Setting of type int expects an integer, got %s.',
            get_debug_type($value),
        ));
    }

    private function requireBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // Only these spellings. Deliberately NOT filter_var's full permissiveness:
        // "on"/"yes" are UI vocabulary, and accepting them here would let a typo in a
        // client become a silent `false`.
        if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            return in_array($value, [1, '1', 'true'], true);
        }

        throw new InvalidArgumentException(sprintf(
            'Setting of type bool expects a boolean, got %s.',
            get_debug_type($value),
        ));
    }
}
