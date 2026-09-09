<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use App\Models\SmsGateway;
use App\Models\WebhookCredential;
use App\Support\Secrets\VaultInterim;
use App\Support\Tenancy\TenantKeyIssuer;

/**
 * Doc 14 SEC-01 / FR-07 / P-DLR-AUTH (R-D14-4.12) — the gateway's credentials,
 * through DOC 02's OWN DOORS, never a second minter:
 *
 *  - the vendor credential (a token, a password) → `VaultInterim::put` under
 *    `vault://sms/<gateway>/token/v<n>`; the gateway row holds the REF (the
 *    CHECK refuses anything else); it is revealed IN MEMORY at the driver's
 *    call and nowhere else (P-SCRUB-LIVE registers it the moment it exists);
 *  - the DLR ingress key: `TenantKeyIssuer::issue('sms_gateway', …)` (Doc 01's
 *    sixth resolution path, the `ResolutionSource::SmsGatewayKey` kind) — the
 *    row keeps the hash; the PLAINTEXT the sender needs to build the callback
 *    URL goes to the vault as `…/dlr-key/v<n>`;
 *  - the callback secret: `WebhookCredential::create(['key_id', 'header_secret_hash'
 *    => sha256(secret)])` for the url_secret surface (an unsigned aggregator),
 *    or `hmac_secret` for a signing gateway (body_hmac); its plaintext → the
 *    vault as `…/dlr-secret/v<n>` — exactly as Doc 05's BotProvisioner mints,
 *    the same two doors.
 *
 * Rotation (§8 "secret rotate") = new refs + a new webhook_credentials row +
 * `retire` on the predecessors; audit rows carry no value and no ref.
 */
final class GatewayCredentials
{
    public function __construct(
        private readonly VaultInterim $vault,
        private readonly TenantKeyIssuer $issuer,
    ) {}

    /**
     * Store the vendor credential; returns the vault ref for the gateway row.
     */
    public function storeVendorCredential(string $gatewayId, string $plaintext, int $version): string
    {
        $ref = sprintf('vault://sms/%s/token/v%d', $gatewayId, $version);
        $this->vault->put($ref, $plaintext);

        return $ref;
    }

    /**
     * Mint the DLR ingress credentials through Doc 02's doors.
     *
     * @return array{webhook_credential_id: string, dlr_key_ref: string, dlr_secret_ref: string}
     */
    public function mintDlrCredentials(string $gatewayId, string $label, bool $signing, int $version): array
    {
        $issued = $this->issuer->issue('sms_gateway', 'sms gateway '.$label);
        $keyId = $issued['key']->getKey();
        if (! is_string($keyId)) {
            throw new \LogicException('TenantKey was issued without a string id.');
        }

        $secret = bin2hex(random_bytes(32)); // 256-bit (Doc 02 SEC-02's size)
        $credential = WebhookCredential::query()->create([
            'key_id' => $keyId,
            // url_secret (unsigned aggregators): the sha-256 the middleware compares; body_hmac (signing gateways): the encrypted secret.
            'header_secret_hash' => $signing ? null : hash('sha256', $secret),
            'hmac_secret' => $signing ? $secret : null,
        ]);
        $credentialId = $credential->getKey();
        if (! is_string($credentialId)) {
            throw new \LogicException('WebhookCredential was created without a string id.');
        }

        $keyRef = sprintf('vault://sms/%s/dlr-key/v%d', $gatewayId, $version);
        $secretRef = sprintf('vault://sms/%s/dlr-secret/v%d', $gatewayId, $version);
        $this->vault->put($keyRef, $issued['plaintext']);
        $this->vault->put($secretRef, $secret);

        return ['webhook_credential_id' => $credentialId, 'dlr_key_ref' => $keyRef, 'dlr_secret_ref' => $secretRef];
    }

    /** The vendor credential, revealed in memory for one call (null when absent or retired). */
    public function vendorCredential(SmsGateway $gateway): ?string
    {
        return $gateway->credential_ref === null ? null : $this->vault->reveal($gateway->credential_ref);
    }

    /**
     * The DLR callback URL for this gateway (P-DLR-AUTH): the key and the secret both ride
     * the path for an unsigned aggregator; a signing gateway gets the key-only signed route.
     * Revealed in memory for the send call; NEVER logged (the URL is the credential).
     */
    public function callbackUrl(SmsGateway $gateway, bool $signing): ?string
    {
        if ($gateway->dlr_key_ref === null) {
            return null;
        }
        $key = $this->vault->reveal($gateway->dlr_key_ref);
        if ($key === null) {
            return null;
        }
        $configured = config('sms.dlr_callback_base');
        $base = is_string($configured) ? rtrim($configured, '/') : '';
        if ($signing || $gateway->dlr_secret_ref === null) {
            return $base.'/api/webhooks/sms/dlr/'.$key;
        }
        $secret = $this->vault->reveal($gateway->dlr_secret_ref);

        return $secret === null ? null : $base.'/api/webhooks/sms/dlr/'.$key.'/'.$secret;
    }

    public function retire(?string ...$refs): void
    {
        foreach ($refs as $ref) {
            if (is_string($ref) && $ref !== '') {
                $this->vault->retire($ref);
            }
        }
    }
}
