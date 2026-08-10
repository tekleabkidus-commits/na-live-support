<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * The denormalized list row (Doc 06 T-08 / Doc 07 T-01) — READ MODEL ONLY.
 *
 * WRITES GO THROUGH TicketListItems::recompute() (query-builder upsert under
 * the column grants), never through this model: it exists so ListReadModel's
 * hydration can ride ScopeSets::apply (Eloquent Builder) and the Eloquent
 * tenant scope — the same two layers every list surface gets. Nothing is
 * fillable, by design.
 *
 * @property string $ticket_id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $status
 * @property string|null $assignee_id
 * @property string|null $department_id
 */
final class TicketListItem extends Model implements TenantScoped
{
    use BelongsToTenant;

    protected $table = 'ticket_list_items';

    protected $primaryKey = 'ticket_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** Microsecond precision — the pinned-provenance stamps round-trip intact. */
    protected $dateFormat = 'Y-m-d H:i:s.u';
}
