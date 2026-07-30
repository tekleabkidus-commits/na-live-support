<?php

declare(strict_types=1);

namespace App\Support\Settings\Exceptions;

use InvalidArgumentException;

/**
 * A setting key the registry does not know (Doc 01 FR-07).
 *
 * FR-07's last clause: reading or writing an unregistered key is an error, not a silent
 * pass. This is that error, and it is thrown rather than returning null because null is
 * indistinguishable from a legitimately-false setting — a typo would then quietly
 * disable a feature in production with nothing to find.
 *
 * The message lists near-miss keys, because in practice this is almost always a typo and
 * the fix is usually visible in the suggestion.
 */
final class UnregisteredSetting extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function forKey(string $key, array $known): self
    {
        $suggestions = array_values(array_filter(
            $known,
            static fn (string $candidate): bool => levenshtein($key, $candidate) <= 4
                || str_contains($candidate, explode('.', $key)[0]),
        ));

        return new self(sprintf(
            'Setting "%s" is not registered in registries/settings.php (Doc 01 FR-07). Every setting has a '
            .'registered key, type, default and validation rule before it may be read or written; an '
            .'unregistered read would return null, and null is indistinguishable from a setting that is '
            .'legitimately off.%s',
            $key,
            $suggestions === [] ? '' : sprintf(' Did you mean: %s?', implode(', ', array_slice($suggestions, 0, 5))),
        ));
    }
}
