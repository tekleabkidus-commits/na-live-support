<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Synthetic GeoIP stub generator (ADR 0006; Doc 02 T-08, FS-04)
|------------------------------------------------------------------------------
|
| Writes fixtures/geoip/stub-city.mmdb: a deliberately minimal, byte-
| deterministic MMDB (spec 2.0, ip_version 4, record size 32) mapping ONLY the
| RFC 5737 documentation networks — addresses that can never belong to a real
| client, so the fixture is self-identifying (CLAUDE.md §8).
|
| THIS IS NOT A GENERAL MMDB WRITER. It exists to produce one small fixture and
| refuses anything its minimal encoder cannot express. Its output is verified by
| the OFFICIAL MaxMind reader in tests/Feature/Auth/GeoIpTest.php — writer and
| reader by different authors, so a shared misreading of the format cannot
| produce a green test (ADR 0006 §Decision 3).
|
| Run:  php fixtures/geoip/generate-stub.php
| Then commit generator and fixture together.
|
*/

/**
 * One control byte (+ optional length continuation) per the MMDB data spec:
 * top 3 bits = type (0 marks an extended type; the NEXT byte is type - 7),
 * low 5 bits = size, with 29 meaning "29 + one more byte".
 */
function control(int $type, int $size): string
{
    if ($size > 284) {
        throw new RuntimeException('stub encoder: size '.$size.' exceeds the minimal encoder (max 284)');
    }

    $sizeBits = $size <= 28 ? $size : 29;
    $tail = $size <= 28 ? '' : chr($size - 29);

    if ($type <= 7) {
        return chr(($type << 5) | $sizeBits).$tail;
    }

    return chr($sizeBits).chr($type - 7).$tail;
}

function encodeString(string $value): string
{
    return control(2, strlen($value)).$value;
}

/** Type 5 = uint16, 6 = uint32, 9 = uint64 — value stored as minimal big-endian bytes. */
function encodeUint(int $value, int $type): string
{
    if ($value < 0) {
        throw new RuntimeException('stub encoder: negative uint');
    }

    $bytes = '';
    while ($value > 0) {
        $bytes = chr($value & 0xFF).$bytes;
        $value >>= 8;
    }

    return control($type, strlen($bytes)).$bytes;
}

/** @param array<string, mixed> $pairs */
function encodeMap(array $pairs): string
{
    $out = control(7, count($pairs));

    foreach ($pairs as $key => $value) {
        $out .= encodeString($key).encodeValue($value);
    }

    return $out;
}

/** @param list<mixed> $values */
function encodeArray(array $values): string
{
    $out = control(11, count($values));

    foreach ($values as $value) {
        $out .= encodeValue($value);
    }

    return $out;
}

function encodeValue(mixed $value): string
{
    return match (true) {
        is_string($value) => encodeString($value),
        is_int($value) => encodeUint($value, 6),
        is_array($value) && array_is_list($value) => encodeArray($value),
        is_array($value) => encodeMap($value),
        default => throw new RuntimeException('stub encoder: unsupported type '.get_debug_type($value)),
    };
}

// ── The synthetic records (ADR 0006's table) ─────────────────────────────────

$networks = [
    '192.0.2.0/24' => [
        'city' => ['names' => ['am' => 'አዲስ አበባ', 'en' => 'Addis Ababa']],
        'country' => ['iso_code' => 'ET', 'names' => ['am' => 'ኢትዮጵያ', 'en' => 'Ethiopia']],
    ],
    '198.51.100.0/24' => [
        'city' => ['names' => ['am' => 'ድሬዳዋ', 'en' => 'Dire Dawa']],
        'country' => ['iso_code' => 'ET', 'names' => ['am' => 'ኢትዮጵያ', 'en' => 'Ethiopia']],
    ],
    // Country but NO city: the "resolves without a city name" shape, distinct
    // from FS-04's corrupt-database shape.
    '203.0.113.0/24' => [
        'country' => ['iso_code' => 'ET', 'names' => ['en' => 'Ethiopia']],
    ],
];

