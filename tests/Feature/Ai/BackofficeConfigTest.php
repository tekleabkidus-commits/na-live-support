<?php

declare(strict_types=1);

use App\Domain\Ai\Honesty\Disclosure;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Ai\PersonaPreviewJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Doc 09 FR-18 / SEC-05 / BR-07 — the Backoffice doors (S09 step 12, Option
 * A [B8]): persona draft → 5-conversation preview (queued, draft persona
 * assembled, nothing published/logged) → publish through the disclosure
 * gate; guardrails draft → publish with the SEC-05 audit row; thresholds
 * simulate → publish ONLY with the token (BR-07); the kill-switch flip
 * needs confirmation and answers the grid + banner; every door gated by its
 * CLOSED key (403 without, 404 pattern for a foreign version).
 */
function backofficeActor(array $keys): App\Models\User
{
    return grantPermissions(App\Models\User::factory()->create(), array_merge(['tickets.view'], $keys), 'S09 Backoffice '.implode(',', $keys));
}

it('persona: draft → preview (queued on ai-background, the DRAFT persona assembled over the five canned dialogs, no decision row, no publish) → publish; a greeting without the disclosure is REFUSED by the door with the verbatim reason', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $admin = backofficeActor(['ai.chatbot.configure']);
    $disclosure = app(Disclosure::class);

    $this->actingAs($admin, 'staff')->get('https://localhost/admin/ai/persona')->assertOk()->assertInertia(fn ($a) => $a->component('Admin/Ai/Persona', shouldExist: false)->has('languages'));
    bindTenant($tenant);

    $draft = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/persona/draft', [
        'name' => 'Fixture Bet Helper', 'greeting' => ['en' => 'Hello from Fixture Bet — '.$disclosure->wording('en').'.', 'am' => 'ሰላም — '.$disclosure->wording('am').'።'], 'tone' => ['formality' => 30, 'detail' => 60],
    ]);
    $draft->assertStatus(201);
    $versionId = $draft->json('version_id');

    Queue::fake();
    $preview = $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/ai/persona/{$versionId}/preview", []);
    $preview->assertStatus(202);
    expect($preview->json('dialogs'))->toBe(PersonaPreviewJob::DIALOGS);
    Queue::assertPushed(PersonaPreviewJob::class, fn (PersonaPreviewJob $job): bool => $job->queue === 'ai-background' && $job->personaPayload['name'] === 'Fixture Bet Helper');

    // Run the job body: five calls, the DRAFT persona in every system layer, no decision rows, nothing published.
    bindTenant($tenant);
    $answer = new ProviderResponse((string) json_encode(['action' => 'handoff', 'reason' => 'customer_request']), 5, 5);
    $driver = copilotDriver($answer, $answer, $answer, $answer, $answer);
    (new PersonaPreviewJob($preview->json('preview_id'), $draft->json('version_id') ? ['name' => 'Fixture Bet Helper', 'greeting' => ['en' => 'x']] : []))->handle(app(App\Domain\Ai\Orchestrator\AiOrchestrator::class), app(App\Domain\Ai\Prompt\LayeredAssembly::class), app(App\Support\Cache\TenantCache::class));
    expect(count($driver->seen))->toBe(5);
    foreach ($driver->seen as $call) {
        expect($call['messages'][0]['content'])->toContain('Fixture Bet Helper');
    }
    expect(DB::table('ai_decisions')->where('surface', 'chatbot')->count())->toBe(0, 'a preview must not write decisions');
    expect(DB::table('ai_config_versions')->where('id', $versionId)->value('status'))->toBe('draft');
    $shown = $this->actingAs($admin, 'staff')->getJson('https://localhost/admin/ai/persona/preview/'.$preview->json('preview_id'));
    expect($shown->json('state'))->toBe('ready')->and($shown->json('results'))->toHaveCount(5)->and($shown->json('results.0.action'))->toBe('handoff');

    // Publish: the gated door; then a disclosure-less greeting is refused verbatim.
    $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/ai/versions/{$versionId}/publish", [])->assertOk();
    asTenant($tenant, fn () => expect(DB::table('ai_config_versions')->where('id', $versionId)->value('status'))->toBe('published'));
    $bad = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/persona/draft', ['name' => 'X', 'greeting' => ['am' => 'ሰላም ብቻ'], 'tone' => ['formality' => 50, 'detail' => 50]]);
    $refused = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/versions/'.$bad->json('version_id').'/publish', []);
    $refused->assertStatus(422);
    expect($refused->json('detail'))->toContain('the am greeting does not contain the AI disclosure');
});

