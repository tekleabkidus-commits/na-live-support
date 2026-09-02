<?php

declare(strict_types=1);

namespace App\Domain\Automation\Webhooks;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 SEC-02 — the per-tenant, ADMIN-MANAGED destination allowlist (RIDER
 * 5 table A). A webhook_out action names a destination BY ID; a URL typed
 * into a rule is not a thing that exists. Birth runs the SSRF rules; the url
 * and host are then immutable by grant. Deactivation is a flag (NO DELETE);
 * EC-13's auto-disable is its OWN stamp so the alert can say which switch
 * flipped. Rides `automation.rules.edit` at the controller (stated at the
 * plan: the catalog is CLOSED at 114 and the document names no destination
 * key). Every write audits `automation.webhook_destination.changed`.
 */
final class WebhookDestinations
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws WebhookDestinationRefused on an SSRF-rule refusal */
    public function create(string $name, string $url, string $actorId): string
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('a destination needs a name');
        }
        SsrfRules::assertAllowed($url);
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $name, $url, $actorId): void {
            DB::table('automation_webhook_destinations')->insert([
                'id' => $id, 'tenant_id' => $this->context->tenantId(),
                'name' => $name, 'url' => $url, 'host' => SsrfRules::hostOf($url), 'created_by' => $actorId,
            ]);
            $this->announce($id, 'created', AuditActor::Staff, $actorId);
        });

        return $id;
    }

    /** @param array{name?: string, active?: bool} $changes */
    public function update(string $id, array $changes, string $actorId): void
    {
        $update = array_intersect_key($changes, array_flip(['name', 'active']));
        if ($update === []) {
            return;
        }
        if (($update['active'] ?? null) === true) {
            // An admin re-enabling a destination also clears EC-13's auto-disable and its streak.
            $update['auto_disabled_at'] = null;
            $update['failure_streak'] = 0;
        }
        $update['updated_at'] = DB::raw('clock_timestamp()');
        DB::transaction(function () use ($id, $update, $actorId): void {
            if (DB::table('automation_webhook_destinations')->where('id', $id)->update($update) === 0) {
                throw new InvalidArgumentException('unknown destination');
            }
            $this->announce($id, isset($update['active']) ? ($update['active'] ? 'activated' : 'deactivated') : 'updated', AuditActor::Staff, $actorId);
        });
    }

    /**
     * EC-13: retry ×8 exhausted — the destination auto-disables, audited as
     * system; the alert event rides WebhookDeliveries' own transaction.
     */
    public function autoDisable(string $id, string $error): void
    {
        DB::table('automation_webhook_destinations')->where('id', $id)->update([
            'auto_disabled_at' => DB::raw('clock_timestamp()'), 'last_error' => mb_substr($error, 0, 500),
            'last_failed_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $this->announce($id, 'auto_disabled', AuditActor::System, null);
    }

    /**
     * The destination a delivery may target now: active, not auto-disabled.
     *
     * @return object{id: string, url: string, host: string, active: bool, auto_disabled_at: ?string, failure_streak: int}|null
     */
    public function live(string $id): ?object
    {
        /** @var object{id: string, url: string, host: string, active: bool, auto_disabled_at: ?string, failure_streak: int}|null $row */
        $row = DB::table('automation_webhook_destinations')->where('id', $id)->first(['id', 'url', 'host', 'active', 'auto_disabled_at', 'failure_streak']);
        if ($row === null || ! $row->active || $row->auto_disabled_at !== null) {
            return null;
        }

        return $row;
    }

    private function announce(string $id, string $kind, AuditActor $actor, ?string $actorId): void
    {
        $this->audit->record('automation.webhook_destination.changed', 'automation_webhook_destination', $id, $actor, ['destination_id' => $id, 'kind' => $kind], actorId: $actorId);
    }
}
