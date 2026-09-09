<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\Routing\RouteWriter;
use App\Models\SmsGateway;
use App\Models\SmsRoute;

/**
 * Doc 14 §21 / R-D14-1 / STOP-P — the seeded FIXTURE gateways for a tenant on
 * the local stack: the GeezSMS profile as an `http_generic` MAPPING authored
 * SECTION BY SECTION from `docs/vendors/GEEZSMS-API-NOTES.md` (the live
 * mapping differs by the base URL and the vault ref only), the REFERENCE
 * profile (a signing gateway with the full DLR vocabulary), and the
 * Ethiopia-first route `+251` → GeezSMS profile, fallback → reference profile
 * (FR-08). Both rows are `is_fixture = true`; the seeder REFUSES in
 * production — no non-fixture gateway row exists in any non-production seed
 * (STOP-P's condition), and a fixture result is never proof of an [OA] row.
 * The credentials are the mock's documented FIXTURE values (never a real
 * token — incident #16).
 */
final class FixtureGatewaySeeder
{
    public const GEEZSMS_FIXTURE_TOKEN = 'fixture-geezsms-token';

    public const REFERENCE_FIXTURE_TOKEN = 'fixture-reference-token';

    public function __construct(
        private readonly GatewayWriter $gateways,
        private readonly RouteWriter $routes,
    ) {}

    /** @return array{geezsms: SmsGateway, reference: SmsGateway, route: SmsRoute} */
    public function seed(string $actorId, string $mockBase = 'http://mock-sms:8082'): array
    {
        if (app()->environment('production')) {
            throw new GatewayRefused('fixture_refused', 'fixture gateways never exist in production (R-D14-1)');
        }
        $geez = SmsGateway::query()->where('name', 'GeezSMS (FIXTURE profile)')->whereNull('deleted_at')->first()
            ?? $this->gateways->create('GeezSMS (FIXTURE profile)', 'http_generic', self::geezsmsMapping($mockBase.'/geezsms/api/v1'), self::GEEZSMS_FIXTURE_TOKEN, $actorId, signingDlr: false, isFixture: true);
        $reference = SmsGateway::query()->where('name', 'Reference aggregator (FIXTURE profile)')->whereNull('deleted_at')->first()
            ?? $this->gateways->create('Reference aggregator (FIXTURE profile)', 'http_generic', self::referenceMapping($mockBase.'/reference'), self::REFERENCE_FIXTURE_TOKEN, $actorId, signingDlr: true, isFixture: true);
        $route = SmsRoute::query()->where('country_prefix', '+251')->first()
            ?? $this->routes->create('+251', $geez->identifier(), [$reference->identifier()], null, $actorId);

        return ['geezsms' => $geez, 'reference' => $reference, 'route' => $route];
    }

    /**
     * The GeezSMS mapping, cited to the vendor notes by section.
     *
     * @return array<string, mixed>
     */
    public static function geezsmsMapping(string $baseUrl): array
    {
        return [
            'base_url' => $baseUrl,                                  // §1: https://api.geezsms.com/api/v1 (live); the FIXTURE profile prefix (dev)
            'send' => [
                'path' => '/sms/send',                               // §3.4: POST /api/v1/sms/send (the GET variant is refused — R-D14-1.6)
                'method' => 'POST',
                'content_type' => 'form',                            // §3.4: formdata (form-encoded is the same field contract)
                'fields' => ['to' => 'phone', 'text' => 'msg', 'sender' => 'shortcode_id', 'callback' => 'callback'], // §3.4
                'to_format' => 'digits',                             // §16: "must start with 2519" — digits without "+"
                'auth' => ['style' => 'form_field', 'field' => 'token'], // §2: the token is a POST form field, never a query/URL
                'response' => ['id_path' => 'data.api_log_id'],      // §4 FIXTURE ASSUMPTION: no response body is documented
            ],
            'dlr' => [
                'status_path' => 'message_status',                   // §9: the five-field callback
                'id_path' => 'api_log_id',
                'mapping' => ['success' => 'accepted'],              // R-D14-1.5: success → ACCEPTED and nothing beyond
            ],
            'limits' => [
                'max_chars' => 335,                                  // §12: "must be less than 335 characters" (characters — §18 Q8 open)
                'refuse_marker' => '[[',                             // §7 / §18 Q12: vendor-side templating marker refused
                'throughput_per_second' => 5,                        // §14: UNKNOWN — a conservative operator seed
            ],
            'sender' => ['default' => null, 'per_country' => []],  // §11: shortcode_id; alphanumeric availability UNKNOWN (EC-08 [OA])
            'rate_card' => [['country_prefix' => '+251', 'price_per_segment' => '0.7500', 'currency' => 'ETB']], // §15: operator-entered
            'capabilities' => ['inbound_stop' => false, 'alphanumeric_sender' => false, 'max_throughput' => 5], // §10: no MO documented
            'health' => [],                                          // §3.6 balance rides X-GeezSMS-Key — read only when live ([OA])
        ];
    }

    /** @return array<string, mixed> */
    public static function referenceMapping(string $baseUrl): array
    {
        return [
            'base_url' => $baseUrl,
            'send' => [
                'path' => '/send', 'method' => 'POST', 'content_type' => 'json',
                'fields' => ['to' => 'to', 'text' => 'text', 'sender' => 'sender_id', 'callback' => 'callback_url'],
                'to_format' => 'e164',
                'auth' => ['style' => 'bearer'],
                'response' => ['id_path' => 'message_id'],
            ],
            'dlr' => [
                'status_path' => 'status', 'id_path' => 'message_id', 'code_path' => 'code',
                'mapping' => ['accepted' => 'accepted', 'sent' => 'sent', 'delivered' => 'delivered', 'undelivered' => 'undelivered'],
            ],
            'limits' => ['throughput_per_second' => 20],
            'sender' => ['default' => 'NABET', 'per_country' => ['+251' => 'NABET']],
            'rate_card' => [['country_prefix' => '+251', 'price_per_segment' => '0.9000', 'currency' => 'ETB'], ['country_prefix' => '+254', 'price_per_segment' => '1.2000', 'currency' => 'ETB']],
            'capabilities' => ['inbound_stop' => true, 'alphanumeric_sender' => true, 'max_throughput' => 20],
            'health' => ['probe_path' => '/health'],
        ];
    }
}
