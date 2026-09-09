<?php

declare(strict_types=1);

namespace App\Domain\Sms\Gateways;

use App\Domain\Sms\Cost\RateCard;

/**
 * Doc 14 T-02 / FR-07 — the `http_generic` MAPPING and the operational knobs,
 * validated at the ONE write door and read by the drivers. The shape (the
 * `sms_gateways.config` jsonb, SCHEMA AMENDMENT 8 item 3):
 *
 *   base_url                    the vendor base (the FIXTURE's profile prefix in dev)
 *   send.path / method          e.g. "/sms/send", POST
 *   send.content_type           form | multipart | json
 *   send.fields.{to,text,sender,callback}   the vendor's field NAMES
 *   send.to_format              e164 | digits (some local aggregators want digits without "+" — vendor notes §16)
 *   send.auth.style             form_field | bearer | header | none
 *   send.auth.field / header    the field or header NAME the credential rides — NEVER a value
 *   send.response.id_path       dot path to the provider id in the send response (may be absent — §4)
 *   dlr.status_path / id_path / code_path / mapping{raw → accepted|sent|delivered|undelivered}
 *   limits.max_chars            a rendered text at/over it is failed{vendor_length_cap} (§12)
 *   limits.refuse_marker        a rendered text containing it is failed{vendor_template_marker} (§7)
 *   limits.throughput_per_second   the gateway's shaping rate (FR-07)
 *   sender.default / per_country{prefix → sender}   FR-07's per-country overrides
 *   rate_card[]                 {country_prefix, price_per_segment, currency} (T-01)
 *   capabilities{inbound_stop, alphanumeric_sender, max_throughput}
 *   health.probe_path           an optional GET the breaker probes when half-open
 *
 * A `token` / `secret` / `password` / `api_key` KEY anywhere in this object is
 * refused here and by the table's CHECK at the top level (SEC-01).
 */
