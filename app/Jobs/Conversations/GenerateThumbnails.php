<?php

declare(strict_types=1);

namespace App\Jobs\Conversations;

use App\Domain\Conversations\Storage\MessageRepository;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * T-04's thumbnails: MAX three sizes, queued on the media family, never inline.
 * Reads the stored (already scanned-clean) object — thumbnails of unscanned
 * bytes cannot exist because only the Clean branch writes a storage_key.
 *
 * thumb_key points at the mid size (the §8 thread render); the full roster
 * lives in `thumbs` for Doc 07's lightbox (thumb → full on demand).
 */
final class GenerateThumbnails implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    /** T-04: "max 3 sizes" — these are the three widths, in pixels. */
    private const WIDTHS = [160, 640, 1280];

    public function __construct(
        public readonly string $messageId,
        public readonly int $index,
    ) {
        $this->captureTenant();
    }

    public function handle(MessageRepository $messages, FilesystemFactory $filesystems): void
    {
        $message = $messages->findById($this->messageId);

        if ($message === null) {
            return;
        }

        $item = $message->media[$this->index] ?? null;

        if (! is_array($item) || ($item['scan_status'] ?? null) !== 'clean' || ! is_string($item['storage_key'] ?? null)) {
            return; // only clean, stored items get thumbnails
        }

        $diskName = config('media.disk');
        $disk = $filesystems->disk(is_string($diskName) ? $diskName : 'media');
        $original = $disk->get($item['storage_key']);

        if ($original === null) {
            return;
        }

        $manager = new ImageManager(new Driver);
        $thumbs = [];

        foreach (self::WIDTHS as $width) {
            $encoded = $manager->decodeBinary($original)
                ->scaleDown(width: $width)
                ->encodeUsingMediaType('image/jpeg', quality: 80);

            $key = $item['storage_key'].'.thumb-'.$width.'.jpg';
            $disk->put($key, $encoded->toString());
            $thumbs[(string) $width] = $key;
        }

        $messages->updateMediaItem($message, $this->index, [
            'thumb_key' => $thumbs['640'],
            'thumbs' => $thumbs,
        ]);
    }
}
