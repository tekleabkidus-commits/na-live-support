<?php

declare(strict_types=1);

use App\Domain\Conversations\Media\MediaStore;

/**
 * P-MEDIA-URL (Doc 04 `config/filesystems.php` media disk, countersigned
 * 2026-09-04): the cookie-less media origin as its OWN host. Presigned URLs
 * are rebased onto `AWS_TEMPORARY_URL` (the HARNESS media host in dev, Doc 18's
 * media domain in production); empty = unchanged. RED before the packet (the
 * disk config has no `temporary_url`, so the URL names the in-cluster store
 * host no browser can reach); green after. The HTTP legs (200 with NO
 * Set-Cookie through the HARNESS; a tampered signature 403 at the store) are
 * the host-side capture in docs/evidence/S13-step2b/media-origin-*.txt.
 */
it('P-MEDIA-URL: the media disk wires AWS_TEMPORARY_URL and a signed URL resolves on the MEDIA host, signed and TTL\'d', function (): void {
    $public = 'https://media.harness.localhost:8443';
    // All three, $_SERVER first — the container publishes env there and Laravel reads it first (tests/bootstrap.php's law).
    $_SERVER['AWS_TEMPORARY_URL'] = $public;
    $_ENV['AWS_TEMPORARY_URL'] = $public;
    putenv('AWS_TEMPORARY_URL='.$public);

    // The config file must READ the env (the packet's one line) — not a runtime override.
    $fresh = require base_path('config/filesystems.php');
    expect($fresh['disks']['media']['temporary_url'] ?? null)->toBe($public, 'config/filesystems.php does not wire AWS_TEMPORARY_URL on the media disk');

    config()->set('filesystems.disks.media.temporary_url', $public);
    Illuminate\Support\Facades\Storage::forgetDisk('media');
    try {

        [$tenant] = widgetFixture();
        bindTenant($tenant);
        $key = sprintf('t/%s/conversations/fixture/msg-1/0.jpg', $tenant->identifier());

        $url = app(MediaStore::class)->signedUrl($key, 'clean');
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);

        expect($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''))->toBe($public, 'the signed URL does not resolve on the media host')
            ->and($query)->toHaveKey('X-Amz-Signature')
            ->and((int) ($query['X-Amz-Expires'] ?? 0))->toBe(600, 'T-04: a 10-minute TTL');
    } finally {
        // Leave the process as the bootstrap set it: no public base for later tests.
        $_SERVER['AWS_TEMPORARY_URL'] = '';
        $_ENV['AWS_TEMPORARY_URL'] = '';
        putenv('AWS_TEMPORARY_URL=');
        config()->set('filesystems.disks.media.temporary_url', null);
        Illuminate\Support\Facades\Storage::forgetDisk('media');
    }
});

it('SEC-04 stands: an unscanned key has no download path even on the media host', function (): void {
    [$tenant] = widgetFixture();
    bindTenant($tenant);

    expect(fn () => app(MediaStore::class)->signedUrl(sprintf('t/%s/x', $tenant->identifier()), 'pending'))
        ->toThrow(DomainException::class);
});