final readonly class GatewayConfig
{
    private const SECRET_KEYS = ['token', 'secret', 'password', 'api_key'];

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(public array $raw) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function validate(array $raw, string $driver): self
    {
        self::assertNoSecretKey($raw, '');
        if ($driver === 'http_generic') {
            foreach (['base_url', 'send'] as $key) {
                if (! isset($raw[$key])) {
                    throw new GatewayRefused('config_invalid', "http_generic mapping needs {$key}", ['missing' => $key]);
                }
            }
            $send = is_array($raw['send']) ? $raw['send'] : [];
            $fields = is_array($send['fields'] ?? null) ? $send['fields'] : [];
            foreach (['to', 'text'] as $key) {
                if (! is_string($fields[$key] ?? null) || $fields[$key] === '') {
                    throw new GatewayRefused('config_invalid', "http_generic mapping needs send.fields.{$key}", ['missing' => 'send.fields.'.$key]);
                }
            }
            $style = is_array($send['auth'] ?? null) ? ($send['auth']['style'] ?? 'none') : 'none';
            if (! in_array($style, ['form_field', 'bearer', 'header', 'none'], true)) {
                throw new GatewayRefused('config_invalid', 'unknown auth style', ['auth.style' => $style]);
            }
            if (! in_array($send['content_type'] ?? 'form', ['form', 'multipart', 'json'], true)) {
                throw new GatewayRefused('config_invalid', 'unknown content type');
            }
            if (! in_array($send['to_format'] ?? 'e164', ['e164', 'digits'], true)) {
                throw new GatewayRefused('config_invalid', 'unknown to_format');
            }
        }
        if ($driver === 'smpp_sidecar' && ! is_string($raw['base_url'] ?? null)) {
            throw new GatewayRefused('config_invalid', 'smpp_sidecar needs base_url (the sidecar\'s private https origin)');
        }
        $base = $raw['base_url'] ?? '';
        if (! is_string($base) || preg_match('~^https?://[^\s/]+~', $base) !== 1) {
            throw new GatewayRefused('config_invalid', 'base_url must be an http(s) origin');
        }
        foreach (self::rateCardRows($raw) as $row) {
            RateCard::fromArray($row); // throws on a malformed price/currency
        }
        $limits = is_array($raw['limits'] ?? null) ? $raw['limits'] : [];
        $rate = $limits['throughput_per_second'] ?? 1;
        if (! is_int($rate) || $rate < 1 || $rate > 10_000) {
            throw new GatewayRefused('config_invalid', 'limits.throughput_per_second must be an int 1..10000');
        }

        return new self($raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromStored(array $raw): self
    {
        return new self($raw);
    }

    public function baseUrl(): string
    {
        $base = $this->raw['base_url'] ?? '';

        return is_string($base) ? rtrim($base, '/') : '';
    }

    /** @return array<string, mixed> */
    public function send(): array
    {
        return self::stringKeyed($this->raw['send'] ?? null);
    }

    public function sendString(string $key, string $default = ''): string
    {
        $value = $this->send()[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        $fields = is_array($this->send()['fields'] ?? null) ? $this->send()['fields'] : [];
        $out = [];
        foreach ($fields as $key => $name) {
            if (is_string($key) && is_string($name)) {
                $out[$key] = $name;
            }
        }

        return $out;
    }

    /** @return array{style: string, field: ?string, header: ?string} */
    public function auth(): array
    {
        $auth = is_array($this->send()['auth'] ?? null) ? $this->send()['auth'] : [];

        return [
            'style' => is_string($auth['style'] ?? null) ? $auth['style'] : 'none',
            'field' => is_string($auth['field'] ?? null) ? $auth['field'] : null,
            'header' => is_string($auth['header'] ?? null) ? $auth['header'] : null,
        ];
    }

    public function responseIdPath(): ?string
    {
        $response = is_array($this->send()['response'] ?? null) ? $this->send()['response'] : [];

        return is_string($response['id_path'] ?? null) ? $response['id_path'] : null;
    }

    /** @return array<string, mixed> */
    public function dlr(): array
    {
        return self::stringKeyed($this->raw['dlr'] ?? null);
    }

    public function dlrString(string $key): ?string
    {
        $value = $this->dlr()[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> raw status word → normalized (accepted|sent|delivered|undelivered) */
    public function dlrMapping(): array
    {
        $mapping = is_array($this->dlr()['mapping'] ?? null) ? $this->dlr()['mapping'] : [];
        $out = [];
        foreach ($mapping as $raw => $normalized) {
            if (is_string($normalized)) {
                $out[(string) $raw] = $normalized;
            }
        }

        return $out;
    }

    public function maxChars(): ?int
    {
        $limits = is_array($this->raw['limits'] ?? null) ? $this->raw['limits'] : [];

        return is_int($limits['max_chars'] ?? null) ? $limits['max_chars'] : null;
    }

    public function refuseMarker(): ?string
    {
        $limits = is_array($this->raw['limits'] ?? null) ? $this->raw['limits'] : [];

        return is_string($limits['refuse_marker'] ?? null) && $limits['refuse_marker'] !== '' ? $limits['refuse_marker'] : null;
    }

    public function throughputPerSecond(): int
    {
        $limits = is_array($this->raw['limits'] ?? null) ? $this->raw['limits'] : [];
        $rate = $limits['throughput_per_second'] ?? 1;

        return is_int($rate) && $rate >= 1 ? $rate : 1;
    }

    /** FR-07: the sender for a country prefix — the per-country override, else the default. */
    public function senderFor(?string $countryPrefix): ?string
    {
        $sender = is_array($this->raw['sender'] ?? null) ? $this->raw['sender'] : [];
        $perCountry = is_array($sender['per_country'] ?? null) ? $sender['per_country'] : [];
        if ($countryPrefix !== null && is_string($perCountry[$countryPrefix] ?? null)) {
            return $perCountry[$countryPrefix];
        }

        return is_string($sender['default'] ?? null) && $sender['default'] !== '' ? $sender['default'] : null;
    }

    /** @return list<RateCard> */
    public function rateCards(): array
    {
        return array_map(static fn (array $row): RateCard => RateCard::fromArray($row), self::rateCardRows($this->raw));
    }

    /** FR-19: the LONGEST matching rate-card prefix for a phone, or null. */
    public function rateCardFor(string $phoneE164): ?RateCard
    {
        $best = null;
        foreach ($this->rateCards() as $card) {
            if (str_starts_with($phoneE164, $card->countryPrefix) && ($best === null || strlen($card->countryPrefix) > strlen($best->countryPrefix))) {
                $best = $card;
            }
        }

        return $best;
    }

    public function capabilities(): Capabilities
    {
        $c = is_array($this->raw['capabilities'] ?? null) ? $this->raw['capabilities'] : [];

        return new Capabilities(
            (bool) ($c['inbound_stop'] ?? false),
            (bool) ($c['alphanumeric_sender'] ?? false),
            is_int($c['max_throughput'] ?? null) ? $c['max_throughput'] : $this->throughputPerSecond(),
        );
    }

    public function healthProbePath(): ?string
    {
        $health = is_array($this->raw['health'] ?? null) ? $this->raw['health'] : [];

        return is_string($health['probe_path'] ?? null) ? $health['probe_path'] : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array{country_prefix: string, price_per_segment: string|int|float, currency: string}>
     */
    private static function rateCardRows(array $raw): array
    {
        $rows = is_array($raw['rate_card'] ?? null) ? $raw['rate_card'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['country_prefix'] ?? null) && isset($row['price_per_segment']) && is_string($row['currency'] ?? null)) {
                /** @var array{country_prefix: string, price_per_segment: string|int|float, currency: string} $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $node  any level of the stored JSON — keys are whatever the operator typed
     */
    private static function assertNoSecretKey(array $node, string $path): void
    {
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                throw new GatewayRefused('config_secret_key', 'a credential never rides the mapping — it is a vault reference (SEC-01)', ['key' => $here]);
            }
            if (is_array($value)) {
                self::assertNoSecretKey($value, $here);
            }
        }
    }

    /**
     * A JSON object node as the string-keyed map the readers promise (a list or a scalar reads as empty).
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        $out = [];
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
