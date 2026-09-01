<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves committed outbox rows onto the event queues (Doc 01 T-03, FS-02, AC-04, NFR-04).
 *
 * ────────────────────────────────────────────────────────────────────────────────
 * WHAT "EXACTLY-ONCE-IN-EFFECT" ACTUALLY MEANS HERE (AC-04).
 *
 * AC-04: "crash relay between DB commit and queue push → event still delivered
 * exactly-once-in-effect."
 *
 * There is no exactly-once delivery. Two systems — Postgres and Redis — cannot be updated
 * atomically without a distributed transaction, so any relay must choose which way to fail:
 *
 *   push, then mark relayed  → a crash between them re-pushes on restart. DUPLICATE.
 *   mark relayed, then push  → a crash between them loses the event. LOSS.
 *
 * This relay chooses DUPLICATE, deliberately and permanently. A duplicate is recoverable by
 * a consumer that is idempotent on `event_id` (C-09), which is a convention CI can enforce.
 * A lost event is not recoverable by anything: nothing anywhere records that it should have
 * existed, and Doc 01 §21's warning applies — "the difference between reporting is
 * eventually right and reporting is quietly wrong".
 *
 * So "exactly-once-in-effect" is a property of the PAIR: at-least-once transport plus
 * idempotent consumers. Anyone tempted to reorder these two statements to avoid duplicates
 * is trading a handled case for an undetectable one.
 * ────────────────────────────────────────────────────────────────────────────────
 *
 * FS-02: "relay resumes from last relayed id — no loss, no duplication (consumer
 * idempotency)." Resumption is not stored state: the query itself is `relayed_at IS NULL`,
 * so the backlog IS the cursor. A relay that crashes and restarts, or two relays running
 * during a deploy, both converge without coordination — there is no watermark to corrupt.
 *
 * Runs as a PLATFORM operation, because the backlog spans every tenant and a single-tenant
 * relay would need one worker per tenant. Each event is then re-published INTO its own
 * tenant's context, so a consumer sees exactly the isolation a request would.
 *
 * The row shape below is declared once rather than cast nine times: the query builder hands
 * back stdClass with `mixed` properties, so without it every column read is an
 * undefined-property error at PHPStan max — and it documents the contract this relay depends
 * on.
 *
 * @phpstan-type OutboxRow object{
 *     id: string,
 *     tenant_id: string,
 *     event_id: string,
 *     name: string,
 *     schema_version: int,
 *     payload: string,
 *     correlation_id: string|null,
 *     causation_id: string|null,
 *     metadata: string|null,
 *     occurred_at: string,
 *     relayed_at: string|null,
 *     relay_attempts: int
 * }
 */
final class OutboxRelay
{
    /**
     * Rows per pass. Bounded so one pass cannot hold a transaction open across a huge
     * backlog after an outage, which would block the very writes that are recovering.
     */
    public const BATCH_SIZE = 100;

    /**
     * Relay one batch. Returns the number of rows relayed.
     */
    public function relayBatch(int $limit = self::BATCH_SIZE): int
    {
        return PlatformConnection::run(
            PlatformActor::RetentionJob,
            'outbox relay',
            function () use ($limit): int {
                /** @var \Illuminate\Support\Collection<int, OutboxRow> $rows */
                $rows = DB::connection(PlatformConnection::NAME)
                    ->table('outbox')
                    ->whereNull('relayed_at')
                    // UUIDv7 ordering is real ordering (C-01/C-11), so this is causal
                    // order without a wall clock (EC-08).
                    ->orderBy('id')
                    ->limit($limit)
                    ->get();

                $relayed = 0;

                foreach ($rows as $row) {
                    if ($this->relayOne($row)) {
                        $relayed++;
                    }
                }

                return $relayed;
            },
        );
    }

