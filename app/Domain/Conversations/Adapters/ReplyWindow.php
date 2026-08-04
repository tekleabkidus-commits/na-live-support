<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Adapters;

use InvalidArgumentException;

/**
 * FR-08's `reply_window: none|duration`. `none` means the channel imposes no window;
 * a duration means outbound past it fails with `window_expired` (FR-14) — a reason
 * code + i18n string this session, a composer state in Doc 05 (pre-agreed bucket).
 */
final class ReplyWindow
{
    private function __construct(public readonly ?int $seconds) {}

    public static function none(): self
    {
        return new self(null);
    }

    public static function duration(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('a reply window duration must be positive; use none().');
        }

        return new self($seconds);
    }

    public function isRestricted(): bool
    {
        return $this->seconds !== null;
    }
}
