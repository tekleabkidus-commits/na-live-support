<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * T-04/SEC-02's object-store door: tenant-prefixed keys on the cookie-less
 * media disk, signed URLs with a 10-minute TTL re-issued on demand.
 *
 * SEC-04 lives HERE as an absence: signedUrl() takes the item's scan_status and
 * refuses anything but `clean` — so there is no code path that mints a URL for
 * infected (or unscanned, or pending) bytes, for ANY role. The AC-05 test
 * asserts the refusal; Doc 07's UI never gets a URL to render.
 *
 * The avatar-media seam (Doc 02 S17 ruling, inherited row 6): store()/signedUrl()
 * are channel-independent — a staff avatar upload rides scan → store() → serve
 * exactly like customer media. Doc 08 wires profile usage; nothing here changes.
 */
final class MediaStore
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly TenantContext $context,
        private readonly string $disk,
        private readonly bool $sse,
        private readonly int $signedUrlTtlSeconds,
    ) {}

    /**
     * Store scanned-clean bytes under a tenant-prefixed key (SEC-02).
     *
     * @param  resource  $stream
     */
    public function store(string $conversationId, string $messageId, int $index, string $extension, $stream): string
    {
        $key = sprintf(
            't/%s/conversations/%s/%s/%d.%s',
            $this->context->tenantId(),
            $conversationId,
            $messageId,
            $index,
            $extension,
        );

        $options = $this->sse ? ['ServerSideEncryption' => 'AES256'] : [];

        $this->filesystems->disk($this->disk)->writeStream($key, $stream, $options);

        return $key;
    }

    /**
     * A fresh signed URL (TTL 10 min, T-04) — for CLEAN items only (SEC-04).
     */
    public function signedUrl(string $storageKey, string $scanStatus): string
    {
        if ($scanStatus !== 'clean') {
            throw new DomainException(sprintf(
                'no download path exists for media with scan_status "%s" (SEC-04) — clean items only, no role exception.',
                $scanStatus,
            ));
        }

        $tenantPrefix = sprintf('t/%s/', $this->context->tenantId());

        if (! str_starts_with($storageKey, $tenantPrefix)) {
            // SEC-02: single-tenant scoping — a key from another tenant's
            // namespace is indistinguishable from a missing one (C-10 spirit).
            throw new DomainException('storage key is outside this tenant\'s namespace.');
        }

        return $this->filesystems->disk($this->disk)->temporaryUrl(
            $storageKey,
            CarbonImmutable::now('UTC')->addSeconds($this->signedUrlTtlSeconds),
        );
    }
}
