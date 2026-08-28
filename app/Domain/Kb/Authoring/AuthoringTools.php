<?php

declare(strict_types=1);

namespace App\Domain\Kb\Authoring;

use App\Domain\Ai\DecisionLog\WeeklyOutputs;
use App\Domain\Kb\Articles\ArticleWriter;
use App\Jobs\Kb\KbAuthoringJob;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 FR-06 / BR-02 / AC-09 (S10 step 9) — the FOUR remaining AI tools as ONE door (Translate
 * variant is Translation/TranslateAll, step 8). Every tool does the same two things and nothing
 * else: it prepares a DRAFT through ArticleWriter's own doors and queues ONE KbAuthoringJob on the
 * `ai` family (external AI from queued jobs only). Nothing here or in the job can reach a workflow
 * transition (KbAiNeverPublishesTest, static + runtime). Draft-from-conversations consumes Doc 09
 * FR-20's top-10 seam — `WeeklyOutputs::topUnanswerable()` — which is IDS ONLY: the job carries the
 * message ids and reads the customer text server-side under the egress guard; no text crosses in
 * the request, the job payload or the decision log's identifiers ([D10] closing rows 2 + 5).
 */
final class AuthoringTools
{
    public const TASKS = ['kb_draft_from_source', 'improve_text', 'kb_faq_variants', 'kb_draft_from_conversations'];

    public function __construct(
        private readonly ArticleWriter $articles,
        private readonly WeeklyOutputs $weekly,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Paste T&Cs/policy text → a structured article DRAFT (the article is born AI-accented).
     *
     * @return array{article_id: string, variant_id: string, task: string}
     */
    public function draftFromSource(string $categoryId, string $audience, string $language, string $title, string $text, string $actorId): array
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('source text is empty');
        }
        $created = DB::transaction(function () use ($categoryId, $audience, $language, $title, $actorId): array {
            $created = $this->articles->create($categoryId, $audience, $language, $title, $actorId, translationSource: 'ai');
            $this->requested('kb_draft_from_source', $created['variant_id'], $actorId, ['chars' => 0]);

            return $created;
        });
        KbAuthoringJob::dispatch('kb_draft_from_source', $created['variant_id'], $actorId, ['text' => $text]);

        return ['article_id' => $created['article_id'], 'variant_id' => $created['variant_id'], 'task' => 'kb_draft_from_source'];
    }

    /**
     * Reading-level pass over an existing variant's working copy (blocks rewritten in place, AI-accented).
     *
     * @return array{article_id: string, variant_id: string, task: string}
     */
    public function simplify(string $variantId, string $actorId): array
    {
        return $this->onVariant('improve_text', $variantId, $actorId);
    }

    /**
     * "Also asked" Q-phrasing alternatives appended as ONE accented block.
     *
     * @return array{article_id: string, variant_id: string, task: string}
     */
    public function faqVariants(string $variantId, string $actorId): array
    {
        return $this->onVariant('kb_faq_variants', $variantId, $actorId);
    }

    /**
     * Doc 09 FR-20's one click: an intent cluster from the ids-only seam → a DRAFT with the questions as headings.
     *
     * @return array{article_id: string, variant_id: string, task: string, message_count: int}
     */
    public function draftFromConversations(string $intent, string $categoryId, string $audience, string $language, string $actorId): array
    {
        $cluster = null;
        foreach ($this->weekly->topUnanswerable() as $group) {
            if ($group['intent'] === $intent) {
                $cluster = $group;
                break;
            }
        }
        if ($cluster === null || $cluster['message_ids'] === []) {
            throw new InvalidArgumentException('no unanswered cluster for that intent this week');
        }
        $title = ucfirst(str_replace('_', ' ', $intent)); // provisional — the job's output titles the draft
        $created = DB::transaction(function () use ($categoryId, $audience, $language, $title, $actorId, $cluster): array {
            $created = $this->articles->create($categoryId, $audience, $language, $title, $actorId, translationSource: 'ai');
            $this->requested('kb_draft_from_conversations', $created['variant_id'], $actorId, ['intent' => $cluster['intent'], 'message_count' => count($cluster['message_ids'])]);

            return $created;
        });
        KbAuthoringJob::dispatch('kb_draft_from_conversations', $created['variant_id'], $actorId, ['message_ids' => $cluster['message_ids'], 'intent' => $cluster['intent']]);

        return ['article_id' => $created['article_id'], 'variant_id' => $created['variant_id'], 'task' => 'kb_draft_from_conversations', 'message_count' => count($cluster['message_ids'])];
    }

    /** @return array{article_id: string, variant_id: string, task: string} */
    private function onVariant(string $task, string $variantId, string $actorId): array
    {
        /** @var object{id: string, article_id: string, working_copy: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->first(['id', 'article_id', 'working_copy']);
        if ($variant === null) {
            throw new InvalidArgumentException('unknown variant');
        }
        if (KbAuthoringJob::textBlocks(json_decode($variant->working_copy, true)) === []) {
            throw new InvalidArgumentException('the working copy has no text to work on');
        }
        $this->requested($task, $variantId, $actorId, []);
        KbAuthoringJob::dispatch($task, $variantId, $actorId, []);

        return ['article_id' => $variant->article_id, 'variant_id' => $variantId, 'task' => $task];
    }

    /** @param  array<string, mixed>  $facts identifiers and counts only */
    private function requested(string $task, string $variantId, string $actorId, array $facts): void
    {
        $this->audit->record('kb.variant.ai_requested', 'kb_article_variant', $variantId, AuditActor::Staff, ['task' => $task] + $facts, actorId: $actorId);
    }
}
