<?php

declare(strict_types=1);

namespace App\Domain\Ai\Copilot;

use App\Domain\Ai\Chatbot\ContextBuilder;
use App\Domain\Ai\Orchestrator\AiOrchestrator;
use App\Domain\Ai\Prompt\LayeredAssembly;
use App\Domain\Ai\Providers\AllProvidersExhausted;
use App\Domain\Ai\Quota\AiQuotaExhausted;
use App\Domain\Ai\Retrieval\HybridRetriever;
use App\Domain\Conversations\Realtime\RealtimePusher;
use App\Jobs\Ai\CopilotPanelRefresh;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;
use Stringable;
use Throwable;

/**
 * FR-14's panel CONTRACT, read side + refresh side.
 *
 * READ (the HTTP door, no provider call inline — CLAUDE.md §3): chips come
 * straight from the latest classification decision (intent / sentiment /
 * urgency + confidence; rg_risk NEVER rides the Copilot — BR-06/SEC-04,
 * RG is Doc 08's banner); summary / KB top-3 / similar tickets / notices
 * come from the per-(conversation, user) cache; when the thread moved past
 * the cached summary a REFRESH JOB is dispatched, debounced at 10 s by a
 * TTL lock (FR-14's "debounced 10 s" as cache physics, no clock in code).
 *
 * REFRESH (the job): summarize_thread (fast tier) + hybrid retrieval with
 * internal articles (FR-10: Copilot-only) + similar resolved tickets
 * (scope-checked) → cache → one ai.copilot.panel realtime frame to the
 * requesting agent. Macro suggestions are Doc 12's seam: an honest empty
 * list under a named seam key, never a fake.
 */
