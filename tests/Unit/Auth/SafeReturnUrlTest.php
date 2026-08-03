<?php

declare(strict_types=1);

use App\Support\Auth\SafeReturnUrl;

/**
 * The FR-11 return-URL guard (Doc 02 FR-11; owner ruling, step 13).
 *
 * THE REFUSAL FIXTURES ARE PINNED BY THE RULING and must never shrink:
 * absolute URLs, protocol-relative //host, and the backslash variants /\host
 * and \/host — browsers normalise `\` to `/` during authority detection, so a
 * naive "starts with /" guard passes exactly the shapes that redirect
 * off-origin. Positive controls prove the guard still lets real return paths
 * through; a guard that refuses everything is a different bug wearing a green
 * test.
 */
const TRUSTED_ORIGIN = 'https://localhost';

it('refuses the ruled shapes: absolute, protocol-relative, and both backslash variants', function (string $candidate): void {
    expect(SafeReturnUrl::sanitize($candidate, TRUSTED_ORIGIN))->toBe('/');
})->with([
    'absolute URL' => 'https://evil.example/security',
    'protocol-relative' => '//evil.example',
    'backslash variant /\\' => '/\\evil.example',
    'backslash variant \\/' => '\\/evil.example',
]);

it('refuses the CLASS those shapes belong to, not just the four examples', function (string $candidate): void {
    expect(SafeReturnUrl::sanitize($candidate, TRUSTED_ORIGIN))->toBe('/');
})->with([
    'any interior backslash' => '/security\\..\\evil',
    'triple slash' => '///evil.example',
    'scheme with no slash' => 'javascript:alert(1)',
    'CR-LF (header injection material)' => "/security\r\nSet-Cookie: x",
    'other control characters' => "/security\x00",
    'origin lookalike host' => 'https://localhost.evil.example/security',
    'empty string' => '',
    'bare origin prefix without separator' => 'https://localhostevil.example',
]);

it('refuses non-strings without dying', function (): void {
    expect(SafeReturnUrl::sanitize(null, TRUSTED_ORIGIN))->toBe('/')
        ->and(SafeReturnUrl::sanitize(123, TRUSTED_ORIGIN))->toBe('/')
        ->and(SafeReturnUrl::sanitize(['x'], TRUSTED_ORIGIN))->toBe('/');
});

it('POSITIVE CONTROL: same-origin relative paths pass through untouched', function (string $candidate): void {
    expect(SafeReturnUrl::sanitize($candidate, TRUSTED_ORIGIN))->toBe($candidate);
})->with([
    'plain path' => '/security',
    'path with query' => '/security?tab=sessions',
    'path with fragment' => '/inbox#unread',
]);

it('reduces a same-origin ABSOLUTE URL to its local path — the shape the framework guest redirect stores', function (): void {
    expect(SafeReturnUrl::sanitize('https://localhost/security?tab=1', TRUSTED_ORIGIN))->toBe('/security?tab=1')
        // The bare origin has no path worth returning to.
        ->and(SafeReturnUrl::sanitize('https://localhost', TRUSTED_ORIGIN))->toBe('/')
        // Reduction earns no trust: the remainder faces the same rules.
        ->and(SafeReturnUrl::sanitize('https://localhost//evil.example', TRUSTED_ORIGIN))->toBe('/')
        ->and(SafeReturnUrl::sanitize('https://localhost/\\evil.example', TRUSTED_ORIGIN))->toBe('/');
});
