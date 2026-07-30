<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Publishes a domain event by inserting an outbox row (Doc 01 FR-08, T-03, NFR-04).
 *
 * There is no other publish path. No `event()`, no `dispatch()`, no queue push. The
 * reason is C-06 and it is worth stating in full, because "just dispatch the job"
 * is the thing every developer will reach for:
 *
 *   A queue is not transactional with the database. Dispatch inside a transaction
 *   that later rolls back and a consumer acts on a write that never happened.
 *   Dispatch after commit and a crash in the microseconds between loses the event
 *   with nothing to detect it — no error, no retry, no row anywhere saying it
 *   should have existed. The second failure is the dangerous one, because the
 *   system looks healthy and reporting is simply, permanently, quietly wrong.
 *
 * An INSERT in the business transaction has neither failure. The event commits with
 * the write or vanishes with it.
 *
 * IT THROWS IF THERE IS NO TRANSACTION. A publish outside one would commit the
 * outbox row on its own, reintroducing the first failure mode. The check is cheap
 * and it turns a subtle correctness bug into an obvious one at the call site.
 *
 * NFR-04 budgets ≤5 ms p99 added to the publishing transaction; one parameterised
 * INSERT into a table whose only index is a partial index on the backlog is well
 * inside that, and tests/Property/L1LatencyBudgetTest.php measures it rather than
 * assuming.
 */
final class EventPublisher
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $payload  must match the catalog entry exactly
     * @return string the event id, which is every consumer's idempotency key (C-09)
     */
    public function publish(string $name, array $payload, ?string $causationId = null): string
    {
        $definition = EventCatalog::definition($name);
        EventCatalog::assertPayloadMatches($name, $payload);

        if (DB::transactionLevel() === 0) {
            throw new LogicException(sprintf(
                'Event "%s" was published outside a transaction (Doc 01 T-03). The outbox row must commit '
                .'with the business write it describes, or the two can disagree: an event for a rolled-back '
                .'write, or a committed write with no event. Wrap the operation in DB::transaction().',
                $name,
            ));
        }

        $eventId = (string) Str::uuid7();

        DB::table('outbox')->insert([
            'id' => (string) Str::uuid7(),          // the outbox ROW; the relay cursor
            'tenant_id' => $this->context->tenantId(),
            'event_id' => $eventId,                 // the EVENT; stable across replays
            'name' => $name,
            'schema_version' => $definition['schema_version'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'correlation_id' => $this->context->correlationId(),
            'causation_id' => $causationId,
            // occurred_at defaults to now() in the database (EC-08/C-11).
        ]);

        return $eventId;
    }
}
