<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Conversations\Adapters\InboundSender;
use App\Domain\Customers\Blocking\BlockCustomer;
use App\Domain\Customers\Fields\CustomerFieldValues;
use App\Domain\Customers\Identity\IdentityResolution;
use App\Domain\Customers\Identity\LinkIdentity;
use App\Domain\Customers\Notes\CustomerNotes;
use App\Domain\Customers\Profile\CustomerProfile;
use App\Domain\Customers\Rg\RgFlagService;
use App\Domain\Customers\Rollups\CustomerRollups;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Doc 08's OWN browser scenes (S08 step 10; the step-3 checkpoint's ruling C):
 * identities are minted THROUGH THE REAL RESOLVER so the provenance under
 * browser test is the provenance the resolver actually writes — the S07
 * seeders' zero-identity customers cannot render B4-S01's enriched frames,
 * and the Doc 07 seeders are NOT extended (a core edit for a Doc 08
 * convenience). Runs AFTER BrowserFixtureSeeder (needs the primary tenant).
 *
 * Scenes (each a B4-S01/B4-S05 frame's data): ENRICHED (A01–A11: renamed
 * telegram identity → A03 history, staff phone → A04 copy, betting fields +
 * two other player_id holders → A06 hint, tags, pinned note, rollups),
 * SHELL (A13), RG-FLAGGED (B4-S05 E01/E03 + the tab dot), BLOCKED (§8
 * banner). Two viewers: one WITH customers.rg_flag.view, one WITHOUT —
 * E03's absence is per viewer, so the pair is the proof's instrument.
 *
 * Synthetic only (@fixture…, +2519000xxxxx — CLAUDE.md §8). Idempotent:
 * the resolver matches on re-run; writers are upserts or guarded.
 */
final class CustomerBrowserSeeder extends Seeder
{
    public const RG_VIEWER_EMAIL = 'customers-agent@fixture.example';

