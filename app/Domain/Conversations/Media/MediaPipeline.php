<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Media;

use App\Domain\Conversations\Outbound\ComposeOutbound;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Jobs\Conversations\GenerateThumbnails;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * FR-10 step 5's chain for ONE media item: fetch (streamed, capped, via the
 * egress proxy) → scan (clamd INSTREAM) → EXIF-strip (BR-06) → store (T-04) →
 * thumbnails (queued) → hooks (T-05). Nothing is ever delivered unscanned: the
 * ONLY path to a storage_key runs through a Clean verdict, and MediaStore
 * refuses signed URLs for anything else (SEC-04).
 *
 * Outcomes by branch:
 *  - EC-02 cap crossed mid-stream → scan_status `rejected`, a media_rejected
 *    SYSTEM marker for staff, and an adapter-delivered customer notice
 *    (messaging.fail.too_large) — terminal, files do not shrink.
 *  - clamd unreachable → `scanning`, held (EC-11); past the 15-min hold OR
 *    three unavailable attempts (NFR-05's ×3) → `quarantined_pending` + staff
 *    marker. There is no default-allow.
 *  - fetch transport failure → retry ×3 (FS-02's "attachment pending"), then
 *    `failed` + staff marker.
 *  - Infected → `infected` + media.infected event; the bytes are NEVER stored,
 *    so SEC-04's "no download path" is an absence, not a guard.
 *  - Clean → strip (photo/sticker), store, `clean` + media.stored, thumbnails
 *    job, and the T-05 hook event for voice (needs_transcript) / photo
 *    (needs_ocr) — Doc 09 subscribes and writes back transcript_ref/ocr_ref.
 *
 * `$now` is a parameter (§8 no-clock rule); attempt bookkeeping lives IN the
 * media item so the EC-11 clock survives worker restarts.
 */
final class MediaPipeline
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly MediaFetcher $fetcher,
        private readonly ClamdClient $clamd,
        private readonly MediaStore $store,
        private readonly MessageRepository $messages,
        private readonly EventPublisher $events,
        private readonly ComposeOutbound $compose,
        private readonly SettingsRepository $settings,
        private readonly int $scanHoldMinutes,
    ) {}

    public function process(Message $message, int $index, CarbonImmutable $now): MediaOutcome
    {
        $item = $this->item($message, $index);
        $status = is_string($item['scan_status'] ?? null) ? $item['scan_status'] : 'pending';

        if (! in_array($status, ['pending', 'scanning'], true)) {
            return MediaOutcome::Done; // idempotent on redelivery (C-09)
        }

        $conversation = Conversation::query()->findOrFail($message->conversation_id);

        $attempts = (is_int($item['scan_attempts'] ?? null) ? $item['scan_attempts'] : 0) + 1;
        $firstAttemptAt = is_string($item['first_attempt_at'] ?? null)
            ? CarbonImmutable::parse($item['first_attempt_at'])
            : $now;

        try {
            $stream = $this->fetcher->fetch(
                is_string($item['source_url'] ?? null) ? $item['source_url'] : '',
                $this->capBytes(),
            );
        } catch (MediaTooLarge) {
            $this->reject($message, $index, $conversation);

            return MediaOutcome::Done;
        } catch (RuntimeException) {
            return $this->holdOrFail($message, $index, $conversation, $attempts, $firstAttemptAt, $now, 'failed');
        }

        $scan = $this->clamd->scan($stream);

        if ($scan['verdict'] === ScanVerdict::Unavailable) {
            return $this->holdOrFail($message, $index, $conversation, $attempts, $firstAttemptAt, $now, 'quarantined_pending');
        }

        if ($scan['verdict'] === ScanVerdict::Infected) {
            DB::transaction(function () use ($message, $index, $conversation, $scan): void {
                $this->messages->updateMediaItem($message, $index, [
                    'scan_status' => 'infected',
                    'scan_signature' => $scan['signature'],
                ]);

                $this->events->publish('media.infected', [
                    'tenant_id' => $message->getAttribute('tenant_id'),
                    'message_id' => $message->identifier(),
                    'conversation_id' => $conversation->identifier(),
                    'index' => $index,
                    'signature' => $scan['signature'] ?? 'unknown',
                ]);
            });

            return MediaOutcome::Done;
        }

        // Clean: strip metadata where BR-06 demands it, then store, then hooks.
        $kind = is_string($item['kind'] ?? null) ? $item['kind'] : 'document';

        if (in_array($kind, ['photo', 'sticker'], true)) {
            $stream = $this->stripExif($stream, is_string($item['mime'] ?? null) ? $item['mime'] : 'image/jpeg');
        }

        rewind($stream);

        try {
            $storageKey = $this->store->store(
                $conversation->identifier(),
                $message->identifier(),
                $index,
                $this->extensionFor($item),
                $stream,
            );
        } catch (FilesystemException) {
            // FS-02: object storage down. Same posture as a fetch-transport
            // failure — held "attachment pending" with redispatch (retry with
            // backoff), ×3 then terminal `failed` + staff marker; text was
            // never touched (it doesn't pass through here). Found by the step
            // 9c drill: this throw previously killed the tries=1 job with the
            // item stuck in `pending` and no retry at all. Alert routing is
            // Doc 18's (bucket).
            return $this->holdOrFail($message, $index, $conversation, $attempts, $firstAttemptAt, $now, 'failed');
        }

        DB::transaction(function () use ($message, $index, $conversation, $storageKey, $kind): void {
            $this->messages->updateMediaItem($message, $index, [
                'scan_status' => 'clean',
                'storage_key' => $storageKey,
            ]);

            $base = [
                'tenant_id' => $message->getAttribute('tenant_id'),
                'message_id' => $message->identifier(),
                'conversation_id' => $conversation->identifier(),
                'index' => $index,
            ];

            $this->events->publish('media.stored', $base);

            // T-05: hooks only — provider-agnostic, Doc 09 subscribes.
            if ($kind === 'voice') {
                $this->events->publish('media.needs_transcript', $base + ['storage_key' => $storageKey]);
            }

            if ($kind === 'photo') {
                $this->events->publish('media.needs_ocr', $base + ['storage_key' => $storageKey]);
            }
        });

        if (in_array($kind, ['photo', 'sticker'], true)) {
            GenerateThumbnails::dispatch($message->identifier(), $index)->onQueue('media');
        }

        return MediaOutcome::Done;
    }

    /** EC-02: terminal rejection + staff marker + adapter-delivered customer notice. */
    private function reject(Message $message, int $index, Conversation $conversation): void
    {
        DB::transaction(function () use ($message, $index, $conversation): void {
            $this->messages->updateMediaItem($message, $index, ['scan_status' => 'rejected']);

            $this->messages->persistSystemMarker($conversation, 'media_rejected', [
                'message_id' => $message->identifier(),
                'index' => $index,
            ]);
        });

        // The customer's half rides the ordinary outbound path (fake adapter's
        // template this session; Docs 05/13 re-prove per channel — bucket).
        // Locale: tenant-default fallback chain (C-08); per-customer language
        // arrives with Doc 08's profile.
        $maxMb = $this->settings->get('media.max_mb');
        $this->compose->compose(
            $conversation,
            'system',
            null,
            'text',
            __('messaging.fail.too_large', ['max_mb' => is_int($maxMb) ? $maxMb : 0]),
        );
    }

    private function holdOrFail(
        Message $message,
        int $index,
        Conversation $conversation,
        int $attempts,
        CarbonImmutable $firstAttemptAt,
        CarbonImmutable $now,
        string $terminalStatus,
    ): MediaOutcome {
        $heldMinutes = $firstAttemptAt->diffInMinutes($now, true);

        if ($attempts >= self::MAX_ATTEMPTS || $heldMinutes >= $this->scanHoldMinutes) {
            DB::transaction(function () use ($message, $index, $conversation, $terminalStatus, $attempts): void {
                $this->messages->updateMediaItem($message, $index, [
                    'scan_status' => $terminalStatus,
                    'scan_attempts' => $attempts,
                ]);

                // EC-11's staff notice: a system marker in the thread.
                $this->messages->persistSystemMarker($conversation, 'media_'.$terminalStatus, [
                    'message_id' => $message->identifier(),
                    'index' => $index,
                ]);
            });

            return MediaOutcome::Done;
        }

        $this->messages->updateMediaItem($message, $index, [
            'scan_status' => 'scanning',
            'scan_attempts' => $attempts,
            'first_attempt_at' => $firstAttemptAt->toIso8601String(),
        ]);

        return MediaOutcome::Held;
    }

    /**
     * @param  resource  $stream
     * @return resource re-encoded via GD, which never carries EXIF through (BR-06)
     */
    private function stripExif($stream, string $mime)
    {
        rewind($stream);
        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new RuntimeException('could not read the fetched media stream for EXIF stripping.');
        }

        $encoded = (new ImageManager(new Driver))->decodeBinary($contents)->encodeUsingMediaType($mime);

        $stream = $encoded->toStream();

        if (! is_resource($stream)) {
            throw new RuntimeException('re-encode produced no stream.');
        }

        return $stream;
    }

    private function capBytes(): int
    {
        $maxMb = $this->settings->get('media.max_mb');

        if (! is_int($maxMb) || $maxMb < 1) {
            throw new RuntimeException('setting media.max_mb must be a positive int (registered validation).');
        }

        return $maxMb * 1024 * 1024;
    }

    /** @return array<string, mixed> */
    private function item(Message $message, int $index): array
    {
        $item = $message->media[$index] ?? null;

        if (! is_array($item)) {
            throw new DomainException(sprintf('message %s has no media item %d.', $message->identifier(), $index));
        }

        return $item;
    }

    /** @param array<string, mixed> $item */
    private function extensionFor(array $item): string
    {
        $mime = is_string($item['mime'] ?? null) ? $item['mime'] : 'application/octet-stream';

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
