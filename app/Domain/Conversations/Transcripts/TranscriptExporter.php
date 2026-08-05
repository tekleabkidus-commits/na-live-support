<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Transcripts;

use App\Domain\Conversations\Media\MediaStore;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Rbac\CapabilityMapProvider;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

/**
 * FR-24/SEC-05/SS-03: the conversation-range transcript export. (The
 * ticket-range half waits for Doc 06's ticket windows — pre-agreed bucket.)
 *
 * The permission check is Doc 03 FR-06's EXPORT RE-CHECK, consumed for the
 * first time here (inherited row 1): the verdict resolves through the provider
 * AT EXPORT TIME — a revoked `conversations.transcript.export` refuses this
 * call no matter what any earlier page load believed.
 *
 * Notes are EXCLUDED BY DEFAULT (US-04: a payment-dispute transcript must not
 * leak internal chatter); including them takes BOTH flags — the second is the
 * §8 double-confirm made structural. The watermark names the exporting user
 * and the DB-time instant (SEC-05); the export is audited and events out.
 *
 * PDF: dompdf with the embedded Noto Sans Ethiopic (ADR 0009) — remote loading
 * OFF (an exporter that fetches URLs is an SSRF surface), chroot pinned to the
 * font directory. Media appear as signed links (10-min TTL — a reader
 * re-requests; embedding thumbs is FR-24's option, reserved).
 */
final class TranscriptExporter
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly MediaStore $media,
    ) {}

    /**
     * @return array{content: string, mime: string, filename: string}
     */
    public function export(
        Conversation $conversation,
        User $exporter,
        string $format,
        bool $includeNotes = false,
        bool $includeNotesConfirmed = false,
    ): array {
        if (! in_array($format, ['text', 'pdf'], true)) {
            throw new DomainException('transcript format is text or pdf (FR-24).');
        }

        // Doc 03 FR-06 export half: the re-check happens AT the export.
        if (! $this->verdicts->verdictFor($exporter, 'conversations.transcript.export')->allowed) {
            throw new DomainException('conversations.transcript.export is required (FR-24; Doc 03 FR-06 re-check).');
        }

        if ($includeNotes && ! $includeNotesConfirmed) {
            throw new DomainException('including internal notes requires the second confirmation (FR-24 double-confirm; US-04).');
        }

        $messages = Message::query()
            ->where('conversation_id', $conversation->identifier())
            ->when(! $includeNotes, fn ($q) => $q->where('is_internal', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $stampValue = DB::scalar('SELECT now()::text');
        $stamp = is_string($stampValue) ? $stampValue : '';
        $exporterName = $exporter->getAttribute('name');
        $watermark = __('messaging.transcript.watermark', [
            'name' => is_string($exporterName) ? $exporterName : '',
            'at' => $stamp,
        ]);

        $lines = $messages->map(function (Message $message): string {
            $body = $message->deleted_at !== null
                ? __('messaging.transcript.deleted_tombstone')
                : ($message->body ?? '');

            $mediaLinks = [];
            foreach ($message->media as $item) {
                $key = ($item['scan_status'] ?? null) === 'clean' ? ($item['storage_key'] ?? null) : null;
                if (is_string($key)) {
                    $mediaLinks[] = $this->media->signedUrl($key, 'clean');
                }
            }

            return sprintf(
                '[%s] %s: %s%s',
                $message->created_at?->toIso8601String() ?? '',
                $message->author_type,
                is_string($body) ? $body : '',
                $mediaLinks === [] ? '' : ' '.implode(' ', $mediaLinks),
            );
        })->all();
        $lines = array_values($lines);

        $result = $format === 'text'
            ? $this->asText($watermark, $lines)
            : $this->asPdf($watermark, $lines);

        DB::transaction(function () use ($conversation, $exporter, $format, $includeNotes): void {
            $this->events->publish('transcript.exported', [
                'tenant_id' => $conversation->getAttribute('tenant_id'),
                'conversation_id' => $conversation->identifier(),
                'exporter_id' => $exporter->identifier(),
                'format' => $format,
            ]);

            $this->audit->record(
                actionKey: 'conversation.transcript_exported',
                entity: 'conversation',
                entityId: $conversation->identifier(),
                actorType: AuditActor::Staff,
                actorId: $exporter->identifier(),
                payload: [
                    'conversation_id' => $conversation->identifier(),
                    'format' => $format,
                    'notes_included' => $includeNotes,
                ],
            );
        });

        return $result;
    }

    /**
     * @param  list<string>  $lines
     * @return array{content: string, mime: string, filename: string}
     */
    private function asText(string $watermark, array $lines): array
    {
        return [
            'content' => $watermark."\n\n".implode("\n", $lines)."\n",
            'mime' => 'text/plain',
            'filename' => 'transcript.txt',
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{content: string, mime: string, filename: string}
     */
    private function asPdf(string $watermark, array $lines): array
    {
        $fontDir = resource_path('fonts');

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setChroot($fontDir);

        $body = implode('', array_map(
            static fn (string $line): string => '<div class="line">'.e($line).'</div>',
            $lines,
        ));

        $html = <<<HTML
            <style>
                @font-face {
                    font-family: 'NotoSansEthiopic';
                    src: url('{$fontDir}/NotoSansEthiopic-Regular.ttf') format('truetype');
                }
                body { font-family: 'NotoSansEthiopic', sans-serif; font-size: 11px; }
                .watermark { color: #888; font-size: 9px; margin-bottom: 12px; }
                .line { margin-bottom: 4px; }
            </style>
            <div class="watermark">{$watermark}</div>
            {$body}
            HTML;

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return [
            'content' => (string) $dompdf->output(),
            'mime' => 'application/pdf',
            'filename' => 'transcript.pdf',
        ];
    }
}
