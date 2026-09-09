<?php

declare(strict_types=1);

use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Gateways\GatewayAvailability;
use App\Domain\Sms\Gateways\GatewayCredentials;
use App\Models\SmsGateway;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__).'/Feature/Sms/SmsSupport.php';

/*
| Doc 14 SEC-01 / AC-10 / STOP-C 4 / R-D14-4.12 — the credential WRITE-ONLY
| round-trip: the vendor credential enters once and lands in the vault as a
| `vault://sms/` REF (never on the row, never in the audit, never in the
| mapping); the DLR credentials are minted through DOC 02's OWN doors
| (TenantKeyIssuer kind `sms_gateway` + a webhook_credentials row) — never a
| second minter; NO response carries the value, the ref, or the callback URL;
| replace = a NEW ref with the predecessor retired; a token-shaped value or a
| secret key inside the mapping is refused before any row exists.
*/

it('creates an http_generic gateway: the mapping stored, the credential a vault://sms/ ref, the DLR key minted through TenantKeyIssuer + WebhookCredential; the response never carries a secret', function (): void {
    [, $user] = smsGatewayScene();

    $created = smsApi($user, 'POST', '/gateways', [
        'name' => '[FIXTURE] GeezSMS profile', 'driver' => 'http_generic',
        'config' => FixtureGatewaySeeder::geezsmsMapping('http://mock-sms:8082/geezsms/api/v1'),
        'credential' => 'fixture-geezsms-token',
    ]);
    $created->assertStatus(201)->assertJsonPath('driver', 'http_generic')->assertJsonPath('has_credential', true)->assertJsonPath('has_dlr_credentials', true)->assertJsonPath('circuit_state', 'closed');
    $body = (string) $created->getContent();
    expect($body)->not->toContain('fixture-geezsms-token')->not->toContain('vault://')->not->toContain('webhooks/sms/dlr');

    /** @var object{credential_ref: string, dlr_key_ref: string, dlr_secret_ref: string, webhook_credential_id: string, config: string} $row */
    $row = DB::table('sms_gateways')->where('id', $created->json('id'))->first();
    expect($row->credential_ref)->toMatch('~^vault://sms/[0-9a-f-]+/token/v1$~')
        ->and($row->dlr_key_ref)->toMatch('~/dlr-key/v1$~')
        ->and($row->dlr_secret_ref)->toMatch('~/dlr-secret/v1$~')
        ->and($row->config)->not->toContain('fixture-geezsms-token');

    // Doc 02's doors: ONE tenant key of kind sms_gateway, ONE webhook_credentials row with the url_secret hash (no hmac — an unsigned aggregator)
    expect(DB::table('tenant_keys')->where('kind', 'sms_gateway')->count())->toBe(1)
        ->and(DB::table('webhook_credentials')->where('id', $row->webhook_credential_id)->whereNotNull('header_secret_hash')->whereNull('hmac_secret')->count())->toBe(1)
        ->and(DB::table('vault_secrets')->where('ref', 'like', 'vault://sms/%')->count())->toBe(3);

    // the plaintext exists in the vault ONLY (encrypted), never on the row, never in the audit
    $audit = (string) DB::table('audit_log')->where('action_key', 'sms.gateway.created')->value('payload');
    expect($audit)->not->toContain('fixture-geezsms-token')->not->toContain('vault://');
    expect((string) DB::table('vault_secrets')->where('ref', $row->credential_ref)->value('ciphertext'))->not->toContain('fixture-geezsms-token');

    // the callback URL the SENDER builds carries both secrets — revealed in memory, and only here
    $gateway = SmsGateway::query()->findOrFail($created->json('id'));
    $url = app(GatewayCredentials::class)->callbackUrl($gateway, false);
    expect($url)->toMatch('~^https://app/api/webhooks/sms/dlr/[a-z0-9]{12}\.[0-9a-f]{64}/[0-9a-f]{64}$~');
    expect(app(GatewayAvailability::class)->exists())->toBeTrue();
});

it('SEC-01 write-only: replacing the credential mints a NEW ref and retires the old; a token-shaped value or a secret key inside the mapping is refused before any row', function (): void {
    [, $user] = smsGatewayScene();
    $id = smsApi($user, 'POST', '/gateways', ['name' => 'g', 'driver' => 'http_generic', 'config' => FixtureGatewaySeeder::referenceMapping('http://mock-sms:8082/reference'), 'credential' => 'fixture-reference-token', 'signing_dlr' => true])->assertStatus(201)->json('id');
    $before = (string) DB::table('sms_gateways')->where('id', $id)->value('credential_ref');

    smsApi($user, 'POST', '/gateways/'.$id.'/credential', ['credential' => 'fixture-reference-token-v2'])->assertOk()->assertJsonPath('credential_saved', true);
    $after = (string) DB::table('sms_gateways')->where('id', $id)->value('credential_ref');
    expect($after)->toEndWith('/token/v2')->and($after)->not->toBe($before)
        ->and(DB::table('vault_secrets')->where('ref', $before)->value('retired_at'))->not->toBeNull()
        ->and(DB::table('vault_secrets')->where('ref', $after)->value('retired_at'))->toBeNull();
    // the signing gateway got an hmac_secret, no url hash
    expect(DB::table('webhook_credentials')->whereNotNull('hmac_secret')->whereNull('header_secret_hash')->count())->toBe(1);

    $mapping = FixtureGatewaySeeder::referenceMapping('http://mock-sms:8082/reference');
    $mapping['send']['token'] = 'fixture-planted';
    $rows = DB::table('sms_gateways')->count();
    smsApi($user, 'POST', '/gateways', ['name' => 'x', 'driver' => 'http_generic', 'config' => $mapping])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'config_secret_key');
    smsApi($user, 'POST', '/gateways', ['name' => 'x', 'driver' => 'http_generic', 'config' => ['base_url' => 'http://x', 'send' => ['fields' => ['to' => 'phone']]]])
        ->assertStatus(422)->assertJsonPath('errors.refusal.0', 'config_invalid');
    expect(DB::table('sms_gateways')->count())->toBe($rows);
});
