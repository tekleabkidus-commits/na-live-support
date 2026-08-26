<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Doc 09 S09 step 12d — the browser fixtures for the AI surfaces (run AFTER
 * BrowserFixtureSeeder + InboxBrowserSeeder; idempotent; synthetic only):
 *
 *  - thresholds published for `default` (chatbot OFF — §21 rec 2) so the
 *    Copilot panel has a thresholds record; every kill switch ON;
 *  - ticket #4875 "Copilot Fixture Player" — the AI thread fixture's OWN
 *    ticket (post-close fix: never #4801, whose FR-09 unread=2 canon the
 *    AI rows and ai-copilot.spec's sent replies were breaking): one
 *    AI-authored outbound (FR-19c's marker — AC-05 DOM), one agent message
 *    carrying channel_meta.ai_translation (FR-15's hover), one
 *    classification decision row (the chips);
 *  - ticket #4871 "Deleted Account Fixture" — a customer whose last three
 *    telegram sends are failed_final/blocked_by_user (Doc 08 EC-03's chip);
 *    ticket #4872 "Live Account Fixture" — a control with one sent message
 *    (no chip).
 *
 * The synthetic marker 'S09-BROWSER' in bodies lets re-runs heal cleanly.
 */
final class AiBrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('AiBrowserSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        $row = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first();
        if ($row === null || ! is_string($row->id)) {
            throw new RuntimeException('Run BrowserFixtureSeeder first — the fixture tenant does not exist.');
        }
        $tenantId = $row->id;
        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenantId, ResolutionSource::Console);
        app(RlsSession::class)->set($tenantId);

        /** @var User $agent */
        $agent = User::query()->where('email', BrowserFixtureSeeder::PRIMARY_EMAIL)->firstOrFail();

        // Thresholds (default endpoint): published once.
        $configs = app(\App\Domain\Ai\Config\ConfigVersions::class);
        if ($configs->published('thresholds', 'default') === null) {
            $v = $configs->draft('thresholds', 'default', ['chatbot_enabled' => false, 'rg_min' => 0.8, 'handoff_below' => 0.3, 'auto_resolve_min' => 0.85, 'spam_min' => 0.9], $agent->identifier());
            $configs->publish($v, $agent->identifier());
        }
        // Provider routing → the deterministic REPLAY driver for the fixture
        // tenant (T-09's CI posture; fixtures/ai/recordings/30-31-browser-*),
        // so a browser Suggest is reproducible and NEVER reaches a live vendor.
        if ($configs->published('provider_routing', 'default') === null) {
            $v = $configs->draft('provider_routing', 'default', [
                'fast' => [['provider' => 'replay', 'model' => 'replay']],
                'strong' => [['provider' => 'replay', 'model' => 'replay']],
                'embeddings' => [['provider' => 'replay', 'model' => 'replay']],
            ], $agent->identifier());
            $configs->publish($v, $agent->identifier());
        }
        // Every kill switch ON (a prior spec run may have left one off).
        $switches = app(\App\Domain\Ai\KillSwitch\KillSwitches::class);
        foreach ($switches->bannerState() as $off) {
            $switches->flip($off['surface'], $off['channel'], $off['endpoint_ref'], false, 'S09-BROWSER reset', $agent->identifier());
        }

        // The AI thread fixture — ITS OWN ticket (#4875), NEVER the Doc 07
        // list scene's #4801 (S09 post-close fix, CI run 32496238558):
        // ai-copilot.spec SENDS a real reply into the thread it opens, and
        // this seeder itself adds three rows — on #4801 that broke
        // inbox-list's FR-09 unread=2 canon the moment spec order let the AI
        // files run first. A dedicated ticket keeps the Doc 07 canon intact
        // whatever the worker order.
        $projector = app(\App\Domain\Inbox\ReadModels\ListProjector::class);
        /** @var object{id: string, conversation_id: string}|null $aiTicket */
        $aiTicket = DB::table('tickets')->where('number', 4875)->first(['id', 'conversation_id']);
        if ($aiTicket === null) {
            $customer = \App\Models\Customer::query()->firstOrCreate(
                ['username' => '@fixture_copilot_s09'],
                ['display_name' => 'Copilot Fixture Player', 'is_shell' => true, 'created_source' => 'telegram'],
            );
            $conversationId = (string) Str::uuid7();
            DB::table('conversations')->insert(['id' => $conversationId, 'tenant_id' => $tenantId, 'channel' => 'telegram', 'channel_ref_id' => 'fixture_brand_bot', 'external_chat_id' => 'chat-'.Str::random(10), 'customer_id' => $customer->identifier(), 'state' => 'active']);
            $ticketId = (string) Str::uuid7();
            // priority stays 'normal': inbox-views' urgent-filter membership
            // proof must keep seeing exactly the R1 row.
            DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'customer_id' => $customer->identifier(), 'number' => 4875, 'status' => 'open', 'priority' => 'normal', 'source' => 'agent', 'subject' => 'AI withdrawal context', 'assignee_id' => $agent->identifier()]);
            $aiTicket = (object) ['id' => $ticketId, 'conversation_id' => $conversationId];
        } else {
            // HEAL, not create-once (the healListScene lesson, relearned the
            // day this ticket shipped): battery specs legitimately consume
            // My Open rows through real doors — a bulk resolve took #4875
            // out of the view and every AI spec lost its thread. Restore the
            // canonical state every seed; the reprojection below re-lists it.
            DB::table('tickets')->where('id', $aiTicket->id)->update([
                'status' => 'open', 'assignee_id' => $agent->identifier(),
                'priority' => 'normal', 'tags' => '{}', 'merged_into' => null,
            ]);
        }
        // Heal a prior run: the marker rows are hidden (tombstoned) so the
        // thread does not grow per seed — the reply ai-copilot.spec actually
        // SENDS (the replay recording's text) included.
        DB::table('messages')->where('conversation_id', $aiTicket->conversation_id)
            ->where(fn (\Illuminate\Database\Query\Builder $q) => $q->where('body', 'like', 'S09-BROWSER%')->orWhere('body', 'like', '[FIXTURE] Thanks for your patience%'))
            ->whereNull('deleted_at')->update(['deleted_at' => DB::raw('clock_timestamp()')]);
        DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $aiTicket->conversation_id, 'direction' => 'out', 'author_type' => 'ai', 'kind' => 'text',
            'body' => 'S09-BROWSER [FIXTURE] Hi! I\'m an AI assistant for this support team. You mentioned a 2,500 ETB withdrawal — a colleague will check it.', 'delivery_state' => 'sent', 'language' => 'en']);
        DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $aiTicket->conversation_id, 'direction' => 'out', 'author_type' => 'agent', 'author_id' => $agent->identifier(), 'kind' => 'text',
            'body' => 'S09-BROWSER የ2,500 ብር ማውጣትዎን አሁን እያረጋገጥን ነው።', 'delivery_state' => 'sent', 'language' => 'am',
            'channel_meta' => json_encode(['ai_translation' => ['artifact_id' => (string) Str::uuid7(), 'original_text' => 'We are checking your 2,500 ETB withdrawal now.', 'source_language' => 'en', 'target_language' => 'am', 'edited' => false]])]);
        DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $aiTicket->conversation_id, 'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
            'body' => 'S09-BROWSER-COPILOT where is my 2,500 ETB withdrawal? [FIXTURE]', 'language' => 'en']);
        $projector->recomputeTicket($aiTicket->id);
        if (! DB::table('ai_decisions')->where('conversation_id', $aiTicket->conversation_id)->where('task', 'classify_message')->exists()) {
            $writer = app(\App\Domain\Ai\DecisionLog\DecisionWriter::class);
            $writer->record(new \App\Domain\Ai\DecisionLog\DecisionRecord(
                id: (string) Str::uuid7(), surface: 'chatbot', task: 'classify_message', provider: 'fixture', model: 'fixture',
                layerHashes: ['immutable' => \App\Domain\Ai\Prompt\ImmutableLayer::hash()], conversationId: $aiTicket->conversation_id,
                language: 'am', intent: 'withdrawals', confidence: 0.91, action: 'copilot_context',
                responsePayload: ['classification' => ['intent' => 'withdrawals', 'sentiment' => -1, 'urgency' => 2, 'spam' => 0.0, 'rg_risk' => 0.05, 'confidence' => 0.91]],
            ));
            $writer->flush();
        }

        // LEGACY HEAL (the post-close fix): earlier S09 seeds planted the
        // marker rows — and ai-copilot.spec's sent replies — into #4801's
        // conversation. Tombstone them there and reproject, so the Doc 07
        // list canon (2 unread Amharic inbound) holds on warm databases too.
        /** @var object{ticket_id: string, conversation_id: string}|null $legacy */
        $legacy = DB::table('ticket_list_items')->where('number', 4801)->first(['ticket_id', 'conversation_id']);
        if ($legacy !== null) {
            // Tombstone AND rewrite language to 'am': lastPublic ignores
            // deleted_at (the escalated reader gap), so a LATER reprojection
            // of #4801 — healListScene after admin-tag-manager's tag drift,
            // for one — would otherwise flip the FR-09 language chip to the
            // newest (EN) pollution row whatever this seeder restored below.
            // With every pollution row carrying 'am', any reprojection
            // converges on the canon.
            $tombstoned = DB::table('messages')->where('conversation_id', $legacy->conversation_id)
                ->where(fn (\Illuminate\Database\Query\Builder $q) => $q->where('body', 'like', 'S09-BROWSER%')->orWhere('body', 'like', '[FIXTURE] Thanks for your patience%'))
                ->update(['deleted_at' => DB::raw('coalesce(deleted_at, clock_timestamp())'), 'language' => 'am']);
            if ($tombstoned > 0) {
                $projector->recomputeTicket($legacy->ticket_id);
                // Hand-restore what recompute cannot recover: sentiment/sla
                // are InboxBrowserSeeder's hand-tuned pair; language/snippet
                // flip to the tombstoned EN rows because
                // CanonicalTicketReader::lastPublic does not filter
                // deleted_at (Doc 07 reader gap, escalated with this fix).
                // Restore the R1 canon (2 Amharic inbound).
                DB::table('ticket_list_items')->where('number', 4801)->update([
                    'sentiment' => 'negative', 'sla_state' => 'breached',
                    'sla_due_at' => DB::raw("clock_timestamp() - interval '12 minutes'"),
                    'language' => 'am',
                    'snippet' => 'እባክዎ በአስቸኳይ ይመልከቱልኝ',
                    'snippet_kind' => 'text',
                ]);
            }
        }

        // EC-03: the deleted-account customer (+ a live control).
        foreach ([[4871, 'Deleted Account Fixture', '@fixture_deleted_s09', 'failed_final', 'blocked_by_user'], [4872, 'Live Account Fixture', '@fixture_live_s09', 'sent', null]] as [$number, $name, $username, $state, $reason]) {
            /** @var object{id: string}|null $existing */
            $existing = DB::table('tickets')->where('number', $number)->first(['id']);
            if ($existing !== null) {
                // HEAL + REPROJECT every seed (the #4875 lesson): a battery
                // spec may consume the row through a real door, and a Pest
                // battery flushing redis-volatile empties the view ZSETs —
                // only a reprojection re-lists the ticket on a warm database.
                DB::table('tickets')->where('id', $existing->id)->update([
                    'status' => 'open', 'assignee_id' => null,
                    'priority' => 'normal', 'merged_into' => null,
                ]);
                $projector->recomputeTicket($existing->id);

                continue;
            }
            $customer = \App\Models\Customer::query()->firstOrCreate(['username' => $username], ['display_name' => $name, 'is_shell' => true, 'created_source' => 'telegram']);
            $conversationId = (string) Str::uuid7();
            DB::table('conversations')->insert(['id' => $conversationId, 'tenant_id' => $tenantId, 'channel' => 'telegram', 'channel_ref_id' => 'fixture_brand_bot', 'external_chat_id' => 'chat-'.Str::random(10), 'customer_id' => $customer->identifier(), 'state' => 'active']);
            $ticketId = (string) Str::uuid7();
            DB::table('tickets')->insert(['id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'customer_id' => $customer->identifier(), 'number' => $number, 'status' => 'open', 'priority' => 'normal', 'source' => 'agent', 'subject' => $name]);
            DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text', 'body' => 'S09-BROWSER hello', 'language' => 'en']);
            foreach (range(1, 3) as $i) {
                DB::table('messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'direction' => 'out', 'author_type' => 'agent', 'author_id' => $agent->identifier(), 'kind' => 'text', 'body' => 'S09-BROWSER reply '.$i, 'delivery_state' => $state, 'delivery_error' => $reason === null ? null : json_encode(['reason' => $reason])]);
            }
            $projector->recomputeTicket($ticketId);
        }

        app(TenantContext::class)->clear();
        $this->command->info('AI browser fixtures ready (S09 step 12d).');
    }
}
