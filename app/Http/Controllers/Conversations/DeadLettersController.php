<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conversations;

use App\Domain\Conversations\Inbound\DeadLetterNotParked;
use App\Domain\Conversations\Inbound\DeadLetters;
use App\Http\Controllers\Controller;
use App\Models\MessageDeadLetter;
use App\Models\User;
use App\Support\Http\CursorPagination;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Rbac\PageCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FS-05's ops surface (Doc 04; ruling R-17 — the session's one class-2 design
 * fill): list parked poison messages with their full payloads, re-drive on an
 * operator's say-so. The page is gated `conversations.view` and the re-drive
 * `tickets.reply` (interpretation A-6: FS-05 names no permission; the parked
 * payload is conversation content and the re-drive is FR-13's manual-retry
 * precedent applied to the inbound side — no key invented, NFR-03).
 */
final class DeadLettersController extends Controller
{
    public function __construct(
        private readonly DeadLetters $deadLetters,
        private readonly PageCapabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->staff($request);

        $page = CursorPagination::fromRequest($request)->paginate(
            MessageDeadLetter::query()
                ->where('status', MessageDeadLetter::STATUS_PARKED)
                ->toBase(),
        );

        return Inertia::render('Conversations/DeadLetters', [
            'can' => $this->capabilities->for($user, 'Conversations/DeadLetters'),
            'copy' => __('messaging.dead_letters'),
            'chrome' => __('rbac.ui.shell'),
            'initials' => $this->initials($user),
            'parked' => $page->items->map(fn (object $row): array => $this->parkedRow($row))->values()->all(),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    public function redrive(Request $request, string $deadLetterId): JsonResponse
    {
        $user = $this->staff($request);

        try {
            $letter = $this->deadLetters->redrive($deadLetterId, $user);
        } catch (DeadLetterNotParked) {
            return ProblemDetails::response(
                code: ErrorCode::DeadLetterNotParked,
                status: 409,
                detail: (string) __('messaging.dead_letters.not_parked'),
            );
        }

        return new JsonResponse([
            'id' => $letter->identifier(),
            'status' => $letter->status,
            'redriven_at' => $letter->redriven_at?->toIso8601String(),
        ]);
    }

    /**
     * One parked row for the page — the cursor helper hands back raw stdClass
     * rows, so every property is narrowed, never cast.
     *
     * @return array<string, mixed>
     */
    private function parkedRow(object $row): array
    {
        $payload = json_decode($this->str($row->payload ?? null), true);

        return [
            'id' => $this->str($row->id ?? null),
            'channel' => $this->str($row->channel ?? null),
            'channel_ref_id' => $this->str($row->channel_ref_id ?? null),
            'error_class' => $this->str($row->error_class ?? null),
            'error_message' => $this->str($row->error_message ?? null),
            'attempts' => is_numeric($row->attempts ?? null) ? (int) $row->attempts : 0,
            'parked_at' => $this->str($row->created_at ?? null),
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** The drawn avatar's initials — data, not copy (N-03 untouched). */
    private function initials(User $user): string
    {
        $name = $user->getAttribute('name');
        $parts = array_values(array_filter(explode(' ', is_string($name) ? $name : '')));
        $first = $parts[0] ?? '';
        $last = $parts[count($parts) - 1] ?? '';

        return mb_strtoupper(mb_substr($first, 0, 1).($last !== $first ? mb_substr($last, 0, 1) : ''));
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