    /**
     * Relay a single row: append to `events` and mark it relayed, atomically.
     *
     * THE ORDER OF THESE TWO STATEMENTS DOES NOT MATTER, and it is worth saying so
     * explicitly because an earlier version of this comment claimed the opposite. They are
     * in ONE transaction, so they commit together or not at all — swapping them changes
     * nothing, which was confirmed by swapping them and watching every test still pass.
     * A comment asserting a significance that does not exist is worse than no comment: the
     * next person preserves the order carefully and learns the wrong lesson about why.
     *
     * What DOES matter is that they share a transaction. Split them and a crash between
     * leaves the reporting log and the relay bookkeeping disagreeing — either an event with
     * no record that it was relayed (re-appended forever, caught here only by the primary
     * key) or a row marked relayed with no event (silent loss).
     *
     * The QUEUE push now exists (ADR 0011): after this transaction commits, the dispatch
     * map fans the event out to its DURABLE consumers — once per row, at relay time, with
     * the queue providing durability from that point. A crash between the commit and the
     * dispatch means a consumer never ran while the event is durable in `events`; that is
     * the loss window the ADR names, recoverable by replay from the events log, and it is
     * logged loudly below rather than absorbed.
     */
    /**
     * @param  OutboxRow  $row
     */
    private function relayOne(object $row): bool
    {
        $connection = DB::connection(PlatformConnection::NAME);
        $id = $row->id;

        try {
            $connection->transaction(function () use ($connection, $row, $id): void {
                $connection->table('events')->insert([
                    // The EVENT's id, not the outbox row's — consumers are idempotent on
                    // this, so it must survive a re-publish unchanged (C-09).
                    'id' => $row->event_id,
                    'tenant_id' => $row->tenant_id,
                    'name' => $row->name,
                    'schema_version' => $row->schema_version,
                    'payload' => $row->payload,
                    'correlation_id' => $row->correlation_id,
                    'causation_id' => $row->causation_id,
                    // Packet #3 (amendment 6): envelope metadata copies byte-identically,
                    // like every other envelope field — the relay knows nothing about keys.
                    'metadata' => $row->metadata,
                    // THE COPY IS THE CONTRACT (A-33, ruled at the S07 step-1 checkpoint;
                    // BUILD-STATUS row 9 corrected there): this value is outbox.occurred_at —
                    // DB time at publish (the outbox default now()), copied byte-identically so
                    // a re-driven row re-produces the SAME composite key and the events PK
                    // (id, occurred_at) refuses the duplicate — C-09 as DATABASE PHYSICS.
                    // Supplying anything else here (a fresh clock, an app value) is sabotage
                    // #21: the A-33 byte-identical proof in EventsOccurredAtTreatmentTest goes
                    // red. Ordered reads over events get their guarantee from the T-07
                    // watermark + UUIDv7 within-stamp tiebreak (A-33 B-4), not from this stamp
                    // being relay-time.
                    'occurred_at' => $row->occurred_at,
                ]);

                $connection->table('outbox')
                    ->where('id', $id)
                    ->update([
                        'relayed_at' => $connection->raw('now()'),
                        'relay_attempts' => $connection->raw('relay_attempts + 1'),
                    ]);
            });
        } catch (Throwable $e) {
            // A single poisoned row must not stop the backlog. Counted so a row that fails
            // forever becomes visible rather than silently retried at every pass.
            $connection->table('outbox')
                ->where('id', $id)
                ->update(['relay_attempts' => $connection->raw('relay_attempts + 1')]);

            Log::error('outbox relay failed for one row', [
                'outbox_id' => $id,
                'event_name' => $row->name,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        $this->dispatchConsumers($row);

        return true;
    }

    /**
     * ADR 0011: queue every mapped durable consumer for a row that just relayed.
     *
     * AFTER the transaction, deliberately: a dispatch failure must not un-relay the
     * row (the ADR's rule — relaying gained no new failure mode). The consumer is
     * handed the event id ONLY and re-reads the row under its own tenant context;
     * tenant and correlation are stamped from the outbox row so C-14's thread runs
     * unbroken from the originating request into the consumer.
     *
     * A dispatch that THROWS (queue unreachable) is logged as an error and swallowed:
     * the row stays relayed, the event stays durable in `events`, and recovery is
     * replay from there — the same posture as a poisoned row, visible rather than
     * blocking. Failures AFTER a successful push (the consumer crashing) are not this
     * method's problem: tries ×3 and the dead-letter machinery own those (FS-02).
     *
     * @param  OutboxRow  $row
     */
    private function dispatchConsumers(object $row): void
    {
        foreach (ConsumerRegistry::consumersFor($row->name) as $consumerClass) {
            try {
                $job = new $consumerClass($row->event_id);
                $job->tenantId = $row->tenant_id;
                $job->correlationId = $row->correlation_id;

                dispatch($job);
            } catch (Throwable $e) {
                Log::error('outbox consumer dispatch failed after relay (ADR 0011) — recover by replay from events', [
                    'event_id' => $row->event_id,
                    'event_name' => $row->name,
                    'consumer' => $consumerClass,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The relay's lag, in seconds, for §12's ≤2 s p95 budget and its 60 s alert.
     *
     * Measured from the OLDEST unrelayed row, not the newest: the newest tells you the relay
     * is alive, the oldest tells you how far behind it is. A relay processing recent events
     * quickly while one old row sits stuck would look healthy by the wrong measure.
     */
    public function lagSeconds(): float
    {
        return PlatformConnection::run(
            PlatformActor::RetentionJob,
            'outbox lag probe',
            function (): float {
                // GREATEST(0, …) because this metric feeds an alert threshold and a negative
                // lag is meaningless to one — "2 seconds behind" is actionable, "-0.4 seconds
                // behind" is a puzzle, and a comparison like `lag > 60` behaves unpredictably
                // around a value that should be impossible.
                //
                // It CAN go negative, which is why the clamp is here rather than assumed away:
                // `occurred_at` defaults to Postgres `now()`, which is the WRITING
                // transaction's start time, not the moment of the INSERT. A row written late
                // in a long transaction is therefore stamped earlier than it was written —
                // and, read back from a different connection, can appear stamped in the
                // future relative to that reader's statement time. Observed as an
                // intermittently negative lag in tests.
                /** @var list<object{lag: float|string|null}> $rows */
                $rows = DB::connection(PlatformConnection::NAME)->select(
                    'SELECT GREATEST(0, COALESCE(EXTRACT(EPOCH FROM (now() - MIN(occurred_at))), 0)) AS lag
                     FROM outbox WHERE relayed_at IS NULL',
                );

                return (float) ($rows[0]->lag ?? 0);
            },
        );
    }
}
