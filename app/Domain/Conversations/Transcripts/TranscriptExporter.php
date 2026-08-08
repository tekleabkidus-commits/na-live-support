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
 * FR-24/SEC-05/SS-03: the transcript export — conversation range, and since S22
 * step 8 the TICKET range (inherited row 1 closed): a ticket's transcript is its
 * anchor rows where present, else its lifetime window (interpretation A-18). Same
 * gate, same watermark, same notes double-confirm on both doors; the ticket door
 * adds `ticket_id` and `range_basis` to the AUDIT entry while the event payload
 * stays v1 (the export signal; detail is the audit's — the Q7 posture).
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
        $this->assertExportable($exporter, $format, $includeNotes, $includeNotesConfirmed);

        $messages = Message::query()
            ->where('conversation_id', $conversation->identifier())
            ->when(! $includeNotes, fn ($q) => $q->where('is_internal', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $this->render($conversation, $exporter, $format, $includeNotes, $messages, []);
    }

    /**
     * The ticket half of FR-24's "conversation or ticket range" (inherited row 1;
     * A-18): anchored tickets export exactly what their anchor ranges resolve to;
     * unanchored tickets export their lifetime window. Same gate, same watermark,
     * same double-confirm — a different SLICE, never a different door.
     *
     * @return array{content: string, mime: string, filename: string}
     */
    public function exportTicketRange(
        \App\Models\Ticket $ticket,
        User $exporter,
        string $format,
        bool $includeNotes = false,
        bool $includeNotesConfirmed = false,
    ): array {
        $this->assertExportable($exporter, $format, $includeNotes, $includeNotesConfirmed);

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->findOrFail($ticket->conversation_id);

        $hasAnchors = DB::table('ticket_message_anchors')->where('ticket_id', $ticket->identifier())->exists();

        if ($hasAnchors) {
            // A-18's anchor basis: every message inside any of the ticket's ranges,
            // bounds resolved in (created_at, id) order — BR-02's boundary table is
            // the authority on what belongs to the ticket.
            $ids = DB::select(
                'SELECT DISTINCT m.id
                   FROM ticket_message_anchors a
                   JOIN messages f ON f.id = a.from_message_id AND f.conversation_id = a.conversation_id
                   JOIN messages t ON t.id = a.to_message_id AND t.conversation_id = a.conversation_id
                   JOIN messages m ON m.conversation_id = a.conversation_id
                    AND (m.created_at, m.id) >= (f.created_at, f.id)
                    AND (m.created_at, m.id) <= (t.created_at, t.id)
                  WHERE a.ticket_id = ?',
                [$ticket->identifier()],
            );

            $messages = Message::query()
                ->whereIn('id', array_map(static fn (object $row): string => (string) $row->id, $ids))
                ->when(! $includeNotes, fn ($q) => $q->where('is_internal', false))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        } else {
            // A-18's lifetime basis: the ticket's own DB-stamped tenure.
            $messages = Message::query()
                ->where('conversation_id', $conversation->identifier())
                ->where('created_at', '>=', $ticket->created_at)
                ->when($ticket->closed_at !== null, fn ($q) => $q->where('created_at', '<=', $ticket->closed_at))
                ->when(! $includeNotes, fn ($q) => $q->where('is_internal', false))
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }

        return $this->render($conversation, $exporter, $format, $includeNotes, $messages, [
            'ticket_id' => $ticket->identifier(),
            'range_basis' => $hasAnchors ? 'anchors' : 'lifetime',
        ]);
    }

    private function assertExportable(User $exporter, string $format, bool $includeNotes, bool $includeNotesConfirmed): void
    {
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
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Message>  $messages
     * @param  array<string, string>  $auditExtra
     * @return array{content: string, mime: string, filename: string}
     */
    private function render(
        Conversation $conversation,
        User $exporter,
        string $format,
        bool $includeNotes,
        $messages,
        array $auditExtra,
    ): array {

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

        DB::transaction(function () use ($conversation, $exporter, $format, $includeNotes, $auditExtra): void {
            // The event payload is v1 on BOTH doors (the ticket detail is the
            // audit's — Q7's posture, A-18).
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
                    ...$auditExtra,
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
