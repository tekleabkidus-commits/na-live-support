<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 12 FR-21 / NFR-04 — the versioned render contract, read side. ONE
 * contract (the version's schema, verbatim) feeds both renderers: Doc 13's
 * widget and Doc 05's Telegram flow engine (T-07). NFR-04 says immutable →
 * infinite TTL; C-07's physics (TenantCache) makes every TTL mandatory-finite,
 * and the two agree in effect: a version's bytes never change, so a reload
 * after expiry is byte-identical — 30 days per entry, keyed per version id,
 * invalidated by nothing but its own expiry (`form.changed` mints NEW keys,
 * never rewrites this one).
 */
final class VersionReader
{
    private const VERSION_TTL_SECONDS = 30 * 24 * 3600;

    public function __construct(private readonly TenantCache $cache) {}

    /** @return array{form_id: string, version: int, schema: array<string, mixed>} */
    public function widgetContract(string $versionId): array
    {
        /** @var array{form_id: string, version: int, schema: array<string, mixed>} */
        return $this->cache->remember('form:version:'.$versionId, self::VERSION_TTL_SECONDS, 'form.changed', function () use ($versionId): array {
            /** @var object{form_id: string, version: int, schema: string}|null $row */
            $row = DB::table('form_versions')->where('id', $versionId)->first(['form_id', 'version', 'schema']);
            if ($row === null) {
                throw new InvalidArgumentException('unknown form version');
            }
            /** @var array<string, mixed> $schema */
            $schema = json_decode($row->schema, true, 32, JSON_THROW_ON_ERROR);

            return ['form_id' => $row->form_id, 'version' => (int) $row->version, 'schema' => $schema];
        });
    }

    /** NFR-04's other half: the widget payload budget (≤ 30 KB), measured on the wire shape. */
    public function payloadBytes(string $versionId): int
    {
        return strlen(json_encode($this->widgetContract($versionId), JSON_THROW_ON_ERROR));
    }
}
