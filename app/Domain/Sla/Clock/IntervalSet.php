<?php

declare(strict_types=1);

namespace App\Domain\Sla\Clock;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Doc 12 T-04 — the SLA clock's interval algebra (pure; no DB, no wall clock).
 *
 * A set of half-open instant ranges [start, end) in INTEGER MICROSECONDS since
 * the epoch (the R-23 family principle: no second-grain coin flips). The
 * constructor NORMALIZES — sorts and merges overlapping/adjacent ranges — so a
 * set is always canonical. EC-04 falls out of that physics: subtracting a set
 * of overlapping pause intervals subtracts their UNION exactly once, because
 * the set never holds an overlap to double-count. The property suite proves it
 * against hand-unioned equivalents; sabotage (j) re-introduces a raw-list
 * subtraction and must go red.
 */
final class IntervalSet
{
    /** @var list<array{int, int}> canonical: sorted, disjoint, non-empty ranges */
    private array $ranges;

    /** @param list<array{int, int}> $ranges any order, overlaps allowed; empty/inverted ranges refused */
    public function __construct(array $ranges = [])
    {
        $clean = [];
        foreach ($ranges as $r) {
            if ($r[1] < $r[0]) {
                throw new InvalidArgumentException('interval end precedes start');
            }
            if ($r[1] === $r[0]) {
                continue; // empty range carries no time
            }
            $clean[] = [$r[0], $r[1]];
        }
        usort($clean, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($clean as $r) {
            $last = count($merged) - 1;
            if ($last >= 0 && $r[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }
        $this->ranges = $merged;
    }

    /** @return list<array{int, int}> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    public function totalMicroseconds(): int
    {
        $sum = 0;
        foreach ($this->ranges as [$s, $e]) {
            $sum += $e - $s;
        }

        return $sum;
    }

    public function union(self $other): self
    {
        return new self(array_merge($this->ranges, $other->ranges));
    }

    public function intersect(self $other): self
    {
        $out = [];
        $a = $this->ranges;
        $b = $other->ranges;
        $i = 0;
        $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $start = max($a[$i][0], $b[$j][0]);
            $end = min($a[$i][1], $b[$j][1]);
            if ($start < $end) {
                $out[] = [$start, $end];
            }
            if ($a[$i][1] < $b[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return new self($out);
    }

    /** This set minus the other's UNION (the other is canonical by construction). */
    public function subtract(self $other): self
    {
        $out = [];
        foreach ($this->ranges as [$s, $e]) {
            $cursor = $s;
            foreach ($other->ranges as [$ps, $pe]) {
                if ($pe <= $cursor || $ps >= $e) {
                    continue;
                }
                if ($ps > $cursor) {
                    $out[] = [$cursor, min($ps, $e)];
                }
                $cursor = max($cursor, $pe);
                if ($cursor >= $e) {
                    break;
                }
            }
            if ($cursor < $e) {
                $out[] = [$cursor, $e];
            }
        }

        return new self($out);
    }

    /** Clip to [fromUs, untilUs). */
    public function clip(int $fromUs, int $untilUs): self
    {
        return $this->intersect(new self([[$fromUs, $untilUs]]));
    }

    /** Instant → integer microseconds since the epoch. */
    public static function us(DateTimeImmutable $at): int
    {
        return (int) $at->format('U') * 1_000_000 + (int) $at->format('u');
    }

    /** Integer microseconds → UTC instant. */
    public static function fromUs(int $us): DateTimeImmutable
    {
        $seconds = intdiv($us, 1_000_000);
        $micros = $us - $seconds * 1_000_000;

        return DateTimeImmutable::createFromFormat(
            'U u',
            sprintf('%d %06d', $seconds, $micros),
            new DateTimeZone('UTC')
        ) ?: throw new InvalidArgumentException('unrepresentable instant');
    }
}
