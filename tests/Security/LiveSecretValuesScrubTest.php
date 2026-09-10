<?php

declare(strict_types=1);

use App\Support\Logging\ScrubSecrets;
use App\Support\Secrets\LiveSecretValues;
use App\Support\Secrets\VaultInterim;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * P-SCRUB-LIVE (Doc 02 core, STOP-G; the P-RIDER1-FIX shape generalized) —
 * the plant→detect→remove control, RED FIRST on the tree without it.
 *
 * A gateway token's SHAPE is unknown (vendor notes §2), so no regex can be
 * written honestly. Instead: `VaultInterim::reveal()` registers the revealed
 * plaintext in a process-local, request/job-scoped `LiveSecretValues` set,
 * and `ScrubSecrets` consults it as an exact-substring VALUE scrub — under
 * any key and inside the message — for every vault-held secret the moment it
 * is revealed. Conditions (the advisor's STOP-G ruling): bounded (≤ 32),
 * non-serializable, cleared at exactly the TenantContext clear points, the
 * scrub applying at ≥ 12 characters.
 */
function liveScrubSink(): string
{
    $path = sys_get_temp_dir().'/live-scrub-sink-'.bin2hex(random_bytes(8)).'.log';
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single'],
        'logging.channels.single.path' => $path,
    ]);
    Log::forgetChannel('stack');
    Log::forgetChannel('single');

    return $path;
}

it('plant → detect → remove: a revealed vault secret is scrubbed under an innocent key AND inside the message; the same value unrevealed lands (the scanner proven first)', function (): void {
    $tenant = createTenant();
    $path = liveScrubSink();
    $secret = 'fixture-geezsms-token-9f2c4e7a1b';   // 32 chars, token-shaped in no known way

    asTenant($tenant, function () use ($secret, $path): void {
        // PLANT (the scanner control): the value logged BEFORE any reveal must land — the sink and the scanner work
        Log::info('before reveal '.$secret, ['detail' => $secret]);
        expect((string) file_get_contents($path))->toContain($secret);

        $vault = app(VaultInterim::class);
        $ref = $vault->put('vault://sms/fixture-gateway/token/v1', $secret);
        $revealed = $vault->reveal($ref);
        expect($revealed)->toBe($secret);

        // DETECT: after the reveal, the SAME value under an innocent key and inside the message is scrubbed
        Log::info('after reveal '.$secret, ['detail' => $secret, 'nested' => ['deep' => 'x'.$secret.'y']]);
        $sink = (string) file_get_contents($path);
        $after = substr($sink, strpos($sink, 'after reveal') ?: 0);
        expect($after)->not->toContain($secret)
            ->and(substr_count($after, ScrubSecrets::REDACTED))->toBeGreaterThanOrEqual(3);
    });

    // REMOVE: the TenantContext clear point (the asTenant boundary) clears the set — the value survives into a later log line
    // ONLY if it is logged again unrevealed (which proves the clear, not a leak: nothing revealed it here)
    Log::info('next request '.$secret);
    expect((string) file_get_contents($path))->toContain('next request '.$secret);
    @unlink($path);
});

it('is bounded (≤ 32 values), refuses short values (< 12 chars are not scrubbed by value), and cannot be serialized', function (): void {
    LiveSecretValues::clear();
    foreach (range(1, 40) as $i) {
        LiveSecretValues::register(sprintf('fixture-secret-value-%04d', $i));
    }
    expect(count(LiveSecretValues::all()))->toBeLessThanOrEqual(32);

    LiveSecretValues::register('short');
    expect(LiveSecretValues::all())->not->toContain('short');

    $ref = new ReflectionClass(LiveSecretValues::class);
    expect($ref->getConstructor()?->isPrivate())->toBeTrue('the set must not be instantiable — nothing to serialize')
        ->and($ref->isFinal())->toBeTrue();

    LiveSecretValues::clear();
    expect(LiveSecretValues::all())->toBe([]);
});

it('is cleared at exactly the TenantContext clear points — a value revealed in one request does not survive into the next', function (): void {
    $tenant = createTenant();

    asTenant($tenant, function (): void {
        LiveSecretValues::register('fixture-secret-value-survives?');
        expect(LiveSecretValues::all())->toContain('fixture-secret-value-survives?');
    });

    // asTenant's exit is the TenantContext::clear() call site (the same one ResolveTenant's finally and
    // BindTenantContextToJob use)
    expect(LiveSecretValues::all())->toBe([]);

    app(TenantContext::class)->clear();
    expect(LiveSecretValues::all())->toBe([]);
});

it('condition (countersign, 2026-09-08): LiveSecretValues::all() has exactly ONE app caller — ScrubSecrets; the scanner is proven on a positive control', function (): void {
    $scan = static function (string $haystack): bool {
        return preg_match('/LiveSecretValues::all\s*\(/', $haystack) === 1;
    };

    $callers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_ends_with($file->getFilename(), 'LiveSecretValues.php')) {
            continue;
        }
        if ($scan((string) file_get_contents($file->getPathname()))) {
            $callers[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }
    expect($callers)->toBe(['app/Support/Logging/ScrubSecrets.php']);

    // the POSITIVE control: the same scanner DOES see a call when one exists
    expect($scan('<?php $x = \App\Support\Secrets\LiveSecretValues::all();'))->toBeTrue();
});
