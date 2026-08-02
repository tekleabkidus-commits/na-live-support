<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FS-05 — "token table bloat: nightly prune of expired tokens with metrics and
 * index plan documented."
 *
 * WHAT IS PRUNED, EXACTLY: rows whose own `expires_at` has passed. Nothing
 * else. Revoked-but-unexpired rows stay — revocation is the audit-visible
 * state FR-18's UI shows and BR-02/BR-03 reason from, and a revoked token
 * with no expiry is precisely the row an incident review needs. An expired
 * token, by contrast, is dead by its own clock on every path (door, guard,
 * this prune), so deleting the row changes no behaviour — the audit_log
 * entries for its creation, use and revocation survive independently.
 *
 * THE INDEX PLAN (documented here per FS-05): the scan runs on
 * `personal_access_tokens_expires_at_index`, a PARTIAL index
 * (`WHERE expires_at IS NOT NULL` — see the table migration) so never-expiring
 * tokens cost it nothing; the delete is a single indexed range DELETE, no
 * per-row work in PHP.
 *
 * WHY THE PLATFORM ROLE: the app role holds no DELETE on this table — that
 * grant absence is what makes "revocation is a timestamp" physics rather than
 * discipline — and the prune is deliberately cross-tenant (every tenant's
 * expired rows, one pass). So it runs under PlatformConnection with a logged
 * actor (SEC-02), like every retention job, and REFUSES to start with a
 * tenant bound.
 *
 * The metric: one structured log line with the pruned count, keyed
 * auth.token.pruned — Doc 18's monitoring picks it up like every other named
 * metric.
 */
final class TokensPrune extends Command
{
    protected $signature = 'tokens:prune';

    protected $description = 'Delete expired API tokens platform-wide (Doc 02 FS-05)';

    public function handle(): int
    {
        $pruned = PlatformConnection::run(
            PlatformActor::RetentionJob,
            'FS-05 nightly prune of expired API tokens',
            static fn (): int => DB::connection(PlatformConnection::NAME)
                ->table('personal_access_tokens')
                ->where('expires_at', '<=', DB::raw('now()'))
                ->delete(),
        );

        Log::info('expired API tokens pruned', [
            'pruned' => $pruned,
            'metric' => 'auth.token.pruned',
        ]);

        $this->info(sprintf('tokens:prune: %d expired token(s) deleted.', $pruned));

        return self::SUCCESS;
    }
}
