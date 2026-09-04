<?php

declare(strict_types=1);

use App\Support\Logging\ScrubSecrets;
use Illuminate\Support\Facades\Log;

/**
 * RIDER 1 (vault_secrets, the C-13 interim) — the confirmation clause "the
 * plaintext never leaves the process; scrubber coverage proven by a
 * plant→detect→remove control". Two VALUE shapes must never reach a sink
 * under ANY key or inside the message string: a `vault://` REF (it names a
 * secret's address) and a Laravel-encrypter CIPHERTEXT (base64 of the
 * {"iv":…} envelope — "eyJpdiI6…"). Key-shape scrubbing catches
 * `identity_secret_ref`; it cannot catch the same ref under `detail`.
 *
 * THE COUNTING BAR (S19 #7): the scanner is proven on a planted NEAR-MISS
 * first (a `vault:` value with one slash — not a ref) which must SURVIVE;
 * only then are the real shapes asserted absent. All values synthetic.
 */
function vaultScrubSink(): string
{
    $path = sys_get_temp_dir().'/scrub-sink-'.bin2hex(random_bytes(8)).'.log';
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single'],
        'logging.channels.single.path' => $path,
    ]);
    Log::forgetChannel('stack');
    Log::forgetChannel('single');

    return $path;
}

it('RIDER 1 control (P-RIDER1-FIX): a vault:// ref and an encrypter ciphertext are scrubbed under an innocent key AND inside the message; the two near-misses (a one-slash vault: value, an innocent eyJ… base64) survive — the scanner is proven first and never over-matches', function (): void {
    $path = vaultScrubSink();
    $ref = 'vault://widget/018f6b2e-0000-7000-8000-00000000d131/v1';
    $ciphertext = 'eyJpdiI6IkZBS0VDUkVEZmFrZWl2ZmFrZWl2ZmFrZSIsInZhbHVlIjoiRkFLRUNSRURmYWtldmFsdWVmYWtldmFsdWVmYWtldmFsdWUiLCJtYWMiOiJmYWtlbWFjZmFrZW1hY2Zha2VtYWNmYWtlbWFjIiwidGFnIjoiIn0=';
    $nearMiss = 'vault:/notaref';
    // Countersign condition (1): an INNOCENT base64 string that begins with the same three characters
    // ("eyJ…" — base64 of any '{"' JSON) but is NOT an encrypter envelope must SURVIVE: the scrubber
    // must not go blind by over-matching (the platform-notes law). base64('{"note":"FAKECRED not a secret"}').
    $innocentBase64 = 'eyJub3RlIjoiRkFLRUNSRUQgbm90IGEgc2VjcmV0In0=';

    Log::info('near-miss '.$nearMiss, ['detail' => $nearMiss, 'blob' => $innocentBase64]);
    Log::info('rotated to '.$ref, ['detail' => $ref, 'blob' => $ciphertext]);

    $sink = (string) file_get_contents($path);
    expect($sink)->toContain($nearMiss)                        // plant → detect: the write path and the scanner both work
        ->and($sink)->toContain($innocentBase64);              // the near-miss envelope SURVIVES — no over-matching
    expect($sink)->not->toContain($ref)                        // remove: the ref never lands — under `detail` or in the message
        ->and($sink)->not->toContain('vault://')
        ->and($sink)->not->toContain($ciphertext)
        ->and($sink)->not->toContain('eyJpdiI6')
        ->and(substr_count($sink, ScrubSecrets::REDACTED))->toBeGreaterThanOrEqual(3);
    @unlink($path);
});
