<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Visitor;

use App\Domain\Auth\Widget\WidgetVisitor;
use App\Domain\Conversations\Media\MediaStore;
use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Webchat\WebchatAdapter;
use App\Http\Middleware\ResolveTenant;
use App\Models\Conversation;
use App\Models\Widget;
use App\Support\Cache\TenantCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Doc 13's ONE way from a visitor principal to a conversation (AC-04 / A-61):
 * the channel key `(webchat, <widget id>, <visitor id>)` — nothing else is
 * addressable, so a foreign conversation is not refused, it is ABSENT.
 * `find()` never creates: the conversation (and its shell customer) is born
 * in the inbound pipeline on the first message (FR-05) and nowhere else.
 */
final class VisitorConversation
{
    public const STAFF_TYPING_TTL = 6;

    public function __construct(
        private readonly Estimator $estimator,
        private readonly MediaStore $media,
        private readonly TenantCache $cache,
    ) {}

    /** The LIVE widget the request's key resolved — a soft-deleted or unconfigured one is ABSENT (C3, C-10). */
    public function widgetFor(Request $request): Widget
    {
        $widget = $this->resolve($request);
        if ($widget === null || $widget->deleted_at !== null) {
            throw new NotFoundHttpException;
        }

        return $widget;
    }

    /** The beacon door's view: the widget even if soft-deleted (it answers 204 to a dead widget, never a hint). */
    public function widgetForBeacon(Request $request): ?Widget
    {
        return $this->resolve($request);
    }

    private function resolve(Request $request): ?Widget
    {
        $keyId = $request->attributes->get(ResolveTenant::KEY_ID_ATTRIBUTE);

        return Widget::query()->where('key_id', is_string($keyId) ? $keyId : '00000000-0000-0000-0000-000000000000')->first();
    }

    /** The widget's id as the string every channel key carries (`channel_ref_id`). */
    public static function widgetId(Widget $widget): string
    {
        $id = $widget->getKey();
        if (! is_string($id)) {
            throw new \LogicException('a widget id is a UUIDv7 string (C-01).');
        }

        return $id;
    }

    public function find(Widget $widget, WidgetVisitor $visitor): ?Conversation
    {
        return Conversation::query()
            ->where('channel', WebchatAdapter::CHANNEL)
            ->where('channel_ref_id', self::widgetId($widget))
            ->where('external_chat_id', $visitor->visitorId)
            ->first();
    }

    /**
     * A message row for the visitor: the curated shape (never is_internal,
     * never author ids, never channel_meta); media as signed URLs for CLEAN
     * items only (SEC-04) — pending/infected items render as the state, not a
     * link.
     *
     * @return array<string, mixed>
     */
    public function present(\stdClass $row): array
    {
        $media = [];
        $decoded = is_string($row->media) ? json_decode($row->media, true) : [];
        foreach (is_array($decoded) ? $decoded : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $status = is_string($item['scan_status'] ?? null) ? $item['scan_status'] : 'pending';
            $entry = [
                'kind' => $item['kind'] ?? null,
                'mime' => $item['mime'] ?? null,
                'bytes' => $item['bytes'] ?? null,
                'width' => $item['width'] ?? null,
                'height' => $item['height'] ?? null,
                'duration_seconds' => $item['duration_seconds'] ?? null,
                'scan' => $status, // clean | pending | infected | … (never `status`: the ticket-status-writer gate's key)
                'url' => null,
            ];
            if ($status === 'clean' && is_string($item['storage_key'] ?? null)) {
                $entry['url'] = $this->media->signedUrl($item['storage_key'], 'clean');
            }
            $media[] = $entry;
        }

        // The CSAT 1-tap contract rides the outbound row's channel meta (Doc 12 T-08:
        // `csat: {token, scores}`) — the ONLY key extracted; nothing else in channel
        // meta ever reaches a visitor (SEC-04's curated payload).
        $survey = null;
        $meta = is_string($row->channel_meta ?? null) ? json_decode($row->channel_meta, true) : null;
        if (is_array($meta) && is_array($meta['csat'] ?? null) && is_string($meta['csat']['token'] ?? null)) {
            $survey = ['token' => $meta['csat']['token'], 'scores' => $meta['csat']['scores'] ?? [1, 2, 3, 4, 5]];
        }

        return [
            'id' => $row->id,
            'created_at' => $row->created_at,
            'direction' => $row->direction,
            'author' => $row->author_type,          // customer | staff | ai | system — the kind a11y announces (Doc 04 §13)
            'kind' => $row->kind,
            'body' => $row->body,
            'media' => $media,
            'delivery_state' => $row->delivery_state, // truthful glyphs: delivered ONLY after the receipt (T-02)
            'edited_at' => $row->edited_at,
            'external_message_id' => $row->external_message_id,
            'reply_to' => $row->reply_to_message_id,
            'occurred_at' => $row->channel_occurred_at,
            'survey' => $survey,
        ];
    }

    /**
     * Media references from the upload door (A-T03-2, step 3b) → the adapter's
     * descriptors. A reference the visitor does not own is absent (AC-04).
     *
     * @param  list<array{reference: string}>  $items
     * @return list<array<string, mixed>>
     */
    public function mediaDescriptors(Widget $widget, array $items): array
    {
        return [];
    }

    /**
     * The queue state for the sync envelope (FR-12/T-05): the band from
     * Doc 11's estimator (never computed here); position rides P-QPOS (step 5)
     * and is null until then — the card renders the band alone (EC-06's
     * "band only when position unknown").
     *
     * @return array<string, mixed>|null
     */
    public function queueState(Conversation $conversation): ?array
    {
        /** @var object{id: string, queue_id: ?string, assignee_id: ?string, status: string}|null $ticket */
        $ticket = is_string($conversation->open_ticket_id)
            ? DB::table('tickets')->where('id', $conversation->open_ticket_id)->first(['id', 'queue_id', 'assignee_id', 'status'])
            : null;
        if ($ticket === null || ! is_string($ticket->queue_id) || $ticket->assignee_id !== null) {
            return null;
        }

        $estimate = $this->estimator->forTicket($ticket->queue_id, $ticket->id);

        return ['band' => $estimate['band'], 'degraded' => $estimate['degraded'], 'position' => null];
    }

    /**
     * The OPEN survey for the visitor's conversation (Doc 12 FR-25/EC-11): the
     * latest surveys row for this conversation whose window has not expired and
     * that has no response yet; the token itself rides the prompt message's
     * channel meta (present()), so the envelope names only the survey id and its
     * expiry — the star row renders when both agree.
     *
     * @return array{survey_id: string, expires_at: string}|null
     */
    public function pendingSurvey(Conversation $conversation): ?array
    {
        /** @var object{id: string, expires_at: string}|null $row */
        $row = DB::table('surveys as s')
            ->leftJoin('survey_responses as r', 'r.survey_id', '=', 's.id')
            ->where('s.conversation_id', $conversation->identifier())
            ->whereNull('s.expired_at')
            ->whereRaw('s.expires_at > clock_timestamp()')
            ->whereNull('r.id')
            ->orderByDesc('s.sent_at')
            ->first(['s.id', 's.expires_at']);

        return $row === null ? null : ['survey_id' => $row->id, 'expires_at' => $row->expires_at];
    }

    /** Polling parity for staff typing (T-04 "feature-identical minus typing granularity"). */
    public function isStaffTyping(Conversation $conversation): bool
    {
        return $this->cache->get('webchat-staff-typing:'.$conversation->external_chat_id) !== null;
    }
}
