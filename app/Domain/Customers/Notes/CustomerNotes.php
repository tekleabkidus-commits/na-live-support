<?php

declare(strict_types=1);

namespace App\Domain\Customers\Notes;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * FR-17: per-customer notes — author + timestamp, one pinned (it surfaces in
 * the Doc 07 panel header), never customer-visible.
 *
 * N-04 IS STRUCTURAL HERE, NOT DISCIPLINE (Doc 04 BR-01's isolation, this
 * table's version): customer_notes is its own table with NO adapter path —
 * nothing in the Conversations outbound pipeline or any channel adapter
 * references it, and CustomerNotesTest's architecture sweep proves that
 * ABSENCE (sabotage #15 routes a note body toward a deliverable path and
 * the sweep goes red). A note is not a message and can never become one.
 *
 * BR-07: notes STAY on the profile — there is no move/copy-to-conversation
 * affordance anywhere; the onboarding copy for this ships as i18n strings
 * only (advisor correction 3).
 */
final class CustomerNotes
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return string the note id */
    public function add(string $customerId, string $body, string $authorId, bool $pinned = false): string
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('a customer note requires a body (FR-17)');
        }

        return DB::transaction(function () use ($customerId, $body, $authorId, $pinned): string {
            $noteId = (string) new UuidV7;

            if ($pinned) {
                // ONE pinned note per customer (FR-17): pinning the new one
                // unpins the incumbent — the merge machinery's survivor-pin
                // precedent, applied to the ordinary write path.
                DB::table('customer_notes')->where('customer_id', $customerId)
                    ->where('pinned', true)->update(['pinned' => false]);
            }

            DB::table('customer_notes')->insert([
                'id' => $noteId,
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'author_id' => $authorId,
                'body' => $body,
                'pinned' => $pinned,
            ]);

            $this->events->publish('customer.note.added', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'note_id' => $noteId,
                'author_id' => $authorId,
                'pinned' => $pinned,
            ]);
            $this->audit->record('customer.note.added', 'customer', $customerId, AuditActor::Staff, ['note_id' => $noteId, 'pinned' => $pinned], actorId: $authorId);

            return $noteId;
        });
    }

    /**
     * The curated notes listing for the panel tab (newest first, pinned on
     * top; body included — customers.notes.view is the door's gate).
     *
     * @return list<array{id: string, author_id: ?string, body: string, pinned: bool, created_at: string}>
     */
    public function listFor(string $customerId, int $limit = 50): array
    {
        /** @var list<object{id: string, author_id: ?string, body: string, pinned: bool, created_at: string}> $rows */
        $rows = DB::table('customer_notes')->where('customer_id', $customerId)
            ->orderByDesc('pinned')->orderByDesc('created_at')->limit(min($limit, 100))
            ->get(['id', 'author_id', 'body', 'pinned', 'created_at'])->all();

        return array_map(static fn (object $row): array => [
            'id' => $row->id,
            'author_id' => $row->author_id,
            'body' => $row->body,
            'pinned' => (bool) $row->pinned,
            'created_at' => $row->created_at,
        ], $rows);
    }
}