    public const NO_RG_VIEWER_EMAIL = 'customers-norg@fixture.example';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'ci'])) {
            throw new RuntimeException('CustomerBrowserSeeder is a browser fixture and must never run outside local/testing/ci.');
        }

        /** @var object{id: string}|null $tenantRow */
        $tenantRow = DB::connection('pgsql_platform')->table('tenants')->where('name', 'Fixture Operator A')->first(['id']);
        if ($tenantRow === null) {
            throw new RuntimeException('run BrowserFixtureSeeder first — the primary tenant is missing.');
        }

        $tenantId = (string) $tenantRow->id;
        app(TenantContext::class)->clear();
        app(TenantContext::class)->bind($tenantId, ResolutionSource::Console);
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);

        [$rgViewer, $noRgViewer] = $this->viewers();

        // ── ENRICHED (A01–A11) — the resolver mints, renames twice (A03),
        //    staff links a phone (A04), betting fields land (A06's subject).
        $resolver = app(IdentityResolution::class);
        $abebe = $resolver->resolveInbound('telegram', new InboundSender('s08-panel-abebe', 'አበበ ታደሰ', '@fixture_abebe_old'))->customerId;
        $resolver->resolveInbound('telegram', new InboundSender('s08-panel-abebe', 'አበበ ታደሰ', '@fixture_abebe1994'));
        $resolver->resolveInbound('telegram', new InboundSender('s08-panel-abebe', 'አበበ ታደሰ', '@fixture_abebe_t'));

        // SELF-HEAL THE SCENE CANON (standing rule 14; the first run's own
        // finding: re-runs re-rename and the history grew 2→29 entries): the
        // resolver minted the row and its shape; every seed restores exactly
        // the canonical two-entry history the A03 frame draws.
        DB::table('customer_identities')->where('customer_id', $abebe)->where('type', 'telegram')->update([
            'metadata' => json_encode([
                'display' => 'አበበ ታደሰ',
                'username' => '@fixture_abebe_t',
                'history' => [
                    ['old' => '@fixture_abebe_old', 'changed_at' => '2026-03-04 09:00:00+00'],
                    ['old' => '@fixture_abebe1994', 'changed_at' => '2026-06-02 09:00:00+00'],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        if (! DB::table('customer_identities')->where('customer_id', $abebe)->where('type', 'phone')->exists()) {
            app(LinkIdentity::class)->link($abebe, 'phone', '0912344417', $rgViewer->identifier(), 'ET');
        }
        app(CustomerProfile::class)->update($abebe, [
            'display_name' => 'አበበ ታደሰ', 'language' => 'am', 'country' => 'ET',
            'tags' => ['vip', 'withdrawal-history'],
        ], $rgViewer->identifier());
        app(CustomerFieldValues::class)->set($abebe, [
            'betting_username' => 'abebe_bet', 'player_id' => 'NB-40219', 'brand' => 'Na Bet',
        ], $rgViewer->identifier());
        // H04 (step 12): the RETIRED fact that stays — the definition went
        // (loyalty_tier, EC-09's fixture), the March value is still true
        // about March. Written at the storage layer: the service rightly
        // refuses retired writes, and this row predates the retirement.
        if (DB::table('customer_field_values')->where('customer_id', $abebe)->where('field_key', 'loyalty_tier')->doesntExist()) {
            DB::table('customer_field_values')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'customer_id' => $abebe,
                'field_key' => 'loyalty_tier', 'value' => 'Gold', 'updated_by' => $rgViewer->identifier(),
            ]);
        }
        if (DB::table('customer_notes')->where('customer_id', $abebe)->count() === 0) {
            app(CustomerNotes::class)->add($abebe, 'VIP — always confirm withdrawals by voice before releasing.', $rgViewer->identifier(), pinned: true);
        }

        // A06's other holders: two more REAL resolver customers on NB-40219.
        foreach ([['s08-panel-dup1', '@fixture_dup_one'], ['s08-panel-dup2', '@fixture_dup_two']] as [$externalId, $username]) {
            $holder = $resolver->resolveInbound('telegram', new InboundSender($externalId, 'Dup Fixture', $username))->customerId;
            app(CustomerFieldValues::class)->set($holder, ['player_id' => 'NB-40219'], $rgViewer->identifier());
        }

        $abebeTicket = $this->scene($tenantId, $abebe, 'chat-s08-abebe', 8801, [
            'subject' => 'Withdrawal delayed', 'assignee_id' => $rgViewer->identifier(), 'tags' => '{withdrawal,vip}',
        ], media: true);

        // ── SHELL (A13): the resolver's own fresh mint, untouched.
        $kidist = $resolver->resolveInbound('telegram', new InboundSender('s08-panel-kidist', 'Kidist H', '@fixture_kidist_h'))->customerId;
        $this->scene($tenantId, $kidist, 'chat-s08-kidist', 8802, ['subject' => 'የመለያ ጥያቄ']);

        // ── EC-08 COLLISION PAIR (S08 step 12; B4-S06 G01): two customers
        //    named አቤል ተስፋዬ, told apart by identity values + short id —
        //    a betting product has real name collisions and a directory
        //    that hides them causes the wrong merge.
        foreach ([['s08-dir-abel1', '@fixture_abel_t'], ['s08-dir-abel2', '@fixture_abel_c']] as $i => [$externalId, $username]) {
            $abel = $resolver->resolveInbound('telegram', new InboundSender($externalId, 'አቤል ተስፋዬ', $username))->customerId;
            app(CustomerProfile::class)->update($abel, ['display_name' => 'አቤል ተስፋዬ', 'tags' => ['name-collision']], $rgViewer->identifier());
            $this->scene($tenantId, $abel, 'chat-s08-abel'.($i + 1), 8807 + $i, ['subject' => 'Collision fixture']);
            app(\App\Domain\Customers\Search\CustomerSearchDocuments::class)->writeFor($abel);
        }

        // ── H03 (B4-S07, step 12): staff-set language vs a DETECTED one —
        //    the one inbound message is English against a staff-set Amharic,
        //    so the apply-once chip has its scene (EC-10).
        $hanaSet = $resolver->resolveInbound('telegram', new InboundSender('s08-dir-h03', 'Selam Detected', '@fixture_selam_h3'))->customerId;
        app(CustomerProfile::class)->update($hanaSet, ['display_name' => 'Selam Detected', 'language' => 'am'], $rgViewer->identifier());
        $this->scene($tenantId, $hanaSet, 'chat-s08-h03', 8809, ['subject' => 'Language fixture'], messageLanguage: 'en');

        // ── RG-FLAGGED (B4-S05 E01/E03 + the tab dot's one state).
        $rgCustomer = $resolver->resolveInbound('telegram', new InboundSender('s08-panel-dawit', 'Dawit Bekele', '@fixture_dawit_b'))->customerId;
        if (! (bool) DB::table('customers')->where('id', $rgCustomer)->value('rg_flag')) {
            app(RgFlagService::class)->set($rgCustomer, 'fixture: self-described chasing losses', 'staff', $rgViewer->identifier());
        }
        $this->scene($tenantId, $rgCustomer, 'chat-s08-dawit', 8803, ['subject' => 'Deposit question', 'assignee_id' => $rgViewer->identifier()]);

        // ── BLOCKED (§8's red banner; the dot stays dark — ruling decision 2).
        $blocked = $resolver->resolveInbound('telegram', new InboundSender('s08-panel-blocked', 'Blocked Fixture', '@fixture_blocked_b'))->customerId;
        if (! (bool) DB::table('customers')->where('id', $blocked)->value('blocked')) {
            app(BlockCustomer::class)->block($blocked, 'fixture: abuse after declined payout', $rgViewer->identifier());
        }
        $this->scene($tenantId, $blocked, 'chat-s08-blocked', 8804, ['subject' => 'Refund demand']);

        // ── MERGE PAIR (B4-S04's arc: the spec merges B into A, then undoes
        //    — the arc restores the scene itself). Both indexed so the merge
        //    dialog's search can find the target.
        $mergeA = $resolver->resolveInbound('telegram', new InboundSender('s08-panel-mergeA', 'Merge Survivor', '@fixture_merge_a'))->customerId;
        // SELF-HEAL (corrected after its own first failure): a crashed arc
        // leaves B TOMBSTONED and its identity MOVED TO A — so resolving B's
        // external id returns A and a lookup "by B" is blind. The honest
        // probe is the tombstone side: any customer merged INTO A is a
        // crashed arc's leftover — undo its snapshot BEFORE resolving B.
        /** @var list<string> $tombstones */
        $tombstones = DB::table('customers')->where('merged_into_id', $mergeA)->pluck('id')->all();
        foreach ($tombstones as $tombstoneId) {
            /** @var ?string $staleSnap */
            $staleSnap = DB::table('customer_merge_snapshots')->where('merged_id', $tombstoneId)->whereNull('undone_at')->orderByDesc('created_at')->value('id');
            if (is_string($staleSnap)) {
                app(\App\Domain\Customers\Merge\UnmergeCustomer::class)->undo($staleSnap, 'seed self-heal: restore the merge-pair scene', $rgViewer->identifier());
            }
        }
        $mergeB = $resolver->resolveInbound('telegram', new InboundSender('s08-panel-mergeB', 'Merge Absorbed', '@fixture_merge_b'))->customerId;
        app(CustomerProfile::class)->update($mergeA, ['display_name' => 'Merge Survivor'], $rgViewer->identifier());
        app(CustomerProfile::class)->update($mergeB, ['display_name' => 'Merge Absorbed', 'language' => 'en'], $rgViewer->identifier());
        $this->scene($tenantId, $mergeA, 'chat-s08-mergeA', 8805, ['subject' => 'Survivor side']);
        $this->scene($tenantId, $mergeB, 'chat-s08-mergeB', 8806, ['subject' => 'Absorbed side']);
        foreach ([$mergeA, $mergeB] as $id) {
            app(\App\Domain\Customers\Search\CustomerSearchDocuments::class)->writeFor($id);
        }

        foreach ([$abebe, $kidist, $hanaSet, $rgCustomer, $blocked, $mergeA, $mergeB] as $customerId) {
            app(CustomerRollups::class)->recompute($customerId);
            // Step 12 (B4-S06): the directory searches these scenes — every
            // scene customer joins the index (only the merge pair did
            // before, which was all the profile spec's search needed).
            app(\App\Domain\Customers\Search\CustomerSearchDocuments::class)->writeFor($customerId);
        }

        // The spec deep-links every scene (FR-15 resolves through the list
        // rows) — ALL FOUR tickets project (the first run's second finding:
        // only abebe's row existed and the other deep links 404'd).
        /** @var list<string> $sceneTickets */
        $sceneTickets = DB::table('tickets')->whereIn('number', [8801, 8802, 8803, 8804, 8805, 8806, 8807, 8808, 8809])->pluck('id')->all();
        foreach ($sceneTickets as $ticketId) {
            app(\App\Domain\Inbox\ReadModels\ListProjector::class)->recomputeTicket($ticketId);
        }
    }

    /** @return array{0: User, 1: User} the RG-permitted viewer and the customers.view-only viewer */
    private function viewers(): array
    {
        $withRg = Role::query()->updateOrCreate(
            ['name' => 'S08 Panel Viewer'],
            [
                'color' => 'role-agent',
                'permissions' => [
                    'tickets.view', 'conversations.view', 'views.manage',
                    'customers.view', 'customers.edit', 'customers.notes.view', 'customers.notes.add',
                    'customers.rg_flag.view', 'customers.rg_flag.set',
                    'customers.merge', 'customers.block',
                    'customers.identity.link', 'customers.identity.unlink',
                    // Step 12 (B4-S07 H05/H06): the honesty spec's arcs.
                    'customers.export', 'customers.delete',
                ],
                'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All'],
            ],
        );
        $withoutRg = Role::query()->updateOrCreate(
            ['name' => 'S08 Panel Viewer (no RG)'],
            [
                'color' => 'role-agent',
                'permissions' => [
                    'tickets.view', 'conversations.view', 'views.manage',
                    'customers.view', 'customers.edit', 'customers.notes.view',
                ],
                'scopes' => ['tickets' => 'All', 'conversations' => 'All', 'customers' => 'All', 'reports' => 'All'],
            ],
        );

        $users = [];
        foreach ([[self::RG_VIEWER_EMAIL, 'S08 Panel Agent', $withRg], [self::NO_RG_VIEWER_EMAIL, 'S08 Panel NoRG', $withoutRg]] as [$email, $name, $role]) {
            if (User::query()->where('email', $email)->doesntExist()) {
                User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make(BrowserFixtureSeeder::PASSWORD)]);
            }
            User::query()->where('email', $email)->update([
                'role_id' => $role->getKey(),
                'email_verified_at' => DB::raw('clock_timestamp()'),
                'password_set_at' => DB::raw('clock_timestamp()'),
            ]);
            /** @var User $user */
            $user = User::query()->where('email', $email)->firstOrFail();
            app(\App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($user);
            $users[] = $user;
        }

        return [$users[0], $users[1]];
    }

    /**
     * A conversation + ticket (+ optional media message) for a scene customer; idempotent on the ticket number.
     *
     * @param  array<string, mixed>  $ticket
     */
    private function scene(string $tenantId, string $customerId, string $chatId, int $number, array $ticket = [], bool $media = false, string $messageLanguage = 'am'): string
    {
        /** @var ?string $existing */
        $existing = DB::table('tickets')->where('number', $number)->value('id');
        if ($existing !== null) {
            return $existing;
        }

        $conversationId = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'tenant_id' => $tenantId, 'channel' => 'telegram',
            'channel_ref_id' => 'fixture_brand_bot', 'external_chat_id' => $chatId,
            'customer_id' => $customerId, 'state' => 'active',
        ]);
        $ticketId = (string) Str::uuid7();
        DB::table('tickets')->insert(array_merge([
            'id' => $ticketId, 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
            'customer_id' => $customerId, 'number' => $number,
            'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
        ], $ticket));
        DB::table('messages')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
            'direction' => 'in', 'author_type' => 'customer', 'kind' => 'text',
            'body' => 'fixture inbound for scene '.$number, 'is_internal' => false, 'language' => $messageLanguage,
        ]);
        if ($media) {
            DB::table('messages')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversationId,
                'direction' => 'in', 'author_type' => 'customer', 'kind' => 'photo', 'body' => null, 'is_internal' => false,
                'media' => json_encode([
                    ['kind' => 'photo', 'storage_key' => 't/'.$tenantId.'/gal/s08-fixture.jpg', 'scan_status' => 'clean',
                        'source_url' => null, 'mime' => 'image/jpeg', 'bytes' => 2048, 'width' => null, 'height' => null,
                        'duration_seconds' => null, 'thumb_key' => null, 'transcript_ref' => null, 'ocr_ref' => null, 'alt_text' => null],
                    ['kind' => 'document', 'storage_key' => 't/'.$tenantId.'/gal/s08-fixture-bad.pdf', 'scan_status' => 'infected',
                        'source_url' => null, 'mime' => 'application/pdf', 'bytes' => 4096, 'width' => null, 'height' => null,
                        'duration_seconds' => null, 'thumb_key' => null, 'transcript_ref' => null, 'ocr_ref' => null, 'alt_text' => null],
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        return $ticketId;
    }
}
