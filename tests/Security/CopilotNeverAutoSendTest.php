<?php

declare(strict_types=1);

use App\Domain\Ai\Providers\ProviderResponse;
use App\Jobs\Ai\CopilotTaskJob;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 BR-01 / AC-06 / N-09 — COPILOT NEVER AUTO-SENDS. Two independent
 * proofs, both of which sabotage #7 (a path from suggestion to adapter send
 * without the composer action) must turn red:
 *
 *  STATIC  — no Copilot source (domain, job, controller) names any outbound
 *            door: ComposeOutbound, the adapter registry/contract, the lane
 *            job, persistOutbound, the notes writer. The absence is the
 *            mechanism: a class that cannot construct a sender cannot send.
 *  RUNTIME — a full suggested reply + every composer action through the real
 *            doors and jobs leaves ZERO outbound rows, ZERO message.queued
 *            events, ZERO adapter calls; the only way a draft reaches the
 *            customer is the agent's own composer reply — and THAT send is
 *            authored by the agent, never by author_type=ai.
 */
const COPILOT_FORBIDDEN_SYMBOLS = [
    'ComposeOutbound', 'AdapterRegistry', 'ChannelAdapter', 'EnqueueOutboundLane', 'LaneWorker',
    'persistOutbound', 'NotesWriter', 'DeliverablePayload', 'sendMessage(', 'send(',
];

function copilotSources(): array
{
    $files = [];
    foreach ([base_path('app/Domain/Ai/Copilot'), base_path('app/Http/Controllers/Ai/CopilotController.php'), base_path('app/Jobs/Ai/CopilotTaskJob.php'), base_path('app/Jobs/Ai/CopilotPanelRefresh.php')] as $path) {
        if (is_file($path)) {
            $files[] = $path;

            continue;
        }
        foreach (glob($path.'/*.php') ?: [] as $file) {
            $files[] = $file;
        }
    }

    return $files;
}

/** Strip comments/docblocks so the prose that EXPLAINS the rule cannot trip the detector. */
function copilotCodeOnly(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

it('STATIC (BR-01, sabotage #7 target): no Copilot source names an outbound door — ComposeOutbound, the adapter contract, the lane, persistOutbound, NotesWriter are all absent from the code', function (): void {
    $files = copilotSources();
    expect(count($files))->toBeGreaterThanOrEqual(8, 'the Copilot surface is smaller than step 10 shipped — the sweep lost files');

    foreach ($files as $file) {
        $code = copilotCodeOnly((string) file_get_contents($file));
        foreach (COPILOT_FORBIDDEN_SYMBOLS as $symbol) {
            expect(str_contains($code, $symbol))->toBeFalse(sprintf('%s names %s — a send path inside the Copilot (BR-01 violated)', basename($file), $symbol));
        }
    }
});

it('STATIC: the Copilot controller\'s and jobs\' constructor graph contains no outbound service (reflection over every dependency, transitively)', function (): void {
    $roots = [
        App\Http\Controllers\Ai\CopilotController::class,
        App\Domain\Ai\Copilot\ComposerActions::class,
        App\Domain\Ai\Copilot\CopilotPanel::class,
        App\Domain\Ai\Copilot\CopilotArtifacts::class,
        App\Domain\Ai\Copilot\CopilotStreamRelay::class,
    ];
    $forbidden = [
        App\Domain\Conversations\Outbound\ComposeOutbound::class,
        App\Domain\Conversations\Adapters\AdapterRegistry::class,
        App\Domain\Conversations\Notes\NotesWriter::class,
    ];
    $seen = [];
    $queue = $roots;
    while ($queue !== []) {
        $class = array_shift($queue);
        if (isset($seen[$class]) || ! class_exists($class)) {
            continue;
        }
        $seen[$class] = true;
        expect(in_array($class, $forbidden, true))->toBeFalse($class.' is reachable from the Copilot constructor graph (BR-01)');
        if (! str_starts_with($class, 'App\\')) {
            continue;
        }
        $constructor = (new ReflectionClass($class))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $queue[] = $type->getName();
            }
        }
    }
    expect(count($seen))->toBeGreaterThan(10);
});

