<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersionRace;
use App\Domain\Ai\Config\ConfigVersions;
use App\Support\Tenancy\ResolutionSource;
use Illuminate\Support\Facades\DB;

/**
 * FR-04's versioned-config lifecycle over the countersigned
 * ai_config_versions physics (S09 step 2b; the doors and the FR-21 event
 * emission arrive at step 3 — this suite proves the WRITER).
 *
 * The held-window race (flag 2's countersigned condition, the addition-8
 * technique): a publish racing into a HELD uncommitted competitor must
 * surface the ConfigVersionRace SENTINEL — never a raw 23505 off the partial
 * published arbiter.
 */
function configVersions(): ConfigVersions
{
    return app(ConfigVersions::class);
}

it('FR-04: draft -> publish -> the single active version; a second publish supersedes the first', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Illuminate\Support\Str::uuid7();

    $v1 = configVersions()->draft('thresholds', 'default', ['handoff_below' => 0.6], $actor);
    configVersions()->publish($v1, $actor);

    $active = configVersions()->published('thresholds', 'default');
    expect($active)->not->toBeNull()
        ->and($active->id)->toBe($v1)
        ->and($active->version)->toBe(1)
        ->and($active->published_at)->not->toBeNull()
        ->and($active->published_by)->toBe($actor);

    $v2 = configVersions()->draft('thresholds', 'default', ['handoff_below' => 0.7], $actor);
    configVersions()->publish($v2, $actor);

    expect(configVersions()->published('thresholds', 'default')->id)->toBe($v2)
        ->and(DB::table('ai_config_versions')->where('id', $v1)->value('status'))->toBe('superseded');
});

it('FR-04: rollback REPUBLISHES the old payload as a NEW version; history stays intact', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Illuminate\Support\Str::uuid7();

    $v1 = configVersions()->draft('guardrails', 'default', ['max_ai_messages' => 8], $actor);
    configVersions()->publish($v1, $actor);
    $v2 = configVersions()->draft('guardrails', 'default', ['max_ai_messages' => 3], $actor);
    configVersions()->publish($v2, $actor);

    $v3 = configVersions()->rollbackTo($v1, $actor);

    $active = configVersions()->published('guardrails', 'default');
    expect($active->id)->toBe($v3)
        ->and($active->version)->toBe(3)
        ->and(json_decode((string) $active->payload, true))->toBe(['max_ai_messages' => 8])
        // History is append-only in substance: v1 and v2 survive untouched.
        ->and(DB::table('ai_config_versions')->where('id', $v1)->value('status'))->toBe('superseded')
        ->and(json_decode((string) DB::table('ai_config_versions')->where('id', $v2)->value('payload'), true))->toBe(['max_ai_messages' => 3]);
});

it('FR-04: only a draft publishes — a superseded version is republished, never re-flipped', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Illuminate\Support\Str::uuid7();

    $v1 = configVersions()->draft('persona', 'default', ['name' => 'Fixture Bet Support'], $actor);
    configVersions()->publish($v1, $actor);

    expect(fn () => configVersions()->publish($v1, $actor))
        ->toThrow(InvalidArgumentException::class);
});

it('flag 2 THE HELD-WINDOW RACE: a publish racing into an uncommitted competitor surfaces the ConfigVersionRace sentinel, never a raw 23505', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    commitFixtures();

    DB::purge('pgsql');
    DB::purge('pgsql_migrator');
    DB::purge('pgsql_platform');

    $dir = sys_get_temp_dir().'/s09-cfgrace-'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        bindTenant($tenantId, ResolutionSource::Console);
        $actor = (string) Illuminate\Support\Str::uuid7();
        $loserDraft = configVersions()->draft('thresholds', 'race-key', ['handoff_below' => 0.5], $actor);
        // The draft must be DURABLE before the fork: the loser republishes it
        // on a purged (fresh) connection, and the competitor's child process
        // could never see an uncommitted row either way.
        commitFixtures();

        // THE COMPETITOR (forked): inserts a PUBLISHED version for the same
        // (kind, key) in an OPEN transaction, signals, HOLDS, then commits.
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::purge('pgsql');
                $child = DB::connection('pgsql');
                $child->statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
                $child->statement('BEGIN');
                $child->statement(
                    <<<'SQL'
                    INSERT INTO ai_config_versions (id, tenant_id, kind, key, version, payload, status, created_by)
                    VALUES (?, ?, 'thresholds', 'race-key', 999, '{"handoff_below":0.9}', 'published', ?)
                    SQL,
                    [(string) Illuminate\Support\Str::uuid7(), $tenantId, $actor],
                );
                file_put_contents("{$dir}/held", '1');
                $child->statement('SELECT pg_sleep(1.2)');
                $child->statement('COMMIT');
                file_put_contents("{$dir}/committed", '1');
            } catch (Throwable $e) {
                file_put_contents("{$dir}/err", $e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $deadline = microtime(true) + 5.0;
        while (! file_exists("{$dir}/held") && ! file_exists("{$dir}/err") && microtime(true) < $deadline) {
            usleep(20_000);
        }
        expect(file_exists("{$dir}/err"))->toBeFalse('the competitor failed to arm: '.@file_get_contents("{$dir}/err"))
            ->and(file_exists("{$dir}/held"))->toBeTrue('the competitor never armed the window');

        DB::purge('pgsql');
        bindTenant($tenantId, ResolutionSource::Console);

        // The loser's supersede-UPDATE sees no committed published row; its
        // own flip blocks on the competitor's held arbiter entry until the
        // commit — then the conflict materializes. THE SENTINEL is the whole
        // assertion.
        $raced = null;
        try {
            configVersions()->publish($loserDraft, $actor);
        } catch (ConfigVersionRace $e) {
            $raced = $e;
        }

        pcntl_waitpid($pid, $status);

        expect($raced)->not->toBeNull('the race never fired — either the window closed early or the publish silently won past the arbiter')
            ->and(DB::table('ai_config_versions')->where('kind', 'thresholds')->where('key', 'race-key')->where('status', 'published')->count())->toBe(1);
    } finally {
        array_map('unlink', glob("{$dir}/*") ?: []);
        @rmdir($dir);
        DB::purge('pgsql');
        cleanupTenants([$tenantId]);
    }
});