it('guardrails: draft → publish writes the SEC-05 audit row (ai.config.published, actor + kind/key/version) and the ai.config.published event; the rollback door republishes the older payload and audits ai.config.rolled_back', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $admin = backofficeActor(['ai.guardrails.edit', 'ai.chatbot.configure']);
    $payload = ['blocked_topics' => ['betting advice', 'legal advice'], 'disclosure' => ['en' => 'You are talking to Fixture Bet\'s AI helper', 'am' => null], 'max_ai_messages' => 6, 'profanity_policy' => 'block', 'pii_redaction' => true];

    $v1 = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/guardrails/draft', $payload)->assertStatus(201)->json('version_id');
    $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/ai/versions/{$v1}/publish", [])->assertOk();
    $v2 = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/guardrails/draft', ['max_ai_messages' => 3] + $payload)->json('version_id');
    $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/ai/versions/{$v2}/publish", [])->assertOk();

    asTenant($tenant, function () use ($admin, $v1): void {
        $rows = DB::table('audit_log')->where('action_key', 'ai.config.published')->get(['actor_id', 'payload', 'entity_id']);
        expect($rows->where('actor_id', $admin->identifier())->filter(fn ($r) => str_contains((string) $r->payload, 'guardrails'))->count())->toBe(2, json_encode($rows->all()))
            ->and(DB::table('outbox')->where('name', 'ai.config.published')->where('payload->kind', 'guardrails')->count())->toBe(2)
            ->and(app(Disclosure::class)->wording('en'))->toBe('You are talking to Fixture Bet\'s AI helper')
            ->and(app(Disclosure::class)->wording('am'))->toBe(__('ai.disclosure.sentence', [], 'am'));
        expect(DB::table('ai_config_versions')->where('id', $v1)->value('status'))->toBe('superseded');
    });

    $this->actingAs($admin, 'staff')->postJson("https://localhost/admin/ai/versions/{$v1}/rollback", [])->assertOk();
    asTenant($tenant, function (): void {
        $published = app(App\Domain\Ai\Config\ConfigVersions::class)->published('guardrails', 'default');
        expect(json_decode((string) $published->payload, true)['max_ai_messages'])->toBe(6)
            ->and(DB::table('audit_log')->where('action_key', 'ai.config.rolled_back')->count())->toBe(1);
    });
});

it('thresholds/scope (BR-07, US-06): simulate returns before/after/deltas + the token for EXACTLY these values; publish with that token lands (chatbot_enabled rides the same record); a stale token is refused; the endpoint list carries each bot\'s on/off', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $admin = backofficeActor(['ai.thresholds.edit']);
    $this->actingAs($admin, 'staff')->get('https://localhost/admin/ai/thresholds')->assertOk()->assertInertia(fn ($a) => $a->component('Admin/Ai/Thresholds', shouldExist: false)->where('endpoints.0.ref', 'default')->where('endpoints.0.chatbot_enabled', false));

    $values = ['rg_min' => 0.75, 'spam_min' => 0.9, 'handoff_below' => 0.4, 'auto_resolve_min' => 0.9];
    $sim = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/thresholds/simulate', $values);
    $sim->assertOk();
    expect($sim->json('token'))->toHaveLength(64)->and($sim->json('before'))->toHaveKeys(['rg_override', 'spam', 'handoff_low_confidence', 'eligible_auto_resolve', 'eligible'])->and($sim->json('deltas'))->toBeArray();

    $stale = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/thresholds/publish', $values + ['day' => $sim->json('day'), 'token' => str_repeat('0', 64), 'chatbot_enabled' => true]);
    $stale->assertStatus(422);
    expect($stale->json('detail'))->toContain('BR-07');

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/thresholds/publish', $values + ['day' => $sim->json('day'), 'token' => $sim->json('token'), 'chatbot_enabled' => true])->assertOk();
    asTenant($tenant, function (): void {
        expect(app(App\Domain\Ai\Pipeline\Thresholds::class)->value('rg_min'))->toBe(0.75);
        $published = app(App\Domain\Ai\Config\ConfigVersions::class)->published('thresholds', 'default');
        expect(json_decode((string) $published->payload, true)['chatbot_enabled'])->toBeTrue();
    });
    $this->actingAs($admin, 'staff')->get('https://localhost/admin/ai/thresholds')->assertInertia(fn ($a) => $a->where('endpoints.0.chatbot_enabled', true));
});

