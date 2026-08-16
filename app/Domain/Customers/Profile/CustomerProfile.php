<?php

declare(strict_types=1);

namespace App\Domain\Customers\Profile;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * FR-01's profile attribute writer — the ONE door for staff edits to
 * display_name / language / country / tags, producing `customer.updated`
 * with attribute NAMES only (Q7; the old/new pairs live in audit_log).
 *
 * STICKY SOURCES (FR-01): a staff write to language/country stamps its
 * `*_source = 'staff'`, and the resolver's detected-latest never overwrites
 * a staff-set value — EC-10's disagreement renders as the "detected — apply?"
 * chip, and applyDetected() is that chip's server half (source returns to
 * 'detected', un-sticky).
 *
 * EC-11 IS LAST-WRITE-WINS WITH AN HONEST SIGNAL, NOT A REJECTION: two
 * agents editing simultaneously both land — the later write wins the column
 * — and the response tells the later writer they overwrote (`conflict:
 * true` when their expected updated_at is stale). BOTH writes audit with
 * old/new, so the trail holds the full story (the A16 frame renders the
 * toast; Doc 04's draft-LWW is the precedent).
 *
 * A-37 AT DESIGN TIME: an update that changes NOTHING is the service's own
 * documented noop — null return, no event, no audit — and the autosave door
 * declares `audit.noop` at exactly that branch.
 */
final class CustomerProfile
{
    private const EDITABLE = ['display_name', 'language', 'country', 'tags'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  subset of EDITABLE
     * @return array{changed: list<string>, conflict: bool, conflict_with: ?string, old: array<string, mixed>, updated_at: string}|null null = the A-37 noop (nothing differed)
     */
    public function update(string $customerId, array $attributes, string $actorId, ?string $expectedUpdatedAt = null): ?array
    {
        $unknown = array_diff(array_keys($attributes), self::EDITABLE);
        if ($unknown !== []) {
            throw new InvalidArgumentException('not a profile attribute: '.implode(', ', $unknown));
        }

        return DB::transaction(function () use ($customerId, $attributes, $actorId, $expectedUpdatedAt): ?array {
            /** @var object{display_name: ?string, language: ?string, country: ?string, tags: ?string, updated_at: string} $current */
            $current = DB::selectOne(
                'SELECT display_name, language, country, tags, updated_at::text AS updated_at FROM customers WHERE id = ? FOR UPDATE',
                [$customerId],
            );

            $writes = [];
            $old = [];
            $new = [];
            foreach ($attributes as $name => $value) {
                if ($name === 'tags') {
                    /** @var list<string> $tags */
                    $tags = is_array($value) ? array_values(array_unique(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value))) : [];
                    $encoded = '{'.implode(',', array_map(
                        // pg text[] literal quoting — a tag with specials stays one element
                        static fn (string $tag): string => preg_match('/[\s,"{}\\\\]/', $tag) === 1
                            ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $tag).'"'
                            : $tag,
                        $tags,
                    )).'}';
                    if ($encoded === ($current->tags ?? '{}')) {
                        continue;
                    }
                    $writes['tags'] = $encoded;
                    $old['tags'] = $current->tags;
                    $new['tags'] = $encoded;

                    continue;
                }

                $value = is_string($value) ? trim($value) : $value;
                if ($value === '' || $value === $current->{$name}) {
                    continue;
                }
                $writes[$name] = $value;
                $old[$name] = $current->{$name};
                $new[$name] = $value;

                // FR-01: a staff write to language/country is STICKY.
                if ($name === 'language') {
                    $writes['language_source'] = 'staff';
                }
                if ($name === 'country') {
                    $writes['country_source'] = 'staff';
                }
            }

            if ($writes === []) {
                return null; // the A-37 noop: no change, no event, no audit
            }

            // A16 (EC-11's toast, the frame's own bar): the loser is told BY
            // NAME — the previous writer's audit label, read BEFORE our own
            // entry lands (server-side; no audit.view door is opened to the
            // client, only the colleague's display label rides the response).
            $isConflict = $expectedUpdatedAt !== null && $expectedUpdatedAt !== $current->updated_at;
            $conflictWith = null;
            if ($isConflict) {
                /** @var ?string $conflictWith */
                $conflictWith = DB::table('audit_log')->where('action_key', 'customer.updated')
                    ->where('entity_id', $customerId)->orderByDesc('occurred_at')->value('actor_label');
            }

            DB::table('customers')->where('id', $customerId)->update($writes + ['updated_at' => DB::raw('clock_timestamp()')]);
            /** @var object{updated_at: string} $after */
            $after = DB::selectOne('SELECT updated_at::text AS updated_at FROM customers WHERE id = ?', [$customerId]);

            $changed = array_keys($new);
            $this->events->publish('customer.updated', [
                'tenant_id' => $this->context->tenantId(),
                'customer_id' => $customerId,
                'changed' => $changed,
            ]);
            $this->audit->record('customer.updated', 'customer', $customerId, AuditActor::Staff, ['old' => $old, 'new' => $new], actorId: $actorId);

            return [
                'changed' => $changed,
                // EC-11: stale expectation = the caller overwrote someone —
                // the write LANDED (last wins); this flag is the honest toast.
                'conflict' => $isConflict,
                'conflict_with' => $conflictWith,
                'old' => $old,
                'updated_at' => $after->updated_at,
            ];
        });
    }

    /**
     * EC-10's "detected: X — apply?" chip, server half: adopt the latest
     * detected message language; the source returns to 'detected' (un-sticky).
     *
     * @return array{changed: list<string>, conflict: bool, conflict_with: ?string, old: array<string, mixed>, updated_at: string}|null
     */
    public function applyDetected(string $customerId, string $actorId): ?array
    {
        $detected = $this->detectedLanguage($customerId);
        if ($detected === null) {
            return null; // nothing detected — the noop the door declares
        }

        $result = $this->update($customerId, ['language' => $detected], $actorId);
        if ($result !== null) {
            DB::table('customers')->where('id', $customerId)->update(['language_source' => 'detected']);
        }

        return $result;
    }

    /** The latest non-null detected message language (EC-10's chip data; derived, never stored twice). */
    public function detectedLanguage(string $customerId): ?string
    {
        /** @var ?string $language */
        $language = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.customer_id', $customerId)
            ->where('messages.direction', 'in')
            ->whereNotNull('messages.language')
            ->orderByDesc('messages.created_at')
            ->value('messages.language');

        return is_string($language) ? $language : null;
    }
}