// ── Data section: encode each record, remembering its offset ─────────────────

$dataSection = '';
$dataOffsets = [];

foreach ($networks as $cidr => $record) {
    $dataOffsets[$cidr] = strlen($dataSection);
    $dataSection .= encodeMap($record);
}

// ── Search tree: a plain bit-trie over the 32 IPv4 bits ──────────────────────
//
// Each node holds two child slots; a slot is ['empty'], ['node', index] or
// ['data', offset]. Node 0 is the root. Because every prefix here is /24 and
// the three networks share no prefix relationship, no slot is ever assigned
// twice — the generator throws if a future edit violates that instead of
// silently producing an overlapping tree.

/** @var list<array{0: array{0: string, 1?: int}, 1: array{0: string, 1?: int}}> $nodes */
$nodes = [[['empty'], ['empty']]];

foreach ($dataOffsets as $cidr => $offset) {
    [$address, $prefixLength] = explode('/', $cidr);
    $bits = sprintf('%032b', ip2long($address));
    $prefixLength = (int) $prefixLength;

    $current = 0;
    for ($depth = 0; $depth < $prefixLength; $depth++) {
        $bit = (int) $bits[$depth];
        $slot = $nodes[$current][$bit];

        if ($depth === $prefixLength - 1) {
            if ($slot[0] !== 'empty') {
                throw new RuntimeException('stub generator: overlapping networks at '.$cidr);
            }
            $nodes[$current][$bit] = ['data', $offset];

            continue;
        }

        if ($slot[0] === 'data') {
            throw new RuntimeException('stub generator: network nested under a data record at '.$cidr);
        }

        if ($slot[0] === 'empty') {
            $nodes[] = [['empty'], ['empty']];
            $nodes[$current][$bit] = ['node', count($nodes) - 1];
        }

        $current = $nodes[$current][$bit][1] ?? throw new RuntimeException('unreachable');
    }
}

// ── Serialize the tree (record size 32: two big-endian uint32 per node) ──────
//
// Record values per spec: < node_count → child node index; == node_count → no
// data; > node_count → (value - node_count - 16) is the data-section offset.

$nodeCount = count($nodes);
$tree = '';

foreach ($nodes as $node) {
    foreach ([0, 1] as $side) {
        $slot = $node[$side];
        $value = match ($slot[0]) {
            'empty' => $nodeCount,
            'node' => $slot[1] ?? throw new RuntimeException('unreachable'),
            'data' => $nodeCount + 16 + ($slot[1] ?? throw new RuntimeException('unreachable')),
            default => throw new RuntimeException('unreachable'),
        };
        $tree .= pack('N', $value);
    }
}

// ── Metadata (fixed build_epoch: regeneration is byte-deterministic) ─────────

$metadata = encodeMap([
    'binary_format_major_version' => 2,
    'binary_format_minor_version' => 0,
    // 2026-01-01T00:00:00Z, fixed — NOT time(): the checked-in fixture must not
    // change bytes when regenerated (ADR 0006).
    'build_epoch' => 1767225600,
    'database_type' => 'Fixture-City-Stub',
    'description' => ['en' => 'Synthetic Na Live Support GeoIP fixture (RFC 5737 ranges only) - ADR 0006'],
    'ip_version' => 4,
    'languages' => ['am', 'en'],
    'node_count' => $nodeCount,
    'record_size' => 32,
]);

// Integers above are written as uint32 even where the spec draws them as
// uint16 (record_size, the format versions). The official reader accepts any
// unsigned-int encoding for these — proven by the fixture test, which is the
// arbiter of this file's validity.

$file = $tree."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00".$dataSection
    ."\xAB\xCD\xEFMaxMind.com".$metadata;

$target = __DIR__.'/stub-city.mmdb';
file_put_contents($target, $file);

echo sprintf(
    "wrote %s: %d bytes, %d nodes, %d records\n",
    $target,
    strlen($file),
    $nodeCount,
    count($networks),
);
