<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

use Closure;

/**
 * The just-in-time source resolver seam (ruling A-26, S23 step 5 — a RULED
 * core edit, enumerated in the zero-core-edits diff assertion).
 *
 * WHY THIS EXISTS: the chain's original contract assumed adapter-provided
 * source URLs are stable, non-secret hostile input. The FIRST REAL CHANNEL
 * broke that assumption — Telegram file URLs are short-lived AND contain the
 * bot token (Doc 05 T-07: secrets, never persisted/logged/serialized). So a
 * media item may persist an OPAQUE scheme-keyed reference instead; this
 * registry turns it into a fetchable URL PER FETCH ATTEMPT, and the real URL
 * exists only as a local variable inside that attempt.
 *
 * N-20: the registry keys on URL SCHEME, never on a channel name — adapters
 * register their resolver; the chain never branches on who they are. An
 * unrecognised or scheme-less source passes through UNCHANGED, so every
 * pre-A-26 adapter behaves exactly as before.
 *
 * Failure classification is the CALLER's contract (A-26 condition 3): a
 * resolver signals a permanently-unfetchable file by throwing MediaTooLarge
 * (the chain's terminal-reject door) and anything transient as a
 * RuntimeException (the chain's hold/retry door). Nothing may silently skip.
 */
final class SourceUrlResolvers
{
    /** @var array<string, Closure(string): string> scheme => resolver */
    private array $resolvers = [];

    /** @param Closure(string): string $resolver receives the FULL opaque reference, returns a fetchable URL */
    public function register(string $scheme, Closure $resolver): void
    {
        $this->resolvers[$scheme] = $resolver;
    }

    /** Resolve per fetch attempt; unknown shapes pass through unchanged. */
    public function resolve(string $sourceUrl): string
    {
        $colon = strpos($sourceUrl, ':');
        if ($colon === false) {
            return $sourceUrl;
        }

        $scheme = substr($sourceUrl, 0, $colon);
        $resolver = $this->resolvers[$scheme] ?? null;

        return $resolver === null ? $sourceUrl : $resolver($sourceUrl);
    }
}
