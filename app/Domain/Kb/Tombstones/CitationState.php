<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

/**
 * Doc 10 FR-16/EC-07 — what a once-issued citation resolves to. A citation is
 * NEVER a 404: `Current` renders the article, `Retired` renders the tombstone
 * ("this article was updated/removed" + the current version's link when one
 * exists — step 7 attaches it), `Unknown` renders the tombstone without a link
 * (a ref this tenant never indexed — the C-10 posture, no existence probing).
 */
enum CitationState: string
{
    case Current = 'current';
    case Retired = 'retired';
    case Unknown = 'unknown';
}
