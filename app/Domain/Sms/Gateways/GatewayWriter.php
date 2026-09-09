<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\Routing\RoutingTable;
use App\Models\SmsGateway;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Doc 14 FR-07 / SEC-01 / SEC-04 / §8 — the ONE write door for gateways:
 * create (the mapping validated; the vendor credential to the vault; the DLR
 * credentials minted through Doc 02's doors), update (mapping / sender /
 * active — never a credential), replaceCredential (write-only re-entry: a NEW
 * ref, the old one retired — HIGH-VISIBILITY audit without value or ref),
 * softDelete. Every audit row carries ids only.
 */
final class GatewayWriter
{
    public function __construct(
        private readonly GatewayCredentials $credentials,
        private readonly AuditLogger $audit,
        private readonly RoutingTable $table,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function create(string $name, string $driver, array $config, ?string $vendorCredential, string $actorId, bool $signingDlr = false, bool $isFixture = false): SmsGateway
    {
        if (! in_array($driver, ['http_generic', 'smpp_sidecar'], true)) {
            throw new GatewayRefused('unknown_driver', 'driver is http_generic or smpp_sidecar (T-02)');
        }
        if ($isFixture && app()->environment('production')) {
            throw new GatewayRefused('fixture_refused', 'a FIXTURE gateway never exists in production (R-D14-1)');
        }
        $validated = GatewayConfig::validate($config, $driver);

        $created = DB::transaction(function () use ($name, $driver, $validated, $vendorCredential, $actorId, $signingDlr, $isFixture): SmsGateway {
            $gateway = SmsGateway::query()->create([
                'name' => $name,
                'driver' => $driver,
                'config' => $validated->raw,
                'is_fixture' => $isFixture,
                'active' => true,
            ]);
            $id = $gateway->identifier();
            $refs = ['credential_ref' => null];
            if ($vendorCredential !== null && $vendorCredential !== '') {
                $refs['credential_ref'] = $this->credentials->storeVendorCredential($id, $vendorCredential, 1);
            }
            $refs += $this->credentials->mintDlrCredentials($id, $name, $signingDlr, 1);
            $gateway->forceFill($refs)->save();

            $this->audit->record('sms.gateway.created', 'sms_gateway', $id, AuditActor::Staff, ['gateway_id' => $id, 'driver' => $driver, 'is_fixture' => $isFixture], $actorId);

            return $gateway->refresh();
        });
        $this->table->invalidate();

        return $created;
    }

    /**
     * @param  array{name?: string, config?: array<string, mixed>, sender_id?: ?string, active?: bool}  $attributes
     */
    public function update(SmsGateway $gateway, array $attributes, string $actorId): SmsGateway
    {
        $fill = [];
        if (isset($attributes['name'])) {
            $fill['name'] = $attributes['name'];
        }
        if (isset($attributes['config'])) {
            $fill['config'] = GatewayConfig::validate($attributes['config'], $gateway->driver)->raw;
        }
        if (array_key_exists('sender_id', $attributes)) {
            $fill['sender_id'] = $attributes['sender_id'];
        }
        if (isset($attributes['active'])) {
            $fill['active'] = $attributes['active'];
        }

        $updated = DB::transaction(function () use ($gateway, $fill, $actorId): SmsGateway {
            $gateway->forceFill($fill)->save();
            $this->audit->record('sms.gateway.updated', 'sms_gateway', $gateway->identifier(), AuditActor::Staff, ['gateway_id' => $gateway->identifier(), 'fields' => array_keys($fill)], $actorId);

            return $gateway->refresh();
        });
        $this->table->invalidate();

        return $updated;
    }

    /** SEC-01 write-only re-entry: a NEW vault ref; the predecessor retired; the value never audited. */
    public function replaceCredential(SmsGateway $gateway, string $plaintext, string $actorId): SmsGateway
    {
        return DB::transaction(function () use ($gateway, $plaintext, $actorId): SmsGateway {
            $version = $this->nextVersion($gateway->credential_ref);
            $ref = $this->credentials->storeVendorCredential($gateway->identifier(), $plaintext, $version);
            $previous = $gateway->credential_ref;
            $gateway->forceFill(['credential_ref' => $ref])->save();
            $this->credentials->retire($previous);
            $this->audit->record('sms.gateway.credential_replaced', 'sms_gateway', $gateway->identifier(), AuditActor::Staff, ['gateway_id' => $gateway->identifier()], $actorId);

            return $gateway->refresh();
        });
    }

    /** §8 "secret rotate": new DLR key + secret through Doc 02's doors; the predecessors retired. */
    public function rotateDlrCredentials(SmsGateway $gateway, string $actorId, bool $signingDlr = false): SmsGateway
    {
        return DB::transaction(function () use ($gateway, $actorId, $signingDlr): SmsGateway {
            $version = $this->nextVersion($gateway->dlr_key_ref);
            $previous = [$gateway->dlr_key_ref, $gateway->dlr_secret_ref];
            $refs = $this->credentials->mintDlrCredentials($gateway->identifier(), $gateway->name, $signingDlr, $version);
            $gateway->forceFill($refs)->save();
            $this->credentials->retire(...$previous);
            $this->audit->record('sms.gateway.updated', 'sms_gateway', $gateway->identifier(), AuditActor::Staff, ['gateway_id' => $gateway->identifier(), 'fields' => ['dlr_credentials']], $actorId);

            return $gateway->refresh();
        });
    }

    public function softDelete(SmsGateway $gateway, string $actorId): void
    {
        DB::transaction(function () use ($gateway, $actorId): void {
            DB::table('sms_gateways')->where('id', $gateway->getKey())->update(['deleted_at' => DB::raw('clock_timestamp()'), 'active' => false, 'updated_at' => DB::raw('clock_timestamp()')]);
            $this->credentials->retire($gateway->credential_ref, $gateway->dlr_key_ref, $gateway->dlr_secret_ref);
            $this->audit->record('sms.gateway.deleted', 'sms_gateway', $gateway->identifier(), AuditActor::Staff, ['gateway_id' => $gateway->identifier()], $actorId);
        });
        $this->table->invalidate();
    }

    private function nextVersion(?string $ref): int
    {
        if ($ref !== null && preg_match('~/v(\d+)$~', $ref, $m) === 1) {
            return (int) $m[1] + 1;
        }

        return 1;
    }
}
