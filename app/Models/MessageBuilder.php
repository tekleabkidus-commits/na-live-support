<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * The RETURNING half of owner ruling R-19 / ADR 0010.
 *
 * `created_at` is no longer sent by the application (Message::CREATED_AT is null and the
 * app role has lost column-level INSERT on it), so PostgreSQL assigns it. This builder
 * reads the assigned value back in the SAME round trip and hydrates the model the insert
 * came from, so `$message->created_at` is populated immediately after create().
 *
 * WHY HERE AND NOT COPIED INTO EACH REPOSITORY METHOD. Eloquent's performInsert() ends
 * at `$query->insert($attributes)` on the Eloquent builder, so overriding that one method
 * covers MessageRepository's three create doors — persistInbound(), the outbound compose
 * door, and persistSystemMarker() — from a single place, and covers any future door
 * without anyone having to remember. Overriding performInsert() itself would mean copying
 * ~25 lines of framework internals (unique ids, model events, timestamps) and re-copying
 * them at every Laravel upgrade; this touches one seam instead.
 *
 * WHY NOT refresh(): it queries by `id` alone, which cannot prune partitions — 10 000
 * unpruned lookups over a soak. RETURNING adds no round trip at all.
 */
/**
 * @extends Builder<Message>
 */
final class MessageBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        // Bulk insert (a list of row arrays) has no single model to hydrate and no
        // single created_at to return — hand it straight to the framework.
        if ($values === [] || is_array(reset($values))) {
            return parent::insert($values);
        }

        // Postgres compiles insertGetId() to `insert ... returning "<column>"`, so the
        // DB-assigned timestamp comes back with the write rather than after it.
        $createdAt = $this->toBase()->insertGetId($values, 'created_at');

        $this->model->setAttribute('created_at', $createdAt);

        return true;
    }
}
