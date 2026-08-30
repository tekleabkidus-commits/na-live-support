<?php

declare(strict_types=1);

use App\Domain\Inbox\Status\AgentStatus;
use App\Domain\Org\Seeding\OrganizationSeeder;
use App\Domain\Org\Structure\QueueWriter;
use App\Domain\Routing\Estimator\Estimator;
use App\Domain\Routing\Estimator\QueueWaitVariable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 11 SEC-05 / AC-09 — "Estimator outputs (customer-facing) contain bands only — never
| agent counts or names". Asserted by ABSENCE on the shapes themselves: the endpoint's
| payload keys, the provider's phrase, the Estimator's customer methods. Sabotage #9
| (a planted `eligible_agents` key) lands on the first test.
|--------------------------------------------------------------------------
*/

const ESTIMATE_PAYLOAD_KEYS = ['band', 'degraded'];

const LEAK_KEY_PATTERN = '/agent|count|online|eligible|name|user|member|position|depth|throughput|minutes/i';

it('SEC-05: GET /internal/routing/estimate/{queue} answers EXACTLY {band, degraded} — no key whose name could carry staffing intelligence; unknown queue = 404', function () {
    $tenant = createTenant();
    app(OrganizationSeeder::class)->seed();
    $queue = (string) DB::table('departments')->where('slug', 'general')->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage'], 'Fixture Leak Admin');
    foreach (['Fixture Leak Agent One', 'Fixture Leak Agent Two'] as $name) {
        $agent = User::factory()->create(['name' => $name]);
        grantPermissions($agent, ['tickets.view'], 'Fixture Leak Agent Role');
        app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
        app(AgentStatus::class)->set($agent, 'online');
    }
    activateTenant($tenant);
    $viewer = ticketActor(['tickets.view'], 'Fixture Leak Viewer');

    $response = $this->actingAs($viewer, 'staff')->getJson('https://localhost/internal/routing/estimate/'.$queue);
    $response->assertStatus(200);
    $payload = (array) $response->json();
    expect(array_keys($payload))->toBe(ESTIMATE_PAYLOAD_KEYS, 'the customer-facing shape grew a key: '.implode(',', array_keys($payload)));
    foreach (array_keys($payload) as $key) {
        expect(preg_match(LEAK_KEY_PATTERN, $key) === 1 && ! in_array($key, ESTIMATE_PAYLOAD_KEYS, true))->toBeFalse('leaking key '.$key);
    }
    expect(is_string($payload['band']))->toBeTrue()->and(is_bool($payload['degraded']))->toBeTrue();
    $body = (string) $response->getContent();
    expect($body)->not->toContain('Fixture Leak Agent')->and($body)->not->toContain('"2"');

    $this->actingAs($viewer, 'staff')->getJson('https://localhost/internal/routing/estimate/'.(string) Illuminate\Support\Str::uuid7())->assertStatus(404);
});

it('SEC-05: the Estimator\'s customer methods return the two-key shape and the {{queue_wait}} phrase carries no agent name, no count, no id — with two named agents online', function () {
    createTenant();
    app(OrganizationSeeder::class)->seed();
    $queue = (string) DB::table('departments')->where('slug', 'general')->value('default_queue_id');
    $admin = ticketActor(['org.queues.manage'], 'Fixture Leak Admin 2');
    $ids = [];
    foreach (['Fixture Leak Agent Three', 'Fixture Leak Agent Four'] as $name) {
        $agent = User::factory()->create(['name' => $name]);
        grantPermissions($agent, ['tickets.view'], 'Fixture Leak Agent Role 2');
        app(QueueWriter::class)->addMember($queue, $agent->identifier(), $admin->identifier());
        app(AgentStatus::class)->set($agent, 'online');
        $ids[] = $agent->identifier();
    }
    $noon = new DateTimeImmutable('2026-09-01 12:00:00', app(App\Domain\Routing\Hours\HoursContract::class)->timezone());
    expect(array_keys(app(Estimator::class)->forQueue($queue, $noon)))->toBe(ESTIMATE_PAYLOAD_KEYS);

    foreach (['en', 'am'] as $language) {
        $phrase = app(QueueWaitVariable::class)->forConversation((string) Illuminate\Support\Str::uuid7(), $language);
        expect($phrase)->not->toContain('Fixture Leak Agent');
        foreach ($ids as $id) {
            expect($phrase)->not->toContain($id);
        }
        expect(preg_match('/\b2\b/', $phrase))->toBe(0, 'the online-agent count leaked into the phrase');
        expect(in_array($phrase, [
            __('routing.estimate.band.short', [], $language), __('routing.estimate.band.medium', [], $language), __('routing.estimate.band.long', [], $language),
        ], true))->toBeTrue('the phrase is not one of the band vocabulary: '.$phrase);
    }
});

it('SEC-05 structural: the customer-facing shape is a CONSTANT of the source — the endpoint and the Estimator\'s forQueue/forTicket build arrays of exactly the two keys', function () {
    $endpoint = (string) file_get_contents(app_path('Domain/Routing/Estimator/EstimateEndpoint.php'));
    $estimator = (string) file_get_contents(app_path('Domain/Routing/Estimator/Estimator.php'));
    expect(substr_count($estimator, "return ['band' => \$d['band'], 'degraded' => \$d['degraded']];"))->toBe(2, 'forQueue/forTicket no longer return the exact two-key shape')
        ->and(substr_count($endpoint, "return ['band' => \$estimate['band'], 'degraded' => (bool) \$estimate['degraded']];"))->toBe(1);
    foreach (['eligible', 'agents', 'name', 'user_id'] as $word) {
        expect(str_contains($endpoint, "'{$word}"))->toBeFalse('the endpoint mentions '.$word);
    }
});
