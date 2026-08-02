<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * Per-session facts for the devices screen (Doc 02 FR-12).
 *
 * The registry's sorted set answers "which sessions, how recently active"; this
 * hash answers everything else a row on the Security page shows — which device,
 * from what IP, resolved to what place, signed in when. Written once at login,
 * deleted whenever the session dies (SessionRegistry::forget is the single
 * funnel), and TTL'd like the index so nothing here can outlive the session it
 * describes (C-07).
 *
 * The stored city is DISPLAY data resolved at login (see DeviceRegistrar); a
 * null city is the FS-04 "location unavailable" state, and the screen renders
 * it as copy, not as an error.
 */
final class SessionMetadataStore
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly string $connection,
        private readonly int $ttlMinutes,
    ) {}

    /**
     * @param  array{device_id: string, device_label: string, ip: ?string, city: ?string, country_code: ?string, signed_in_at: string}  $metadata
     */
    public function put(string $tenantId, string $sessionId, array $metadata): void
    {
        $key = $this->key($tenantId, $sessionId);

        $this->connection()->hmset($key, array_map(
            static fn (?string $value): string => $value ?? '',
            $metadata,
        ));
        $this->connection()->expire($key, $this->ttlMinutes * 60);
    }

    /**
     * @return array{device_id: string, device_label: string, ip: ?string, city: ?string, country_code: ?string, signed_in_at: ?string}|null
     */
    public function get(string $tenantId, string $sessionId): ?array
    {
        /** @var array<string, string>|false $raw */
        $raw = $this->connection()->hgetall($this->key($tenantId, $sessionId));

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $string = static fn (string $field): string => $raw[$field] ?? '';
        $nullable = static fn (string $field): ?string => ($raw[$field] ?? '') === '' ? null : $raw[$field];

        return [
            'device_id' => $string('device_id'),
            'device_label' => $string('device_label'),
            'ip' => $nullable('ip'),
            'city' => $nullable('city'),
            'country_code' => $nullable('country_code'),
            'signed_in_at' => $nullable('signed_in_at'),
        ];
    }

    public function forget(string $tenantId, string $sessionId): void
    {
        $this->connection()->del($this->key($tenantId, $sessionId));
    }

    private function key(string $tenantId, string $sessionId): string
    {
        return sprintf('t:%s:sess-meta:%s', $tenantId, $sessionId);
    }

    private function connection(): Connection
    {
        return $this->redis->connection($this->connection);
    }
}
