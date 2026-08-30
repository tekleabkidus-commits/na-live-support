<?php

declare(strict_types=1);

namespace App\Domain\Routing\Seams;

/**
 * Doc 11 FR-08 step 1 — "conversation default (Doc 05 connection/bot routing tag)".
 * STOP-C.3 (ruled 2026-08-30): the CONNECTION-side tag is Doc 05 FR-08's and was never
 * built (no column); Doc 11 preserves the precedence SLOT and tests it against the
 * fixture `fixtures/routing/seams/doc05-connection-tags.json` in local/testing/ci. In
 * production this source returns null — step 1 falls through to the tenant default
 * department. Reopener filed on Doc 05's FR-08 BUILD-STATUS row.
 */
final class ConnectionRoutingTagSource
{
    public function tagFor(?string $connectionId): ?string
    {
        if ($connectionId === null || ! app()->environment(['local', 'testing', 'ci'])) {
            return null;
        }
        $path = base_path('fixtures/routing/seams/doc05-connection-tags.json');
        if (! is_file($path)) {
            return null;
        }
        /** @var array{connections?: list<array{connection_id: string, routing_tag: ?string}>} $seam */
        $seam = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        foreach ($seam['connections'] ?? [] as $row) {
            if ($row['connection_id'] === $connectionId) {
                return $row['routing_tag'];
            }
        }

        return null;
    }
}