it('kill switches (FR-18/US-07/AC-08 bannered): the grid page; a flip WITHOUT confirmation is refused; with it the audited door flips and the answer + the banner door carry "OFF since {time} by {actor}"; flipping back clears the banner', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $admin = backofficeActor(['ai.killswitch']);
    $admin->forceFill(['name' => 'Fixture Admin'])->save();
    $this->actingAs($admin, 'staff')->get('https://localhost/admin/ai/kill-switches')->assertOk()->assertInertia(fn ($a) => $a->component('Admin/Ai/KillSwitches', shouldExist: false)->where('surfaces', ['chatbot', 'copilot', 'supervisor']));

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/kill-switches/flip', ['surface' => 'chatbot', 'channel' => 'telegram', 'off' => true])->assertStatus(422);

    $flip = $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/kill-switches/flip', ['surface' => 'chatbot', 'channel' => 'telegram', 'off' => true, 'reason' => 'incident drill', 'confirm' => true]);
    $flip->assertOk();
    $row = collect($flip->json('grid'))->firstWhere('channel', 'telegram');
    expect($row['is_off'])->toBeTrue()->and($row['flipped_by_name'])->toBe('Fixture Admin')->and($row['flipped_at'])->toBeString();
    asTenant($tenant, fn () => expect(DB::table('audit_log')->where('action_key', 'ai.killswitch.changed')->where('actor_id', $admin->identifier())->count())->toBe(1));

    $banner = $this->actingAs($actor, 'staff')->getJson('https://localhost/admin/ai/banner'); // every staff member (tickets.view) sees the banner
    expect($banner->json('off'))->toHaveCount(1)->and($banner->json('off.0.surface'))->toBe('chatbot');

    $this->actingAs($admin, 'staff')->postJson('https://localhost/admin/ai/kill-switches/flip', ['surface' => 'chatbot', 'channel' => 'telegram', 'off' => false, 'confirm' => true])->assertOk();
    expect($this->actingAs($actor, 'staff')->getJson('https://localhost/admin/ai/banner')->json('off'))->toBe([]);
});

it('permissions: every Backoffice door is gated by its closed key — a viewer without it gets 403; publishing a persona version with only ai.guardrails.edit is refused by the kind re-check', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $nobody = backofficeActor([]);
    foreach (['persona', 'guardrails', 'thresholds', 'kill-switches', 'decisions'] as $page) {
        $this->actingAs($nobody, 'staff')->get("https://localhost/admin/ai/{$page}")->assertStatus(403);
    }
    bindTenant($tenant);
    $guardrailsOnly = backofficeActor(['ai.guardrails.edit', 'ai.chatbot.configure']);
    $personaAdmin = backofficeActor(['ai.chatbot.configure']);
    $v = $this->actingAs($personaAdmin, 'staff')->postJson('https://localhost/admin/ai/persona/draft', ['name' => 'P', 'greeting' => ['en' => 'Hi — '.app(Disclosure::class)->wording('en')], 'tone' => ['formality' => 50, 'detail' => 50]])->assertStatus(201)->json('version_id');
    // Route-gated on ai.chatbot.configure; the kind re-check passes for persona → ok for personaAdmin; guardrails-only (lacking chatbot.configure) never reaches the door.
    bindTenant($tenant);
    $noConfigure = backofficeActor(['ai.guardrails.edit']);
    $this->actingAs($noConfigure, 'staff')->postJson("https://localhost/admin/ai/versions/{$v}/publish", [])->assertStatus(403);
    $this->actingAs($personaAdmin, 'staff')->postJson("https://localhost/admin/ai/versions/{$v}/publish", [])->assertOk();
    // A guardrails version published by someone holding chatbot.configure but NOT guardrails.edit → the kind re-check refuses.
    $g = $this->actingAs($guardrailsOnly, 'staff')->postJson('https://localhost/admin/ai/guardrails/draft', ['blocked_topics' => ['x'], 'max_ai_messages' => 5, 'profanity_policy' => 'allow', 'pii_redaction' => false])->json('version_id');
    $this->actingAs($personaAdmin, 'staff')->postJson("https://localhost/admin/ai/versions/{$g}/publish", [])->assertStatus(403);
});
