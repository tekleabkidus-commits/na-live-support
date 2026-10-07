<?php

declare(strict_types=1);

namespace App\Console\Commands\Sms;

use App\Support\Settings\SettingsRepository;
use App\Support\Tenancy\RunsWithinTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TEST-ONLY fixture for the P-EXPIRED-CARD browser spec (never registered for
 * production use — it mutates browser-seed rows directly). Puts the Yonas
 * #4820 ticket's conversation into the expired reply window, sets a
 * non-covering quiet-hours window (so a re-engagement QUEUES rather than
 * holding overnight), and toggles the phone identity / an active gateway /
 * a recent already-sent re-engagement per the flags.
 */
final class FixtureExpiredCard extends Command
{
    use RunsWithinTenant;

    protected $signature = 'sms:fixture-expired-card {--gateway} {--phone} {--duplicate}';

    protected $description = 'Seed the expired-window SMS re-engagement browser scenario on ticket #4820';

    public function handle(): int
    {
        $p = DB::connection('pgsql_platform');
        /** @var object{id: string, tenant_id: string, conversation_id: string, customer_id: string} $t */
        $t = $p->table('tickets')->where('number', 4820)->first(['id', 'tenant_id', 'conversation_id', 'customer_id']);

        // the seeder leaves open_ticket_id unset (the real flow stamps it when a
        // ticket opens); the reengage door 404s without it (C-10), so the fixture
        // states what is already true — #4820 is this conversation's open ticket.
        $p->table('conversations')->where('id', $t->conversation_id)->update(['open_ticket_id' => $t->id, 'reply_window_state' => 'expired', 'reply_window_expires_at' => now()->subHour()]);

        // a non-covering quiet window in the tenant's timezone, so the reengagement queues
        $this->withinTenant($t->tenant_id, function (): void {
            $nowAddis = now()->setTimezone('Africa/Addis_Ababa');
            app(SettingsRepository::class)->set('sms.quiet_hours', ['start' => $nowAddis->copy()->addHours(3)->format('H:i'), 'end' => $nowAddis->copy()->addHours(4)->format('H:i')]);
        });

        // phone identity (distinctive value, cleared tenant-wide first)
        $p->table('customer_identities')->where('customer_id', $t->customer_id)->where('type', 'phone')->delete();
        $p->table('customer_identities')->where('tenant_id', $t->tenant_id)->where('type', 'phone')->where('value', '+251900004820')->delete();
        if ($this->option('phone')) {
            $p->table('customer_identities')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t->tenant_id, 'customer_id' => $t->customer_id, 'channel' => 'sms', 'external_user_id' => 'exp-'.Str::random(6), 'type' => 'phone', 'value' => '+251900004820', 'source' => 'staff', 'metadata' => '{}']);
        }

        // an active gateway with a credential ref (GatewayAvailability::exists)
        $p->table('sms_routes')->where('tenant_id', $t->tenant_id)->delete();
        $p->table('sms_messages')->where('ticket_id', $t->id)->where('event', 'window_reengagement')->delete();
        $p->table('sms_gateways')->where('tenant_id', $t->tenant_id)->delete();
        if ($this->option('gateway')) {
            $p->table('sms_gateways')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t->tenant_id, 'name' => 'Expired card fixture', 'driver' => 'http_generic', 'config' => '{}', 'credential_ref' => 'vault://sms/fixture/token/v1', 'active' => true, 'health' => 'healthy', 'circuit_state' => 'closed', 'is_fixture' => true]);
        }

        // a re-engagement already sent inside the 10-min window (EC-10)
        if ($this->option('duplicate')) {
            $p->table('sms_messages')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t->tenant_id, 'customer_id' => $t->customer_id, 'ticket_id' => $t->id, 'conversation_id' => $t->conversation_id, 'event' => 'window_reengagement', 'language' => 'en', 'phone' => '+251900004820', 'rendered_body' => 'come back', 'state' => 'sent', 'lane' => 'sms-reengagement', 'segments' => 1]);
        }

        $this->info('expired-card fixture ready on #4820');

        return self::SUCCESS;
    }
}
