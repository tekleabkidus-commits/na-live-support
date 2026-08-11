<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Domain\Inbox\Search\SearchIndex;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The event-driven indexer's WORKER (T-04, S07 step 6): dispatched by the
 * ListProjector after a recompute — DELIBERATELY A SEPARATE QUEUED JOB so a
 * Meilisearch outage fails THIS job into retry ×3 → dead-letter → re-drive
 * (FS-03's "indexer catches up on recovery") and never touches the list
 * projection riding the same event. Idempotent by construction: Meilisearch
 * addDocuments is an upsert keyed on id (C-09's posture for derived work).
 *
 * Documents carry the SCOPE KEYS the proxy filters on (department_id,
 * assignee_id) — copied from the same canonical row the lists read, so
 * search visibility and list visibility cannot drift.
 *
 * TENANCY VIA RunsWithinTenant, NOT the TenantAware middleware — a
 * DELIBERATE choice with a found defect behind it: BindTenantContextToJob
 * used to CLEAR context in its finally (correct on a queue worker), so a
 * job dispatched mid-flow under the sync driver stripped the DISPATCHER's
 * ambient binding — the projector's own suite went MissingTenantContext the
 * moment this job was wired in (the FIRST of the two sightings behind
 * ruling A-35, which made the middleware restore-what-you-found).
 * RunsWithinTenant restores the caller's binding (and skips the rebind
 * entirely when already inside the tenant); it REMAINS here as defense in
 * depth per A-35 condition (4). FR-03f's guarantee is kept by hand: a null
 * tenant fails before the handler works.
 */
final class IndexSearchDocuments implements ShouldQueue
{
    use Queueable, RunsWithinTenant;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $ticketId,
        public readonly ?string $messageId = null,
    ) {
        $this->onQueue('automation');
    }

    public function handle(SearchIndex $search): void
    {
        $this->withinTenant($this->tenantId, function () use ($search): void {
            $this->index($search);
        });
    }

    private function index(SearchIndex $search): void
    {
        /** @var object{ticket_id: string, number: int|string, subject: string|null, tags: string, customer_display_name: string|null, customer_id: string|null, department_id: string|null, assignee_id: string|null, status: string, conversation_id: string}|null $row */
        $row = DB::table('ticket_list_items')->where('ticket_id', $this->ticketId)->first();
        if ($row === null) {
            return; // no canonical row yet — the projector's next pass re-dispatches
        }

        /** @var object{username: string|null, phone: string|null}|null $customer */
        $customer = $row->customer_id !== null
            ? DB::table('customers')->where('id', $row->customer_id)->first()
            : null;

        $client = $search->client();
        $tickets = $search->ensureTickets();
        /** @var array{taskUid: int} $task */
        $task = $tickets->addDocuments([[
            'id' => $row->ticket_id,
            'number' => (string) $row->number,
            'subject' => $row->subject,
            'tags' => json_decode($row->tags, true, 8, JSON_THROW_ON_ERROR),
            'customer_display_name' => $row->customer_display_name,
            'customer_username' => $customer->username ?? null,
            'customer_phone' => $customer->phone ?? null,
            'department_id' => $row->department_id,
            'assignee_id' => $row->assignee_id,
            'status' => $row->status,
        ]]);
        $client->waitForTask($task['taskUid']);

        if ($this->messageId !== null) {
            /** @var object{id: string, body: string|null, kind: string}|null $message */
            $message = DB::table('messages')->where('id', $this->messageId)
                ->select(['id', 'body', 'kind'])->first();

            // Notes never index (N-04's posture: internal content stays out
            // of the shared search surface until Doc 10/12 rule otherwise);
            // media-only messages have no body to index.
            if ($message !== null && $message->kind !== 'note' && is_string($message->body) && $message->body !== '') {
                $messages = $search->ensureMessages();
                /** @var array{taskUid: int} $task */
                $task = $messages->addDocuments([[
                    'id' => $message->id,
                    'ticket_id' => $row->ticket_id,
                    'conversation_id' => $row->conversation_id,
                    'body' => $message->body,
                    'department_id' => $row->department_id,
                    'assignee_id' => $row->assignee_id,
                ]]);
                $client->waitForTask($task['taskUid']);
            }
        }
    }
}
