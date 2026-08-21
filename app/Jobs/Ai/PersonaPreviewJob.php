<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Domain\Ai\Chatbot\ChatbotTurn;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\ImmutableLayer;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Quota\AiQuotaExhausted;
use App\Support\Cache\TenantCache;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * FR-18: "persona changes preview against 5 canned conversations before
 * publish". The DRAFT persona (not the published one) is assembled over the
 * five canned dialogs (fixtures/ai/golden — fixture text, so the egress
 * guard passes outside production) and the model's action per dialog is
 * shown to the admin. A queued job on ai-background (CLAUDE.md §3: never
 * inline); the page polls the cached result. Nothing is sent, logged as a
 * decision, or published — a preview is a preview.
 */
final class PersonaPreviewJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public const DIALOGS = ['deposit-question-en', 'deposit-question-am', 'human-request-en', 'thanks-resolve-en', 'money-no-grounding-en'];

    public int $tries = 1;

    /** @param array<string, mixed> $personaPayload */
    public function __construct(
        public readonly string $previewId,
        public readonly array $personaPayload,
    ) {
        $this->captureTenant();
        $this->onQueue('ai-background');
    }

    public static function cacheKey(string $previewId): string
    {
        return 'ai:persona-preview:'.$previewId;
    }

    public function handle(AiOrchestrator $orchestrator, LayeredAssembly $assembly, TenantCache $cache): void
    {
        $results = [];
        foreach (self::DIALOGS as $name) {
            $path = base_path('fixtures/ai/golden/'.$name.'.json');
            $fixture = json_decode((string) file_get_contents($path), true);
            if (! is_array($fixture)) {
                continue;
            }
            $language = is_string($fixture['language'] ?? null) ? $fixture['language'] : 'en';
            $window = [];
            foreach (is_array($fixture['dialog'] ?? null) ? $fixture['dialog'] : [] as $turn) {
                if (is_array($turn) && is_string($turn['role'] ?? null) && is_string($turn['content'] ?? null)) {
                    $window[] = ['role' => $turn['role'], 'content' => $turn['content']];
                }
            }
            $context = ['language' => $language, 'prior_topics' => [], 'acquisition' => null, 'window' => $window];
            $assembled = $assembly->assemble('chatbot_turn', ChatbotTurn::TEMPLATE."\nAvailable forms (run_form may name ONLY these): none.\nJSON only — no prose.", $context, [], false, $this->personaPayload);

            try {
                $result = $orchestrator->execute('chatbot_turn', $assembled['messages']);
                $decoded = json_decode((string) $result['response']->content, true);
                $results[] = [
                    'dialog' => $name, 'language' => $language,
                    'action' => is_array($decoded) && is_string($decoded['action'] ?? null) ? $decoded['action'] : 'invalid',
                    'text' => is_array($decoded) && is_string($decoded['text'] ?? null) ? $decoded['text'] : (is_array($decoded) && is_string($decoded['closing_text'] ?? null) ? $decoded['closing_text'] : null),
                    'reason' => is_array($decoded) && is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null,
                ];
            } catch (AllProvidersExhausted|AiQuotaExhausted $e) {
                $results[] = ['dialog' => $name, 'language' => $language, 'action' => 'degraded', 'text' => null, 'reason' => $e instanceof AiQuotaExhausted ? 'quota_exhausted' : 'provider_error'];
            }
        }

        $cache->put(self::cacheKey($this->previewId), ['state' => 'ready', 'immutable_hash' => ImmutableLayer::hash(), 'results' => $results], 900, 'TTL — a preview lives fifteen minutes');
    }
}
