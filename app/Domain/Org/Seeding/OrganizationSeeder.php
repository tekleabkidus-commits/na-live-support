<?php

declare(strict_types=1);

namespace App\Domain\Org\Seeding;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 11 FR-01 / BR-04 / EC-10 — the seeded department set at provisioning (S11 STOP-C.1,
 * countersigned: ProvisioningStep::Organization, the RoleSeeder pattern).
 *
 * FR-01's named set, verbatim: General, Payments, Withdrawals, Deposits, Bonuses,
 * Technical, Verification, Responsible Gaming, VIP — "all editable/deletable (content,
 * not fixtures)". Each department is created WITH its default queue in ONE transaction,
 * default_queue_id set before commit (SCHEMA AMENDMENT 5 FLAG A: the seeder cannot mint
 * the defaultless state the service door refuses). "Responsible Gaming" carries the
 * tenant's ONE is_rg queue (FR-03; the Doc 09 FR-13 / Doc 08 FR-15 target). "General" is
 * the tenant default department (FR-08 step 1's terminal fallback) — position 0.
 *
 * IDEMPOTENT under the provisioner's retry/concurrency semantics (Doc 01 EC-05): an
 * existing slug is left alone (an operator-edited seed is NEVER overwritten — the
 * seeded_fingerprint contract, RoleSeeder's shape); a concurrent provisioner losing the
 * unique race treats the winner's row as the consistent set. Audited as the SYSTEM actor;
 * org.department.created / org.queue.created events published (FR-19).
 */
final class OrganizationSeeder
{
    /** @var list<array{slug: string, en: string, am: string, rg: bool}> FR-01's list, in position order */
    public const DEPARTMENTS = [
        ['slug' => 'general', 'en' => 'General', 'am' => 'አጠቃላይ', 'rg' => false],
        ['slug' => 'payments', 'en' => 'Payments', 'am' => 'ክፍያዎች', 'rg' => false],
        ['slug' => 'withdrawals', 'en' => 'Withdrawals', 'am' => 'ገንዘብ ማውጣት', 'rg' => false],
        ['slug' => 'deposits', 'en' => 'Deposits', 'am' => 'ተቀማጭ', 'rg' => false],
        ['slug' => 'bonuses', 'en' => 'Bonuses', 'am' => 'ጉርሻዎች', 'rg' => false],
        ['slug' => 'technical', 'en' => 'Technical', 'am' => 'ቴክኒካል', 'rg' => false],
        ['slug' => 'verification', 'en' => 'Verification', 'am' => 'ማረጋገጫ', 'rg' => false],
        ['slug' => 'responsible-gaming', 'en' => 'Responsible Gaming', 'am' => 'ኃላፊነት የተሞላበት ጨዋታ', 'rg' => true],
        ['slug' => 'vip', 'en' => 'VIP', 'am' => 'ቪአይፒ', 'rg' => false],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int how many departments were created this call */
    public function seed(): int
    {
        $created = 0;
        foreach (self::DEPARTMENTS as $position => $department) {
            $created += DB::transaction(function () use ($position, $department): int {
                if (DB::table('departments')->where('slug', $department['slug'])->whereNull('deleted_at')->exists()) {
                    return 0; // an operator-edited seed is never overwritten; a re-run is a no-op
                }

                $tenantId = $this->context->tenantId();
                $departmentId = (string) Str::uuid7();
                $queueId = (string) Str::uuid7();
                $name = ['en' => $department['en'], 'am' => $department['am']];

                try {
                    DB::table('departments')->insert([
                        'id' => $departmentId,
                        'tenant_id' => $tenantId,
                        'name' => json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'slug' => $department['slug'],
                        'position' => $position,
                        'default_queue_id' => $queueId,      // FLAG A: set before commit, never observably absent
                        'seeded' => true,
                        'seeded_fingerprint' => self::fingerprint($department),
                    ]);
                    DB::table('queues')->insert([
                        'id' => $queueId,
                        'tenant_id' => $tenantId,
                        'department_id' => $departmentId,
                        'name' => $department['en'],
                        'position' => 0,
                        'is_rg' => $department['rg'],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    return 0; // the concurrent provisioner won; its rows ARE the consistent set
                }

                $this->events->publish('org.department.created', ['tenant_id' => $tenantId, 'department_id' => $departmentId]);
                $this->audit->record(
                    actionKey: 'org.department.created',
                    entity: 'department',
                    entityId: $departmentId,
                    actorType: AuditActor::System,
                    payload: ['department_id' => $departmentId, 'name' => $name],
                );
                $this->events->publish('org.queue.created', ['tenant_id' => $tenantId, 'queue_id' => $queueId]);
                $this->audit->record(
                    actionKey: 'org.queue.created',
                    entity: 'queue',
                    entityId: $queueId,
                    actorType: AuditActor::System,
                    payload: ['queue_id' => $queueId, 'department_id' => $departmentId, 'assignment_strategy' => 'round_robin'],
                );

                return 1;
            });
        }

        return $created;
    }

    /** @param  array{slug: string, en: string, am: string, rg: bool}  $department */
    public static function fingerprint(array $department): string
    {
        return hash('sha256', json_encode($department, JSON_THROW_ON_ERROR));
    }
}
