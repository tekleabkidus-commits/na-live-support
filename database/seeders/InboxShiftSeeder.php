<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Inbox\ReadModels\ListProjector;
use App\Models\Customer;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * S07 step 13 — SS-01's shift floor: SIXTY unassigned open tickets, one
 * inbound message each, projected into the read model. The keyboard-shift
 * spec (US-01/SS-01) runs the whole set claim→answer→resolve with zero
 * pointer interactions.
 *
 * TOP-UP, not reset: tickets a prior run consumed (resolved/claimed) are
 * left as history — `resolved_at` is not app-writable (the R-19b grant
 * physics; the transition service owns it), so the pool is refilled with
 * FRESH tickets until sixty open unassigned shift tickets exist. All
 * synthetic (CLAUDE.md §8).
 */
final class InboxShiftSeeder extends Seeder
{
    private const COUNT = 60;

    private const SUBJECT_PREFIX = 'Shift question';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('InboxShiftSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();
        if ($row === null || ! is_string($row->id)) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }

        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($row->id, ResolutionSource::Console);
        app(RlsSession::class)->set($row->id);
        $tenantId = $row->id;

        $projector = app(ListProjector::class);

        $alive = (int) DB::table('tickets')
            ->where('subject', 'like', self::SUBJECT_PREFIX.'%')
            ->where('status', 'open')
            ->whereNull('assignee_id')
            ->count();
        $needed = max(0, self::COUNT - $alive);
        $maxNumber = DB::table('tickets')->where('number', '<', 1_000_000)->max('number');
        $nextNumber = (is_numeric($maxNumber) ? (int) $maxNumber : 7000) + 1;

        for ($i = 0; $i < $needed; $i++) {
            $number = $nextNumber + $i;
            $customer = Customer::query()->firstOrCreate(
                ['username' => sprintf('@fixture_shift_%04d', $number)],
                ['display_name' => sprintf('Shift Player %04d', $number), 'is_shell' => true],
            );
            $conversationId = (string) Str::uuid7();
            DB::table('conversations')->insert([
                'id' => $conversationId, 'tenant_id' => $tenantId, 'channel' => 'telegram',
                'channel_ref_id' => 'fixture_brand_bot', 'external_chat_id' => 'chat-shift-'.Str::random(8),
                'customer_id' => $customer->identifier(), 'state' => 'active',
            ]);
            $ticketId = (string) Str::uuid7();
            DB::table('tickets')->insert([
                'id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'customer_id' => $customer->identifier(), 'number' => $number,
                'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
                'subject' => sprintf('%s %04d', self::SUBJECT_PREFIX, $number),
            ]);
            DB::table('messages')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
                'body' => sprintf('Fixture shift question %04d — where is my withdrawal?', $number),
                'is_internal' => false, 'language' => 'en',
            ]);
            app(\App\Domain\Conversations\Storage\ConversationRepository::class)
                ->claimOpenTicket(\App\Models\Conversation::query()->findOrFail($conversationId), $ticketId);
            $projector->recomputeTicket($ticketId);
        }

        app(RlsSession::class)->clear();
        app(TenantContext::class)->clear();
    }
}
