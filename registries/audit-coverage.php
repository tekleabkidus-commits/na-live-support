<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Audit-coverage matrix (CLAUDE.md §2, Doc 17)
|------------------------------------------------------------------------------
|
| Every state-mutating action in the platform must have an entry here; a
| mutation without one fails the `audit-coverage` CI gate. Doc 17 owns the
| audit engine; every module registers its own actions as it lands.
|
| Entry schema (all fields required):
|   action_key      string  dot-namespaced, e.g. "ticket.status_changed"
|   entity          string  the audited aggregate, e.g. "ticket"
|   actor_types     list<string>  non-empty subset of: "staff" | "customer" | "system" | "platform_admin"
|   required_fields list<string>  payload fields the audit entry must carry,
|                                 e.g. ["old_status", "new_status"]
|   owning_doc      string  "DocNN"
|
*/

return [
    // Intentionally empty in Session 0. First entries arrive with Doc 01
    // (tenant.provisioned, settings.changed).
];
