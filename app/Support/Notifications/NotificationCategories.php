<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * The code side of `registries/notifications.php` (CLAUDE.md §2: every
 * notification belongs to a registered category). A producer that names a
 * category asks HERE before it ships one — the SettingsRegistry shape, read
 * once per process. Doc 12's notify action is the first producer that takes
 * the category from OPERATOR content (a rule card), so the check must be a
 * runtime door, not a CI gate alone.
 */
final class NotificationCategories
{
    /** @var list<string>|null */
    private static ?array $keys = null;

    public static function has(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        if (self::$keys === null) {
            /** @var list<array{category_key: string}> $entries */
            $entries = require base_path('registries/notifications.php');
            self::$keys = array_map(static fn (array $entry): string => $entry['category_key'], $entries);
        }

        return self::$keys;
    }
}