it('RUNTIME (AC-06): a suggested reply + improve + translate + summarize through the real doors produce ZERO outbound messages, ZERO message.queued events, ZERO adapter calls — the agent\'s own composer send is the only send, and it is author_type=agent', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] my deposit of 500 ETB has not arrived');

    copilotDriver(
        copilotEmbedding(3),
        new ProviderResponse((string) json_encode(['text' => 'Thanks for letting us know — I will check the 500 ETB deposit now.', 'citations' => [], 'confidence' => 0.9]), 100, 40),
        new ProviderResponse('[FIXTURE] Thank you for letting us know; I will check the 500 ETB deposit right away.', 60, 30),
        new ProviderResponse('[FIXTURE] ስለነገሩን እናመሰግናለን፤ የ500 ETB ተቀማጭዎን አሁኑኑ እፈትሻለሁ።', 60, 30),
        new ProviderResponse('The customer reports a 500 ETB deposit that has not arrived. Nothing verified yet. Open: confirm with payments.', 80, 40),
    );

    $base = 'https://localhost/inbox/ai/copilot/'.$conversation->identifier();
    $this->actingAs($actor, 'staff')->postJson($base.'/draft')->assertStatus(202);
    $this->actingAs($actor, 'staff')->postJson($base.'/actions/improve', ['text' => '[FIXTURE] Thanks, I check the 500 ETB deposit now.'])->assertStatus(202);
    $this->actingAs($actor, 'staff')->postJson($base.'/actions/translate', ['text' => '[FIXTURE] I will check your 500 ETB deposit now.', 'target_language' => 'am'])->assertStatus(202);
    $this->actingAs($actor, 'staff')->postJson($base.'/actions/summarize')->assertStatus(202);

    asTenant($tenant, function () use ($conversation): void {
        $rows = DB::table('ai_decisions')->where('surface', 'copilot')->get(['task', 'action', 'error_category']);
        expect($rows->where('action', 'artifact')->count())->toBe(4, 'four artifacts expected; rows: '.json_encode($rows->all()));
        expect(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->count())->toBe(0, 'the Copilot produced an OUTBOUND message (BR-01 violated)');
        expect(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('author_type', 'ai')->count())->toBe(0);
        expect(DB::table('outbox')->where('name', 'message.queued')->count())->toBe(0, 'a message.queued event was published by the Copilot');
    });
    $adapter = app(App\Domain\Conversations\Adapters\AdapterRegistry::class)->adapter('fakechan');
    expect($adapter instanceof Tests\Support\Adapters\FakeChannelAdapter ? $adapter->sent : [])->toBe([], 'the adapter received a send from the Copilot');

    // The ONLY send: the agent, through the composer, as author_type=agent.
    $sent = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/reply', ['body' => 'Thanks — I will check the 500 ETB deposit now.']);
    $sent->assertStatus(201);
    asTenant($tenant, function () use ($sent): void {
        expect(DB::table('messages')->where('id', $sent->json('message_id'))->value('author_type'))->toBe('agent');
    });
});

it('RUNTIME: the job that generates a draft has no send either — running CopilotTaskJob directly for every action leaves the outbound table untouched', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] where is my bonus?', 'en', ['intent' => 'bonuses']);
    copilotDriver(
        copilotEmbedding(2),
        new ProviderResponse((string) json_encode(['text' => 'Let me look into the bonus for you.', 'citations' => [], 'confidence' => 0.9]), 10, 10),
        new ProviderResponse('Summary.', 10, 10),
    );

    (new CopilotTaskJob((string) Illuminate\Support\Str::uuid7(), 'draft', $conversation->identifier(), $actor->identifier()))->handle(app(App\Domain\Ai\Copilot\ComposerActions::class), app(App\Domain\Ai\Copilot\CopilotStreamRelay::class), app(App\Domain\Ai\Concurrency\TenantSemaphore::class));
    (new CopilotTaskJob((string) Illuminate\Support\Str::uuid7(), 'summarize', $conversation->identifier(), $actor->identifier()))->handle(app(App\Domain\Ai\Copilot\ComposerActions::class), app(App\Domain\Ai\Copilot\CopilotStreamRelay::class), app(App\Domain\Ai\Concurrency\TenantSemaphore::class));

    expect(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('direction', 'out')->count())->toBe(0);
});