final class CopilotPanel
{
    public const DEBOUNCE_SECONDS = 10;

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly TenantCache $cache,
        private readonly AiOrchestrator $orchestrator,
        private readonly LayeredAssembly $assembly,
        private readonly ContextBuilder $contextBuilder,
        private readonly HybridRetriever $retriever,
        private readonly SimilarTickets $similar,
        private readonly RealtimePusher $pusher,
        private readonly \App\Domain\Kb\Tombstones\CitationLinks $citationLinks, // S10 packet P3 (countersigned): title + version-pinned url on the KB card
        private readonly \App\Domain\Kb\Search\CopilotKbBlend $blend, // S10 packet P6 (countersigned): FR-17 — retrieval first, keyword fills to three
    ) {}

    /** @return array<string, mixed> */
    public function read(User $user, Conversation $conversation): array
    {
        $conversationId = $conversation->identifier();
        $latest = $this->latestMessageId($conversationId);
        $cached = $this->cache->get($this->cacheKey($conversationId, $user->identifier()));
        $cached = is_array($cached) ? $cached : null;

        $stale = $cached === null || ($cached['as_of_message_id'] ?? null) !== $latest;
        $refreshing = false;
        if ($stale && $latest !== null) {
            $lock = 'ai:copilot:refresh-lock:'.$conversationId.':'.$user->identifier();
            if ($this->cache->get($lock) === null) {
                $this->cache->put($lock, 1, self::DEBOUNCE_SECONDS, 'TTL — FR-14 10 s debounce');
                CopilotPanelRefresh::dispatch($conversationId, $user->identifier());
                $refreshing = true;
            } else {
                $refreshing = true; // a refresh is already in flight (or debounced)
            }
        }

        return [
            'chips' => $this->chips($conversationId),
            'summary' => $cached['summary'] ?? null,
            'summary_state' => $refreshing ? 'refreshing' : ($cached === null ? 'empty' : 'fresh'),
            'kb' => $cached['kb'] ?? [],
            'similar_tickets' => $cached['similar_tickets'] ?? [],
            'notices' => $cached['notices'] ?? [],
            'macros' => ['seam' => 'Doc12', 'items' => []],
            'as_of_message_id' => $cached['as_of_message_id'] ?? null,
        ];
    }

    /**
     * The job body. Returns what it cached (tests read it directly).
     *
     * @return array<string, mixed>
     */
    public function refresh(User $user, Conversation $conversation): array
    {
        $conversationId = $conversation->identifier();
        $latest = $this->latestMessageId($conversationId);
        $language = $this->language($conversationId, $conversation);
        $query = $this->latestCustomerBody($conversationId);
        $notices = [];

        // Summary (summarize_thread, fast tier).
        $summary = null;
        try {
            $window = $this->window($conversationId);
            $context = $this->contextBuilder->build(self::str($conversation->getAttribute('customer_id')), $language, $window);
            $assembled = $this->assembly->assemble('summarize_thread', ComposerActions::SUMMARIZE_TEMPLATE, $context);
            $result = $this->orchestrator->execute('summarize_thread', $assembled['messages']);
            $summary = trim((string) $result['response']->content);
            $summary = $summary === '' ? null : $summary;
        } catch (AllProvidersExhausted|AiQuotaExhausted) {
            $notices[] = 'ai.copilot.notice.degraded';
        } catch (Throwable) {
            $notices[] = 'ai.copilot.notice.unavailable';
        }

        // KB top 3 — internal articles included (FR-10: Copilot may see them).
        $kb = [];
        try {
            $retrieval = $this->retriever->retrieve($query, array_values(array_unique([$language, 'en'])), includeInternal: true, topK: 3);
            // P6: the map's INPUT is the FR-17 blend (retrieval chunks first, keyword hits fill to three,
            // published-only, Meilisearch down → retrieval-only); the P3 decoration per item is unchanged.
            $kb = array_map(fn (array $c): array => ['article_ref' => $c['article_ref'], 'excerpt' => $c['excerpt']] + ($this->citationLinks->forArticle($c['article_ref'], $language) ?? []), $this->blend->top3($query, $retrieval['chunks'], $language));
            if ($retrieval['degraded']) {
                $notices[] = 'ai.copilot.notice.retrieval_degraded';
            }
        } catch (Throwable) {
            $notices[] = 'ai.copilot.notice.retrieval_degraded';
        }

        // Similar resolved tickets, scope-checked.
        $currentTicket = DB::table('tickets')->where('conversation_id', $conversationId)->orderByDesc('created_at')->value('id');
        $similar = $this->similar->forQuery($user, $query, is_string($currentTicket) ? $currentTicket : null);

        $payload = [
            'summary' => $summary,
            'kb' => $kb,
            'similar_tickets' => $similar,
            'notices' => array_values(array_unique($notices)),
            'as_of_message_id' => $latest,
        ];
        $this->cache->put($this->cacheKey($conversationId, $user->identifier()), $payload, self::CACHE_TTL_SECONDS, 'TTL + as_of_message_id staleness check on read');

        $this->pusher->toUser($user->identifier(), 'ai.copilot.panel', ['conversation_id' => $conversationId] + $payload);

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private function chips(string $conversationId): ?array
    {
        /** @var object{response_payload: ?string, confidence: string|float|null}|null $row */
        $row = DB::table('ai_decisions')->where('conversation_id', $conversationId)->where('task', 'classify_message')->orderByDesc('created_at')->first(['response_payload', 'confidence']);
        if ($row === null || ! is_string($row->response_payload)) {
            return null;
        }
        $decoded = json_decode($row->response_payload, true);
        $classification = is_array($decoded) && is_array($decoded['classification'] ?? null) ? $decoded['classification'] : [];

        // The allowlist of what the Copilot shows — rg_risk is NOT on it.
        return [
            'intent' => is_string($classification['intent'] ?? null) ? $classification['intent'] : null,
            'sentiment' => is_numeric($classification['sentiment'] ?? null) ? (int) $classification['sentiment'] : null,
            'urgency' => is_numeric($classification['urgency'] ?? null) ? (int) $classification['urgency'] : null,
            'confidence' => is_numeric($row->confidence) ? round((float) $row->confidence, 2) : null,
        ];
    }

    private function cacheKey(string $conversationId, string $userId): string
    {
        return 'ai:copilot:panel:'.$conversationId.':'.$userId;
    }

    private function latestMessageId(string $conversationId): ?string
    {
        $id = DB::table('messages')->where('conversation_id', $conversationId)->where('kind', '!=', 'note')->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return is_string($id) ? $id : null;
    }

    private function latestCustomerBody(string $conversationId): string
    {
        $body = DB::table('messages')->where('conversation_id', $conversationId)->where('author_type', 'customer')->orderByDesc('created_at')->value('body');

        return is_string($body) ? $body : '';
    }

    private function language(string $conversationId, Conversation $conversation): string
    {
        $lang = DB::table('messages')->where('conversation_id', $conversationId)->where('author_type', 'customer')->whereNotNull('language')->orderByDesc('created_at')->value('language');
        if (in_array($lang, ['en', 'am'], true)) {
            return $lang;
        }
        $lang = DB::table('customers')->where('id', self::str($conversation->getAttribute('customer_id')))->value('language');

        return in_array($lang, ['en', 'am'], true) ? $lang : 'en';
    }

    /** @return list<array{role: string, content: string, kind?: string}> */
    private function window(string $conversationId): array
    {
        $rows = DB::table('messages')->where('conversation_id', $conversationId)
            ->whereIn('author_type', ['customer', 'agent', 'ai'])->where('kind', '!=', 'note')->where('is_internal', false)
            ->orderByDesc('created_at')->limit(30)->get()->reverse();
        $window = [];
        foreach ($rows as $row) {
            if (is_string($row->body) && $row->body !== '') {
                $window[] = ['role' => $row->author_type === 'customer' ? 'user' : 'assistant', 'content' => $row->body, 'kind' => self::str($row->kind)];
            }
        }

        return $window;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }
}
