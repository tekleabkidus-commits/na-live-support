<?php

declare(strict_types=1);

namespace App\Jobs\Customers;

use App\Domain\Conversations\Realtime\RealtimePusher;
use App\Domain\Customers\Panel\CustomerPayload;
use App\Domain\Customers\Panel\FilesGallery;
use App\Domain\Customers\Search\CustomerScope;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Rbac\CapabilityMapProvider;
use App\Support\Tenancy\RunsWithinTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * SEC-04: the async customer export — watermarked archive (profile + tickets
 * index + files manifest), stored under the tenant prefix, announced to the
 * requester with a signed URL, audited.
 *
 * SEC-06 (Doc 03's job semantics): the verdict is re-checked HERE, at
 * generation time — the requester must STILL hold customers.export and the
 * customer must STILL sit inside their customers-module scope (the same
 * CustomerScope brain as search/degrade). A revocation between click and run
 * produces NO artifact.
 *
 * FS-05 BY CONSTRUCTION: the archive is written with ONE atomic put at the
 * very end — a crash at any earlier point leaves NO object, and an absent
 * object can never be signed (the re-issue door 404s). The job is idempotent
 * on its exportId: a retry rebuilds from truth and overwrites the same key.
 * RG DISCIPLINE: the archive contains exactly what THIS requester may see —
 * CustomerPayload's query-level gate, re-evaluated in the job.
 */
final class CustomerExportJob implements ShouldQueue
{
    use Queueable, RunsWithinTenant;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $exportId,
        public readonly string $customerId,
        public readonly string $requesterId,
    ) {
        $this->onQueue('reports');
    }

    public static function storageKey(string $tenantId, string $exportId): string
    {
        return sprintf('t/%s/exports/customer-%s.zip', $tenantId, $exportId);
    }

    public function handle(): void
    {
        $this->withinTenant($this->tenantId, function (): void {
            $this->export();
        });
    }

    private function export(): void
    {
        $requester = User::query()->find($this->requesterId);
        if (! $requester instanceof User) {
            return; // requester gone — nothing to build, nothing to sign
        }

        // SEC-06: the live re-check, both halves (key + scope), at run time.
        $map = app(CapabilityMapProvider::class)->mapFor($requester);
        $scope = app(CustomerScope::class);
        $inScope = $scope->constrainQuery(
            DB::table('customers')->where('customers.id', $this->customerId),
            $scope->signature($requester),
        )->exists();
        if (! $map->allows('customers.export') || ! $inScope) {
            app(RealtimePusher::class)->toUser($this->requesterId, 'customer.export.refused', ['export_id' => $this->exportId]);

            return; // no artifact, no URL — the refusal is the outcome
        }

        $payload = app(CustomerPayload::class)->build($this->customerId, $map->allows('customers.rg_flag.view'));
        if ($payload === null) {
            return; // customer gone (C-10 posture: missing is missing)
        }

        /** @var list<object{id: string, number: int|string, subject: ?string, status: string, created_at: string}> $tickets */
        $tickets = DB::table('tickets')->where('customer_id', $this->customerId)
            ->orderByDesc('created_at')
            ->get(['id', 'number', 'subject', 'status', DB::raw('created_at::text AS created_at')])->all();

        $files = app(FilesGallery::class)->listFor($this->customerId, limit: 100);

        $watermark = __('customers.export.watermark', [
            'requester' => $this->requesterId,
            'at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'cx-export-');
        if ($tmp === false) {
            throw new RuntimeException('could not allocate the export scratch file');
        }

        try {
            $zip = new ZipArchive;
            $zip->open($tmp, ZipArchive::OVERWRITE);
            $zip->addFromString('watermark.txt', $watermark);
            $zip->addFromString('profile.json', json_encode(['watermark' => $watermark, 'profile' => $payload], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('tickets.json', json_encode(['watermark' => $watermark, 'tickets' => $tickets], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('files-manifest.json', json_encode(['watermark' => $watermark, 'files' => $files['items']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $zip->close();

            // FS-05 chaos hook (testing only): die AFTER the build, BEFORE the
            // put — the drill that proves a crash leaves nothing signable.
            if (config()->boolean('customers.export_chaos_kill', false)) {
                throw new RuntimeException('export chaos kill (FS-05 drill)');
            }

            // The ONE atomic put. Before this line nothing exists; after it
            // the artifact is whole. Partials are unrepresentable.
            $contents = (string) file_get_contents($tmp);
            Storage::disk(config()->string('media.disk'))->put(self::storageKey($this->tenantId, $this->exportId), $contents);
        } finally {
            @unlink($tmp);
        }

        $url = Storage::disk(config()->string('media.disk'))->temporaryUrl(
            self::storageKey($this->tenantId, $this->exportId),
            CarbonImmutable::now('UTC')->addSeconds(config()->integer('media.signed_url_ttl_seconds')),
        );

        app(AuditLogger::class)->record('customer.exported', 'customer', $this->customerId, AuditActor::Staff, ['export_id' => $this->exportId], actorId: $this->requesterId);
        app(RealtimePusher::class)->toUser($this->requesterId, 'customer.export.ready', ['export_id' => $this->exportId, 'url' => $url]);
    }
}
