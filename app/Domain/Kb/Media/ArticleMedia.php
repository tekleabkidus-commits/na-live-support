<?php

declare(strict_types=1);

namespace App\Domain\Kb\Media;

use App\Domain\Conversations\Media\ClamdClient;
use App\Domain\Conversations\Media\MediaStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 FR-05 / SEC-02 / EC-05 (S10 step 11) — article images RIDE Doc 04's media
 * pipeline: the SAME clamd scan (ClamdClient — an infected upload never touches the
 * disk) and the SAME serve half (MediaStore::signedUrl for staff editing — clean-only,
 * tenant-namespace-checked). Only the WRITE is Doc 10's: MediaStore::store's key shape
 * is conversation-bound (t/{tenant}/conversations/…), so article files land beside it
 * at `t/{tenant}/kb/{article}/{uuid}.{ext}` on the SAME disk with the SAME SSE posture
 * (the S17/row-4b precedent left the non-conversation write half open; DISCLOSED at
 * the batch-D checkpoint). Customers get media ONLY through the public media door
 * (`/kb/media/{article}/{file}` — public + published enforced there); EC-05: a
 * retention-deleted file becomes `placeholder: true`, and publish is BLOCKED listing
 * the missing files (Transitions' gate calls exists()).
 */
final class ArticleMedia
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly TenantContext $context,
        private readonly ClamdClient $clamd,
        private readonly MediaStore $store,
        private readonly string $disk,
        private readonly bool $sse,
    ) {}

    /**
     * Scan then store (SEC-02: unscanned bytes never persist).
     *
     * @param  resource  $stream
     * @return array{media_key: string, file: string, url: string}
     */
    public function upload(string $articleId, string $extension, $stream): array
    {
        $extension = strtolower($extension);
        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidArgumentException('unsupported image type');
        }
        $scan = $this->clamd->scan($stream);
        if ($scan['verdict'] !== \App\Domain\Conversations\Media\ScanVerdict::Clean) {
            // Infected refuses outright; scanner-down ALSO refuses (an article image has an editor
            // to retry — no held-in-scanning state like Doc 04's inbound media needs).
            throw new MediaRefused($scan['verdict']->value, 'the file did not scan clean and was not stored');
        }
        rewind($stream);
        $file = ((string) new UuidV7).'.'.$extension;
        $key = $this->key($articleId, $file);
        $options = $this->sse ? ['ServerSideEncryption' => 'AES256'] : [];
        $this->filesystems->disk($this->disk)->writeStream($key, $stream, $options);

        return ['media_key' => $key, 'file' => $file, 'url' => $this->store->signedUrl($key, 'clean')];
    }

    public function exists(string $mediaKey): bool
    {
        return $this->filesystems->disk($this->disk)->exists($mediaKey);
    }

    /**
     * The STABLE customer-facing path (FR-05's long-lived URL): the widget media door —
     * tenant context rides the widget key, the door checks public+published, and the
     * signing rotates BEHIND the stable path. §19 builds no public site; Telegram's
     * serving half is its renderer's business ([D05]/[D12]).
     */
    public function publicPath(string $articleId, string $file): string
    {
        return '/api/widget/v1/kb/media/'.$articleId.'/'.$file;
    }

    /** Rebuild the tenant-scoped storage key from the door's route params (never trust a client-supplied key). */
    public function key(string $articleId, string $file): string
    {
        if (preg_match('/^[0-9a-f-]{36}\.[a-z]{3,4}$/', $file) !== 1) {
            throw new InvalidArgumentException('malformed media file name');
        }

        return sprintf('t/%s/kb/%s/%s', $this->context->tenantId(), $articleId, $file);
    }

    /**
     * EC-05: image blocks resolve to stable public paths; a missing file marks the block
     * `placeholder: true` instead of shipping a dead URL.
     *
     * @param  list<mixed>  $blocks
     * @return list<mixed>
     */
    public function resolveBlocks(string $articleId, array $blocks): array
    {
        return array_map(function ($block) use ($articleId) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'image' || ! is_string($block['media_key'] ?? null)) {
                return $block;
            }
            $file = basename($block['media_key']);
            if (! $this->exists($block['media_key'])) {
                return ['placeholder' => true] + array_diff_key($block, ['media_key' => true]);
            }

            return ['url' => $this->publicPath($articleId, $file)] + array_diff_key($block, ['media_key' => true]);
        }, $blocks);
    }

    /**
     * The publish gate's half (Transitions calls this beside the alt gate).
     *
     * @param  array<string, mixed>  $content
     * @return list<string> block ids whose media file is gone
     */
    public function missingMedia(array $content): array
    {
        $missing = [];
        foreach (array_values(is_array($content['blocks'] ?? null) ? $content['blocks'] : []) as $index => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'image' && is_string($block['media_key'] ?? null) && ! $this->exists($block['media_key'])) {
                $id = $block['id'] ?? null;
                $missing[] = is_string($id) ? $id : 'block:'.$index;
            }
        }

        return $missing;
    }
}
