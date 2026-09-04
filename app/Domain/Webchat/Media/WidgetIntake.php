<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Media;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Models\Widget;
use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 13 FR-16 / T-03 amendment (`POST media`) — THE INTAKE, on Doc 04's physics
 * and nothing else:
 *
 *   1. the visitor's bytes land in an INTAKE object on the media disk
 *      (`t/{tenant}/widget-intake/{widget}/{visitor}/{uuid7}.{ext}`) — capped
 *      BEFORE a byte is read (`media.max_mb`, EC-08), never served, never signed
 *      for a browser (SEC-04: no download path for anything unscanned);
 *   2. `POST messages` carries `{upload_id, kind}` descriptors; `describe()` turns
 *      them into Doc 04 media items whose `source_url` is the OPAQUE scheme-keyed
 *      reference `webchat-intake:<key>` — the visitor can only name their OWN
 *      intake objects (the key prefix is the ownership check);
 *   3. Doc 04's pipeline fetches it like any channel file: the `webchat-intake`
 *      resolver (registered by WebchatServiceProvider — SourceUrlResolvers' own
 *      door, N-20) mints a fresh presigned GET on the object store's INTERNAL
 *      endpoint per attempt, the fetch rides the egress proxy (`^minio$` is on
 *      the allowlist), clamd scans, EXIF strips, the store keeps a clean copy;
 *   4. the panel reads clean media SAME-ORIGIN through `GET media/{message}/{index}`
 *      (`stream()`), so the panel's CSP stays `img-src 'self'`; a pending, infected
 *      or foreign item is a 404 (C-10).
 */
final class WidgetIntake
{
    public const SCHEME = 'webchat-intake';

    public function __construct(
        private readonly TenantContext $context,
        private readonly Filesystems $filesystems,
        private readonly SettingsRepository $settings,
        private readonly string $disk = 'media',
    ) {}

    public function capBytes(): int
    {
        $mb = $this->settings->get('media.max_mb');

        return (is_numeric($mb) ? (int) $mb : 25) * 1024 * 1024;
    }

    /** @return array{upload_id: string, kind: string, mime: string, bytes: int, name: string} */
    public function store(Widget $widget, WidgetVisitor $visitor, UploadedFile $file): array
    {
        $bytes = (int) $file->getSize();
        if ($bytes <= 0 || $bytes > $this->capBytes()) {
            throw new InvalidArgumentException('too_large');
        }
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $kind = self::kindFor($mime);
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin'));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $id = (string) Str::uuid7();
        $key = $this->prefix($widget, $visitor).$id.'.'.$ext;
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new InvalidArgumentException('unreadable');
        }
        try {
            $this->filesystems->disk($this->disk)->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }
        $name = mb_substr((string) $file->getClientOriginalName(), 0, 120);

        return ['upload_id' => $id.'.'.$ext, 'kind' => $kind, 'mime' => $mime, 'bytes' => $bytes, 'name' => $name !== '' ? $name : $id.'.'.$ext];
    }

    /**
     * The client's descriptors (`{reference: upload_id, mime?, width?, height?}`) → Doc 04 media items (source_url = the opaque intake reference).
     *
     * @param  list<mixed>  $items
     * @return list<array{source_url: string, mime: string, bytes: ?int, width: ?int, height: ?int}>
     */
    public function describe(Widget $widget, WidgetVisitor $visitor, array $items): array
    {
        $out = [];
        $disk = $this->filesystems->disk($this->disk);
        foreach ($items as $item) {
            $reference = is_array($item) ? ($item['reference'] ?? $item['upload_id'] ?? null) : null;
            if (! is_string($reference) || preg_match('/^[0-9a-f-]{36}\.[a-z0-9]{1,8}$/', $reference) !== 1) {
                throw new InvalidArgumentException('invalid_media');
            }
            $key = $this->prefix($widget, $visitor).$reference;   // the prefix IS the ownership check
            if (! $disk->exists($key)) {
                throw new InvalidArgumentException('unknown_media');
            }
            $out[] = [
                'source_url' => self::SCHEME.':'.$key,
                'mime' => is_string($item['mime'] ?? null) ? $item['mime'] : (string) ($disk->mimeType($key) ?: 'application/octet-stream'),
                'bytes' => (int) $disk->size($key),
                'width' => isset($item['width']) && is_int($item['width']) ? $item['width'] : null,
                'height' => isset($item['height']) && is_int($item['height']) ? $item['height'] : null,
            ];
        }

        return $out;
    }

    /** The resolver: a fresh presigned GET on the INTERNAL endpoint (the egress proxy's allowlisted host), per attempt. */
    public function resolve(string $reference): string
    {
        if (! str_starts_with($reference, self::SCHEME.':')) {
            throw new \RuntimeException('malformed webchat-intake reference');
        }
        $key = substr($reference, strlen(self::SCHEME) + 1);
        if (! str_starts_with($key, 't/'.$this->context->tenantId().'/widget-intake/')) {
            throw new \RuntimeException('intake reference outside this tenant');   // C-10 spirit; hold/retry never reaches it
        }
        /** @var array<string, mixed> $config */
        $config = config('filesystems.disks.'.$this->disk);
        $config['temporary_url'] = null;   // the INTERNAL endpoint, never the browser-facing media origin
        /** @var \Illuminate\Filesystem\FilesystemAdapter $internal */
        $internal = Storage::build($config);

        return $internal->temporaryUrl($key, CarbonImmutable::now('UTC')->addMinutes(10));
    }

    /**
     * The same-origin read door: the visitor's OWN message, a CLEAN item, streamed.
     *
     * @return array{stream: resource, mime: string, bytes: ?int}|null
     */
    public function open(Widget $widget, WidgetVisitor $visitor, string $messageId, int $index): ?array
    {
        $conversation = app(VisitorConversation::class)->find($widget, $visitor);
        if ($conversation === null) {
            return null;
        }
        /** @var object{media: ?string}|null $row */
        $row = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversation->identifier())->whereNull('deleted_at')->first(['media']);
        $items = $row !== null && is_string($row->media) ? json_decode($row->media, true) : null;
        $item = is_array($items) ? ($items[$index] ?? null) : null;
        if (! is_array($item) || ($item['scan_status'] ?? null) !== 'clean' || ! is_string($item['storage_key'] ?? null)) {
            return null;   // pending / infected / rejected / foreign: indistinguishable from missing (SEC-04, C-10)
        }
        $stream = $this->filesystems->disk($this->disk)->readStream($item['storage_key']);
        if ($stream === null) {
            return null;
        }

        return ['stream' => $stream, 'mime' => is_string($item['mime'] ?? null) ? $item['mime'] : 'application/octet-stream', 'bytes' => is_int($item['bytes'] ?? null) ? $item['bytes'] : null];
    }

    public static function kindFor(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'photo',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'voice',
            default => 'document',
        };
    }

    private function prefix(Widget $widget, WidgetVisitor $visitor): string
    {
        return sprintf('t/%s/widget-intake/%s/%s/', $this->context->tenantId(), VisitorConversation::widgetId($widget), $visitor->visitorId);
    }
}
